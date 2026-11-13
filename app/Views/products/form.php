<?php
$isEdit    = ($mode === 'edit');
$unitNames = ['bottle', 'can', 'crate', 'pack'];
$baseUnit  = $product['base_unit'] ?? 'bottle';
?>
<?php if ($errors): ?>
  <div class="alert alert-danger">
    <strong>Please fix the following:</strong>
    <ul>
      <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= e(url($isEdit ? 'product/update' : 'product/store')) ?>" class="panel form-grid" data-online-only>
  <?= csrf_field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$product['id'] ?>"><?php endif; ?>

  <?php if ($isEdit): ?>
    <div class="full muted">
      Product ID: <strong><?= field($product, 'product_code') ?></strong> &nbsp;|&nbsp;
      Quantity in stock: <strong><?= (int)($product['quantity_in_stock'] ?? 0) ?> <?= e($baseUnit) ?>(s)</strong>
      <br><small>Stock changes only through purchases, sales and stock adjustments, never by editing the product.</small>
    </div>
  <?php endif; ?>

  <div>
    <label for="name">Product name *</label>
    <input type="text" id="name" name="name" maxlength="120" required value="<?= field($product, 'name') ?>">
  </div>
  <div>
    <label for="category_id">Category *</label>
    <select id="category_id" name="category_id" required>
      <option value="">Choose...</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ((int)($product['category_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="brand">Brand</label>
    <input type="text" id="brand" name="brand" maxlength="80" value="<?= field($product, 'brand') ?>">
  </div>
  <div>
    <label for="barcode">Barcode (if available)</label>
    <input type="text" id="barcode" name="barcode" maxlength="50" value="<?= field($product, 'barcode') ?>">
  </div>
  <div>
    <label for="base_unit">Base unit (smallest unit stock is counted in) *</label>
    <select id="base_unit" name="base_unit">
      <?php foreach (['bottle', 'can'] as $b): ?>
        <option value="<?= e($b) ?>" <?= $baseUnit === $b ? 'selected' : '' ?>><?= e(ucfirst($b)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="active"   <?= ($product['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= ($product['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
  </div>
  <div>
    <label for="purchase_price">Purchase price per <span class="base-label"><?= e($baseUnit) ?></span> (RWF) *</label>
    <input type="number" id="purchase_price" name="purchase_price" min="0" step="1" required value="<?= field($product, 'purchase_price') ?>">
  </div>
  <div>
    <label for="selling_price">Selling price per <span class="base-label"><?= e($baseUnit) ?></span> (RWF) *</label>
    <input type="number" id="selling_price" name="selling_price" min="1" step="1" required value="<?= field($product, 'selling_price') ?>">
  </div>
  <div>
    <label for="min_stock">Minimum stock (reorder warning level) *</label>
    <input type="number" id="min_stock" name="min_stock" min="0" step="1" required value="<?= field($product, 'min_stock', '0') ?>">
  </div>
  <div>
    <label for="expiry_date">Expiry date (if applicable)</label>
    <input type="date" id="expiry_date" name="expiry_date" value="<?= field($product, 'expiry_date') ?>">
  </div>

  <div class="full">
    <h3>Other selling units</h3>
    <p class="muted small">
      Example: a crate contains 20 bottles. Stock is always counted in <strong>bottles</strong>, so buying 5 crates
      adds 100 bottles, and you can still sell single bottles. Leave the price empty to use units &times; base price.
    </p>
    <div id="unit-rows">
      <?php foreach ($unitRows as $r) { require __DIR__ . '/../partials/product_unit_row.php'; } ?>
    </div>
    <button type="button" class="btn btn-light" id="add-unit">+ Add unit (crate, pack...)</button>
    <template id="unit-template"><?php $r = ['id' => 0, 'name' => 'crate', 'factor' => '', 'price' => '']; require __DIR__ . '/../partials/product_unit_row.php'; ?></template>
  </div>

  <div class="full form-actions">
    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create product' ?></button>
    <a class="btn btn-light" href="<?= e(url('product')) ?>">Cancel</a>
  </div>
</form>

<script src="<?= e(asset('js/products.js')) ?>"></script>
