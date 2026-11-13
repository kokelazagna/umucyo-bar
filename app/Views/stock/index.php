<?php
$label = [
    'purchase' => 'Purchase received', 'sale' => 'Sale', 'sale_cancel' => 'Sale cancelled', 'purchase_cancel' => 'Purchase cancelled',
    'damaged' => 'Damaged', 'expired' => 'Expired', 'missing' => 'Missing', 'returned' => 'Returned', 'correction' => 'Correction',
];
?>
<div class="orders__filters">
  <nav class="tabs" aria-label="Stock sections">
    <a class="tabs__tab tabs__tab--active" href="<?= e(url('stock')) ?>" aria-current="page">Movements</a>
    <a class="tabs__tab" href="<?= e(url('stock/adjustments')) ?>">Adjustment records</a>
  </nav>
  <div class="orders__actions">
    <a class="btn btn-primary" href="<?= e(url('stock/adjust')) ?>">+ Stock adjustment</a>
  </div>
</div>

<div class="toolbar">
  <form method="get" action="<?= e(url('stock')) ?>" class="filters">
    <select name="product">
      <option value="0">All products</option>
      <?php foreach ($products as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $filters['product'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type">
      <option value="">Any type</option>
      <?php foreach ($types as $t): ?>
        <option value="<?= e($t) ?>" <?= $filters['type'] === $t ? 'selected' : '' ?>><?= e($label[$t] ?? $t) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="check">From <input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
    <label class="check">To <input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    <button type="submit" class="btn btn-light">Filter</button>
  </form>
</div>

<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Time</th><th>Product</th><th>Type</th><th class="right">Change</th><th class="right">Balance after</th><th class="right">Unit cost</th><th>Reason / reference</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($movements as $m): ?>
      <?php $ch = (int)$m['quantity_change']; ?>
      <tr>
        <td><?= e($m['created_at']) ?></td>
        <td><strong><?= e($m['product_name']) ?></strong><div class="muted small"><?= e($m['product_code']) ?></div></td>
        <td><?= e($label[$m['movement_type']] ?? $m['movement_type']) ?></td>
        <td class="right"><strong class="<?= $ch >= 0 ? 'text-green' : 'text-red' ?>"><?= $ch > 0 ? '+' : '' ?><?= $ch ?></strong> <?= e($m['base_unit']) ?></td>
        <td class="right"><?= (int)$m['balance_after'] ?></td>
        <td class="right"><?= $m['unit_cost'] !== null ? e(number_format((float)$m['unit_cost'], 2)) : '-' ?></td>
        <td>
          <?= e($m['reason'] ?: '') ?>
          <?php if ($m['reference_type'] === 'purchase' && $m['reference_id']): ?>
            <a href="<?= e(url('purchase/show?id=' . (int)$m['reference_id'])) ?>">[view]</a>
          <?php elseif ($m['reference_type'] === 'sale' && $m['reference_id'] && $canSales): ?>
            <a href="<?= e(url('sale/receipt?id=' . (int)$m['reference_id'])) ?>">[view]</a>
          <?php endif; ?>
        </td>
        <td><?= e($m['user_name'] ?: '-') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$movements): ?><tr><td colspan="8" class="muted">No stock movements found.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($movTotal, current_page(), PER_PAGE, 'stock movements'); ?>
<footer class="orders__footer">
  <p class="muted small">Every change to stock is recorded here: purchases, sales, cancellations and adjustments. Showing the latest 500.</p>
  <p class="orders__results" aria-live="polite">Results: <?= count($movements) ?></p>
</footer>
