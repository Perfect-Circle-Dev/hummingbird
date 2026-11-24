<?php
// Organizer pages: the months (open, lock, close) and the combined order for Hummingbird.
// Only members marked as organizers get here; index.php checks that.
declare(strict_types=1);

// Sentences shown after a button is pressed. The address carries only the short
// word on the left, so nobody can make the page show text of their own.
const ORGANIZER_DONE = [
    'opened' => 'The month is open and members can order. The "time to order" email is not sent from here yet.',
    'saved'  => 'Saved.',
    'locked' => 'The month is locked. Prices are fixed and every fully claimed share is on the order.',
    'reopened' => 'The month is open again. Members can change their orders, and prices follow the catalog until you lock it.',
    'closed' => 'The month is closed.',
];
const ORGANIZER_PROBLEMS = [
    'label'      => 'Give the month a name, like "December 2026".',
    'date'       => 'That deadline could not be read. Pick a date and a time.',
    'duplicate'  => 'Another month already has that name.',
    'busy'       => 'Another month is still open or locked. Close it before opening a new one.',
    'unsure'     => 'Nothing was changed. Tick the box to confirm, then press the button again.',
    'not_open'   => 'That month is not open, so it can\'t be locked.',
    'not_locked' => 'That month is not locked, so nothing was changed.',
    'closed'     => 'A closed month can\'t be changed.',
    'price'      => 'A price could not be read and was left as it was. Type it like 25.08.',
];

function page_organizer(array $member, bool $isPost): void
{
    if ($isPost) {
        $do = (string) ($_POST['do'] ?? '');
        $cycleId = (int) ($_POST['cycle_id'] ?? 0);
        $label = (string) ($_POST['label'] ?? '');
        $due = local_to_utc((string) ($_POST['due_date'] ?? ''), (string) ($_POST['due_time'] ?? ''));
        $note = (string) ($_POST['pickup_note'] ?? '');
        $sure = !empty($_POST['sure']);
        $problem = null;
        $done = '';

        if ($do === 'stock') {
            // Out-of-stock ticks and corrected prices from the combined order page.
            $cycle = find_cycle($cycleId);
            if (!$cycle || $cycle['status'] !== 'locked') {
                redirect('/?a=organizer&problem=not_locked');
            }
            // Keep only the parts of the form this needs, each as a list.
            $form = [];
            foreach (['price', 'all', 'was_all', 'out', 'share_out'] as $group) {
                $form[$group] = is_array($_POST[$group] ?? null) ? $_POST[$group] : [];
            }
            $result = stock_and_prices_save($cycle, $form);
            $emailed = !empty($_POST['email']) ? stock_and_prices_email($cycle, $result['notes']) : 0;
            redirect('/?a=organizer&month=' . $cycleId . '&saved=' . $result['changes'] . '&emailed=' . $emailed
                . '&told=' . count($result['notes']) . ($result['bad_prices'] ? '&problem=price' : ''));
        }

        if ($do === 'open') {
            $problem = cycle_open($label, $due, $note);
            $done = 'opened';
        } elseif ($do === 'save') {
            $problem = cycle_update($cycleId, $label, $due, $note);
            $done = 'saved';
        } elseif ($do === 'lock') {
            // Locking can't be undone, so the form has a box to tick first.
            $problem = !$sure ? 'unsure' : (cycle_lock($cycleId) === null ? 'not_open' : null);
            $done = 'locked';
        } elseif ($do === 'reopen') {
            $problem = !$sure ? 'unsure' : (cycle_reopen($cycleId) ? null : 'not_locked');
            $done = 'reopened';
        } elseif ($do === 'close') {
            $problem = !$sure ? 'unsure' : (cycle_close($cycleId) ? null : 'not_locked');
            $done = 'closed';
        }
        redirect('/?a=organizer&' . ($problem ? 'problem=' . $problem : 'done=' . $done));
    }

    // The "next month" list has no combined order yet; it gets one when it is opened.
    $month = isset($_GET['month']) ? find_cycle((int) $_GET['month']) : null;
    if ($month && $month['status'] !== 'next') {
        organizer_combined_order($month);
    } else {
        organizer_months();
    }
}

/** The name, deadline and pickup note boxes, used both to open a month and to change one. */
function organizer_month_fields(?array $cycle): void
{
    ?>
    <div>
      <label for="label">Name of the month</label>
      <input id="label" name="label" type="text" value="<?= e($cycle['label'] ?? '') ?>" placeholder="December 2026" required>
    </div>
    <div class="row">
      <div class="grow">
        <label for="due_date">Order deadline</label>
        <input id="due_date" name="due_date" type="date" value="<?= $cycle ? e(fmt_local($cycle['due_at'], 'Y-m-d')) : '' ?>" required>
      </div>
      <div class="grow">
        <label for="due_time">Time</label>
        <input id="due_time" name="due_time" type="time" value="<?= $cycle ? e(fmt_local($cycle['due_at'], 'H:i')) : '19:00' ?>" required>
      </div>
    </div>
    <div>
      <label for="pickup_note">Pickup note (optional)</label>
      <input id="pickup_note" name="pickup_note" type="text" value="<?= e($cycle['pickup_note'] ?? '') ?>" placeholder="Pickup from Wednesday, Dec 16">
    </div>
    <?php
}

/** Organizer home: the month in progress (or the form to open one) and past months. */
function organizer_months(): void
{
    $cycle = current_cycle();
    $past = q("SELECT * FROM cycles WHERE status = 'closed' ORDER BY id DESC")->fetchAll();
    $done = ORGANIZER_DONE[(string) ($_GET['done'] ?? '')] ?? null;
    $problem = ORGANIZER_PROBLEMS[(string) ($_GET['problem'] ?? '')] ?? null;

    page_start('Organizer');
    echo '<h1>Organizer</h1>';
    if ($done) {
        echo '<p class="notice" role="status">' . e($done) . '</p>';
    }
    if ($problem) {
        echo '<p class="notice warn" role="alert">' . e($problem) . '</p>';
    }

    if (!$cycle):
        $saved = saved_for_next_count(); ?>
      <div class="card stack">
        <h2>Open a month</h2>
        <p class="note">No month is open right now. Members see the deadline you set here, but nothing closes until you lock the month.</p>
        <?php if ($saved): ?>
          <p><?= $saved ?> <?= $saved === 1 ? 'household has' : 'households have' ?> already saved items for this month. They will be on those orders when you open it, waiting to be submitted.</p>
        <?php endif; ?>
        <form method="post" action="/?a=organizer" class="stack">
          <?= csrf_field() ?>
          <?php organizer_month_fields(null); ?>
          <button class="btn" name="do" value="open">Open the month</button>
        </form>
      </div>
    <?php else:
        $combined = combined_order($cycle);
        $isOpen = $cycle['status'] === 'open';
        $unfinished = count($combined['unfinished']); ?>
      <div class="card stack">
        <h2><?= e($cycle['label']) ?> <span class="flag"><?= $isOpen ? 'Open for orders' : 'Locked' ?></span></h2>
        <p class="note">
          Due <?= e(fmt_local($cycle['due_at'])) ?><?= $cycle['pickup_note'] ? ' · ' . e($cycle['pickup_note']) : '' ?>
          <?= $isOpen ? '' : '<br>Locked ' . e(fmt_local($cycle['locked_at'])) ?>
        </p>
        <p>
          <?= (int) $combined['household_count'] ?> <?= $combined['household_count'] === 1 ? 'household is' : 'households are' ?> on the order<?= $isOpen ? ' so far' : '' ?>,
          with <?= (int) $combined['unit_count'] ?> <?= $combined['unit_count'] === 1 ? 'item' : 'items' ?> to order from Hummingbird.
          <?php if ($unfinished): ?>
            <strong><?= $unfinished ?> <?= $unfinished === 1 ? 'order is' : 'orders are' ?> unfinished.</strong>
          <?php endif; ?>
        </p>
        <p><a class="btn" href="/?a=organizer&amp;month=<?= (int) $cycle['id'] ?>">See the combined order</a></p>

        <?php if ($isOpen): ?>
        <form method="post" action="/?a=organizer" class="stack step">
          <?= csrf_field() ?>
          <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
          <h3>Lock the month</h3>
          <p class="note">Do this when you are ready to place the order. Prices are fixed, every fully claimed share goes onto this order, and members can no longer change their orders. Anything they add after that is saved for next month. You can reopen the month if you lock it too early.</p>
          <label class="check"><input type="checkbox" name="sure" value="1" required> I'm ready to lock <?= e($cycle['label']) ?></label>
          <button class="btn" name="do" value="lock">Lock the month</button>
        </form>
        <?php else: ?>
        <form method="post" action="/?a=organizer" class="stack step">
          <?= csrf_field() ?>
          <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
          <h3>Close the month</h3>
          <p class="note">Do this when pickup is over and everyone has paid. Members stop seeing this order, and you can open the next month. Anything marked out of stock is carried over: items go to each household's "Saved for next month" list and shares go back on the share board. It can't be undone.</p>
          <label class="check"><input type="checkbox" name="sure" value="1" required> <?= e($cycle['label']) ?> is finished</label>
          <button class="btn" name="do" value="close">Close the month</button>
        </form>
        <details class="step">
          <summary>Locked too early? Reopen the month</summary>
          <form method="post" action="/?a=organizer" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
            <p class="note">Members can change their orders again and prices go back to following the catalog. Shares on this order return to the share board, still fully claimed, and come back when you lock again. Any out-of-stock ticks and corrected prices are cleared. Best not done once the order has been placed with Hummingbird.</p>
            <label class="check"><input type="checkbox" name="sure" value="1" required> Reopen <?= e($cycle['label']) ?> for changes</label>
            <button class="btn" name="do" value="reopen">Reopen the month</button>
          </form>
        </details>
        <?php endif; ?>

        <details class="step">
          <summary>Change the name, deadline or pickup note</summary>
          <form method="post" action="/?a=organizer" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
            <?php organizer_month_fields($cycle); ?>
            <button class="btn" name="do" value="save">Save changes</button>
          </form>
        </details>
      </div>
    <?php endif; ?>

    <div class="card stack">
      <h2>Past months</h2>
      <?php if (!$past): ?>
        <p class="note">Closed months will be listed here.</p>
      <?php else: ?>
        <ul class="plain">
          <?php foreach ($past as $p): ?>
            <li><a href="/?a=organizer&amp;month=<?= (int) $p['id'] ?>"><?= e($p['label']) ?></a>
              <span class="note">closed <?= e(fmt_local($p['closed_at'], 'M j, Y')) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php
    page_end();
}

/** The combined order for one month: what to order from Hummingbird, and who it is for. */
function organizer_combined_order(array $cycle): void
{
    $combined = combined_order($cycle);
    $status = [
        'open'   => 'Still open, so this list can change. Fully claimed shares are included; they go onto the order when you lock the month.',
        'locked' => 'Locked ' . fmt_local((string) $cycle['locked_at']) . '. Prices are fixed unless you correct them below.',
        'closed' => 'Closed ' . fmt_local((string) $cycle['closed_at'], 'F j, Y') . '.',
    ][$cycle['status']];
    // Out of stock and prices can be changed only between locking and closing.
    $canEdit = $cycle['status'] === 'locked';

    // What the last save did. The address carries only numbers, never text.
    $saved = null;
    if (isset($_GET['saved'])) {
        $changes = (int) $_GET['saved'];
        $told = (int) ($_GET['told'] ?? 0);
        $emailed = (int) ($_GET['emailed'] ?? 0);
        if ($changes === 0) {
            $saved = 'Nothing was changed.';
        } else {
            $saved = 'Saved. ' . $told . ' ' . ($told === 1 ? 'household is' : 'households are') . ' affected'
                . ($emailed ? ' and ' . ($told === 1 ? 'has' : 'have') . ' been emailed.' : '; no email was sent.');
        }
    }
    $problem = ORGANIZER_PROBLEMS[(string) ($_GET['problem'] ?? '')] ?? null;

    page_start('Combined order');
    ?>
    <p><a href="/?a=organizer">Back to Organizer</a></p>
    <h1>Combined order for <?= e($cycle['label']) ?></h1>
    <p class="note"><?= e($status) ?></p>
    <?php if ($saved): ?><p class="notice" role="status"><?= e($saved) ?></p><?php endif; ?>
    <?php if ($problem): ?><p class="notice warn" role="alert"><?= e($problem) ?></p><?php endif; ?>

    <?php if ($combined['unfinished']): ?>
      <div class="card stack">
        <h2>Unfinished orders</h2>
        <p class="note">These households have items on their order but have not submitted it. Their items are in the list below, marked unfinished.</p>
        <ul class="plain">
          <?php foreach ($combined['unfinished'] as $o): ?>
            <li>
              <strong><?= e($o['household_name']) ?></strong>
              <span class="note"><?= $o['submitted_at'] ? 'changed after submitting' : 'never submitted' ?></span>
              <?php foreach ($o['people'] as $person): ?>
                <div>
                  <?= e($person['name']) ?> ·
                  <?php if ($person['phone']): ?>
                    <a href="sms:<?= e(preg_replace('/[^0-9+]/', '', $person['phone'])) ?>"><?= e($person['phone']) ?></a> ·
                  <?php endif; ?>
                  <a href="mailto:<?= e($person['email']) ?>"><?= e($person['email']) ?></a>
                </div>
              <?php endforeach; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="card stack">
      <h2>What to order</h2>
      <?php if (!$combined['items']): ?>
        <p>Nothing has been ordered yet.</p>
      <?php else: ?>
      <?php if ($canEdit): ?>
        <p class="note">When Hummingbird confirms the order, tick anything that was out of stock and correct any price that changed, then save. Each household affected gets one email saying what changed, with their new total.</p>
        <form method="post" action="/?a=organizer" class="stack">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="stock">
          <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
      <?php endif; ?>
      <div class="scroll">
        <table class="wide">
          <thead><tr><th>Item no.</th><th>Item</th><th>Qty</th><th>Price each</th><th>Total</th><th>Who it is for</th></tr></thead>
          <tbody>
          <?php foreach ($combined['items'] as $item):
              $id = (int) $item['variant_id'];
              $entries = count($item['buyers']) + count($item['shares']);
              $allOut = $item['qty'] === 0;           // nothing of this item is left in stock
              $ownBoxes = $canEdit && $entries > 1;   // each household gets its own tick box
              $itemName = $item['product_title'] . ', ' . $item['size']; ?>
            <tr>
              <td><strong><?= e($item['sku']) ?></strong></td>
              <td>
                <?= e($item['product_title']) ?>
                <div class="note"><?= e($item['size']) ?></div>
                <?php if (!$item['active']): ?>
                  <div><span class="flag">No longer in Hummingbird's catalog</span></div>
                <?php elseif (!$item['available']): ?>
                  <div><span class="flag">Hummingbird shows this out of stock</span></div>
                <?php endif; ?>
              </td>
              <td>
                <strong><?= (int) $item['qty'] ?></strong>
                <?php if ($item['out_qty']): ?><div class="note"><?= (int) $item['out_qty'] ?> out of stock</div><?php endif; ?>
              </td>
              <td>
                <?php if ($canEdit): ?>
                  <input class="price" type="text" inputmode="decimal" name="price[<?= $id ?>]"
                         value="<?= e(number_format($item['price_cents'] / 100, 2, '.', '')) ?>"
                         aria-label="Price each for <?= e($itemName) ?>">
                <?php else: ?>
                  <?= money($item['price_cents']) ?>
                <?php endif; ?>
              </td>
              <td><?= money($item['total_cents']) ?></td>
              <td>
                <?php if ($canEdit): ?>
                  <label class="check">
                    <input type="checkbox" name="all[<?= $id ?>]" value="1" data-all-for="<?= $id ?>" <?= $allOut ? 'checked' : '' ?>>
                    Out of stock<?= $entries > 1 ? ' for everyone' : '' ?>
                  </label>
                  <?php if ($allOut): ?><input type="hidden" name="was_all[<?= $id ?>]" value="1"><?php endif; ?>
                <?php endif; ?>
                <ul class="plain">
                  <?php foreach ($item['buyers'] as $b):
                      $who = $b['household'] . ' × ' . $b['qty']; ?>
                    <li>
                      <?php if ($ownBoxes): ?>
                        <label class="check">
                          <input type="checkbox" name="out[<?= (int) $b['line_id'] ?>]" value="1" data-item="<?= $id ?>" <?= $b['out'] ? 'checked' : '' ?>>
                          <span><?= e($who) ?> <span class="note">didn't get it</span></span>
                        </label>
                      <?php else: ?>
                        <?= e($who) ?>
                        <?= $b['out'] && !$canEdit ? ' <span class="flag">out of stock</span>' : '' ?>
                      <?php endif; ?>
                      <?= $b['unfinished'] ? ' <span class="flag">unfinished</span>' : '' ?>
                    </li>
                  <?php endforeach; ?>
                  <?php foreach ($item['shares'] as $s):
                      $parts = array_map(fn(array $c): string => $c['name'] . ' ' . fmt_amount($c['amount']) . ' ' . $s['size_unit'], $s['claims']);
                      $who = 'Share × 1: ' . implode(', ', $parts); ?>
                    <li>
                      <?php if ($ownBoxes): ?>
                        <label class="check">
                          <input type="checkbox" name="share_out[<?= (int) $s['id'] ?>]" value="1" data-item="<?= $id ?>" <?= $s['out_of_stock'] ? 'checked' : '' ?>>
                          <span><?= e($who) ?> <span class="note">didn't arrive</span></span>
                        </label>
                      <?php else: ?>
                        <?= e($who) ?>
                        <?= $s['out_of_stock'] && !$canEdit ? ' <span class="flag">out of stock</span>' : '' ?>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($canEdit): ?>
          <div class="row end">
            <label class="check grow"><input type="checkbox" name="email" value="1" checked> Email the households affected</label>
            <button class="btn" type="submit">Save changes</button>
          </div>
        </form>
      <?php endif; ?>
      <dl class="totals">
        <div><dt>Households ordering</dt><dd><?= (int) $combined['household_count'] ?></dd></div>
        <div><dt>Items to order</dt><dd><?= (int) $combined['unit_count'] ?></dd></div>
        <?php if ($combined['out_count']): ?>
          <div><dt>Marked out of stock</dt><dd><?= (int) $combined['out_count'] ?></dd></div>
        <?php endif; ?>
        <div><dt>Container deposits charged to members</dt><dd><?= money($combined['deposits_cents']) ?></dd></div>
        <div class="due"><dt>Order total at member prices</dt><dd><?= money($combined['total_cents']) ?></dd></div>
      </dl>
      <p class="note">The total is before deposits and leaves out anything out of stock. Deposits are not charged on shares.</p>
      <?php endif; ?>
    </div>
    <?php
    // Lets the "for everyone" box tick each household's box as you click it.
    // Saving works the same without it.
    echo '<script src="/site.js" defer></script>';
    page_end();
}
