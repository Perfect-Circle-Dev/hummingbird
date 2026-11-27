<?php
// Organizer pages for people and settings: members, households, and the group's settings.
// Only members marked as organizers get here; index.php checks that.
declare(strict_types=1);

/** The row of links at the top of the main organizer pages. */
function organizer_nav(string $current): void
{
    $pages = ['' => 'Months', 'members' => 'Members', 'email' => 'Email everyone', 'settings' => 'Settings'];
    echo '<nav class="subnav" aria-label="Organizer">';
    foreach ($pages as $view => $label) {
        echo $view === $current
            ? '<strong aria-current="page">' . $label . '</strong>'
            : '<a href="/?a=organizer' . ($view ? '&amp;view=' . $view : '') . '">' . $label . '</a>';
    }
    echo '</nav>';
}

/**
 * Handles the forms on the Members and Settings pages, then redirects.
 * Returns without doing anything when $do belongs to another page.
 */
function organizer_people_post(string $do, array $member): void
{
    $actingId = (int) $member['id'];
    $memberId = (int) ($_POST['member_id'] ?? 0);
    $members = '/?a=organizer&view=members';

    if ($do === 'member_add') {
        // A new member starts out active; the form has no box for it.
        $problem = member_save(null, ['active' => 1] + $_POST, $actingId);
        redirect($members . ($problem ? '&problem=' . $problem : '&done=member_added'));
    }
    if ($do === 'member_save') {
        $problem = find_member($memberId) ? member_save($memberId, $_POST, $actingId) : 'member_gone';
        redirect($members . ($problem ? '&edit=' . $memberId . '&problem=' . $problem : '&done=member_saved'));
    }
    if ($do === 'member_archive' || $do === 'member_restore') {
        $restore = $do === 'member_restore';
        $problem = member_set_active($memberId, $restore, $actingId);
        redirect($members . ($problem ? '&problem=' . $problem : '&done=' . ($restore ? 'member_restored' : 'member_archived')));
    }
    if ($do === 'member_send_login') {
        // A sign-in link sent by the organizer. It lasts a week, not half an hour, because
        // the member isn't waiting for it.
        $to = find_member($memberId);
        $sent = $to && send_login_link($to['email'], true);
        redirect($members . ($sent ? '&done=login_sent' : '&problem=login_not_sent'));
    }
    if ($do === 'member_delete') {
        $problem = member_delete($memberId, $actingId);
        redirect($members . ($problem ? '&edit=' . $memberId . '&problem=' . $problem : '&done=member_deleted'));
    }
    if ($do === 'members_import') {
        $result = members_import((string) ($_POST['list'] ?? ''));
        // The address carries only numbers: how many were added, how many were already
        // members, and which line numbers could not be read.
        redirect($members . '&added=' . $result['added'] . '&already=' . $result['already']
            . '&bad=' . implode(',', $result['bad']));
    }
    if ($do === 'household_rename') {
        $problem = household_rename((int) ($_POST['household_id'] ?? 0), (string) ($_POST['name'] ?? ''));
        redirect($members . ($problem ? '&problem=' . $problem : '&done=household_saved'));
    }
    if ($do === 'notice_test' || $do === 'notice_send') {
        // The "Email everyone" page. What was typed is kept first, so nothing is lost if
        // there is a problem, and so it is still there after a test.
        $subject = (string) ($_POST['subject'] ?? '');
        $body = (string) ($_POST['body'] ?? '');
        $email = '/?a=organizer&view=email';
        $greet = !empty($_POST['greet']);
        $to = ($_POST['to'] ?? '') === 'selected' ? 'selected' : 'everyone';
        $picked = is_array($_POST['pick'] ?? null) ? array_map('intval', $_POST['pick']) : [];
        notice_draft_save($subject, $body, $greet, $to, $picked);
        if (trim($subject) === '' || trim($body) === '') {
            redirect($email . '&problem=notice_empty');
        }
        // With the box ticked, "Hi {first_name}," goes above the message.
        $body = notice_body($body, $greet);
        if ($do === 'notice_test') {
            redirect($email . (notice_test($subject, $body, $member) ? '&done=notice_tested' : '&problem=notice_not_sent'));
        }
        if ($to === 'selected' && !$picked) {
            redirect($email . '&problem=notice_nobody');
        }
        // Sending needs the box ticked, so a stray tap can't do it.
        if (empty($_POST['sure'])) {
            redirect($email . '&problem=unsure');
        }
        $sent = notice_queue($subject, $body, $to === 'selected' ? $picked : null, $actingId);
        redirect($email . ($sent ? '&sent=' . $sent : '&problem=notice_nobody'));
    }
    if ($do === 'settings_save') {
        $problem = settings_save($_POST);
        redirect('/?a=organizer&view=settings' . ($problem ? '&problem=' . $problem : '&done=settings_saved'));
    }
}

/** Shows the "done" or "problem" message for the last button pressed, if any. */
function organizer_messages(): void
{
    $done = ORGANIZER_DONE[(string) ($_GET['done'] ?? '')] ?? null;
    $problem = ORGANIZER_PROBLEMS[(string) ($_GET['problem'] ?? '')] ?? null;
    if ($done) {
        echo '<p class="notice" role="status">' . e($done) . '</p>';
    }
    if ($problem) {
        echo '<p class="notice error" role="alert">' . e($problem) . '</p>';
    }
}

/** The boxes for one member, used to add someone and to edit someone. */
function organizer_member_fields(?array $m, array $households): void
{
    ?>
    <div class="row">
      <div class="grow">
        <label for="m-first">First name</label>
        <input id="m-first" name="first_name" type="text" value="<?= e($m ? first_name($m) : '') ?>" autocomplete="off" required>
      </div>
      <div class="grow">
        <label for="m-last">Last name</label>
        <input id="m-last" name="last_name" type="text" value="<?= e($m['last_name'] ?? '') ?>" autocomplete="off">
      </div>
      <div class="grow">
        <label for="m-email">Email address (this is how they sign in)</label>
        <input id="m-email" name="email" type="email" value="<?= e($m['email'] ?? '') ?>" autocomplete="off" required>
      </div>
    </div>
    <div class="row">
      <div class="grow">
        <label for="m-phone">Phone, for texts (optional)</label>
        <input id="m-phone" name="phone" type="text" inputmode="tel" value="<?= e($m['phone'] ?? '') ?>" autocomplete="off">
      </div>
      <div class="grow">
        <label for="m-household">Household</label>
        <select id="m-household" name="household_id">
          <?php if (!$m): ?><option value="0">A new household</option><?php endif; ?>
          <?php foreach ($households as $h): ?>
            <option value="<?= (int) $h['id'] ?>" <?= $m && (int) $m['household_id'] === (int) $h['id'] ? 'selected' : '' ?>><?= e($h['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grow">
        <label for="m-new-household"><?= $m ? 'Or move to a new household named' : 'Name for the new household' ?></label>
        <input id="m-new-household" name="new_household" type="text" autocomplete="off">
      </div>
    </div>
    <label class="check"><input type="checkbox" name="is_admin" value="1" <?= !empty($m['is_admin']) ? 'checked' : '' ?>> Organizer (can see these pages and gets the reports)</label>
    <?php if ($m): ?>
      <label class="check"><input type="checkbox" name="active" value="1" <?= $m['active'] ? 'checked' : '' ?>> Active (untick to archive: they can't sign in and get no emails)</label>
      <input type="hidden" name="has_group_box" value="1">
      <label class="check"><input type="checkbox" name="group_emails" value="1" <?= $m['email_opt_out'] ? '' : 'checked' ?>> Receives notification emails (time to order, last call, share reminders, and emails about their household's order)</label>
    <?php endif; ?>
    <?php
}

/** Members page: add, edit and switch off members, rename households. */
function organizer_members(array $member): void
{
    $households = households_with_members();
    $editing = isset($_GET['edit']) ? find_member((int) $_GET['edit']) : null;
    $activeMembers = 0;
    $activeHouseholds = 0;
    foreach ($households as $h) {
        $activeMembers += $h['active_count'];
        $activeHouseholds += $h['active_count'] > 0 ? 1 : 0;
    }

    page_start('Members');
    organizer_nav('members');
    echo '<h1>Members</h1>';
    organizer_messages();

    // What the last pasted list did.
    if (isset($_GET['added'])) {
        $added = (int) $_GET['added'];
        $already = (int) ($_GET['already'] ?? 0);
        $bad = array_filter(array_map('intval', explode(',', (string) ($_GET['bad'] ?? ''))));
        echo '<p class="notice" role="status">' . $added . ' ' . ($added === 1 ? 'member' : 'members') . ' added.'
            . ($already ? ' ' . $already . ' ' . ($already === 1 ? 'was' : 'were') . ' already on the list and left as they are.' : '') . '</p>';
        if ($bad) {
            echo '<p class="notice warn" role="alert">' . (count($bad) === 1 ? 'Line ' : 'Lines ') . e(implode(', ', $bad))
                . ' could not be read (no email address, or no name) and ' . (count($bad) === 1 ? 'was' : 'were') . ' skipped.</p>';
        }
    }
    ?>
    <p class="note"><?= $activeMembers ?> active <?= $activeMembers === 1 ? 'member' : 'members' ?> in <?= $activeHouseholds ?> <?= $activeHouseholds === 1 ? 'household' : 'households' ?>. People in the same household share one order.</p>
    <p class="note">On December 31 each year, members whose household had nothing on any order that year are archived by the site, and each is sent a note saying they can come back any time by emailing you. Organizers and anyone added since July are left alone. You are emailed the list.<?= setting('archive_done_year') ? ' Last done for ' . e((string) setting('archive_done_year')) . '.' : '' ?></p>

    <?php if ($editing): ?>
      <div class="card stack">
        <h2>Edit <?= e($editing['name']) ?></h2>
        <form method="post" action="/?a=organizer" class="stack">
          <?= csrf_field() ?>
          <input type="hidden" name="member_id" value="<?= (int) $editing['id'] ?>">
          <?php organizer_member_fields($editing, $households); ?>
          <p class="note">An archived member can't sign in and gets no emails. Their past orders stay on record, and Restore brings them back.</p>
          <div class="row">
            <button class="btn" name="do" value="member_save">Save</button>
            <a class="btn quiet" href="/?a=organizer&amp;view=members">Cancel</a>
          </div>
        </form>
        <?php if (member_is_unused((int) $editing['id']) && (int) $editing['id'] !== (int) $member['id']): ?>
          <form method="post" action="/?a=organizer" class="step">
            <?= csrf_field() ?>
            <input type="hidden" name="member_id" value="<?= (int) $editing['id'] ?>">
            <p class="note"><?= e($editing['name']) ?> has never signed in or ordered, so they can be deleted outright if they were added by mistake.</p>
            <button class="link danger" name="do" value="member_delete">Delete <?= e($editing['name']) ?></button>
          </form>
        <?php endif; ?>
        <?php $editingHousehold = (string) q('SELECT name FROM households WHERE id = ?', [$editing['household_id']])->fetchColumn(); ?>
        <form method="post" action="/?a=organizer" class="row step">
          <?= csrf_field() ?>
          <input type="hidden" name="household_id" value="<?= (int) $editing['household_id'] ?>">
          <div class="grow">
            <label for="h-name">Rename the household "<?= e($editingHousehold) ?>" (for everyone in it)</label>
            <input id="h-name" name="name" type="text" value="<?= e($editingHousehold) ?>" required>
          </div>
          <button class="btn quiet" name="do" value="household_rename">Rename</button>
        </form>
      </div>
    <?php else: ?>
      <div class="card stack">
        <h2>Add a member</h2>
        <form method="post" action="/?a=organizer" class="stack">
          <?= csrf_field() ?>
          <?php organizer_member_fields(null, $households); ?>
          <p class="note">Adding someone doesn't email them. They sign in by asking for a link with this email address.</p>
          <div><button class="btn" name="do" value="member_add">Add member</button></div>
        </form>
        <details class="step">
          <summary>Add several at once</summary>
          <form method="post" action="/?a=organizer" class="stack">
            <?= csrf_field() ?>
            <label for="list">One person per line: first name, last name, email, household, phone</label>
            <textarea id="list" name="list" rows="8" placeholder="Ana, Alder, ana@example.com, Alder House, 541-555-0100&#10;Ben, Alder, ben@example.com, Alder House&#10;Cora, Cedar, cora@example.com"></textarea>
            <p class="note">Put commas between the parts, or paste straight from a spreadsheet. Household and phone can be left off; someone with no household gets one named after their last name. People in the same household need the same household name. Anyone whose email is already on the list is skipped, so pasting the same list twice does no harm. A heading row is ignored.</p>
            <div><button class="btn" name="do" value="members_import">Add everyone in the list</button></div>
          </form>
        </details>
      </div>
    <?php endif; ?>

    <div class="cards">
    <?php foreach ($households as $h): ?>
      <div class="card stack">
        <h2><?= e($h['name']) ?><?= $h['active_count'] === 0 ? ' <span class="flag">archived</span>' : '' ?></h2>
        <ul class="plain people">
          <?php foreach ($h['members'] as $m):
              $isMe = (int) $m['id'] === (int) $member['id']; ?>
            <li>
              <div class="grow">
                <strong><?= e($m['name']) ?></strong>
                <?= $m['is_admin'] ? ' <span class="flag">organizer</span>' : '' ?>
                <?= $m['active'] ? '' : ' <span class="flag">archived</span>' ?>
                <?= $m['active'] && $m['email_opt_out'] ? ' <span class="flag">no group emails</span>' : '' ?>
                <div class="note"><?= e($m['email']) ?><?= $m['phone'] ? ' · ' . e($m['phone']) : '' ?></div>
              </div>
              <form method="post" action="/?a=organizer" class="row">
                <?= csrf_field() ?>
                <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                <a class="btn quiet small" href="/?a=organizer&amp;view=members&amp;edit=<?= (int) $m['id'] ?>" aria-label="Edit <?= e($m['name']) ?>">Edit</a>
                <?php if ($m['active']): ?>
                  <button class="btn quiet small" name="do" value="member_send_login" aria-label="Send a sign-in link to <?= e($m['name']) ?>">Send login</button>
                  <?php if (!$isMe): ?>
                    <button class="btn quiet small" name="do" value="member_archive" aria-label="Archive <?= e($m['name']) ?>">Archive</button>
                  <?php endif; ?>
                <?php else: ?>
                  <button class="btn quiet small" name="do" value="member_restore" aria-label="Restore <?= e($m['name']) ?>">Restore</button>
                <?php endif; ?>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
    </div>
    <?php
    page_end();
}

/** Email everyone page: the organizer writes a message and it goes to every member. */
function organizer_email(array $member): void
{
    // The members who can be sent it, listed by name for the tick boxes.
    $recipients = group_recipients();
    usort($recipients, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    $count = count($recipients);
    // Active members who have switched notification emails off: they are left out.
    $off = q('SELECT name FROM members WHERE active = 1 AND email_opt_out = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    // What was typed and ticked last time, if it has not been sent yet.
    $subject = (string) setting('notice_draft_subject', '');
    $body = (string) setting('notice_draft_body', '');
    $greet = (string) setting('notice_draft_greet', '') === '1';
    $toSelected = setting('notice_draft_to') === 'selected';
    $picked = array_map('intval', array_filter(explode(',', (string) setting('notice_draft_picked', ''))));
    $sentBefore = notices_sent();

    page_start('Email everyone');
    organizer_nav('email');
    echo '<h1>Email everyone</h1>';
    organizer_messages();
    if (isset($_GET['sent'])) {
        $sent = (int) $_GET['sent'];
        echo '<p class="notice" role="status">Your email is on its way to ' . $sent . ' ' . ($sent === 1 ? 'member' : 'members')
            . '. Emails go out a few at a time over the next few minutes.</p>';
    }
    organizer_queue_notice();
    ?>
    <div class="card stack narrow-form">
      <form method="post" action="/?a=organizer" class="stack">
        <?= csrf_field() ?>
        <div>
          <label for="notice-subject">Subject</label>
          <input id="notice-subject" name="subject" type="text" value="<?= e($subject) ?>" maxlength="150" autocomplete="off" required>
        </div>
        <label class="check"><input type="checkbox" name="greet" value="1" <?= $greet ? 'checked' : '' ?>> Start the email with "Hi &lt;first name&gt;,"</label>
        <div>
          <label for="notice-body">Message</label>
          <textarea id="notice-body" name="body" rows="14" required><?= e($body) ?></textarea>
        </div>
        <p class="note">Apart from that greeting, if you tick its box, the email is sent exactly as you type it, so sign your name. Type {first_name} wherever else you want each person's first name. Leave an empty line between paragraphs. Each copy ends with the group's usual footer and that person's own unsubscribe link.</p>
        <div><button class="btn quiet" name="do" value="notice_test">Send a test to me first</button></div>
        <p class="note">The test goes only to <?= e($member['email']) ?>, and your message stays here afterwards.</p>

        <?php // The list of names shows only while "Send to selected members" is chosen
              // (see .send-to in site.css); in a browser too old for that it always shows. ?>
        <fieldset class="send-to stack step">
          <legend>Who gets it</legend>
          <label class="check"><input type="radio" name="to" value="everyone" <?= $toSelected ? '' : 'checked' ?>> Send to everyone (<?= $count ?> <?= $count === 1 ? 'member' : 'members' ?>)</label>
          <label class="check"><input type="radio" name="to" value="selected" <?= $toSelected ? 'checked' : '' ?>> Send to selected members</label>
          <div class="pick-list">
            <?php foreach ($recipients as $r): ?>
              <label class="check"><input type="checkbox" name="pick[]" value="<?= (int) $r['id'] ?>" <?= in_array((int) $r['id'], $picked, true) ? 'checked' : '' ?>> <?= e($r['name']) ?> <span class="note">(<?= e($r['household_name']) ?>)</span></label>
            <?php endforeach; ?>
          </div>
          <p class="note">Only members who receive notification emails are listed.
            <?php if ($off): ?>Left out, because they have switched those off: <?= e(implode(', ', $off)) ?>.<?php endif; ?></p>
        </fieldset>
        <div class="stack step">
          <label class="check"><input type="checkbox" name="sure" value="1"> Yes, send this email</label>
          <div><button class="btn" name="do" value="notice_send">Send</button></div>
        </div>
      </form>
    </div>

    <div class="card stack narrow-form">
      <h2>Previously sent</h2>
      <?php if (!$sentBefore): ?>
        <p class="note">Nothing yet. Each email you send from this page is kept here.</p>
      <?php endif; ?>
      <?php foreach ($sentBefore as $n): ?>
        <details class="step">
          <summary><?= e($n['subject']) ?> <span class="note">· <?= e(fmt_local($n['sent_at'], 'M j, Y')) ?></span></summary>
          <p class="note">Sent <?= e(fmt_local($n['sent_at'])) ?><?= $n['sender'] ? ' by ' . e($n['sender']) : '' ?>, to
            <?= $n['sent_to'] === 'everyone'
                ? 'everyone (' . (int) $n['recipient_count'] . ')'
                : (int) $n['recipient_count'] . ' selected: ' . e($n['sent_to']) ?>.</p>
          <pre class="email"><?= e($n['body']) ?></pre>
        </details>
      <?php endforeach; ?>
    </div>
    <?php
    page_end();
}

/** Settings page: discount, deposits, share expiry, Venmo name. */
function organizer_settings(): void
{
    $dollars = fn(string $key, string $default): string => number_format(((int) setting($key, $default)) / 100, 2, '.', '');
    $synced = setting('catalog_synced_at');

    page_start('Settings');
    organizer_nav('settings');
    echo '<h1>Settings</h1>';
    organizer_messages();
    ?>
    <div class="card stack narrow-form">
      <form method="post" action="/?a=organizer" class="stack">
        <?= csrf_field() ?>
        <div>
          <label for="discount_percent">Group discount off Hummingbird's list price (percent)</label>
          <input id="discount_percent" name="discount_percent" type="text" inputmode="decimal" value="<?= e((string) setting('discount_percent', '0')) ?>" required>
        </div>
        <div class="row">
          <div class="grow">
            <label for="deposit_glass">Deposit on each glass jug or jar ($)</label>
            <input id="deposit_glass" name="deposit_glass" type="text" inputmode="decimal" value="<?= e($dollars('deposit_glass_cents', '150')) ?>" required>
          </div>
          <div class="grow">
            <label for="deposit_plastic">Deposit on each plastic jug, cube, bucket or pail ($)</label>
            <input id="deposit_plastic" name="deposit_plastic" type="text" inputmode="decimal" value="<?= e($dollars('deposit_plastic_cents', '300')) ?>" required>
          </div>
        </div>
        <div>
          <label for="share_expiry_months">Months an unfilled share stays listed under Share items</label>
          <input id="share_expiry_months" name="share_expiry_months" type="number" min="1" max="24" value="<?= e((string) setting('share_expiry_months', '3')) ?>" required>
        </div>
        <div>
          <label for="venmo_handle">Your Venmo name, without the @ (for the pay link in pickup emails)</label>
          <input id="venmo_handle" name="venmo_handle" type="text" value="<?= e(venmo_handle()) ?>" autocomplete="off">
        </div>
        <div>
          <label for="email_daily_cap">Most emails the site may send in 24 hours (Resend's free plan allows 100)</label>
          <input id="email_daily_cap" name="email_daily_cap" type="number" min="1" value="<?= e((string) setting('email_daily_cap', '90')) ?>" required>
        </div>
        <p class="note">Group emails wait their turn when the limit is reached and go out the next day. Sign-in links and order confirmations are always sent at once, which is why the limit sits a little under 100.</p>
        <p class="note">Glass means half-gallon and one-gallon glass jugs, 64 oz jars and 5.5 lb honey jars. A case is charged for each container in it. Smaller jars, barrels, bag-in-a-box and empty containers carry no deposit.</p>
        <p class="note">A new discount or deposit takes effect straight away on open orders and on items saved for next month. Locked and closed months keep the prices they had. A new share expiry applies to shares started from now on.</p>
        <div><button class="btn" name="do" value="settings_save">Save settings</button></div>
      </form>
    </div>
    <div class="card stack narrow-form">
      <h2>Good to know</h2>
      <ul class="plain">
        <li>Catalog last refreshed from Hummingbird: <?= $synced ? e(fmt_local($synced)) : 'not yet' ?>.</li>
        <li>Last database backup: <?= setting('backup_last_at') ? e(fmt_local(setting('backup_last_at'))) : 'none yet' ?>.
          Last copy emailed to you: <?= setting('backup_emailed_at') ? e(fmt_local(setting('backup_emailed_at'), 'l, F j')) : 'none yet' ?>.</li>
        <li>Emails sent in the last 24 hours: <?= emails_last_24h() ?>. Waiting to go out: <?= queue_waiting()['count'] ?>.</li>
      </ul>
    </div>
    <?php
    page_end();
}
