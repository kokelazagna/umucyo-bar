<?php
$limit = (float)$customer['credit_limit'];
$debt  = (float)$customer['current_debt'];
$over  = $limit > 0 && $debt > $limit;
$badge = ['open' => 'yellow', 'partial' => 'yellow', 'paid' => 'green', 'cancelled' => 'red'];
?>
<div class="toolbar no-print">
  <div>
    <h3 style="margin:0"><?= e($customer['full_name']) ?> <span class="muted small">(<?= e($customer['customer_code']) ?>)</span></h3>
    <span class="muted small"><?= e($customer['phone'] ?: '') ?> <?= e($customer['address'] ?: '') ?></span>
  </div>
  <div>
    <button type="button" class="btn btn-light" onclick="window.print()">Print statement</button>
    <a class="btn btn-light" href="<?= e(url('customer')) ?>">Back</a>
  </div>
</div>

<div class="cards">
  <div class="card"><div class="card-value small-num"><?= $limit > 0 ? e(rwf($limit)) : 'No credit' ?></div><div class="card-label">Credit limit</div></div>
  <div class="card"><div class="card-value small-num text-red"><?= e(rwf($debt)) ?></div><div class="card-label">Current debt <?= $over ? '<span class="badge badge-red">Over limit</span>' : '' ?></div></div>
  <div class="card"><div class="card-value small-num"><?= $limit > 0 ? e(rwf(max($limit - $debt, 0))) : '-' ?></div><div class="card-label">Available credit</div></div>
</div>

<?php if ($debt > 0): ?>
<div class="panel no-print">
  <h3>Receive a debt payment</h3>
  <form method="post" action="<?= e(url('debt/pay')) ?>" class="inline-form" data-online-only>
    <?= csrf_field() ?>
    <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">
    <input type="number" name="amount" min="1" max="<?= e($customer['current_debt']) ?>" step="1" required
           placeholder="Amount (max <?= e(number_format($debt, 0)) ?>)">
    <select name="payment_method">
      <option value="cash">Cash</option>
      <option value="mobile_money">Mobile Money</option>
      <option value="bank">Bank</option>
    </select>
    <input type="text" name="reference" maxlength="60" placeholder="Reference (optional)">
    <input type="text" name="notes" maxlength="255" placeholder="Note (optional)">
    <button type="submit" class="btn btn-primary">Record payment</button>
  </form>
  <p class="muted small">The oldest debt is paid first. Cash payments are recorded in the open cash session.</p>
</div>
<?php endif; ?>

<h3>Debt statement</h3>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th class="right">Debit (owed)</th><th class="right">Credit (paid)</th><th class="right">Balance</th></tr></thead>
    <tbody>
    <?php foreach ($ledger as $row): ?>
      <tr>
        <td><?= e($row['date']) ?></td>
        <td><?= e($row['type']) ?></td>
        <td><?= e($row['ref']) ?></td>
        <td class="right"><?= $row['debit'] > 0 ? e(number_format($row['debit'], 0)) : '' ?></td>
        <td class="right"><?= $row['credit'] > 0 ? e(number_format($row['credit'], 0)) : '' ?></td>
        <td class="right"><strong><?= e(number_format($row['balance'], 0)) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ledger): ?><tr><td colspan="6" class="muted">No credit transactions yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($debts_total, (int)($_GET['debts_page'] ?? 1), PER_PAGE, 'credit transactions'); ?>

<h3>Credit sales</h3>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Receipt</th><th>Date</th><th class="right">Original</th><th class="right">Paid</th><th class="right">Balance</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($debts as $d): ?>
      <tr>
        <td><a href="<?= e(url('sale/receipt?id=' . (int)$d['sale_id'])) ?>"><?= e($d['receipt_no']) ?></a></td>
        <td><?= e($d['sale_date']) ?></td>
        <td class="right"><?= e(number_format((float)$d['original_amount'], 0)) ?></td>
        <td class="right"><?= e(number_format((float)$d['paid_amount'], 0)) ?></td>
        <td class="right"><?= e(number_format((float)$d['balance'], 0)) ?></td>
        <td><span class="badge badge-<?= $badge[$d['status']] ?? 'gray' ?>"><?= e($d['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$debts): ?><tr><td colspan="6" class="muted">No credit sales.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($debts_total, (int)($_GET['debts_page'] ?? 1), PER_PAGE, 'credit sales'); ?>

<h3>All purchases</h3>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Receipt</th><th>Date</th><th class="right">Total</th><th class="right">Paid at sale</th><th class="right">On credit</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($purchases as $p): ?>
      <tr>
        <td><a href="<?= e(url('sale/receipt?id=' . (int)$p['id'])) ?>"><?= e($p['receipt_no']) ?></a></td>
        <td><?= e($p['sale_date']) ?></td>
        <td class="right"><?= e(number_format((float)$p['total_amount'], 0)) ?></td>
        <td class="right"><?= e(number_format((float)$p['amount_paid'], 0)) ?></td>
        <td class="right"><?= e(number_format((float)$p['credit_amount'], 0)) ?></td>
        <td><span class="badge badge-<?= $p['status'] === 'completed' ? 'green' : 'red' ?>"><?= e($p['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$purchases): ?><tr><td colspan="6" class="muted">No purchases yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($sales_total, (int)($_GET['sales_page'] ?? 1), PER_PAGE, 'sales'); ?>
