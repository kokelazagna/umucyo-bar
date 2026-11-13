<div class="toolbar">
  <form method="get" action="<?= e(url('purchase')) ?>" class="filters">
    <input type="text" name="q" placeholder="Reference no." value="<?= e($filters['q']) ?>">
    <select name="supplier">
      <option value="0">All suppliers</option>
      <?php foreach ($suppliers as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $filters['supplier'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status">
      <option value="">Any status</option>
      <?php foreach (['pending', 'confirmed', 'cancelled'] as $st): ?>
        <option value="<?= e($st) ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="check">From <input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
    <label class="check">To <input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    <button type="submit" class="btn btn-light">Filter</button>
  </form>
  <a class="btn btn-primary" href="<?= e(url('purchase/create')) ?>">+ New purchase</a>
</div>

<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Reference</th><th>Date</th><th>Supplier</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($purchases as $p): ?>
      <tr>
        <td><strong><?= e($p['reference_no']) ?></strong></td>
        <td><?= e($p['purchase_date']) ?></td>
        <td><?= e($p['supplier_name']) ?></td>
        <td><?= e(rwf($p['total_cost'])) ?></td>
        <td><?= e(rwf($p['amount_paid'])) ?></td>
        <td><?= ($p['status'] !== 'cancelled' && (float)$p['balance'] > 0) ? '<strong class="text-red">' . e(rwf($p['balance'])) . '</strong>' : e(rwf($p['balance'])) ?></td>
        <td><span class="badge badge-<?= $p['status'] === 'confirmed' ? 'green' : ($p['status'] === 'pending' ? 'yellow' : 'red') ?>"><?= e($p['status']) ?></span></td>
        <td><a class="btn btn-light" href="<?= e(url('purchase/show?id=' . (int)$p['id'])) ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$purchases): ?><tr><td colspan="8" class="muted">No purchases found.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($total, $page, PER_PAGE, 'purchases'); ?>
