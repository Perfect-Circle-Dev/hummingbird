<?php
// "My household" page: members look after their own household. They can correct names
// and phone numbers, change their own email address (confirmed by a link sent to the new
// address), add a partner or friend to share the order, and take someone off the household
// (which archives that person's account). Restoring people, moving them between households
// and making organizers stay with the organizer.
declare(strict_types=1);

// Sentences shown after a button is pressed. The address carries only the short word.
const HOUSEHOLD_DONE = [
    'saved'     => 'Saved.',
    'added'     => 'Added. They have been emailed a sign-in link, and Brent has been told.',
    'email'     => 'Check your new address: we have sent a link there. Your email changes when you press the button it leads to.',
    'cancelled' => 'The change of email address has been cancelled.',
    'removed'   => 'Removed. They can no longer sign in, and they and Brent have been told.',
];
const HOUSEHOLD_PROBLEMS = [
    'name'      => 'A name is needed.',
    'email'     => 'That email address doesn\'t look right.',
    'same'      => 'That is already your email address.',
    'taken'     => 'That email address already belongs to a member. If that seems wrong, contact Brent.',
    'full'      => 'Your household has as many people as it can add by itself. Ask Brent to add more.',
    'not_yours' => 'That person is not in your household.',
    'not_sent'  => 'The confirmation email could not be sent, so nothing was changed. Please try again later.',
    'self'      => 'You can\'t remove yourself. To leave the group, contact Brent.',
    'organizer' => 'That person is an organizer, so only an organizer can remove them.',
    'unsure'    => 'Nobody was removed. Tick the box to confirm, then press the button again.',
];

function page_household(array $member, bool $isPost): void
{
    if ($isPost) {
        $do = (string) ($_POST['do'] ?? '');
        $problem = null;
        $done = '';
        if ($do === 'person') {
            $problem = household_person_save($member, (int) ($_POST['member_id'] ?? 0),
                (string) ($_POST['name'] ?? ''), (string) ($_POST['phone'] ?? ''));
            $done = 'saved';
        } elseif ($do === 'add') {
            $problem = household_person_add($member, (string) ($_POST['name'] ?? ''),
                (string) ($_POST['email'] ?? ''), (string) ($_POST['phone'] ?? ''));
            $done = 'added';
        } elseif ($do === 'email') {
            $problem = email_change_request($member, (string) ($_POST['email'] ?? ''));
            $done = 'email';
        } elseif ($do === 'remove') {
            // Removing needs its box ticked, like the organizer's lock and close buttons.
            $problem = empty($_POST['sure']) ? 'unsure' : household_person_remove($member, (int) ($_POST['member_id'] ?? 0));
            $done = 'removed';
        } elseif ($do === 'email_cancel') {
            email_change_cancel((int) $member['id']);
            $done = 'cancelled';
        }
        redirect('/?a=household&' . ($problem ? 'problem=' . $problem : 'done=' . $done));
    }

    $people = household_members((int) $member['household_id']);
    $done = HOUSEHOLD_DONE[(string) ($_GET['done'] ?? '')] ?? null;
    $problem = HOUSEHOLD_PROBLEMS[(string) ($_GET['problem'] ?? '')] ?? null;
    // An unconfirmed change of this member's own email, if one is still waiting.
    $pending = $member['pending_email'] && $member['pending_email_expires_at'] > now_utc() ? $member['pending_email'] : null;

    page_start('My household');
    ?>
    <h1><?= e($member['household_name']) ?></h1>
    <p class="note">Everyone here shares one order: any of you can add to it and submit it. You can each sign in with your own email address.</p>
    <?php if ($done): ?><p class="notice" role="status"><?= e($done) ?></p><?php endif; ?>
    <?php if ($problem): ?><p class="notice error" role="alert"><?= e($problem) ?></p><?php endif; ?>

    <div class="columns">
    <?php foreach ($people as $p):
        $isMe = (int) $p['id'] === (int) $member['id'];
        $id = (int) $p['id']; ?>
      <div class="card stack">
        <h2><?= e($p['name']) ?><?= $isMe ? ' <span class="flag">you</span>' : '' ?><?= $p['active'] ? '' : ' <span class="flag">archived</span>' ?></h2>
        <?php if (!$p['active']): ?>
          <p class="note"><?= e($p['email']) ?></p>
          <p class="note">This account is archived, so it can't sign in or add to your order. To switch it back on, contact Brent.</p>
        <?php else: ?>
          <form method="post" action="/?a=household" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="member_id" value="<?= $id ?>">
            <div>
              <label for="name-<?= $id ?>">Name</label>
              <input id="name-<?= $id ?>" name="name" type="text" value="<?= e($p['name']) ?>" autocomplete="off" required>
            </div>
            <div>
              <label for="phone-<?= $id ?>">Phone, for texts about the order (optional)</label>
              <input id="phone-<?= $id ?>" name="phone" type="text" inputmode="tel" value="<?= e($p['phone'] ?? '') ?>" autocomplete="off">
            </div>
            <div><button class="btn" name="do" value="person">Save</button></div>
          </form>

          <div class="stack step">
            <p><span class="note">Signs in with</span><br><?= e($p['email']) ?></p>
            <?php if (!$isMe): ?>
              <p class="note">Only <?= e($p['name']) ?> can change this address, from their own sign-in.</p>
            <?php elseif ($pending): ?>
              <p class="notice info">Waiting for you to confirm <strong><?= e($pending) ?></strong>. We sent a link there; your address changes when you press the button it leads to. Until then you sign in with the address above.</p>
              <form method="post" action="/?a=household">
                <?= csrf_field() ?>
                <button class="link" name="do" value="email_cancel">Cancel this change</button>
              </form>
            <?php else: ?>
              <details>
                <summary>Change my email address</summary>
                <form method="post" action="/?a=household" class="stack">
                  <?= csrf_field() ?>
                  <div>
                    <label for="new-email">New email address</label>
                    <input id="new-email" name="email" type="email" autocomplete="email" required>
                  </div>
                  <p class="note">We will send a link to the new address. Your sign-in changes only when you open that link and press the button, so a typing slip can't lock you out.</p>
                  <div><button class="btn" name="do" value="email">Send the link</button></div>
                </form>
              </details>
            <?php endif; ?>
          </div>
          <?php if (!$isMe && !$p['is_admin']): ?>
          <details class="step">
            <summary>Remove <?= e($p['name']) ?> from this household</summary>
            <form method="post" action="/?a=household" class="stack">
              <?= csrf_field() ?>
              <input type="hidden" name="member_id" value="<?= $id ?>">
              <p class="note"><?= e($p['name']) ?> will no longer be able to sign in or add to your order, and will stop getting the group's emails. They are told by email, and so is Brent, who can set them up again. Anything they added to your order stays on it.</p>
              <label class="check"><input type="checkbox" name="sure" value="1" required> Yes, remove <?= e($p['name']) ?></label>
              <div><button class="btn quiet" name="do" value="remove">Remove from my household</button></div>
            </form>
          </details>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>

    <div class="card stack narrow-form">
      <h2>Add someone to your household</h2>
      <p class="note">For a partner, housemate or friend who orders with you. They share this order, sign in with their own email address, and get the group's emails. They are sent a sign-in link, and Brent is told.</p>
      <form method="post" action="/?a=household" class="stack">
        <?= csrf_field() ?>
        <div>
          <label for="add-name">Their name</label>
          <input id="add-name" name="name" type="text" autocomplete="off" required>
        </div>
        <div>
          <label for="add-email">Their email address</label>
          <input id="add-email" name="email" type="email" autocomplete="off" required>
        </div>
        <div>
          <label for="add-phone">Their phone, for texts about the order (optional)</label>
          <input id="add-phone" name="phone" type="text" inputmode="tel" autocomplete="off">
        </div>
        <div><button class="btn" name="do" value="add">Add to my household</button></div>
      </form>
    </div>
    <?php
    page_end();
}

/**
 * The page behind the link sent to a new email address. It works without signing in,
 * because the link may be opened on another device. The change needs one more press of
 * a button, since some mail programs open links by themselves to scan them.
 */
function page_confirm_email(bool $isPost): void
{
    $token = (string) ($_REQUEST['t'] ?? '');
    if ($isPost) {
        $newEmail = email_change_confirm($token);
        // The address shows only whether it worked; the new address is not put in it.
        redirect('/?a=confirm-email&' . ($newEmail ? 'done=1' : 'problem=1'));
    }
    $member = email_change_find($token);

    page_start('Confirm email');
    ?>
    <div class="card narrow stack">
      <?php if (isset($_GET['done'])): ?>
        <h1>Your email address is changed</h1>
        <p>From now on, use your new address to sign in. Emails from the group will go there too.</p>
        <p><a class="btn" href="/">Go to the site</a></p>
      <?php elseif ($member): ?>
        <h1>Confirm your new address</h1>
        <p>Use <strong><?= e($member['pending_email']) ?></strong> for <?= e(config('site_name')) ?> in place of <?= e($member['email']) ?>?</p>
        <form method="post" action="/?a=confirm-email">
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <button class="btn" type="submit">Yes, use this address</button>
        </form>
      <?php else: ?>
        <h1>That link didn't work</h1>
        <p class="note">It may have expired (it works for <?= EMAIL_CHANGE_HOURS ?> hours), been used already, or been cancelled. Your email address has not changed. You can ask for a new link from the My household page.</p>
        <p><a href="/">Go to the site</a></p>
      <?php endif; ?>
    </div>
    <?php
    page_end();
}
