<?php
// The page behind the "stop these group emails" link at the bottom of group emails.
// It works without signing in: the link carries a secret that belongs to one member.
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

    page_start('Group emails');
    ?>
    <div class="card narrow stack">
      <?php if (!$member): ?>
        <h1>That link didn't work</h1>
        <p class="note">It may have been cut short by your mail program. Try the link in the email again, or contact Brent.</p>
      <?php elseif ($member['email_opt_out']): ?>
        <h1>Group emails are off</h1>
        <p><?= e($member['email']) ?> will not get "time to order" emails, last calls or share reminders.</p>
        <p class="note">You will still get emails about your own orders: confirmations, changes and pickup.</p>
        <form method="post" action="/?a=unsubscribe">
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <button class="btn quiet" name="do" value="start">Turn them back on</button>
        </form>
      <?php else: ?>
        <h1><?= isset($_GET['done']) ? 'Group emails are back on' : 'Stop group emails?' ?></h1>
        <p>Group emails for <?= e($member['email']) ?> are the "time to order" email each month, the last call before the deadline, and reminders about shares you are part of.</p>
        <p class="note">If you stop them, you will still get emails about your own orders: confirmations, changes and pickup.</p>
        <form method="post" action="/?a=unsubscribe">
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <button class="btn" name="do" value="stop">Stop group emails</button>
        </form>
      <?php endif; ?>
    </div>
    <?php
    page_end();
}
