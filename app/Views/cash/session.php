<?php
$isOpen = ($cs['status'] === 'open');
$typeLabel = [
    'sale' => 'Cash sale', 'debt_payment' => 'Customer debt payment', 'deposit' => 'Cash deposit',
    'supplier_payment' => 'Supplier payment', 'expense' => 'Expense', 'owner_withdrawal' => 'Owner withdrawal', 'refund' => 'Refund (cancelled sale)',
];
$counted  = $cs['counted_closing'];
$variance = $cs['variance'];
?>
<div class="toolbar no-print">
  <div>
    <h3 style="margin:0">Cash session #<?= (int)$cs['id'] ?>
      <span class="badge badge-<?= $isOpen ? 'yellow' : 'gray' ?>"><?= e($cs['status']) ?></span></h3>
    <span class="muted small">Opened <?= e($cs['opened_at']) ?> by <?= e($cs['opened_name'] ?: '-') ?>
      <?php if (!$isOpen): ?> &middot; closed <?= e($cs['closed_at']) ?> by <?= e($cs['closed_name'] ?: '-') ?><?php endif; ?></span>
  </div>
  <div>
    <?php if ($isOpen): ?><a class="btn btn-primary" href="<?= e(url('pos')) ?>">Go to POS</a><?php endif; ?>
    <button type="button" class="btn btn-light" onclick="window.print()">Print reconciliation</button>
    <a class="btn btn-light" href="<?= e(url('cash')) ?>">All sessions</a>
  </div>
</div>

<div class="panel">
  <h3>Daily cash reconciliation</h3>
  <table class="table recon">
    <tbody>
      <tr><td>Opening cash balance</td><td class="right"><?= e(rwf($sum['opening'])) ?></td></tr>
      <tr><td>+ Cash received from sales</td><td class="right"><?= e(rwf($sum['sale'])) ?></td></tr>
      <tr><td>+ Cash received from customer debts</td><td class="right"><?= e(rwf($sum['debt_payment'])) ?></td></tr>
      <tr><td>+ Cash deposits</td><td class="right"><?= e(rwf($sum['deposit'])) ?></td></tr>
      <tr><td>- Cash paid to suppliers</td><td class="right"><?= e(rwf($sum['supplier_payment'])) ?></td></tr>
      <tr><td>- Business expenses</td><td class="right"><?= e(rwf($sum['expense'])) ?></td></tr>
      <tr><td>- Owner withdrawals</td><td class="right"><?= e(rwf($sum['owner_withdrawal'])) ?></td></tr>
      <tr><td>- Refunds (cancelled sales)</td><td class="right"><?= e(rwf($sum['refund'])) ?></td></tr>
      <tr class="bold"><td><?= $isOpen ? 'Expected cash in drawer (so far)' : 'Expected closing cash' ?></td><td class="right"><?= e(rwf($sum['expected'])) ?></td></tr>
      <?php if (!$isOpen): ?>
        <tr class="bold"><td>Counted closing cash</td><td class="right"><?= e(rwf($counted)) ?></td></tr>
        <tr class="bold"><td>Variance (counted - expected)</td>
          <td class="right"><span class="<?= abs((float)$variance) > 0.009 ? 'text-red' : 'text-green' ?>"><?= e(number_format((float)$variance, 0)) ?> RWF</span></td></tr>
        <?php if ($cs['notes']): ?><tr><td>Note</td><td class="right"><?= e($cs['notes']) ?></td></tr><?php endif; ?>
      <?php endif; ?>
    </tbody>
  </table>
  <p class="muted small">Credit sales are NOT cash received. Mobile Money and bank payments are not counted in the drawer.</p>
</div>

<?php if ($isOpen): ?>
<div class="cash-actions no-print">
  <div class="panel">
    <h3>Close session</h3>
    <form method="post" action="<?= e(url('cash/close')) ?>" data-online-only onsubmit="return confirm('Close this cash session? You will not be able to sell until a new one is opened.');">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$cs['id'] ?>">
      <label>Cash counted in the drawer (RWF) *</label>
      <input type="number" name="counted" min="0" step="1" required>
      <label>Note (required if it differs from expected)</label>
      <input type="text" name="notes" maxlength="255">
      <p><button type="submit" class="btn btn-primary">Close &amp; reconcile</button></p>
    </form>
  </div>

  <?php if ($isManager): ?>
  <div class="panel">
    <h3>Cash deposit</h3>
    <form method="post" action="<?= e(url('cash/deposit')) ?>" data-online-only>
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$cs['id'] ?>">
      <label>Amount (RWF)</label>
      <input type="number" name="amount" min="1" step="1" required>
      <label>Description</label>
      <input type="text" name="description" maxlength="255" placeholder="e.g. Change float added">
      <p><button type="submit" class="btn btn-light">Record deposit</button></p>
    </form>
  </div>

  <div class="panel">
    <h3>Owner withdrawal</h3>
    <form method="post" action="<?= e(url('cash/withdraw')) ?>" data-online-only onsubmit="return confirm('Record this owner withdrawal?');">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$cs['id'] ?>">
      <label>Amount (RWF)</label>
      <input type="number" name="amount" min="1" step="1" required>
      <label>Reason *</label>
      <input type="text" name="description" minlength="3" maxlength="255" required>
      <p><button type="submit" class="btn btn-light">Record withdrawal</button></p>
      <p class="muted small">Not a business expense: it never reduces profit.</p>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<h3>Cash movements</h3>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Time</th><th>Type</th><th>Description</th><th>By</th><th class="right">In</th><th class="right">Out</th></tr></thead>
    <tbody>
    <?php foreach ($txns as $t): ?>
      <tr>
        <td><?= e($t['created_at']) ?></td>
        <td><?= e($typeLabel[$t['txn_type']] ?? $t['txn_type']) ?></td>
        <td><?= e($t['description'] ?: '') ?></td>
        <td><?= e($t['full_name'] ?: '-') ?></td>
        <td class="right"><?= $t['direction'] === 'in' ? e(number_format((float)$t['amount'], 0)) : '' ?></td>
        <td class="right"><?= $t['direction'] === 'out' ? e(number_format((float)$t['amount'], 0)) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$txns): ?><tr><td colspan="6" class="muted">No cash movements yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($txnTotal, (int)($_GET['txn_page'] ?? 1), PER_PAGE, 'cash movements'); ?>
