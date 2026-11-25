<?php
// "My order" page: search, favorites, the household's order for this month,
// the list saved for next month, and submit.
declare(strict_types=1);

function page_order(array $member, bool $isPost): void
{
    $cycle = current_cycle();
    $open = $cycle && $cycle['status'] === 'open';
    $householdId = (int) $member['household_id'];
    $order = $cycle ? household_order((int) $cycle['id'], $householdId) : null;
    // Every household also has a "saved for next month" list, whatever state this month
    // is in. When the organizer opens the next month, that list becomes its order.
    $nextOrder = household_order((int) next_cycle()['id'], $householdId);
    $query = trim((string) ($_REQUEST['q'] ?? ''));
    $back = '/?a=order' . ($query !== '' ? '&q=' . urlencode($query) : '');

    if ($isPost) {
        $do = (string) ($_POST['do'] ?? '');
        $variantId = (int) ($_POST['variant_id'] ?? 0);
        $lineId = (int) ($_POST['line_id'] ?? 0);
        $qty = (int) ($_POST['qty'] ?? 0);
        if ($do === 'fav') {
            toggle_favorite((int) $member['id'], $variantId);
        } elseif ($do === 'later') {
            order_add($nextOrder, $variantId, (int) $member['id']);
        } elseif ($do === 'qty_later') {
            order_set_qty($nextOrder, $lineId, $qty);
        } elseif ($open && $do === 'add') {
            order_add($order, $variantId, (int) $member['id']);
        } elseif ($open && $do === 'qty') {
            order_set_qty($order, $lineId, $qty);
        } elseif ($open && $do === 'submit') {
            order_submit($order, $cycle);
            redirect('/?a=order&sent=1');
        }
        redirect($back);
    }

    $favorites = favorites_for((int) $member['id']);
    $favoriteIds = array_column($favorites, 'id');
    $results = search_catalog($query);

    // One row of the catalog, used for both search results and favorites.
    $itemRow = function (array $v) use ($favoriteIds, $open, $query): void {
        $isFav = in_array($v['id'], $favoriteIds);
        $deposit = variant_deposit_cents($v);
        ?>
        <div class="item">
          <div class="item-text">
            <div class="item-name"><?= e($v['product_title']) ?></div>
            <div class="note"><?= e($v['sku']) ?> · <?= e($v['title']) ?> · <?= money(member_price_cents((int) $v['list_price_cents'])) ?><?= $deposit ? ' + ' . money($deposit) . ' deposit' : '' ?><?= $v['available'] ? '' : ' · out of stock at Hummingbird right now' ?></div>
          </div>
          <form method="post" action="/?a=order">
            <?= csrf_field() ?>
            <input type="hidden" name="q" value="<?= e($query) ?>">
            <input type="hidden" name="variant_id" value="<?= (int) $v['id'] ?>">
            <button class="star<?= $isFav ? ' on' : '' ?>" name="do" value="fav" aria-label="<?= $isFav ? 'Remove from favorites' : 'Add to favorites' ?>: <?= e($v['product_title'] . ', ' . $v['title']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/></svg></button>
            <?php if ($open): ?><button class="btn" name="do" value="add">Add</button><?php endif; ?>
            <button class="btn<?= $open ? ' quiet' : '' ?>" name="do" value="later">Save for next month</button>
          </form>
          <?php if ($v['size_amount']): ?>
            <a class="note" href="/?a=shares&amp;start=<?= (int) $v['id'] ?>">Share it</a>
          <?php endif; ?>
        </div>
        <?php
    };

    $showResults = function () use ($query, $results, $itemRow): void {
        if ($query === '') {
            return;
        }
        $n = count($results);
        echo '<p class="note">' . ($n ? $n . ' matching ' . ($n === 1 ? 'item' : 'items')
            . ($n === 30 ? ' (showing the first 30; add a word to narrow it)' : '') : 'No items match that search.') . '</p>';
        foreach ($results as $v) {
            $itemRow($v);
        }
    };

    // Search-as-you-type asks for just the results, not the whole page.
    if (isset($_GET['partial'])) {
        $showResults();
        return;
    }

    // One row of an order table, used for this month's order and for the saved list.
    // "Retail price" is Hummingbird's list price; "Our price" is that less the group discount.
    // $qtyAction is the "do" value its plus and minus buttons send, or '' for no buttons.
    // On a phone the table folds down to item, quantity and total (see "order tables on a
    // narrow screen" in site.css): cells marked "wide-only" are left out there, and the
    // size, marked "narrow-only", is shown beside the item number in their place, with the
    // deposit when there is one. The role="..." labels repeat what the table tags already
    // say: some screen readers stop treating a table as one when its layout is changed,
    // and the labels keep them reading it row by row.
    $lineRow = function (array $l, string $qtyAction): void {
        ?>
        <tr role="row">
          <td class="what" role="cell"><div class="what-name"><?= e($l['product_title']) ?></div><div class="note"><?= e($l['sku']) ?><span class="narrow-only"> · <?= e($l['size']) ?></span><?= $l['out_of_stock'] ? ' · out of stock, not charged' : '' ?><?= $l['still_listed'] ? '' : ' · no longer in Hummingbird\'s catalog' ?></div><?= $l['line_deposit_cents'] ? '<div class="note narrow-only own-line">' . money($l['line_deposit_cents']) . ' deposit</div>' : '' ?></td>
          <td class="wide-only" role="cell"><?= e($l['size']) ?></td>
          <td class="center" role="cell">
            <?php if ($qtyAction !== ''): ?>
            <form method="post" action="/?a=order" class="qty">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="<?= e($qtyAction) ?>">
              <input type="hidden" name="line_id" value="<?= (int) $l['id'] ?>">
              <button name="qty" value="<?= $l['qty'] - 1 ?>" aria-label="One fewer">−</button>
              <span><?= (int) $l['qty'] ?></span>
              <button name="qty" value="<?= $l['qty'] + 1 ?>" aria-label="One more">+</button>
            </form>
            <?php else: ?><?= (int) $l['qty'] ?><?php endif; ?>
          </td>
          <td class="money wide-only" role="cell"><?= money((int) $l['list_price_cents']) ?></td>
          <td class="money wide-only" role="cell"><?= money((int) $l['unit_price_cents']) ?></td>
          <td class="money wide-only" role="cell"><?= $l['line_deposit_cents'] ? money($l['line_deposit_cents']) : 'None' ?></td>
          <td class="money total" role="cell"><?= money($l['line_cents'] + $l['line_deposit_cents']) ?></td>
        </tr>
        <?php
    };

    page_start('My order');
    if (!$cycle) {
        echo '<div class="card"><h1>My order</h1><p>No order is open right now. You\'ll get an email when the next one opens. Until then, anything you add is saved for it, and you can set up your favorites.</p></div>';
    } elseif (!$open) {
        echo '<p class="notice warn">The ' . e($cycle['label']) . ' order is locked and can no longer be changed. Anything you add now is saved for next month.</p>';
    }
    if (isset($_GET['sent'])) {
        echo '<p class="notice">Order submitted. Cool beans! A summary is on its way to your inbox.</p>';
    }
    ?>
    <div class="columns">
      <div class="card stack">
        <h2>Find an item</h2>
        <form method="get" action="/" class="search" id="search-form">
          <input type="hidden" name="a" value="order">
          <label for="q">Spill the beans: what are you after?</label>
          <input id="q" name="q" type="text" value="<?= e($query) ?>" placeholder="Try honey, almonds or G129" autocomplete="off">
          <button class="btn" type="submit">Search</button>
          <?php // Clear is a plain link to this page with no search, so it works without the
                // script. With the script it empties the box on the spot and puts the cursor in it. ?>
          <a class="tap clear" id="clear-search" href="/?a=order" aria-label="Clear the search">Clear</a>
        </form>
        <?php // Read out by screen readers when an item is added or saved; nothing to see. ?>
        <p class="visually-hidden" id="order-said" role="status"></p>
        <div id="results" class="stack" aria-live="polite"><?php $showResults(); ?></div>
      </div>
      <details class="card fold" id="favorites-box" open>
        <summary><h2>My favorites <span class="note" id="favorites-count"><?= $favorites ? '(' . count($favorites) . ')' : '' ?></span></h2></summary>
        <div class="stack" id="favorites">
          <?php if (!$favorites): ?>
            <p class="note">No favorites yet. Tap the star beside any item to keep it here.</p>
          <?php endif; ?>
          <?php foreach ($favorites as $v) { $itemRow($v); } ?>
        </div>
      </details>
    </div>
    <div id="order-lists">
    <?php
    if ($cycle) {
        $summary = order_summary($order);
        ?>
        <div class="card stack">
          <h2><?= e($member['household_name']) ?> order for <?= e($cycle['label']) ?></h2>
          <p class="note">Due <?= e(fmt_local($cycle['due_at'])) ?><?= pickup_text($cycle) !== '' ? ' · ' . e(pickup_text($cycle)) : '' ?></p>
          <?php if (!$summary['lines'] && !$summary['shares']): ?>
            <p>Nothing on your order yet. Search above or add from your favorites.</p>
          <?php else: ?>
          <div class="scroll">
            <table class="order" role="table">
              <thead role="rowgroup"><tr role="row"><th role="columnheader">Item</th><th class="wide-only" role="columnheader">Size</th><th class="center" role="columnheader">Qty</th><th class="money wide-only" role="columnheader">Retail price</th><th class="money wide-only" role="columnheader">Our price</th><th class="money wide-only" role="columnheader">Deposit</th><th class="money total" role="columnheader">Total</th></tr></thead>
              <tbody role="rowgroup">
              <?php foreach ($summary['lines'] as $l) { $lineRow($l, $open ? 'qty' : ''); } ?>
              <?php foreach ($summary['shares'] as $s): ?>
                <tr class="share" role="row">
                  <?php $part = fmt_amount($s['amount']) . ' ' . $s['size_unit'] . ' of ' . $s['size']; ?>
                  <td class="what" role="cell"><div class="what-name"><?= e($s['product_title']) ?> (share)</div><div class="note"><?= e($s['sku']) ?> · <?= e($s['member_name']) ?><span class="narrow-only"> · <?= e($part) ?></span><?= $s['out_of_stock'] ? ' · out of stock, not charged' : '' ?></div></td>
                  <td class="wide-only" role="cell"><?= e($part) ?></td>
                  <td class="center" role="cell"></td><td class="wide-only" role="cell"></td><td class="wide-only" role="cell"></td><td class="money wide-only" role="cell">None</td>
                  <td class="money total" role="cell"><?= money($s['cost_cents']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <dl class="totals">
            <div><dt>Regular items</dt><dd><?= money($summary['items_cents']) ?></dd></div>
            <div><dt>Container deposits</dt><dd><?= money($summary['deposits_cents']) ?></dd></div>
            <div><dt>Our part of filled shares</dt><dd><?= money($summary['shares_cents']) ?></dd></div>
            <div class="due"><dt><?= $open ? 'Estimated due at pickup' : 'Due at pickup' ?></dt><dd><?= money($summary['due_cents']) ?></dd></div>
          </dl>
          <?php if ($order['paid_at']): ?>
            <p class="notice"><?= e(paid_text($order['paid_at'], $order['paid_method'])) ?>. Thank you!</p>
          <?php endif; ?>
          <?php endif; ?>
          <?php if ($open): ?>
          <form method="post" action="/?a=order" class="row end">
            <?= csrf_field() ?>
            <p class="note grow"><?= $order['status'] === 'submitted'
                ? 'Submitted ' . e(fmt_local($order['submitted_at'])) . '. If you change anything, submit again.'
                : 'Not submitted yet. Submit to send your order in and get a summary by email.' ?></p>
            <button class="btn" name="do" value="submit">Submit my order</button>
          </form>
          <?php endif; ?>
        </div>
        <?php
    }

    $saved = order_summary($nextOrder);
    ?>
    <div class="card stack">
      <h2>Saved for next month</h2>
      <?php if (!$saved['lines']): ?>
        <p class="note">Nothing saved. If you think of something for a later order, choose "Save for next month" on the item and it will be waiting for you.</p>
      <?php else: ?>
        <p class="note"><?= $cycle ? 'These are not part of the ' . e($cycle['label']) . ' order. ' : '' ?>When the next month opens they will already be on your order. You will still need to look it over and submit it. Prices are today's and may change by then.</p>
        <div class="scroll">
          <table class="order" role="table">
            <thead role="rowgroup"><tr role="row"><th role="columnheader">Item</th><th class="wide-only" role="columnheader">Size</th><th class="center" role="columnheader">Qty</th><th class="money wide-only" role="columnheader">Retail price</th><th class="money wide-only" role="columnheader">Our price</th><th class="money wide-only" role="columnheader">Deposit</th><th class="money total" role="columnheader">Total</th></tr></thead>
            <tbody role="rowgroup">
            <?php foreach ($saved['lines'] as $l) { $lineRow($l, 'qty_later'); } ?>
            </tbody>
          </table>
        </div>
        <dl class="totals">
          <div class="due"><dt>Estimated total so far</dt><dd><?= money($saved['items_cents'] + $saved['deposits_cents']) ?></dd></div>
        </dl>
      <?php endif; ?>
    </div>
    </div>
    <?php
    page_end();
}
