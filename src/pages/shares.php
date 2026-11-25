<?php
// Share board page.
declare(strict_types=1);

function page_shares(array $member, bool $isPost): void
{
    $cycle = current_cycle();
    $memberId = (int) $member['id'];

    if ($isPost) {
        $do = (string) ($_POST['do'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        if ($do === 'start') {
            share_start((int) ($_POST['variant_id'] ?? 0), $memberId, $amount);
        } elseif ($do === 'claim') {
            share_set_claim((int) ($_POST['share_id'] ?? 0), $memberId, $amount);
        } elseif ($do === 'cancel') {
            share_set_claim((int) ($_POST['share_id'] ?? 0), $memberId, 0);
        }
        redirect('/?a=shares');
    }

    $shares = board_shares($cycle ? (int) $cycle['id'] : null);
    $startVariant = isset($_GET['start']) ? find_variant((int) $_GET['start']) : null;
    if ($startVariant && (!$startVariant['size_amount'] || !$startVariant['active'])) {
        $startVariant = null;
    }

    page_start('Share items');
    ?>
    <h1>Share items</h1>
    <p class="note">Split a bulk item with other members. When an item is fully claimed it goes on the next order, and each person pays for their part.</p>

    <?php if ($startVariant):
        $price = member_price_cents((int) $startVariant['list_price_cents']); ?>
      <div class="card stack">
        <h2>Start a share</h2>
        <p><strong><?= e($startVariant['product_title']) ?></strong><br>
          <span class="note"><?= e($startVariant['sku']) ?> · <?= e($startVariant['title']) ?> · <?= money($price) ?> (<?= money((int) round($price / $startVariant['size_amount'])) ?> per <?= e($startVariant['size_unit']) ?>)</span></p>
        <form method="post" action="/?a=shares" class="row">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="start">
          <input type="hidden" name="variant_id" value="<?= (int) $startVariant['id'] ?>">
          <div class="grow">
            <label for="start-amount">How much do you want? (<?= e($startVariant['size_unit']) ?>, out of <?= e(fmt_amount((float) $startVariant['size_amount'])) ?>)</label>
            <input id="start-amount" name="amount" type="number" inputmode="decimal" min="0.01" step="0.01" max="<?= e(fmt_amount((float) $startVariant['size_amount'])) ?>" required>
          </div>
          <button class="btn" type="submit">Post share</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if (!$shares): ?>
      <div class="card"><p>Nothing is up for sharing right now.</p></div>
    <?php endif; ?>

    <?php // "cards" lays the shares out in equal columns, each row as tall as its tallest card. ?>
    <div class="cards">
    <?php foreach ($shares as $s):
        $mine = null;
        foreach ($s['claims'] as $c) {
            if ((int) $c['member_id'] === $memberId) { $mine = $c; }
        }
        $editable = $s['status'] === 'open';
        $unit = $s['size_unit'];
        $percent = min(100, round($s['claimed'] / $s['size_amount'] * 100)); ?>
      <div class="card stack">
        <?php if ($editable && !$s['full']): ?><div class="eyebrow">Up for sharing?</div><?php endif; ?>
        <div>
          <h2><?= e($s['product_title']) ?></h2>
          <div class="note"><?= e($s['sku']) ?> · <?= e($s['size']) ?> · <?= money($s['price_cents']) ?> (<?= money($s['per_unit_cents']) ?> per <?= e($unit) ?>)</div>
        </div>
        <div class="bar" role="img" aria-label="<?= $percent ?> percent claimed"><div style="width: <?= $percent ?>%"></div></div>
        <div><?= e(fmt_amount($s['claimed'])) ?> of <?= e(fmt_amount((float) $s['size_amount'])) ?> <?= e($unit) ?> claimed · <?= e(fmt_amount($s['left'])) ?> <?= e($unit) ?> left</div>
        <ul class="claims">
          <?php foreach ($s['claims'] as $c): ?>
            <li><span><?= e($c['name']) ?> · <?= e(fmt_amount($c['amount'])) ?> <?= e($unit) ?></span><span><?= money($c['cost_cents']) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php if ($s['status'] === 'ordered'): ?>
          <?php if ($s['out_of_stock']): ?>
            <p class="notice warn">On the <?= e($cycle['label'] ?? '') ?> order, but Hummingbird was out of stock. Nobody is charged, and it comes back to Share items for next month.</p>
          <?php else: ?>
            <p class="notice">On the <?= e($cycle['label'] ?? '') ?> order.</p>
          <?php endif; ?>
        <?php elseif ($s['full']): ?>
          <p class="notice">Fully claimed. It goes on the next order.</p>
        <?php endif; ?>
        <?php // The foot of the card sits at the bottom, so the boxes and buttons of a row line up. ?>
        <?php if ($editable): ?>
        <div class="card-foot stack">
        <?php if (!$s['full'] || $mine): ?>
          <form method="post" action="/?a=shares" class="row">
            <?= csrf_field() ?>
            <input type="hidden" name="share_id" value="<?= (int) $s['id'] ?>">
            <div class="grow">
              <label for="amt-<?= (int) $s['id'] ?>"><?= $mine ? 'Change my amount' : 'Amount I want' ?> (<?= e($unit) ?>)</label>
              <input id="amt-<?= (int) $s['id'] ?>" name="amount" type="number" inputmode="decimal" min="0.01" step="0.01"
                     max="<?= e(fmt_amount($s['left'] + ($mine['amount'] ?? 0))) ?>" value="<?= $mine ? e(fmt_amount($mine['amount'])) : '' ?>" required>
            </div>
            <button class="btn accent" name="do" value="claim"><?= $mine ? 'Update' : 'I\'ll take some' ?></button>
          </form>
          <?php if ($mine): ?>
          <form method="post" action="/?a=shares">
            <?= csrf_field() ?>
            <input type="hidden" name="share_id" value="<?= (int) $s['id'] ?>">
            <button class="link" name="do" value="cancel">Cancel my claim</button>
          </form>
          <?php endif; ?>
        <?php endif; ?>
          <div class="note">Started by <?= e($s['started_by_name']) ?> on <?= e(fmt_local($s['created_at'], 'M j')) ?> · comes off the list <?= e(fmt_local($s['expires_at'], 'M j')) ?> if not filled</div>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>

    <div class="card">
      <h2>Start a new share</h2>
      <p class="note">Find the item on <a href="/?a=order">My order</a> and choose "Share it".</p>
    </div>
    <?php
    page_end();
}
