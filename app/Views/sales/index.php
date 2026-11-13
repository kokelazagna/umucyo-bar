
<div class="toolbar">
  <form method="get" action="<?= e(url('sale')) ?>" class="filters">
    <input type="text" name="q" placeholder="Receipt no." value="<?= e($filters['q']) ?>">
    <select name="status">
      <option value="">Any status</option>
      <option value="completed" <?= $filters['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
      <option value="cancelled" <?= $filters['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
    </select>
    <label class="check">From <input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
    <label class="check">To <input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    <button type="submit" class="btn btn-light">Filter</button>
  </form>
  <a class="btn btn-primary" href="<?= e(url('pos')) ?>">+ New sale</a>
</div>

<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Receipt</th><th>Date</th><th>Customer</th><th>Sold by</th><th>Total</th><th>Paid</th><th>Credit</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sales as $s): ?>
      <tr>
        <td><strong><?= e($s['receipt_no']) ?></strong></td>
        <td><?= e($s['sale_date']) ?></td>
        <td><?= e($s['customer_name'] ?: 'Walk-in') ?></td>
        <td><?= e($s['seller_name'] ?: '-') ?></td>
        <td><?= e(rwf($s['total_amount'])) ?></td>
        <td><?= e(rwf($s['amount_paid'])) ?></td>
        <td><?= (float)$s['credit_amount'] > 0 ? '<strong class="text-red">' . e(rwf($s['credit_amount'])) . '</strong>' : e(rwf(0)) ?></td>
        <td><span class="badge badge-<?= $s['status'] === 'completed' ? 'green' : 'red' ?>"><?= e($s['status']) ?></span></td>
        <td><a class="btn btn-light" href="<?= e(url('sale/receipt?id=' . (int)$s['id'])) ?>">Receipt</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sales): ?><tr><td colspan="9" class="muted">No sales found.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($total, $page, PER_PAGE, 'sales'); ?>
