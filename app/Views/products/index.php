<?php
// Shows stock in the biggest unit too, e.g. "1 crate(s) + 3 bottle(s)"
if (!function_exists('stock_breakdown')) {
    function stock_breakdown(array $p, array $units): string
    {
        $big = null;
        foreach ($units as $u) {
            if ((int)$u['conversion_factor'] > 1 && ($big === null || (int)$u['conversion_factor'] > (int)$big['conversion_factor'])) {
                $big = $u;
            }
        }
        $qty = (int)$p['quantity_in_stock'];
        if ($big === null || $qty < (int)$big['conversion_factor']) {
            return '';
        }
        $f = (int)$big['conversion_factor'];
        return intdiv($qty, $f) . ' ' . $big['unit_name'] . '(s) + ' . ($qty % $f) . ' ' . $p['base_unit'] . '(s)';
    }
}
$today = date('Y-m-d');
?>
<div class="toolbar">
  <form method="get" action="<?= e(url('product')) ?>" class="filters">
    <input type="text" name="q" placeholder="Search name, brand, barcode, code" value="<?= e($filters['q']) ?>">
    <select name="category">
      <option value="0">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $filters['category'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status">
      <option value="">Any status</option>
      <option value="active"   <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
    <label class="check"><input type="checkbox" name="low" value="1" <?= $filters['low'] ? 'checked' : '' ?>> Low stock only</label>
    <button type="submit" class="btn btn-light">Filter</button>
  </form>
  <a class="btn btn-primary" href="<?= e(url('product/create')) ?>">+ New product</a>
</div>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Code</th><th>Product</th><th>Category</th><th>Stock</th><th>Min</th>
        <th>Avg cost</th><th>Selling price</th><th>Expiry</th><th>Status</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($products as $p): ?>
      <?php
        $units = $unitsByProduct[$p['id']] ?? [];
        $low = (int)$p['quantity_in_stock'] <= (int)$p['min_stock'];
        $breakdown = stock_breakdown($p, $units);
      ?>
      <tr>
        <td><?= e($p['product_code']) ?></td>
        <td>
          <strong><?= e($p['name']) ?></strong>
          <?php if ($p['brand']): ?><div class="muted small"><?= e($p['brand']) ?></div><?php endif; ?>
          <?php if ($p['barcode']): ?><div class="muted small"><?= e($p['barcode']) ?></div><?php endif; ?>
        </td>
        <td><?= e($p['category_name']) ?></td>
        <td>
          <?= (int)$p['quantity_in_stock'] ?> <?= e($p['base_unit']) ?>(s)
          <?php if ($low): ?><span class="badge badge-red">Low</span><?php endif; ?>
          <?php if ($breakdown): ?><div class="muted small"><?= e($breakdown) ?></div><?php endif; ?>
        </td>
        <td><?= (int)$p['min_stock'] ?></td>
        <td><?= e(rwf($p['avg_cost'])) ?></td>
        <td>
          <?= e(rwf($p['selling_price'])) ?>
          <?php foreach ($units as $u): ?>
            <?php if (!(int)$u['is_base']): ?>
              <div class="muted small"><?= e($u['unit_name']) ?> (<?= (int)$u['conversion_factor'] ?>): <?= e(rwf($u['selling_price'])) ?></div>
            <?php endif; ?>
          <?php endforeach; ?>
        </td>
        <td>
          <?= $p['expiry_date'] ? e($p['expiry_date']) : '-' ?>
          <?php if ($p['expiry_date'] && $p['expiry_date'] < $today): ?><span class="badge badge-red">Expired</span><?php endif; ?>
        </td>
        <td><span class="badge badge-<?= $p['status'] === 'active' ? 'green' : 'gray' ?>"><?= e($p['status']) ?></span></td>
        <td class="actions">
          <a class="btn btn-light" href="<?= e(url('product/edit?id=' . (int)$p['id'])) ?>">Edit</a>
          <form method="post" action="<?= e(url('product/toggle')) ?>" style="display:inline"
                onsubmit="return confirm('Change the status of this product?');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="btn btn-light"><?= $p['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$products): ?>
      <tr><td colspan="10" class="muted">No products found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($total, $page, PER_PAGE, 'products'); ?>
