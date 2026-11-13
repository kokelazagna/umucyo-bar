<div class="toolbar">
  <form method="get" action="<?= e(url('expense')) ?>" class="filters">
    <select name="category">
      <option value="0">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $filters['category'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="check">From <input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
    <label class="check">To <input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    <button type="submit" class="btn btn-light">Filter</button>
  </form>
  <a class="btn btn-primary" href="<?= e(url('expense/create')) ?>">+ New expense</a>
</div>

<div class="table-wrap">
  <table class="table">
    <thead><tr><th>ID</th><th>Date</th><th>Category</th><th>Description</th><th>Method</th><th>Recorded by</th><th class="right">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($expenses as $x): ?>
      <tr>
        <td><?= e($x['expense_code']) ?></td>
        <td><?= e($x['expense_date']) ?></td>
        <td><?= e($x['category_name']) ?></td>
        <td><?= e($x['description']) ?></td>
        <td><?= e(str_replace('_', ' ', $x['payment_method'])) ?></td>
        <td><?= e($x['recorded_name'] ?: '-') ?></td>
        <td class="right"><?= e(number_format((float)$x['amount'], 0)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$expenses): ?><tr><td colspan="7" class="muted">No expenses found.</td></tr><?php endif; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="6" class="right"><strong>Total</strong></td><td class="right"><strong><?= e(rwf($total)) ?></strong></td></tr>
    </tfoot>
  </table>
</div>
<?php render_pagination($expTotal, current_page(), PER_PAGE, 'expenses'); ?>
<p class="muted small">Owner withdrawals are not business expenses. Record them in Cash Management.</p>
