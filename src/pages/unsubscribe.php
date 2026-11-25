<?php
// The page behind the "stop emails from the site" link at the bottom of group emails.
// It works without signing in: the link carries a secret that belongs to one member.
// It flips the same switch as "Receives notification emails" on the My household page.
declare(strict_types=1);

function page_unsubscribe(bool $isPost): void
{
    $token = (string) ($_REQUEST['t'] ?? '');
    $member = $token === '' ? null
        : (q('SELECT * FROM members WHERE unsubscribe_token = ?', [$token])->fetch() ?: null);

    if ($isPost && $member) {
        // "Turn them back on" sends do=start. Anything else stops them, which includes the
        // one-click unsubscribe button some mail programs show (it posts here with no "do").
        $stop = ($_POST['do'] ?? '') !== 'start';
        q('UPDATE members SET email_opt_out = ? WHERE id = ?', [$stop ? 1 : 0, $member['id']]);
        redirect('/?a=unsubscribe&t=' . urlencode($token) . '&done=1');
    }

    // Who in the household gets the emails about its order, for the sentence that says so.
    // If nobody has them switched on, they go to everyone, so they always reach someone.
    $orderEmails = '';
    if ($member) {
        $names = array_map('first_name', array_filter(household_email_recipients((int) $member['household_id']),
            fn(array $m): bool => (int) $m['id'] !== (int) $member['id']));
        $orderEmails = $names
            ? 'Emails about your household\'s order (confirmations, changes, pickup) go to ' . implode(' and ', $names) . '.'
            : 'Nobody else in your household receives them, so emails about your order (confirmations, changes, pickup) still come to you.';
    }

    page_start('Emails');
    ?>
    <div class="card narrow stack">
      <?php if (!$member): ?>
        <h1>That link didn't work</h1>
        <p class="note">It may have been cut short by your mail program. Try the link in the email again, or contact Brent.</p>
      <?php elseif ($member['email_opt_out']): ?>
        <h1>Emails are off</h1>
        <p><?= e($member['email']) ?> will not get "time to order" emails, last calls or share reminders.</p>
        <p class="note"><?= e($orderEmails) ?></p>
        <form method="post" action="/?a=unsubscribe">
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <button class="btn quiet" name="do" value="start">Turn them back on</button>
        </form>
      <?php else: ?>
        <h1><?= isset($_GET['done']) ? 'Emails are back on' : 'Stop emails from the site?' ?></h1>
        <p>For <?= e($member['email']) ?>, that is the "time to order" email each month, the last call before the deadline, reminders about shares you are part of, and emails about your household's order.</p>
        <p class="note">If you stop them: <?= e(lcfirst($orderEmails)) ?> You can switch them back on here, or on the My household page.</p>
        <form method="post" action="/?a=unsubscribe">
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <button class="btn" name="do" value="stop">Stop the emails</button>
        </form>
      <?php endif; ?>
    </div>
    <?php
    page_end();
}
