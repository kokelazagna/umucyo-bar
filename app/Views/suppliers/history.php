<div class="toolbar">
  <div>
    <strong><?= e($supplier['name']) ?></strong> (<?= e($supplier['supplier_code']) ?>)
    <?php if ($supplier['phone']): ?><span class="muted"> &middot; <?= e($supplier['phone']) ?></span><?php endif; ?>
  </div>
  <div>
    <a class="btn btn-light" href="<?= e(url('supplier')) ?>">Back</a>
    <a class="btn btn-primary" href="<?= e(url('purchase/create')) ?>">+ New purchase</a>
  </div>
</div>

<div class="cards">
  <div class="card"><div class="card-value"><?= (int)$summary['purchase_count'] ?></div><div class="card-label">Purchases (not cancelled)</div></div>
  <div class="card"><div class="card-value small-num"><?= e(rwf($summary['total_purchased'])) ?></div><div class="card-label">Total purchased</div></div>
  <div class="card"><div class="card-value small-num"><?= e(rwf($summary['total_paid'])) ?></div><div class="card-label">Total paid</div></div>
  <div class="card"><div class="card-value small-num text-red"><?= e(rwf($summary['outstanding'])) ?></div><div class="card-label">Outstanding balance</div></div>
</div>

<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Reference</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($purchases as $p): ?>
      <tr>
        <td><?= e($p['reference_no']) ?></td>
        <td><?= e($p['purchase_date']) ?></td>
        <td><?= e(rwf($p['total_cost'])) ?></td>
        <td><?= e(rwf($p['amount_paid'])) ?></td>
        <td><?= e(rwf($p['balance'])) ?></td>
        <td><span class="badge badge-<?= $p['status'] === 'confirmed' ? 'green' : ($p['status'] === 'pending' ? 'yellow' : 'red') ?>"><?= e($p['status']) ?></span></td>
        <td><a class="btn btn-light" href="<?= e(url('purchase/show?id=' . (int)$p['id'])) ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$purchases): ?><tr><td colspan="7" class="muted">No purchases from this supplier yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($histTotal, current_page(), PER_PAGE, 'supplier purchases'); ?>
