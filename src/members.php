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
            member_sign_out_everywhere($memberId);
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

/** Signs a member out on every device and cancels any sign-in link still on its way. */
function member_sign_out_everywhere(int $memberId): void
{
    q('DELETE FROM sessions WHERE member_id = ?', [$memberId]);
    q('DELETE FROM login_tokens WHERE member_id = ?', [$memberId]);
}

/**
 * Archives a member, or restores an archived one. An archived member can't sign in and
 * gets no emails; their past orders stay on record. $actingId is the organizer doing it,
 * who can't archive themselves. Returns a problem code, or null if it was done.
 */
function member_set_active(int $memberId, bool $active, int $actingId): ?string
{
    if (!find_member($memberId)) {
        return 'member_gone';
    }
    if (!$active && $memberId === $actingId) {
        return 'member_self';
    }
    q('UPDATE members SET active = ? WHERE id = ?', [$active ? 1 : 0, $memberId]);
    if (!$active) {
        member_sign_out_everywhere($memberId);
    }
    return null;
}

// ---- The end-of-year tidy-up ----

// Members added in the last few months of the year are left alone, because they have
// not had a fair chance to order yet. With 6, that is anyone added from July onward.
const ARCHIVE_GRACE_MONTHS = 6;

/**
 * The members the end-of-year tidy-up would archive for $year: active, not an organizer,
 * on the list since before July, and in a household that had nothing on any order that
 * year. "Nothing" means no items of its own in any month, and no part in a share that
 * was ordered. People in a household share one order, so they are kept or archived together.
 */
function yearly_archive_candidates(int $year): array
{
    // The months whose deadline fell in that year, by the site's own clock.
    $months = [];
    foreach (q("SELECT id, due_at FROM cycles WHERE status <> 'next'")->fetchAll() as $c) {
        if ((int) fmt_local($c['due_at'], 'Y') === $year) {
            $months[] = (int) $c['id'];
        }
    }
    $ordered = []; // ids of households that ordered something
    if ($months) {
        $in = implode(',', $months); // whole numbers from the database, safe to put in the query
        $own = q("SELECT DISTINCT o.household_id FROM orders o JOIN order_lines l ON l.order_id = o.id
                  WHERE o.cycle_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        $shared = q("SELECT DISTINCT m.household_id FROM share_claims c JOIN shares s ON s.id = c.share_id
                     JOIN members m ON m.id = c.member_id WHERE s.cycle_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        $ordered = array_flip(array_map('intval', array_merge($own, $shared)));
    }
    $addedBefore = sprintf('%d-%02d-01 00:00:00', $year, 13 - ARCHIVE_GRACE_MONTHS);
    $members = q('SELECT m.*, h.name AS household_name FROM members m JOIN households h ON h.id = m.household_id
                  WHERE m.active = 1 AND m.is_admin = 0 AND m.created_at < ?
                  ORDER BY h.name COLLATE NOCASE, m.name COLLATE NOCASE', [$addedBefore])->fetchAll();
    return array_values(array_filter($members, fn(array $m): bool => !isset($ordered[(int) $m['household_id']])));
}

/** The note an archived member gets. Replies go to the organizer, because it is sent from his address. */
function archive_email_text(array $member, int $year): string
{
    return "Hi {$member['name']},\n\n"
        . "At the end of each year we tidy up the " . config('site_name') . " member list. Your household didn't\n"
        . "place an order in $year, so your account has been archived.\n\n"
        . "That only means two things: you won't get our emails, and your sign-in link won't work for now.\n"
        . "Nothing has been deleted.\n\n"
        . "You are welcome back any time. If you'd like to order with us again, next month or next year,\n"
        . "just reply to this email, or write to " . config('mail_from') . ", and I'll switch your account back on.\n\n"
        . "Thank you for being part of the group.\n\n"
        . "Brent\n";
}

/**
 * Runs the end-of-year tidy-up for $year: archives each member found above, queues the
 * note to them, and emails the organizers the list. Returns the members archived.
 */
function yearly_archive_run(int $year): array
{
    $members = yearly_archive_candidates($year);
    foreach ($members as $m) {
        q('UPDATE members SET active = 0 WHERE id = ?', [$m['id']]);
        member_sign_out_everywhere((int) $m['id']);
        queue_email($m['email'], 'Your ' . config('site_name') . ' account has been archived', archive_email_text($m, $year));
    }
    set_setting('archive_done_year', (string) $year);

    $count = count($members);
    $body = "The end-of-year tidy-up of the " . config('site_name') . " member list has run for $year.\n\n";
    if ($count === 0) {
        $body .= "Nobody needed archiving: every household either ordered this year or joined since July.\n";
    } else {
        $body .= "$count " . ($count === 1 ? 'member was' : 'members were') . " archived, because their household had nothing on any order this year:\n\n";
        foreach ($members as $m) {
            $body .= "  {$m['name']} <{$m['email']}>, {$m['household_name']}\n";
        }
        $body .= "\nEach has been sent a note saying their account is archived and that they can come back\n"
            . "any time by emailing you.\n\n"
            . "To bring someone back: Organizer, Members, then Restore beside their name.\n"
            . config('base_url') . "/?a=organizer&view=members\n";
    }
    $body .= "\nOrganizers, and anyone added since July, are never archived by this.\n";
    foreach (q('SELECT email FROM members WHERE is_admin = 1 AND active = 1')->fetchAll(PDO::FETCH_COLUMN) as $email) {
        send_email($email, config('site_name') . " member list tidy-up for $year", $body);
    }
    return $members;
}
