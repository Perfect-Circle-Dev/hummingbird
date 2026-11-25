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
    // A category picked from the "browse" links (see CATEGORIES in src/orders.php). A search wins
    // over it, so the two are never shown at once.
    $cat = (string) ($_REQUEST['cat'] ?? '');
    if ($query !== '' || !isset(CATEGORIES[$cat])) {
        $cat = '';
    }
    // Where to go back to after a button is pressed: the same search or category.
    $back = '/?a=order' . ($query !== '' ? '&q=' . urlencode($query) : ($cat !== '' ? '&cat=' . $cat : ''));

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
    $results = $cat !== '' ? category_items($cat) : search_catalog($query);

    // One row of the catalog, used for both search results and favorites.
    $itemRow = function (array $v) use ($favoriteIds, $open, $query, $cat): void {
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
            <input type="hidden" name="cat" value="<?= e($cat) ?>">
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

    $showResults = function () use ($query, $cat, $results, $itemRow): void {
        if ($query === '' && $cat === '') {
            return;
        }
        $n = count($results);
        if ($cat !== '') {
            // A whole category: every size of everything in it.
            echo '<p class="note">' . e(CATEGORIES[$cat][0]) . ': ' . ($n ? $n . ' ' . ($n === 1 ? 'item' : 'items') : 'nothing listed right now') . '</p>';
        } else {
            echo '<p class="note">' . ($n ? $n . ' matching ' . ($n === 1 ? 'item' : 'items')
                . ($n === 30 ? ' (showing the first 30; add a word to narrow it)' : '') : 'No items match that search.') . '</p>';
        }
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

    // One line of the sums under an order table (regular items, deposits, the amount due).
    // It is a row of the table itself, with the amount in the Total column, so that it lines
    // up with the line totals above it. The label sits under the three price columns.
    $sumRow = function (string $label, int $cents, bool $due = false): void {
        ?>
        <tr class="sum<?= $due ? ' due' : '' ?>" role="row">
          <td class="wide-only" colspan="3" role="cell"></td>
          <td class="sum-label" colspan="3" role="cell"><?= e($label) ?></td>
          <td class="money total" role="cell"><?= money($cents) ?></td>
        </tr>
        <?php
    };

    page_start('My order');
    if (!$cycle) {
        echo '<div class="card"><h1>My order</h1><p>No order is open right now. You\'ll get an email when the next one opens. Until then, anything you add is saved for it, and you can set up your favorites.</p></div>';
    } elseif (!$open) {
        // Once the organizer has pressed "Order placed", the figures on this page are the confirmed ones.
        echo '<p class="notice warn">The ' . e($cycle['label']) . (!empty($cycle['placed_at'])
            ? ' order has been placed with Hummingbird and can no longer be changed. The amounts below are confirmed.'
            : ' order is locked and can no longer be changed.') . ' Anything you add now is saved for next month.</p>';
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
          <?php // Clear sits inside the box, at its right-hand end, and shows only when there is
                // something to clear: text in the box, or a category being browsed. It is a
                // plain link to this page with nothing showing, so it works without the script.
                // With the script it empties the box on the spot and puts the cursor in it.
                $clearable = $query !== '' || $cat !== ''; ?>
          <div class="box<?= $clearable ? ' has-clear' : '' ?>">
            <input id="q" name="q" type="text" value="<?= e($query) ?>" placeholder="Try honey, almonds or G129" autocomplete="off">
            <a class="clear" id="clear-search" href="/?a=order" aria-label="Clear the search"<?= $clearable ? '' : ' hidden' ?>><span>Clear</span></a>
          </div>
          <button class="btn" type="submit">Search</button>
        </form>
        <?php // Read out by screen readers when an item is added or saved; nothing to see. ?>
        <p class="visually-hidden" id="order-said" role="status"></p>
        <div id="results" class="stack" aria-live="polite"><?php $showResults(); ?></div>
      </div>
      <?php // The right-hand column: My favorites, and Browse by category under it. ?>
      <div class="side">
      <details class="card fold" id="favorites-box" data-remember="favorites-folded" open>
        <summary><h2>My favorites <span class="note" id="favorites-count"><?= $favorites ? '(' . count($favorites) . ')' : '' ?></span></h2></summary>
        <div class="stack" id="favorites">
          <?php if (!$favorites): ?>
            <p class="note">No favorites yet. Tap the star beside any item to keep it here.</p>
          <?php endif; ?>
          <?php foreach ($favorites as $v) { $itemRow($v); } ?>
        </div>
      </details>
      <?php // Browse by category: each chip shows everything in that category in the results under
            // the search box. The link ends in #results so that, without the script, the page
            // opens at the results. It folds away like "My favorites", and site.js remembers. ?>
      <?php $categories = categories_in_use(); ?>
      <?php if ($categories): ?>
      <details class="card fold" id="cats-box" data-remember="categories-folded" data-fold-on-phone open>
        <summary><h2>Browse by category</h2></summary>
        <nav class="cats" id="cats" aria-label="Categories">
          <?php foreach ($categories as $key => $name): ?>
            <a href="/?a=order&amp;cat=<?= e($key) ?>#results"<?= $cat === $key ? ' aria-current="true"' : '' ?>><?= e($name) ?></a>
          <?php endforeach; ?>
        </nav>
      </details>
      <?php endif; ?>
      </div>
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
              <tfoot role="rowgroup">
              <?php
              $sumRow('Regular items', $summary['items_cents']);
              $sumRow('Container deposits', $summary['deposits_cents']);
              $sumRow('Our part of filled shares', $summary['shares_cents']);
              $sumRow($open ? 'Estimated due at pickup' : 'Due at pickup', $summary['due_cents'], true);
              ?>
              </tfoot>
            </table>
          </div>
          <?php if ($order['paid_at']): ?>
            <p class="notice"><?= e(paid_text($order['paid_at'], $order['paid_method'])) ?>. Thank you!</p>
          <?php endif; ?>
          <?php endif; ?>
          <?php if ($open): ?>
          <form method="post" action="/?a=order" class="row end">
            <?= csrf_field() ?>
            <?php // Three cases: submitted; submitted and changed since; never submitted. ?>
            <p class="note grow"><?php if ($order['status'] === 'submitted'): ?>
                Submitted <?= e(fmt_local($order['submitted_at'])) ?>. If you change anything, submit again.
              <?php elseif ($order['submitted_at']): ?>
                Changed since you submitted it on <?= e(fmt_local($order['submitted_at'])) ?>. Your order will go in as it stands now. Submit again to get an updated summary by email.
              <?php else: ?>
                Not submitted yet. Submit to send your order in and get a summary by email. Anything not submitted when the order is placed stays on your list for next month.
              <?php endif; ?></p>
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
            <tfoot role="rowgroup">
            <?php $sumRow('Estimated total so far', $saved['items_cents'] + $saved['deposits_cents'], true); ?>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>
    </div>
    <?php
    page_end();
}
