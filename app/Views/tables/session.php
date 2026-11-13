<?php
$sale     = $tab['sale'];
$isOpen   = ($tab['status'] === 'open');
$isManager = !empty($isManager);
$servedTotal = $sale ? (float)$sale['total_amount'] : 0.0;
$paid        = $sale ? (float)$sale['amount_paid'] : 0.0;
$credit      = $sale ? (float)$sale['credit_amount'] : 0.0;
$balance     = max($servedTotal - $paid - $credit, 0);
$draftTotal  = 0.0;
foreach ($tab['draft_items'] as $d) {
    $draftTotal += (int)$d['quantity'] * (float)$d['unit_price_snapshot'];
}
$waiterName = '-';
foreach ($staff as $s) {
    if ((int)$s['id'] === (int)$tab['assigned_waiter_id']) {
        $waiterName = $s['full_name'];
    }
}
$methodLabel = ['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'bank' => 'Bank'];
$hasServed = $sale && (float)$sale['subtotal'] > 0;
?>

<div class="toolbar">
  <div>
    <h3 style="margin:0">Table <?= e($tab['table_code']) ?>
      <span class="badge badge-<?= $isOpen ? 'yellow' : 'gray' ?>"><?= e($tab['status']) ?></span>
    </h3>
    <span class="muted small">
      <?= e($tab['area_name']) ?> &middot; tab #<?= (int)$tab['id'] ?> &middot; opened <?= e($tab['opened_at']) ?>
      &middot; waiter <?= e($waiterName) ?>
    </span>
  </div>
  <div>
    <?php if ($sale): ?>
      <a class="btn btn-primary" href="<?= e(url('sale/receipt?id=' . (int)$sale['id'])) ?>">Receipt <?= e($sale['receipt_no']) ?></a>
    <?php endif; ?>
    <a class="btn btn-light" href="<?= e(url('table')) ?>">Floor</a>
    <a class="btn btn-light" href="<?= e(url('pos')) ?>">Walk-in sale</a>
  </div>
</div>

<div class="cards">
  <div class="card"><div class="card-value small-num"><?= e(rwf($servedTotal)) ?></div><div class="card-label">Served bill</div></div>
  <div class="card"><div class="card-value small-num"><?= e(rwf($draftTotal)) ?></div><div class="card-label">Draft (not billed)</div></div>
  <div class="card"><div class="card-value small-num text-green"><?= e(rwf($paid)) ?></div><div class="card-label">Paid</div></div>
  <div class="card"><div class="card-value small-num text-red"><?= e(rwf($balance)) ?></div><div class="card-label">Outstanding</div></div>
</div>

<?php if ($credit > 0): ?>
  <div class="alert alert-warning"><?= e(rwf($credit)) ?> of this bill was left on an authorised customer credit account.</div>
<?php endif; ?>
<?php if ($tab['notes']): ?>
  <div class="alert alert-info"><?= e($tab['notes']) ?></div>
<?php endif; ?>

<?php if ($isOpen): ?>
  <div class="panel">
    <h3>Add to the draft round</h3>
    <p class="muted small">Draft drinks never touch stock. Stock is deducted only when the round is confirmed served.
       Tap a size on a product tile to add one; change the quantity in the draft round table below.</p>
    <div class="draft-scroll">
      <div class="draft-scroll__head">
        <div class="toolbar" style="margin:0 0 10px">
          <input type="text" id="draft-search" placeholder="Search by name..." style="max-width:300px" autocomplete="off">
        </div>
        <div class="tabs" id="draft-cats" role="tablist" aria-label="Product categories" style="margin:0 0 12px"></div>
      </div>
      <div class="product-grid" id="draft-grid">
      <?php foreach ($draftProducts as $p): ?>
        <?php
          $stockPill = $p['stock'] <= 0 ? '<span class="pill pill--out">Out of stock</span>'
            : ($p['stock'] <= 5 ? '<span class="pill pill--low">' . (int)$p['stock'] . ' left</span>'
                                : '<span class="pill pill--ok">' . (int)$p['stock'] . ' in stock</span>');
          $w = preg_split('/\s+/', trim($p['name'])) ?: [''];
          $initials = mb_strtoupper(($w[0][0] ?? '') . ($w[1][0] ?? ''));
          $hue = ['#a07a35', '#3a67c4', '#de9187', '#8fd5bd', '#f2706b', '#ffd699', '#a9cffb', '#c487a0'][(int)$p['id'] % 8];
        ?>
        <?php $outOfStock = $p['stock'] <= 0; ?>
        <div class="tile<?= $outOfStock ? ' out' : '' ?>" data-name="<?= e(mb_strtolower($p['name'] . ' ' . $p['category'])) ?>" data-cat="<?= e($p['category'] ?: 'Other') ?>">
          <span class="tile__art" style="background:<?= e($hue) ?>"><?= e($initials) ?></span>
          <span class="tile__name"><?= e($p['name']) ?></span>
          <span class="tile__units">
            <?php foreach ($p['units'] as $u): ?>
              <form method="post" action="<?= e(url('table/addItem')) ?>" class="inline-form" data-offline-action="table_draft_add" data-offline-note="Drink queued for the draft round - it syncs when the connection returns." onsubmit="return <?= $outOfStock ? 'false' : 'true' ?>;">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($tokens['add']) ?>">
                <input type="hidden" name="session_id" value="<?= (int)$tab['id'] ?>">
                <input type="hidden" name="product_unit" value="<?= (int)$p['id'] ?>:<?= (int)$u['id'] ?>">
                <input type="hidden" name="quantity" value="1">
                <button type="submit" class="unit-btn"<?= $outOfStock ? ' disabled tabindex="-1"' : '' ?> title="<?= $outOfStock ? e($p['name']) . ' is out of stock' : 'Add one ' . e($u['name']) . ' of ' . e($p['name']) ?>">
                  <span><?= e($u['name']) ?><?= (int)$u['factor'] > 1 ? ' (' . (int)$u['factor'] . ' ' . e($p['base_unit']) . ')' : '' ?></span>
                  <strong><?= e(rwf($u['price'])) ?></strong>
                </button>
              </form>
            <?php endforeach; ?>
          </span>
          <span class="tile__foot"><span class="tile__why"><?= $stockPill ?></span></span>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted small" id="draft-empty" hidden>No products found.</p>
    </div>
  </div>
<?php endif; ?>
<script src="<?= e(asset('js/draft_round.js')) ?>"></script>
<script src="<?= e(asset('js/other_option.js')) ?>"></script>

<h3>Draft round (unserved)</h3>
<?php if (!$tab['draft_items']): ?>
  <p class="muted">No unserved items.</p>
<?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Item</th><th>Quantity</th><th>Base units</th><th class="right">Unit price</th><th class="right">Line</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($tab['draft_items'] as $d): ?>
        <tr>
          <td><strong><?= e($d['product_name']) ?></strong><div class="muted small"><?= e($d['unit_name']) ?> (<?= (int)$d['conversion_factor_snapshot'] ?> <?= e($d['base_unit']) ?>)</div></td>
          <td>
            <form method="post" action="<?= e(url('table/updateItem')) ?>" class="inline-form" data-offline-action="table_draft_update" data-offline-note="Draft change saved offline - it syncs when the connection returns.">
              <?= csrf_field() ?>
              <input type="hidden" name="token" value="<?= e($tokens['update']) ?>">
              <input type="hidden" name="session_id" value="<?= (int)$tab['id'] ?>">
              <input type="hidden" name="item_id" value="<?= (int)$d['id'] ?>">
              <input type="number" name="quantity" min="1" max="999" step="1" value="<?= (int)$d['quantity'] ?>" style="width:80px" aria-label="Quantity">
              <button type="submit" class="btn btn-light">Update</button>
            </form>
          </td>
          <td><?= (int)$d['base_quantity'] ?> <?= e($d['base_unit']) ?></td>
          <td class="right"><?= e(rwf($d['unit_price_snapshot'])) ?></td>
          <td class="right"><?= e(rwf((int)$d['quantity'] * (float)$d['unit_price_snapshot'])) ?></td>
          <td class="actions">
            <form method="post" action="<?= e(url('table/removeItem')) ?>" data-offline-action="table_draft_update" data-offline-note="Draft removal saved offline - it syncs when the connection returns." onsubmit="return confirm('Remove this draft line?');">
              <?= csrf_field() ?>
              <input type="hidden" name="token" value="<?= e($tokens['update']) ?>">
              <input type="hidden" name="session_id" value="<?= (int)$tab['id'] ?>">
              <input type="hidden" name="item_id" value="<?= (int)$d['id'] ?>">
              <button type="submit" class="btn btn-light text-red">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($isOpen): ?>
    <div class="toolbar">
      <span class="muted small">Confirming served deducts stock exactly once and adds these lines to the bill.</span>
      <div class="inline-form">
        <form method="post" action="<?= e(url('table/clearDraft')) ?>" data-online-only onsubmit="return confirm('Clear the whole draft round? Nothing has been served.');">
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($tokens['update']) ?>">
          <input type="hidden" name="session_id" value="<?= (int)$tab['id'] ?>">
          <button type="submit" class="btn btn-light">Clear draft</button>
        </form>
        <form method="post" action="<?= e(url('table/serve')) ?>" data-offline-action="table_serve" data-offline-note="Serving confirmation saved offline - stock is re-checked by the server when it syncs." onsubmit="return confirm('Confirm these drinks were served? Stock will be deducted once per line.');">
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($tokens['serve']) ?>">
          <input type="hidden" name="session_id" value="<?= (int)$tab['id'] ?>">
          <button type="submit" class="btn btn-primary">Confirm served</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<h3>Served items on the bill</h3>
<?php if (!$sale || !$sale['items']): ?>
  <p class="muted">Nothing has been served on this table yet.</p>
<?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Item</th><th>Quantity</th><th>Base units</th><th class="right">Unit price</th><th class="right">Line total</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sale['items'] as $it): ?>
        <tr>
          <td><strong><?= e($it['product_name']) ?></strong><div class="muted small"><?= e($it['unit_name']) ?></div></td>
          <td><?= (int)$it['quantity'] ?></td>
          <td><?= (int)$it['base_quantity'] ?> <?= e($it['base_unit']) ?></td>
          <td class="right"><?= e(rwf($it['unit_price'])) ?></td>
          <td class="right"><?= e(rwf($it['subtotal'])) ?></td>
          <td class="actions">
            <?php if ($isManager && $it['line_status'] === 'active'): ?>
              <form method="post" action="<?= e(url('table/void')) ?>" data-online-only onsubmit="return confirm('Void this served line? Stock will be returned.');">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($tokens['void']) ?>">
                <input type="hidden" name="session_id" value="<?= (int)$tab['id'] ?>">
                <input type="hidden" name="sale_item_id" value="<?= (int)$it['id'] ?>">
                <input type="text" name="reason" minlength="3" maxlength="255" placeholder="Reason (required)" required style="width:150px">
                <button type="submit" class="btn btn-light text-red">Void</button>
              </form>
            <?php else: ?>
              <span class="badge badge-gray"><?= e($it['line_status']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<h3>Payments on this tab</h3>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Time</th><th>Method</th><th>Reference</th><th class="right">Amount</th></tr></thead>
    <tbody>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td><?= e($p['paid_at']) ?></td>
          <td><?= e($methodLabel[$p['method']] ?? $p['method']) ?></td>
          <td><?= e($p['reference'] ?: '-') ?></td>
          <td class="right"><?= e(rwf($p['amount'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$payments): ?><tr><td colspan="4" class="muted">No payments yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($isOpen): ?>
  <div class="split">
    <div class="panel">
      <h3>Settle the bill</h3>
      <?php if (!$hasServed): ?>
        <p class="muted small">Serve at least one round before taking payment.</p>
      <?php else: ?>
        <p class="muted small">
          Bill <strong><?= e(rwf($servedTotal)) ?></strong>, paid <strong><?= e(rwf($paid)) ?></strong>,
          outstanding <strong class="text-red"><?= e(rwf($balance)) ?></strong>.
          A tab stays occupied until the outstanding amount is zero.
        </p>
        <form method="post" action="<?= e(url('table/settle')) ?>" data-offline-action="table_settle" data-offline-note="Tab payment saved offline - it syncs when the connection returns.">
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($tokens['settle']) ?>">
          <input type="hidden" name="session_id" value="<?= (int)$tab['id'] ?>">
          <label>Cash received (RWF)</label>
          <input type="number" name="cash_amount" min="0" step="1" value="0">
          <label>Mobile Money amount (held until confirmed)</label>
          <input type="number" name="momo_amount" min="0" step="1" value="0">
          <input type="text" name="momo_ref" maxlength="60" placeholder="MoMo reference">
          <label>Bank amount <span class="muted small">(online only)</span></label>
          <input type="number" name="bank_amount" min="0" step="1" value="0" data-online-only>
          <input type="text" name="bank_ref" maxlength="60" placeholder="Bank reference" data-online-only>
          <label>Credit customer <span class="muted small">(online only)</span></label>
          <select name="customer_id" data-other-input="customer_other" data-online-only>
            <option value="0">Walk-in / no credit</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e($c['full_name']) ?> (debt <?= e(rwf($c['current_debt'])) ?>, limit <?= e(rwf($c['credit_limit'])) ?>)</option>
            <?php endforeach; ?>
            <option value="__other__">&#10133; Other / New customer...</option>
          </select>
          <input type="text" name="customer_other" hidden maxlength="120" placeholder="Type the new customer name...">
          <?php if ($isManager): ?>
            <label>Discount at settlement (RWF, manager)</label>
            <input type="number" name="discount" min="0" step="1" value="0">
            <label class="check"><input type="checkbox" name="override_limit" value="1"> Authorize over the credit limit</label>
          <?php endif; ?>
          <p><button type="submit" class="btn btn-primary">Record payment</button></p>
        </form>
        <p class="muted small">Cash needs an open cash session. Mobile Money counts only after the reference is confirmed by the manager.</p>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h3>Tab actions</h3>
      <?php if (!$sale && !$tab['draft_items']): ?>
        <form method="post" action="<?= e(url('table/close')) ?>" data-online-only onsubmit="return confirm('Close this empty tab?');">
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($tokens['close']) ?>">
          <input type="hidden" name="session_id" value="<?= (int)$tab['id'] ?>">
          <button type="submit" class="btn btn-light">Close empty tab</button>
        </form>
      <?php endif; ?>
      <?php if ($isManager): ?>
        <?php if ($available): ?>
          <h4>Transfer to another table</h4>
          <form method="post" action="<?= e(url('table/transfer')) ?>" data-online-only>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($tokens['transfer']) ?>">
            <input type="hidden" name="session_id" value="<?= (int)$tab['id'] ?>">
            <select name="target_table_id" required>
              <option value="">Choose a free table...</option>
              <?php foreach ($available as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= e($t['code']) ?> &middot; <?= e($t['area_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="reason" minlength="3" maxlength="255" placeholder="Reason (required)" required>
            <p><button type="submit" class="btn btn-light">Transfer tab</button></p>
          </form>
        <?php endif; ?>

        <?php if ($otherTabs): ?>
          <h4>Merge another tab into this one</h4>
          <form method="post" action="<?= e(url('table/merge')) ?>" data-online-only onsubmit="return confirm('Merge that tab into this bill?');">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($tokens['merge']) ?>">
            <input type="hidden" name="session_id" value="<?= (int)$tab['id'] ?>">
            <select name="source_session_id" required>
              <option value="">Choose a tab...</option>
              <?php foreach ($otherTabs as $o): ?>
                <option value="<?= (int)$o['session_id'] ?>">Table <?= e($o['table_code']) ?> &middot; <?= e(rwf($o['balance'] ?? 0)) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="reason" minlength="3" maxlength="255" placeholder="Reason (required)" required>
            <p><button type="submit" class="btn btn-light">Merge tabs</button></p>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
