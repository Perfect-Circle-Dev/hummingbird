<?php
// Every request comes through here. The "a" value in the address picks the page.
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/mailer.php';
require dirname(__DIR__) . '/src/auth.php';
require dirname(__DIR__) . '/src/catalog.php';
require dirname(__DIR__) . '/src/orders.php';
require dirname(__DIR__) . '/src/shares.php';
require dirname(__DIR__) . '/src/layout.php';
require dirname(__DIR__) . '/src/pages/order.php';
require dirname(__DIR__) . '/src/pages/shares.php';

$action = (string) ($_GET['a'] ?? 'home');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$member = current_member();

// ---- Pages that work without being signed in ----

if ($action === 'login') {
    $sent = false;
    if ($isPost) {
        send_login_link((string) ($_POST['email'] ?? ''));
        $sent = true;
    }
    page_start('Sign in');
    ?>
    <div class="card narrow stack">
      <h1>Sign in</h1>
      <?php if ($sent): ?>
        <p class="notice">If that address is on our member list, a sign-in link is on its way. It works for <?= (int) config('login_link_minutes') ?> minutes.</p>
        <p class="note">Nothing after a few minutes? Check your spam folder, or contact Brent.</p>
      <?php else: ?>
        <p class="note">Enter your email and we'll send you a link. There is no password to remember.</p>
        <form method="post" action="/?a=login" class="stack">
          <div>
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" autocomplete="email" required>
          </div>
          <button class="btn" type="submit">Email me a sign-in link</button>
        </form>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    exit;
}

if ($action === 'verify') {
    // The emailed link lands here. Signing in takes one more tap, because some email
    // programs open links automatically to scan them, which would use the link up.
    $token = (string) ($_REQUEST['t'] ?? '');
    if ($isPost && complete_login($token)) {
        redirect('/');
    }
    $good = !$isPost && check_login_token($token) !== null;
    page_start('Sign in');
    ?>
    <div class="card narrow stack">
      <?php if ($good): ?>
        <h1>Welcome back</h1>
        <form method="post" action="/?a=verify" class="stack">
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <button class="btn" type="submit">Sign in to <?= e(config('site_name')) ?></button>
        </form>
      <?php else: ?>
        <h1>That link has expired</h1>
        <p class="note">Sign-in links work once and only for a short time.</p>
        <p><a href="/?a=login">Send me a new link</a></p>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    exit;
}

// ---- Everything below needs a signed-in member ----

if (!$member) {
    redirect('/?a=login');
}
if ($isPost) {
    require_csrf();
}

if ($action === 'logout' && $isPost) {
    logout();
    redirect('/?a=login');
}

if ($action === 'order') {
    page_order($member, $isPost);
    exit;
}
if ($action === 'shares') {
    page_shares($member, $isPost);
    exit;
}

// Home
$cycle = current_cycle();
page_start('Home');
?>
<div class="card stack">
  <h1>Hello, <?= e($member['name']) ?></h1>
  <?php if (!$cycle): ?>
    <p>No order is open right now. You'll get an email when the next one opens.</p>
  <?php elseif ($cycle['status'] === 'open'): ?>
    <p>The <strong><?= e($cycle['label']) ?></strong> order is open. Orders are due <?= e(fmt_local($cycle['due_at'])) ?>.</p>
  <?php else: ?>
    <p>The <strong><?= e($cycle['label']) ?></strong> order is locked and being placed.</p>
  <?php endif; ?>
  <p class="row">
    <a class="btn" href="/?a=order">My order</a>
    <a class="btn" href="/?a=shares">Share board</a>
  </p>
</div>
<?php
page_end();
