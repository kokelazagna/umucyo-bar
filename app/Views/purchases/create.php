<?php if ($errors): ?>
  <div class="alert alert-danger"><strong>Please fix the following:</strong>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if (!$suppliers || !$products): ?>
  <div class="alert alert-warning">
    You need at least one active supplier and one active product before recording a purchase.
  </div>
<?php endif; ?>

<form method="post" action="<?= e(url('purchase/store')) ?>" class="panel" id="purchase-form" data-online-only>
  <?= csrf_field() ?>

  <div class="form-grid">
    <div>
      <label for="supplier_id">Supplier *</label>
      <select id="supplier_id" name="supplier_id" required data-other-input="supplier_other">
        <option value="">Choose...</option>
        <?php foreach ($suppliers as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= ((int)$h['supplier_id'] === (int)$s['id']) ? 'selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
        <option value="__other__">&#10133; Other / New supplier...</option>
      </select>
      <input type="text" id="supplier_other" name="supplier_other" hidden maxlength="120" placeholder="Type the new supplier name...">
    </div>
    <div>
      <label for="purchase_date">Purchase date *</label>
      <input type="date" id="purchase_date" name="purchase_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e($h['purchase_date']) ?>">
    </div>
    <div>
      <label for="notes">Notes</label>
      <input type="text" id="notes" name="notes" maxlength="255" value="<?= e($h['notes'] ?? '') ?>">
    </div>
  </div>

  <h3>Products purchased</h3>
  <div class="table-wrap">
    <table class="table items-table">
      <thead><tr><th>Product</th><th>Purchase unit</th><th>Quantity</th><th>Unit cost (RWF)</th><th>Line total</th><th></th></tr></thead>
      <tbody id="item-rows">
        <?php foreach ($rows as $r) { require __DIR__ . '/../partials/purchase_item_row.php'; } ?>
      </tbody>
    </table>
  </div>
  <p><button type="button" class="btn btn-light" id="add-row">+ Add product</button></p>

  <p class="muted small">
    Example: 5 crates of Primus (20 bottles each) are stored as 100 bottles. The cost per bottle is worked out automatically.
  </p>

  <div class="form-grid">
    <div>
      <label>Purchase total</label>
      <div class="big-total" id="grand-total">0 RWF</div>
    </div>
    <div>
      <label for="amount_paid">Amount paid now (RWF)</label>
      <input type="number" id="amount_paid" name="amount_paid" min="0" step="1" value="<?= e($h['amount_paid']) ?>">
    </div>
    <div>
      <label for="payment_method">Payment method</label>
      <select id="payment_method" name="payment_method">
        <?php foreach (['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'bank' => 'Bank'] as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $h['payment_method'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Outstanding supplier balance</label>
      <div class="big-total text-red" id="balance-left">0 RWF</div>
    </div>
  </div>

  <div class="form-actions" style="margin-top:16px">
    <button type="submit" class="btn btn-primary">Save purchase (pending)</button>
    <a class="btn btn-light" href="<?= e(url('purchase')) ?>">Cancel</a>
  </div>
  <p class="muted small">Stock is NOT increased yet. It increases when you confirm that the goods were received.</p>
</form>

<template id="row-template"><?php $r = ['product_id' => 0, 'unit_id' => 0, 'quantity' => '', 'unit_cost' => '']; require __DIR__ . '/../partials/purchase_item_row.php'; ?></template>
<script type="application/json" id="product-data"><?= json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= e(asset('js/purchases.js')) ?>"></script>
<script src="<?= e(asset('js/other_option.js')) ?>"></script>
