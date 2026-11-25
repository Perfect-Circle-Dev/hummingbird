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
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex">
<title><?= e($title) ?> · <?= e(config('site_name')) ?></title>
<link rel="preload" href="/fonts/atkinson-hyperlegible-latin-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('site.css')) ?>">
<?php if ($logo): ?><link rel="icon" href="<?= e($logo['src']) ?>">
<?php endif; ?></head>
<body>
<?php // The painted-tile strip. Its colours come from site.css, so it changes with light and dark. ?>
<svg class="tiles" aria-hidden="true" focusable="false">
  <defs>
    <pattern id="tile" width="80" height="36" patternUnits="userSpaceOnUse">
      <rect class="t-primary" x="0" y="0" width="40" height="36"/><polygon class="t-ground" points="20,4 34,18 20,32 6,18"/><circle class="t-accent" cx="20" cy="18" r="5"/>
      <rect class="t-accent" x="40" y="0" width="40" height="36"/><polygon class="t-ground" points="60,4 74,18 60,32 46,18"/><circle class="t-primary" cx="60" cy="18" r="5"/>
    </pattern>
  </defs>
  <rect width="100%" height="36" fill="url(#tile)"/>
</svg>
<?php // "has-menu" tells site.css the menu is there, which is the part that stays in view on a phone. ?>
<header class="top<?= $member ? ' has-menu' : '' ?>">
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
  <?php // An organizer's menu has a fourth pill; "four" lets site.css set them two by two on a phone. ?>
  <nav class="nav<?= $member['is_admin'] ? ' four' : '' ?>" aria-label="Main">
    <?php // The page you are on is marked, which fills its pill in (see site.css).
    $here = (string) ($_GET['a'] ?? '');
    $pages = ['order' => 'My order', 'shares' => 'Share items', 'household' => 'My household'];
    if ($member['is_admin']) {
        $pages['organizer'] = 'Organizer';
    }
    foreach ($pages as $page => $label): ?>
    <a href="/?a=<?= $page ?>"<?= $here === $page ? ' aria-current="page"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
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
