<?php
// Dashboard. Everything comes from dashboard_index() in controllers/dashboard.php:
//   $stock    = products, units in stock, stock value
//   $figures  = today's money figures (null for roles that may not see money)
//   $chart    = sales of the last 7 days     $recent = last sales
//   $lowStock = emptiest products           $categoryCounts = products per category

// Tiles: label, value, page to open, colour, icon, roles allowed to open that page, small text
$tiles = [
    ['Products registered',   number_format($stock['products']),      'products',                    'orange',  'products',  ['manager', 'stock_manager'], ''],
    ['Total stock available', number_format($stock['units']) . ' units', 'reports/view?r=stock_balance', 'coral', 'stock',     ['manager', 'stock_manager'],
        has_role(['manager', 'stock_manager']) ? 'Worth ' . rwf($stock['value']) . ' at cost' : ''],
];
if ($figures) {
    $tiles[] = ['Sales today',                rwf($figures['sales']),     'sales',                          'primary', 'payments',  ['manager', 'cashier'],       $figures['sales_n'] . ' sale(s)'];
    $tiles[] = ['Cash received today',        rwf($figures['cash']),      'cash',                           'dark',    'cash',      ['manager', 'cashier'],       'Cash sales + cash debt payments'];
    $tiles[] = ['Purchases today',            rwf($figures['purchases']), 'purchases',                      'orange',  'purchases', ['manager', 'stock_manager'], ''];
    $tiles[] = ['Expenses today',             rwf($figures['expenses']),  'expenses',                       'coral',   'expenses',  ['manager'],                  ''];
    $tiles[] = ['Gross profit today',         rwf($figures['gross']),     'reports/view?r=gross_profit',    'primary', 'reports',   ['manager'],                  'Revenue minus actual cost'];
    $tiles[] = ['Net profit today',           rwf($figures['net']),       'reports/view?r=net_profit',      'dark',    'reports',   ['manager'],                  'Gross profit minus expenses'];
    $tiles[] = ['Outstanding customer debts', rwf($figures['debts']),     'reports/view?r=customer_debt',   'orange',  'customers', ['manager'],                  ''];
}

// Quick action buttons: label, page, roles allowed
$quick = [
    ['New sale',         'pos',              ['manager', 'cashier']],
    ['New purchase',     'purchases/create', ['manager', 'stock_manager']],
    ['Stock adjustment', 'stock/adjust',     ['manager', 'stock_manager']],
    ['New product',      'products/create',  ['manager', 'stock_manager']],
    ['Reports',          'reports',          ['manager', 'stock_manager']],
];
$fullName = current_user()['full_name'] ?? '';
?>
<section class="dash">
  <div class="dash__main">
    <p class="dash__welcome">Welcome, <?= e($fullName) ?>.
      <?php if ($session): ?>
        Cash session #<?= (int)$session['id'] ?> is open.
      <?php elseif ($canSales): ?>
        <strong class="text-red">No cash session is open.</strong> <a href="<?= e(url('cash')) ?>">Open one to start selling</a>.
      <?php endif; ?>
    </p>

    <div class="stats">
      <?php foreach ($tiles as $t): ?>
        <?php $tag = has_role($t[5]) ? 'a' : 'div'; ?>
        <<?= $tag ?> class="stat"<?= $tag === 'a' ? ' href="' . e(url($t[2])) . '"' : '' ?>>
          <span class="stat__icon stat__icon--<?= e($t[3]) ?>"><svg class="icon"><use href="#i-<?= e($t[4]) ?>"/></svg></span>
          <span>
            <span class="stat__label"><?= e($t[0]) ?></span>
            <strong class="stat__value"><?= e($t[1]) ?></strong>
            <?php if ($t[6] !== ''): ?><span class="stat__sub"><?= e($t[6]) ?></span><?php endif; ?>
          </span>
        </<?= $tag ?>>
      <?php endforeach; ?>
    </div>

    <?php if ($chart): ?>
      <div class="dash__section">
        <h2 class="dash__heading">Sales, last 7 days</h2>
        <div class="bars">
          <?php foreach ($chart as $bar): ?>
            <div class="bar-col" title="<?= e($bar['date']) ?>: <?= e(number_format($bar['value'], 0)) ?> RWF">
              <small><?= $bar['value'] > 0 ? e(number_format($bar['value'], 0)) : '' ?></small>
              <div class="bar" style="height: <?= (int)$bar['pct'] ?>%"></div>
              <span><?= e($bar['label']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="dash__section">
      <h2 class="dash__heading">Quick actions</h2>
      <div class="quick">
        <?php foreach ($quick as $q): ?>
          <?php if (!has_role($q[2])) continue; ?>
          <a class="btn btn-light" href="<?= e(url($q[1])) ?>"><?= e($q[0]) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($canSales): ?>
      <div class="dash__section">
        <h2 class="dash__heading">Recent sales</h2>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Receipt</th><th>Customer</th><th class="right">Total (RWF)</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $s): ?>
              <tr>
                <td><a href="<?= e(url('sale/receipt?id=' . (int)$s['id'])) ?>"><strong><?= e($s['receipt_no']) ?></strong></a>
                  <span class="muted small"><?= e($s['sale_date']) ?></span></td>
                <td><?= e($s['customer']) ?></td>
                <td class="right"><?= e(number_format((float)$s['total_amount'], 0)) ?>
                  <?php if ((float)$s['credit_amount'] > 0): ?><span class="small text-red">credit <?= e(number_format((float)$s['credit_amount'], 0)) ?></span><?php endif; ?></td>
                <td><span class="badge badge-<?= $s['status'] === 'completed' ? 'green' : 'red' ?>"><?= e($s['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="4" class="muted">No sales yet.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <aside class="dash__side">
    <h2 class="dash__heading">Low stock <?= $lowCount > 0 ? '(' . (int)$lowCount . ')' : '' ?></h2>
    <?php if ($lowStock): ?>
      <ul class="statlist">
        <?php foreach ($lowStock as $p): ?>
          <li class="statlist__item">
            <span class="statlist__dot"></span>
            <span class="statlist__main">
              <span class="statlist__name"><?= e($p['name']) ?></span>
              <span class="statlist__sub">minimum <?= (int)$p['min_stock'] ?> <?= e($p['base_unit']) ?></span>
            </span>
            <span class="statlist__value"><?= (int)$p['qty'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="statlist__empty">All products are above their minimum level.</p>
    <?php endif; ?>

    <h2 class="dash__heading">Statistics</h2>
    <?php if ($categoryCounts): ?>
      <ul class="statlist">
        <?php foreach ($categoryCounts as $c): ?>
          <li class="statlist__item">
            <span class="statlist__dot"></span>
            <span class="statlist__main">
              <span class="statlist__name"><?= e($c['name']) ?></span>
              <span class="statlist__sub">products in category</span>
            </span>
            <span class="statlist__value"><?= (int)$c['total'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="statlist__empty">No categories yet.</p>
    <?php endif; ?>
  </aside>
</section>
