<?php
// Shared page frame: header with the group name, then the page content.
declare(strict_types=1);

function page_start(string $title): void
{
    $member = current_member();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> · <?= e(config('site_name')) ?></title>
<link rel="stylesheet" href="/site.css">
</head>
<body>
<header class="top">
  <a class="brand" href="/">
    <span class="brand-name"><?= e(config('site_name')) ?></span>
    <span class="brand-tag"><?= e(config('site_tagline')) ?></span>
  </a>
  <?php if ($member): ?>
  <nav class="nav" aria-label="Main">
    <a href="/?a=order">My order</a>
    <a href="/?a=shares">Share board</a>
  </nav>
  <form method="post" action="/?a=logout" class="who">
    <span><?= e($member['name']) ?></span>
    <?= csrf_field() ?>
    <button class="link" type="submit">Sign out</button>
  </form>
  <?php endif; ?>
</header>
<main>
<?php
}

function page_end(): void
{
    ?>
</main>
</body>
</html>
<?php
}
