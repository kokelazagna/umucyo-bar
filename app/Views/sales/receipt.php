<?php
$methodLabel = ['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'bank' => 'Bank'];
?>
<div class="toolbar no-print">
  <div>
    <?php if ($isNew): ?><strong class="text-green">Sale completed.</strong><?php endif; ?>
  </div>
  <div>
    <a class="btn btn-primary" href="<?= e(url('pos')) ?>">+ New sale</a>
    <button type="button" class="btn btn-light" onclick="window.print()">Print / Save as PDF</button>
    <a class="btn btn-light" href="<?= e(url('sale')) ?>">Sales history</a>
  </div>
</div>

<div class="receipt">
  <div class="center"><strong class="big">UMUCYO BAR</strong><br>Kicukiro, Kigali, Rwanda</div>
  <div class="line"></div>
  <?php if ($sale['status'] === 'cancelled'): ?>
    <div class="cancelled-banner">*** CANCELLED ***</div>
  <?php endif; ?>
  <table>
    <tr><td>Receipt</td><td class="right"><?= e($sale['receipt_no']) ?></td></tr>
    <tr><td>Date</td><td class="right"><?= e($sale['sale_date']) ?></td></tr>
    <tr><td>Served by</td><td class="right"><?= e($sale['seller_name'] ?: '-') ?></td></tr>
    <tr><td>Customer</td><td class="right"><?= e($sale['customer_name'] ?: 'Walk-in') ?></td></tr>
  </table>
  <div class="line"></div>
  <table>
    <?php foreach ($items as $it): ?>
      <tr><td colspan="2"><?= e($it['product_name']) ?></td></tr>
      <tr>
        <td><?= (int)$it['quantity'] ?> <?= e($it['unit_name']) ?> x <?= e(number_format((float)$it['unit_price'], 0)) ?></td>
        <td class="right"><?= e(number_format((float)$it['subtotal'], 0)) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <div class="line"></div>
  <table>
    <tr><td>Subtotal</td><td class="right"><?= e(number_format((float)$sale['subtotal'], 0)) ?></td></tr>
    <?php if ((float)$sale['discount'] > 0): ?>
      <tr><td>Discount</td><td class="right">-<?= e(number_format((float)$sale['discount'], 0)) ?></td></tr>
    <?php endif; ?>
    <tr class="bold"><td>TOTAL (RWF)</td><td class="right"><?= e(number_format((float)$sale['total_amount'], 0)) ?></td></tr>
  </table>
  <div class="line"></div>
  <table>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td><?= e($methodLabel[$p['method']] ?? $p['method']) ?><?= $p['reference'] ? ' (' . e($p['reference']) . ')' : '' ?></td>
        <td class="right"><?= e(number_format((float)$p['amount'], 0)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ((float)$sale['credit_amount'] > 0): ?>
      <tr class="bold"><td>ON CREDIT (DEBT)</td><td class="right"><?= e(number_format((float)$sale['credit_amount'], 0)) ?></td></tr>
    <?php endif; ?>
  </table>
  <div class="line"></div>
  <div class="center">Thank you! Murakoze!</div>
</div>

<?php if ($sale['status'] === 'cancelled'): ?>
  <div class="alert alert-danger no-print" style="max-width:360px;margin:16px auto">
    Cancelled on <?= e($sale['cancelled_at']) ?> by <?= e($sale['cancelled_name'] ?: '-') ?>.<br>
    Reason: <?= e($sale['cancel_reason']) ?>
  </div>
<?php elseif ($canCancel): ?>
  <div class="panel no-print" style="max-width:360px;margin:16px auto">
    <h3>Cancel this sale</h3>
    <p class="muted small">Stock is restored with movement records, any credit is reversed, and the cash part is refunded from the open cash session.</p>
    <form method="post" action="<?= e(url('sale/cancel')) ?>" class="inline-form" data-online-only
          onsubmit="return confirm('Cancel this sale? The stock will be restored.');">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$sale['id'] ?>">
      <input type="text" name="reason" placeholder="Reason (required)" minlength="3" maxlength="255" required>
      <button type="submit" class="btn btn-light text-red">Cancel sale</button>
    </form>
  </div>
<?php endif; ?>
