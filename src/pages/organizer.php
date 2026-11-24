<?php
// Organizer pages: the months (open, lock, close) and the combined order for Hummingbird.
// Only members marked as organizers get here; index.php checks that.
declare(strict_types=1);

// Sentences shown after a button is pressed. The address carries only the short
// word on the left, so nobody can make the page show text of their own.
const ORGANIZER_DONE = [
    'opened' => 'The month is open and members can order. The "time to order" email is not sent from here yet.',
    'saved'  => 'Saved.',
    'locked' => 'The month is locked. Prices are fixed and every fully claimed share is on the order. The report has been emailed to the organizers.',
    'reopened' => 'The month is open again. Members can change their orders, and prices follow the catalog until you lock it.',
    'closed' => 'The month is closed. The final report has been emailed to the organizers.',
    'member_added'   => 'Member added. Nothing has been emailed to them.',
    'member_saved'   => 'Member saved.',
    'member_deleted' => 'Member deleted.',
    'household_saved' => 'Household renamed.',
    'settings_saved' => 'Settings saved. Open orders and saved lists now use the new amounts.',
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
    'not_payable' => 'Payments can be recorded once the month is locked.',
    'member_name'      => 'Give the member a name.',
    'member_email'     => 'That email address doesn\'t look right.',
    'member_duplicate' => 'Someone with that email address is already a member.',
    'member_household' => 'Choose a household, or type a name for a new one.',
    'member_self'      => 'You can\'t switch off or delete your own organizer account. Another organizer can do it.',
    'member_used'      => 'That member has used the site, so they can\'t be deleted. Untick "Active" instead.',
    'member_gone'      => 'That member could not be found.',
    'household_name'      => 'Give the household a name.',
    'household_duplicate' => 'Another household already has that name.',
    'settings_discount' => 'Nothing was saved. The discount should be a number from 0 to 100.',
    'settings_deposit'  => 'Nothing was saved. Type each deposit as an amount, like 1.50.',
    'settings_months'   => 'Nothing was saved. The number of months should be from 1 to 24.',
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

        // Members and Settings have their own forms (src/pages/organizer_people.php).
        organizer_people_post($do, $member);

        if ($do === 'paid' || $do === 'unpaid') {
            // The Check / Venmo / Cash buttons (and "undo") on the report page.
            $cycle = find_cycle($cycleId);
            $householdId = (int) ($_POST['household_id'] ?? 0);
            $method = $do === 'paid' ? (string) ($_POST['method'] ?? '') : null;
            $ok = $cycle && order_set_paid($cycle, $householdId, $method);
            redirect('/?a=organizer&month=' . $cycleId . '&view=report' . ($ok ? '' : '&problem=not_payable'));
        }
        if ($do === 'pickup') {
            $cycle = find_cycle($cycleId);
            if (!$cycle || $cycle['status'] !== 'locked') {
                redirect('/?a=organizer&problem=not_locked');
            }
            // Sending a second time needs the box ticked, so a double tap can't email everyone twice.
            if ($cycle['pickup_emailed_at'] && !$sure) {
                redirect('/?a=organizer&month=' . $cycleId . '&view=report&problem=unsure');
            }
            redirect('/?a=organizer&month=' . $cycleId . '&view=report&pickup=' . pickup_emails_send($cycle));
        }

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
            // Locking needs a ticked box first, so it can't happen by a stray tap.
            $problem = !$sure ? 'unsure' : (cycle_lock($cycleId) === null ? 'not_open' : null);
            $done = 'locked';
            if (!$problem) {
                month_report_email(find_cycle($cycleId), 'Sent when the month was locked. This is what was ordered.');
            }
        } elseif ($do === 'reopen') {
            $problem = !$sure ? 'unsure' : (cycle_reopen($cycleId) ? null : 'not_locked');
            $done = 'reopened';
        } elseif ($do === 'close') {
            // The report is written out before closing, because closing moves out-of-stock
            // shares on to next month and they would no longer appear in it.
            $cycle = find_cycle($cycleId);
            $occasion = 'Sent when the month was closed. These are the final figures.';
            $report = $cycle && $cycle['status'] === 'locked' ? month_report_text($cycle, $occasion) : null;
            $problem = !$sure ? 'unsure' : (cycle_close($cycleId) ? null : 'not_locked');
            $done = 'closed';
            if (!$problem && $report !== null) {
                month_report_email($cycle, $occasion, $report);
            }
        } elseif ($do === 'report') {
            // The "Email this report" button on the report page.
            $cycle = find_cycle($cycleId);
            if (!$cycle || $cycle['status'] === 'next') {
                redirect('/?a=organizer');
            }
            $sent = month_report_email($cycle, 'Sent on request from the report page.');
            redirect('/?a=organizer&month=' . $cycleId . '&view=report&sent=' . $sent);
        }
        redirect('/?a=organizer&' . ($problem ? 'problem=' . $problem : 'done=' . $done));
    }

    // The "next month" list has no combined order yet; it gets one when it is opened.
    $month = isset($_GET['month']) ? find_cycle((int) $_GET['month']) : null;
    $view = (string) ($_GET['view'] ?? '');
    if ($month && $month['status'] !== 'next') {
        if ($view === 'report') {
            organizer_report($month);
        } elseif ($view === 'shares') {
            organizer_split_sheets($month);
        } else {
            organizer_combined_order($month);
        }
    } elseif ($view === 'members') {
        organizer_members($member);
    } elseif ($view === 'settings') {
        organizer_settings();
    } else {
        organizer_months();
    }
}

/** The links at the top of each page about one month. */
function organizer_month_links(array $cycle, string $current): void
{
    $id = (int) $cycle['id'];
    $pages = ['' => 'Combined order', 'report' => 'Report and payments', 'shares' => 'Split sheets'];
    echo '<nav class="subnav" aria-label="This month"><a href="/?a=organizer">Back to Organizer</a>';
    foreach ($pages as $view => $label) {
        echo $view === $current
            ? '<strong aria-current="page">' . $label . '</strong>'
            : '<a href="/?a=organizer&amp;month=' . $id . ($view ? '&amp;view=' . $view : '') . '">' . $label . '</a>';
    }
    echo '</nav>';
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
    organizer_nav('');
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
        <p class="row">
          <a class="btn" href="/?a=organizer&amp;month=<?= (int) $cycle['id'] ?>">See the combined order</a>
          <a class="btn quiet" href="/?a=organizer&amp;month=<?= (int) $cycle['id'] ?>&amp;view=report">Report and payments</a>
          <a class="btn quiet" href="/?a=organizer&amp;month=<?= (int) $cycle['id'] ?>&amp;view=shares">Split sheets</a>
        </p>

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
            <li><strong><?= e($p['label']) ?></strong>:
              <a href="/?a=organizer&amp;month=<?= (int) $p['id'] ?>&amp;view=report">report and payments</a> ·
              <a href="/?a=organizer&amp;month=<?= (int) $p['id'] ?>">combined order</a>
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
    <?php organizer_month_links($cycle, ''); ?>
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

/**
 * The report for one month: what each household ordered and owes. Shows the current
 * figures, so it is an estimate while the month is open and final once it is closed.
 */
function organizer_report(array $cycle): void
{
    $report = month_report($cycle);
    $id = (int) $cycle['id'];
    $status = [
        'open'   => 'Still open, so these are estimates and will change as members order.',
        'locked' => 'Locked ' . fmt_local((string) $cycle['locked_at']) . '. Out-of-stock ticks and corrected prices on the combined order show here straight away.',
        'closed' => 'Closed ' . fmt_local((string) $cycle['closed_at'], 'F j, Y') . '. These are the final figures.',
    ][$cycle['status']];
    $organizers = q('SELECT name FROM members WHERE is_admin = 1 AND active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

    // Payments can be recorded from the lock onwards, including after the month is closed.
    $canPay = in_array($cycle['status'], ['locked', 'closed'], true);
    $toEmail = pickup_email_households($cycle);
    $emailCount = household_email_count($toEmail);
    $sentLately = emails_last_24h();
    $problem = ORGANIZER_PROBLEMS[(string) ($_GET['problem'] ?? '')] ?? null;

    page_start('Report');
    organizer_month_links($cycle, 'report');
    ?>
    <h1><?= $cycle['status'] === 'open' ? 'Report so far' : 'Report' ?> for <?= e($cycle['label']) ?></h1>
    <p class="note"><?= e($status) ?></p>
    <?php if (isset($_GET['sent'])): ?>
      <p class="notice" role="status">The report has been emailed to <?= (int) $_GET['sent'] ?> <?= (int) $_GET['sent'] === 1 ? 'organizer' : 'organizers' ?>.</p>
    <?php endif; ?>
    <?php if (isset($_GET['pickup'])): ?>
      <p class="notice" role="status">Pickup emails went to <?= (int) $_GET['pickup'] ?> <?= (int) $_GET['pickup'] === 1 ? 'household' : 'households' ?>.</p>
    <?php endif; ?>
    <?php if ($problem): ?><p class="notice warn" role="alert"><?= e($problem) ?></p><?php endif; ?>

    <?php if (!$report['households']): ?>
      <div class="card"><p>Nobody has ordered anything yet.</p></div>
    <?php else: ?>
    <div class="card stack">
      <h2>What each household owes</h2>
      <div class="scroll">
        <table>
          <thead><tr><th>Household</th><th>Total due</th><?= $canPay ? '<th>Paid</th>' : '' ?></tr></thead>
          <tbody>
          <?php foreach ($report['households'] as $h): ?>
            <tr>
              <td><a href="#household-<?= (int) $h['id'] ?>"><?= e($h['name']) ?></a><?= $h['unfinished'] ? ' <span class="flag">unfinished</span>' : '' ?></td>
              <td><strong><?= money($h['summary']['due_cents']) ?></strong></td>
              <?php if ($canPay): ?>
              <td>
                <form method="post" action="/?a=organizer" class="pay">
                  <?= csrf_field() ?>
                  <input type="hidden" name="cycle_id" value="<?= $id ?>">
                  <input type="hidden" name="household_id" value="<?= (int) $h['id'] ?>">
                  <?php if ($h['paid_at']): ?>
                    <span><?= e(paid_text($h['paid_at'], $h['paid_method'])) ?></span>
                    <button class="link" name="do" value="unpaid" aria-label="Undo: mark <?= e($h['name']) ?> as not paid">Undo</button>
                  <?php elseif ($h['summary']['due_cents'] === 0): ?>
                    <span class="note">Nothing due</span>
                  <?php else: ?>
                    <input type="hidden" name="do" value="paid">
                    <?php foreach (PAID_METHODS as $method => $label): ?>
                      <button class="btn quiet" name="method" value="<?= $method ?>" aria-label="<?= e($h['name']) ?> paid by <?= strtolower($label) ?>"><?= $label ?></button>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
            <tr>
              <td><strong>Total, <?= count($report['households']) ?> <?= count($report['households']) === 1 ? 'household' : 'households' ?></strong></td>
              <td><strong><?= money($report['due_cents']) ?></strong></td>
              <?= $canPay ? '<td></td>' : '' ?>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="note">Regular items <?= money($report['items_cents']) ?>, container deposits <?= money($report['deposits_cents']) ?>, shares <?= money($report['shares_cents']) ?>.</p>
      <?php if ($canPay): ?>
        <p><strong>Collected <?= money($report['collected_cents']) ?> of <?= money($report['due_cents']) ?>.</strong>
          <?= $report['owing_count'] === 0 ? 'Everyone has paid.' : $report['owing_count'] . ' ' . ($report['owing_count'] === 1 ? 'household still has' : 'households still have') . ' to pay.' ?>
          Tap Check, Venmo or Cash when a household pays.</p>
      <?php endif; ?>

      <?php if ($cycle['status'] === 'locked'): ?>
      <form method="post" action="/?a=organizer" class="stack step">
        <?= csrf_field() ?>
        <input type="hidden" name="cycle_id" value="<?= $id ?>">
        <h3>Pickup emails</h3>
        <p class="note">Tells each household that still owes money that the order is ready: your pickup note, the amount due, how to pay, and their order in full. Send it once the order has arrived and any out-of-stock or price changes are saved.
          <?= venmo_handle() !== '' ? 'It includes a Venmo link for @' . e(venmo_handle()) . '.' : 'There is no Venmo link yet: add your Venmo name under <a href="/?a=organizer&amp;view=settings">Settings</a>.' ?></p>
        <?php if (!$toEmail): ?>
          <p class="note">Nobody owes anything, so there is nobody to email.</p>
        <?php else: ?>
          <p>This sends <?= $emailCount ?> <?= $emailCount === 1 ? 'email' : 'emails' ?> to <?= count($toEmail) ?> <?= count($toEmail) === 1 ? 'household' : 'households' ?>.
            <span class="note">The site has sent <?= $sentLately ?> in the last 24 hours, and Resend allows 100 a day.</span></p>
          <?php if ($sentLately + $emailCount > 100): ?>
            <p class="notice warn">That would go over 100 for the day, so some of these would not arrive. Better to wait until tomorrow.</p>
          <?php endif; ?>
          <?php if ($cycle['pickup_emailed_at']): ?>
            <p class="note">Last sent <?= e(fmt_local($cycle['pickup_emailed_at'])) ?>.</p>
            <label class="check"><input type="checkbox" name="sure" value="1" required> Send them again</label>
          <?php endif; ?>
          <div><button class="btn" name="do" value="pickup">Send pickup emails</button></div>
        <?php endif; ?>
      </form>
      <?php endif; ?>

      <form method="post" action="/?a=organizer" class="row end step">
        <?= csrf_field() ?>
        <input type="hidden" name="cycle_id" value="<?= $id ?>">
        <p class="note grow">Emails this report, as it stands now, to every organizer (<?= e(implode(', ', $organizers)) ?>). It is also sent by itself when the month is locked and when it is closed.</p>
        <button class="btn quiet" name="do" value="report">Email this report</button>
      </form>
    </div>

    <?php foreach ($report['households'] as $h):
        $summary = $h['summary']; ?>
      <div class="card stack" id="household-<?= (int) $h['id'] ?>">
        <h2><?= e($h['name']) ?><?= $h['unfinished'] ? ' <span class="flag">unfinished</span>' : '' ?></h2>
        <p class="note"><?= e(implode(', ', $h['people'])) ?><?= $h['unfinished'] ? ' · this order was not submitted' : '' ?></p>
        <div class="scroll">
          <table>
            <thead><tr><th>Item</th><th>Size</th><th>Qty</th><th>Price</th><th>Deposit</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($summary['lines'] as $l): ?>
              <tr>
                <td><?= e($l['product_title']) ?><div class="note"><?= e($l['sku']) ?><?= $l['out_of_stock'] ? ' · out of stock, not charged' : '' ?></div></td>
                <td><?= e($l['size']) ?></td>
                <td><?= (int) $l['qty'] ?></td>
                <td><?= money((int) $l['unit_price_cents']) ?></td>
                <td><?= $l['line_deposit_cents'] ? money($l['line_deposit_cents']) : 'None' ?></td>
                <td><?= money($l['line_cents'] + $l['line_deposit_cents']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php foreach ($summary['shares'] as $s): ?>
              <tr>
                <td><?= e($s['product_title']) ?> (share)<div class="note"><?= e($s['sku']) ?> · <?= e($s['member_name']) ?><?= $s['out_of_stock'] ? ' · out of stock, not charged' : '' ?></div></td>
                <td><?= e(fmt_amount($s['amount']) . ' ' . $s['size_unit']) ?> of <?= e($s['size']) ?></td>
                <td></td><td></td><td>None</td>
                <td><?= money($s['cost_cents']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <dl class="totals">
          <div><dt>Regular items</dt><dd><?= money($summary['items_cents']) ?></dd></div>
          <div><dt>Container deposits</dt><dd><?= money($summary['deposits_cents']) ?></dd></div>
          <div><dt>Part of filled shares</dt><dd><?= money($summary['shares_cents']) ?></dd></div>
          <div class="due"><dt><?= $cycle['status'] === 'open' ? 'Estimated due at pickup' : 'Due at pickup' ?></dt><dd><?= money($summary['due_cents']) ?></dd></div>
        </dl>
        <p class="note"><?= $h['paid_at'] ? e(paid_text($h['paid_at'], $h['paid_method'])) . ' · ' : '' ?><a href="#top">Back to top</a></p>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php
    page_end();
}

/** Split sheets: for each shared item on the month's order, who gets how much. For divide-up day. */
function organizer_split_sheets(array $cycle): void
{
    $shares = split_sheets($cycle);
    page_start('Split sheets');
    organizer_month_links($cycle, 'shares');
    ?>
    <h1>Split sheets for <?= e($cycle['label']) ?></h1>
    <p class="note"><?= $cycle['status'] === 'open'
        ? 'The month is still open. These are the shares that are fully claimed so far; they go onto the order when you lock it.'
        : 'One sheet for each shared item on this order. The last column is the same amount as you would read it on a scale or a measuring jug.' ?></p>

    <?php if (!$shares): ?>
      <div class="card"><p>There are no shared items on this order.</p></div>
    <?php endif; ?>

    <?php foreach ($shares as $s):
        $unit = (string) $s['size_unit']; ?>
      <div class="card stack">
        <h2><?= e($s['product_title']) ?><?= $s['out_of_stock'] ? ' <span class="flag">out of stock</span>' : '' ?></h2>
        <p class="note"><?= e($s['sku']) ?> · <?= e($s['size']) ?> · <?= money($s['price_cents']) ?> (<?= money($s['per_unit_cents']) ?> per <?= e($unit) ?>)<?= $s['out_of_stock'] ? ' · did not arrive, so there is nothing to divide' : '' ?></p>
        <div class="scroll">
          <table>
            <thead><tr><th>Who</th><th>Amount</th><th>On the scale</th><th>Cost</th></tr></thead>
            <tbody>
            <?php foreach ($s['claims'] as $c): ?>
              <tr>
                <td><strong><?= e($c['name']) ?></strong><div class="note"><?= e($c['household_name']) ?></div></td>
                <td><strong><?= e(fmt_amount($c['amount'])) ?> <?= e($unit) ?></strong></td>
                <td><?= e(scale_text($c['amount'], $unit)) ?></td>
                <td><?= $s['out_of_stock'] ? 'Not charged' : money($c['cost_cents']) ?></td>
              </tr>
            <?php endforeach; ?>
              <tr>
                <td><strong>Whole item</strong></td>
                <td><strong><?= e(fmt_amount((float) $s['size_amount'])) ?> <?= e($unit) ?></strong></td>
                <td><?= e(scale_text((float) $s['size_amount'], $unit)) ?></td>
                <td><?= money($s['price_cents']) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
    <?php
    page_end();
}
