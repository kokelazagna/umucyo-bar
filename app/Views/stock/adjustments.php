<div class="orders__filters">
  <nav class="tabs" aria-label="Stock sections">
    <a class="tabs__tab" href="<?= e(url('stock')) ?>">Movements</a>
    <a class="tabs__tab tabs__tab--active" href="<?= e(url('stock/adjustments')) ?>" aria-current="page">Adjustment records</a>
  </nav>
  <div class="orders__actions">
    <a class="btn btn-primary" href="<?= e(url('stock/adjust')) ?>">+ Stock adjustment</a>
  </div>
</div>

<div class="toolbar">
  <form method="get" action="<?= e(url('stock/adjustments')) ?>" class="filters">
    <select name="type">
      <option value="">Any type</option>
      <?php foreach ($types as $t): ?>
        <option value="<?= e($t) ?>" <?= $filters['type'] === $t ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="check">From <input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
    <label class="check">To <input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    <button type="submit" class="btn btn-light">Filter</button>
  </form>
</div>

<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Date</th><th>Product</th><th>Type</th><th class="right">Quantity</th><th class="right">Value at cost</th><th>Reason</th><th>Responsible user</th></tr></thead>
    <tbody>
    <?php $loss = 0.0; foreach ($adjustments as $a): ?>
      <?php if ($a['direction'] === 'decrease') { $loss += (float)$a['value']; } ?>
      <tr>
        <td><?= e($a['created_at']) ?></td>
        <td><strong><?= e($a['product_name']) ?></strong><div class="muted small"><?= e($a['product_code']) ?></div></td>
        <td><span class="badge badge-<?= $a['direction'] === 'decrease' ? 'red' : 'green' ?>"><?= e($a['adjustment_type']) ?></span></td>
        <td class="right"><?= $a['direction'] === 'decrease' ? '-' : '+' ?><?= (int)$a['base_quantity'] ?> <?= e($a['base_unit']) ?></td>
        <td class="right"><?= e(number_format((float)$a['value'], 0)) ?> RWF</td>
        <td><?= e($a['reason']) ?></td>
        <td><?= e($a['user_name'] ?: '-') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$adjustments): ?><tr><td colspan="7" class="muted">No adjustments found.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td colspan="4" class="right"><strong>Stock written off (decreases)</strong></td><td class="right"><strong><?= e(number_format($loss, 0)) ?> RWF</strong></td><td colspan="2"></td></tr></tfoot>
  </table>
</div>
<?php render_pagination($adjTotal, current_page(), PER_PAGE, 'stock adjustments'); ?>

<footer class="orders__footer">
  <p></p>
  <p class="orders__results" aria-live="polite">Results: <?= count($adjustments) ?></p>
</footer>
