<?php
// Shared page frame: header with the group name, then the page content.
declare(strict_types=1);

/**
 * The group's logo, if there is one. To add or change it, put a file named logo.svg,
 * logo.webp or logo.png in the public/ folder; nothing else needs editing.
 * Returns its address and size, or null when no file is there.
 */
function site_logo(): ?array
{
    foreach (['svg', 'webp', 'png'] as $type) {
        $file = dirname(__DIR__) . '/public/logo.' . $type;
        if (is_file($file)) {
            $size = $type === 'svg' ? null : @getimagesize($file);
            // The number on the end changes when the file does, so browsers fetch the new one.
            return ['src' => '/logo.' . $type . '?v=' . filemtime($file),
                    'width' => $size[0] ?? null, 'height' => $size[1] ?? null];
        }
    }
    return null;
}

/**
 * The address of a file in public/, with a number on the end that changes whenever the
 * file does. Browsers keep stylesheets and scripts for a while; the new number makes them
 * fetch the new one straight after an update instead of going on using the old.
 */
function asset(string $file): string
{
    return '/' . $file . '?v=' . filemtime(dirname(__DIR__) . '/public/' . $file);
}

function page_start(string $title): void
{
    $member = current_member();
    $logo = site_logo();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> · <?= e(config('site_name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('site.css')) ?>">
<?php if ($logo): ?><link rel="icon" href="<?= e($logo['src']) ?>">
<?php endif; ?></head>
<body>
<header class="top">
  <a class="brand" href="/">
    <?php if ($logo): ?>
    <img class="brand-logo" src="<?= e($logo['src']) ?>" alt=""<?= $logo['width'] ? ' width="' . (int) $logo['width'] . '" height="' . (int) $logo['height'] . '"' : '' ?>>
    <?php endif; ?>
    <span class="brand-text">
      <span class="brand-name"><?= e(config('site_name')) ?></span>
      <span class="brand-tag"><?= e(config('site_tagline')) ?></span>
    </span>
  </a>
  <?php if ($member): ?>
  <nav class="nav" aria-label="Main">
    <a href="/?a=order">My order</a>
    <a href="/?a=shares">Share board</a>
    <a href="/?a=household">My household</a>
    <?php if ($member['is_admin']): ?><a href="/?a=organizer">Organizer</a><?php endif; ?>
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
