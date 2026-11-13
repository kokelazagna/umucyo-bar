<?php if ($errors): ?>
  <div class="alert alert-danger"><strong>Please fix the following:</strong>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if ($expired): ?>
  <div class="alert alert-warning">
    <strong>Expired products still in stock:</strong>
    <?php foreach ($expired as $x): ?>
      <a href="<?= e(url('stock/adjust?type=expired&product_id=' . (int)$x['id'])) ?>"><?= e($x['name']) ?> (<?= (int)$x['quantity_in_stock'] ?> <?= e($x['base_unit']) ?>, expired <?= e($x['expiry_date']) ?>)</a>&nbsp;
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" action="<?= e(url('stock/store')) ?>" class="panel form-grid" data-online-only>
  <?= csrf_field() ?>
  <div>
    <label for="type">Adjustment type *</label>
    <select id="type" name="type">
      <?php foreach (['damaged' => 'Damaged (decreases stock)', 'expired' => 'Expired (decreases stock)', 'missing' => 'Missing (decreases stock)',
                      'returned' => 'Returned (increases stock)', 'correction' => 'Manual correction'] as $k => $lbl): ?>
        <option value="<?= e($k) ?>" <?= $d['type'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div id="dir-box" hidden>
    <label for="direction">Correction direction</label>
    <select id="direction" name="direction">
      <option value="decrease" <?= $d['direction'] === 'decrease' ? 'selected' : '' ?>>Decrease stock</option>
      <option value="increase" <?= $d['direction'] === 'increase' ? 'selected' : '' ?>>Increase stock</option>
    </select>
  </div>
  <div>
    <label for="product_id">Product *</label>
    <select id="product_id" name="product_id" required>
      <option value="">Choose...</option>
    </select>
  </div>
  <div>
    <label for="unit_id">Unit</label>
    <select id="unit_id" name="unit_id"></select>
  </div>
  <div>
    <label for="quantity">Quantity *</label>
    <input type="number" id="quantity" name="quantity" min="1" step="1" required value="<?= e($d['quantity']) ?>">
  </div>
  <div>
    <label>Stock now &rarr; after</label>
    <div class="big-total" id="preview">-</div>
  </div>
  <div class="full">
    <label for="reason">Reason * (recorded with your name)</label>
    <input type="text" id="reason" name="reason" minlength="3" maxlength="255" required value="<?= e($d['reason']) ?>" placeholder="e.g. 3 bottles broken while moving the crate">
  </div>
  <div class="full muted small">
    Damaged, expired and missing products reduce stock. Returned products add stock back. Stock is valued at the current weighted average cost,
    and can never go below zero. Every adjustment is saved in the stock movement history.
  </div>
  <div class="full form-actions">
    <button type="submit" class="btn btn-primary">Save adjustment</button>
    <a class="btn btn-light" href="<?= e(url('stock')) ?>">Cancel</a>
  </div>
</form>

<script type="application/json" id="adjust-data"><?= json_encode($payload + ['selectedProduct' => (int)$d['product_id']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= e(asset('js/adjust.js')) ?>"></script>
