<?php
// Members, households and settings, as the organizer manages them.
declare(strict_types=1);

/** Every household with its members. Households with someone active come first, then by name. */
function households_with_members(): array
{
    $households = q('SELECT * FROM households ORDER BY name COLLATE NOCASE')->fetchAll();
    foreach ($households as &$h) {
        $h['members'] = q('SELECT * FROM members WHERE household_id = ? ORDER BY active DESC, name COLLATE NOCASE',
            [$h['id']])->fetchAll();
        $h['active_count'] = count(array_filter($h['members'], fn(array $m): bool => (bool) $m['active']));
    }
    unset($h);
    // Sorting on "has anyone active" keeps the name order within each group.
    usort($households, fn(array $a, array $b): int => ($b['active_count'] > 0) <=> ($a['active_count'] > 0));
    return $households;
}

function find_member(int $memberId): ?array
{
    $row = q('SELECT * FROM members WHERE id = ?', [$memberId])->fetch();
    return $row ?: null;
}

/** The id of the household with this name, which is created if there isn't one yet. */
function household_named(string $name): int
{
    $id = q('SELECT id FROM households WHERE name = ? COLLATE NOCASE', [$name])->fetchColumn();
    if ($id === false) {
        q('INSERT INTO households (name) VALUES (?)', [$name]);
        $id = db()->lastInsertId();
    }
    return (int) $id;
}

/** Removes households nobody belongs to any more, unless they have orders on record. */
function households_tidy(): void
{
    q('DELETE FROM households WHERE id NOT IN (SELECT household_id FROM members)
                                AND id NOT IN (SELECT household_id FROM orders)');
}

/**
 * Adds a member (when $memberId is null) or changes one.
 * $data holds: name, email, phone, household_id or new_household, is_admin, active.
 * $actingId is the organizer doing this, who can't switch themselves off by mistake.
 * Returns a short code for what is wrong, or null if it was saved.
 */
function member_save(?int $memberId, array $data, int $actingId): ?string
{
    $name = trim((string) ($data['name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $newHousehold = trim((string) ($data['new_household'] ?? ''));
    $householdId = (int) ($data['household_id'] ?? 0);
    $isAdmin = empty($data['is_admin']) ? 0 : 1;
    $active = empty($data['active']) ? 0 : 1;
    // The "gets group emails" box is only on the edit form (has_group_box says it was there).
    // A new member gets group emails until they unsubscribe.
    $optOut = !empty($data['has_group_box']) && empty($data['group_emails']) ? 1 : 0;

    if ($name === '') {
        return 'member_name';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'member_email';
    }
    if (q('SELECT 1 FROM members WHERE email = ? AND id <> ?', [$email, $memberId ?? 0])->fetchColumn()) {
        return 'member_duplicate';
    }
    if ($newHousehold === '' && !q('SELECT 1 FROM households WHERE id = ?', [$householdId])->fetchColumn()) {
        return 'member_household';
    }
    if ($memberId === $actingId && (!$isAdmin || !$active)) {
        return 'member_self';
    }
    if ($newHousehold !== '') {
        $householdId = household_named($newHousehold);
    }

    if ($memberId === null) {
        q('INSERT INTO members (household_id, name, email, phone, is_admin, active) VALUES (?, ?, ?, ?, ?, ?)',
            [$householdId, $name, $email, $phone ?: null, $isAdmin, $active]);
    } else {
        q('UPDATE members SET household_id = ?, name = ?, email = ?, phone = ?, is_admin = ?, active = ?, email_opt_out = ?
           WHERE id = ?', [$householdId, $name, $email, $phone ?: null, $isAdmin, $active, $optOut, $memberId]);
        if (!$active) {
            // Sign them out everywhere, and cancel any sign-in link still on its way.
            q('DELETE FROM sessions WHERE member_id = ?', [$memberId]);
            q('DELETE FROM login_tokens WHERE member_id = ?', [$memberId]);
        }
    }
    households_tidy();
    return null;
}

/**
 * Has this member never done anything on the site? Only then can they be deleted;
 * anyone with a history is switched to inactive, so past orders keep their names.
 */
function member_is_unused(int $memberId): bool
{
    foreach (['sessions WHERE member_id', 'order_lines WHERE added_by', 'shares WHERE started_by',
              'share_claims WHERE member_id', 'favorites WHERE member_id'] as $where) {
        if (q("SELECT 1 FROM $where = ? LIMIT 1", [$memberId])->fetchColumn()) {
            return false;
        }
    }
    return true;
}

/** Deletes a member who was added by mistake. Returns a problem code, or null if deleted. */
function member_delete(int $memberId, int $actingId): ?string
{
    if ($memberId === $actingId) {
        return 'member_self';
    }
    if (!member_is_unused($memberId)) {
        return 'member_used';
    }
    q('DELETE FROM login_tokens WHERE member_id = ?', [$memberId]);
    q('DELETE FROM members WHERE id = ?', [$memberId]);
    households_tidy();
    return null;
}

/** Renames a household. Returns a problem code, or null if it was saved. */
function household_rename(int $householdId, string $name): ?string
{
    $name = trim($name);
    if ($name === '') {
        return 'household_name';
    }
    if (q('SELECT 1 FROM households WHERE name = ? COLLATE NOCASE AND id <> ?', [$name, $householdId])->fetchColumn()) {
        return 'household_duplicate';
    }
    q('UPDATE households SET name = ? WHERE id = ?', [$name, $householdId]);
    return null;
}

/**
 * Adds many members from pasted text, one person per line, with commas or tabs between
 * the parts: name, email, household, phone. Household and phone can be left off; a person
 * with no household gets one named after them. The email is spotted wherever it sits in
 * the line, so "email, first name, last name" from a Mailchimp export also works (the
 * first name becomes the name and the last name the household).
 * Returns how many were added, how many were already members, and the numbers of the
 * lines that could not be read.
 */
function members_import(string $text): array
{
    $added = 0;
    $already = 0;
    $bad = [];
    $seenAPerson = false;
    foreach (preg_split('/\R/', $text) as $index => $line) {
        if (trim($line) === '') {
            continue;
        }
        $fields = str_contains($line, "\t") ? explode("\t", $line) : str_getcsv($line, ',', '"', '');
        $fields = array_map(fn($field): string => trim((string) $field), $fields);

        $email = null;
        $others = [];
        foreach ($fields as $field) {
            if ($email === null && filter_var($field, FILTER_VALIDATE_EMAIL)) {
                $email = $field;
            } elseif ($field !== '') {
                $others[] = $field;
            }
        }
        if ($email === null) {
            // A first line with no email in it is a heading row ("Name, Email, ..."), not a mistake.
            if ($seenAPerson || $index > 0) {
                $bad[] = $index + 1;
            }
            continue;
        }
        $seenAPerson = true;
        if (!$others) {
            $bad[] = $index + 1; // an email with no name
            continue;
        }
        if (q('SELECT 1 FROM members WHERE email = ?', [$email])->fetchColumn()) {
            $already++;
            continue;
        }
        [$name, $household, $phone] = [$others[0], $others[1] ?? $others[0], $others[2] ?? null];
        q('INSERT INTO members (household_id, name, email, phone) VALUES (?, ?, ?, ?)',
            [household_named($household), $name, $email, $phone]);
        $added++;
    }
    return ['added' => $added, 'already' => $already, 'bad' => $bad];
}

/**
 * Saves the organizer's settings: group discount, the two deposit amounts, how long an
 * unfilled share stays listed, and the Venmo handle. Nothing is saved unless all of it
 * is readable. Returns a problem code, or null if saved.
 */
function settings_save(array $form): ?string
{
    $discount = trim((string) ($form['discount_percent'] ?? ''));
    $glass = money_to_cents(trim((string) ($form['deposit_glass'] ?? '')));
    $plastic = money_to_cents(trim((string) ($form['deposit_plastic'] ?? '')));
    $months = trim((string) ($form['share_expiry_months'] ?? ''));
    $venmo = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($form['venmo_handle'] ?? ''));
    $cap = trim((string) ($form['email_daily_cap'] ?? ''));

    if (!is_numeric($discount) || $discount < 0 || $discount > 100) {
        return 'settings_discount';
    }
    if ($glass === null || $plastic === null) {
        return 'settings_deposit';
    }
    if (!ctype_digit($months) || (int) $months < 1 || (int) $months > 24) {
        return 'settings_months';
    }
    if (!ctype_digit($cap) || (int) $cap < 1) {
        return 'settings_cap';
    }
    set_setting('email_daily_cap', (string) (int) $cap);
    set_setting('discount_percent', (string) (float) $discount);
    set_setting('deposit_glass_cents', (string) $glass);
    set_setting('deposit_plastic_cents', (string) $plastic);
    set_setting('share_expiry_months', (string) (int) $months);
    set_setting('venmo_handle', $venmo);

    // New amounts take effect at once: deposits are worked out again for the whole catalog,
    // then open orders and saved-for-next-month lists are repriced. Locked and closed
    // months keep the prices they had.
    recompute_deposits();
    refresh_open_order_prices();
    return null;
}
