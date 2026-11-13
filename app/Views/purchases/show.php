<?php
$st = $purchase['status'];
$badge = $st === 'confirmed' ? 'green' : ($st === 'pending' ? 'yellow' : 'red');
?>
<div class="toolbar">
  <div>
    <h3 style="margin:0">
      <?= e($purchase['reference_no']) ?>
      <span class="badge badge-<?= $badge ?>"><?= e($st) ?></span>
    </h3>
  </div>
  <a class="btn btn-light" href="<?= e(url('purchase')) ?>">Back to purchases</a>
</div>

<div class="panel details">
  <div><span class="muted">Supplier</span><br><strong><?= e($purchase['supplier_name']) ?></strong>
    <?php if ($purchase['business_name']): ?><br><span class="small muted"><?= e($purchase['business_name']) ?></span><?php endif; ?></div>
  <div><span class="muted">Purchase date</span><br><strong><?= e($purchase['purchase_date']) ?></strong></div>
  <div><span class="muted">Recorded by</span><br><strong><?= e($purchase['created_name'] ?: '-') ?></strong></div>
  <?php if ($purchase['confirmed_at']): ?>
    <div><span class="muted">Received / confirmed</span><br><strong><?= e($purchase['confirmed_at']) ?></strong>
      <br><span class="small muted">by <?= e($purchase['confirmed_name'] ?: '-') ?></span></div>
  <?php endif; ?>
  <?php if ($purchase['notes']): ?><div><span class="muted">Notes</span><br><?= e($purchase['notes']) ?></div><?php endif; ?>
</div>

<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Product</th><th>Unit</th><th>Quantity</th><th>Stock added</th><th>Unit cost</th><th>Cost per base unit</th><th>Line total</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><strong><?= e($it['product_name']) ?></strong><div class="muted small"><?= e($it['product_code']) ?></div></td>
        <td><?= e($it['unit_name']) ?></td>
        <td><?= (int)$it['quantity'] ?></td>
        <td><?= (int)$it['base_quantity'] ?> <?= e($it['base_unit']) ?>(s)</td>
        <td><?= e(rwf($it['unit_cost'])) ?></td>
        <td><?= e(number_format((float)$it['cost_per_base_unit'], 2)) ?> RWF</td>
        <td><?= e(rwf($it['total_cost'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="6" class="right"><strong>Total cost</strong></td><td><strong><?= e(rwf($purchase['total_cost'])) ?></strong></td></tr>
      <tr><td colspan="6" class="right">Amount paid</td><td><?= e(rwf($purchase['amount_paid'])) ?></td></tr>
      <tr><td colspan="6" class="right">Outstanding supplier balance</td>
          <td><?= ($st !== 'cancelled' && (float)$purchase['balance'] > 0) ? '<strong class="text-red">' . e(rwf($purchase['balance'])) . '</strong>' : e(rwf($purchase['balance'])) ?></td></tr>
    </tfoot>
  </table>
</div>

<?php if ($st === 'cancelled'): ?>
  <div class="alert alert-danger" style="margin-top:16px">
    Cancelled on <?= e($purchase['cancelled_at']) ?> by <?= e($purchase['cancelled_name'] ?: '-') ?>.
    Reason: <?= e($purchase['cancel_reason']) ?>
    <?php if ((float)$purchase['amount_paid'] > 0): ?>
      <br>Note: <?= e(rwf($purchase['amount_paid'])) ?> had been paid to the supplier. Settle that refund with the supplier separately.
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="panel" style="margin-top:16px">
    <h3>Actions</h3>

    <?php if ($st === 'pending'): ?>
      <form method="post" action="<?= e(url('purchase/confirm')) ?>" style="margin-bottom:16px" data-online-only
            onsubmit="return confirm('Confirm that these goods were received? Stock will be increased.');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$purchase['id'] ?>">
        <button type="submit" class="btn btn-primary">Confirm &amp; receive stock</button>
        <span class="muted small">Adds the quantities above to stock and updates the weighted average cost.</span>
      </form>
    <?php endif; ?>

    <?php if ((float)$purchase['balance'] > 0): ?>
      <form method="post" action="<?= e(url('purchase/pay')) ?>" class="inline-form" style="margin-bottom:16px" data-online-only>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$purchase['id'] ?>">
        <input type="number" name="amount" min="1" max="<?= e($purchase['balance']) ?>" step="1" placeholder="Payment amount (max <?= e(number_format((float)$purchase['balance'], 0)) ?>)" required>
        <select name="payment_method">
          <option value="cash">Cash</option>
          <option value="mobile_money">Mobile Money</option>
          <option value="bank">Bank</option>
        </select>
        <button type="submit" class="btn btn-light">Record payment to supplier</button>
      </form>
    <?php endif; ?>

    <form method="post" action="<?= e(url('purchase/cancel')) ?>" class="inline-form" data-online-only
          onsubmit="return confirm('Cancel this purchase?<?= $st === 'confirmed' ? ' The stock it added will be reversed.' : '' ?>');">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$purchase['id'] ?>">
      <input type="text" name="reason" placeholder="Reason for cancelling (required)" minlength="3" maxlength="255" required>
      <button type="submit" class="btn btn-light text-red">Cancel purchase</button>
    </form>
    <?php if ($st === 'confirmed'): ?>
      <p class="muted small">Cancelling a confirmed purchase reverses the stock it added and records stock movements. It is refused if that stock was already sold.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>
