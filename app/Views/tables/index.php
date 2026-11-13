<div class="toolbar">
  <div class="muted">
    Open a tab, add a draft round, then confirm <strong>served</strong> to deduct stock once. A tab stays occupied until the bill is fully paid.
  </div>
  <div>
    <a class="btn btn-light" href="<?= e(url('pos')) ?>">Walk-in sale (POS)</a>
    <?php if ($canManage): ?><a class="btn btn-light" href="<?= e(url('settings')) ?>">Tables &amp; areas</a><?php endif; ?>
  </div>
</div>

<?php
$grouped = [];
foreach ($tables as $t) {
    $grouped[$t['area_name'] ?: 'No area'][] = $t;
}
?>

<?php foreach ($grouped as $areaName => $areaTables): ?>
  <section class="floor">
    <h2 class="dash__heading"><?= e($areaName) ?></h2>
    <div class="floor__grid">
      <?php foreach ($areaTables as $t): ?>
        <?php
          $occupied = !empty($t['session_id']);
          $total    = (float)($t['sale_total'] ?? 0);
          $paid     = (float)($t['sale_paid'] ?? 0);
          $balance  = max($total - $paid, 0);
          $usable   = ((int)$t['active'] === 1 && $t['status'] !== 'out_of_service');
        ?>
        <article class="table-card<?= $occupied ? ' table-card--occupied' : '' ?><?= !$usable ? ' table-card--off' : '' ?>">
          <header class="table-card__head">
            <strong><?= e($t['code']) ?></strong>
            <span class="badge badge-<?= $occupied ? 'yellow' : ($t['status'] === 'available' ? 'green' : 'gray') ?>">
              <?= e($occupied ? 'occupied' : str_replace('_', ' ', $t['status'])) ?>
            </span>
          </header>
          <p class="muted small">
            <?= (int)$t['capacity'] ?> seats<?= $t['floor_position'] ? ' &middot; ' . e($t['floor_position']) : '' ?>
            <?= $t['waiter_name'] ? ' &middot; ' . e($t['waiter_name']) : '' ?>
          </p>

          <?php if ($occupied): ?>
            <p class="table-card__amount"><?= e(rwf($balance)) ?></p>
            <p class="muted small">
              Open since <?= e(date('H:i', strtotime((string)$t['session_opened_at']))) ?>
              <?= (int)$t['draft_orders'] > 0 ? ' &middot; unserved draft' : '' ?>
            </p>
            <a class="btn btn-primary" href="<?= e(url('table/session?id=' . (int)$t['session_id'])) ?>">Open tab</a>
          <?php elseif ($usable): ?>
            <form method="post" action="<?= e(url('table/open')) ?>" class="table-card__open" data-online-only>
              <?= csrf_field() ?>
              <input type="hidden" name="token" value="<?= e($token) ?>">
              <input type="hidden" name="table_id" value="<?= (int)$t['id'] ?>">
              <select name="waiter_id" aria-label="Waiter">
                <option value="0">No specific waiter</option>
                <?php foreach ($staff as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= e($s['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-primary">Open tab</button>
            </form>
          <?php else: ?>
            <p class="muted small">Not in service.</p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>

<?php if (!$tables): ?>
  <div class="alert alert-warning">
    No tables configured yet.<?php if ($canManage): ?> <a href="<?= e(url('settings')) ?>">Add areas and tables in Settings</a>.<?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($tabs): ?>
  <h2 class="dash__heading">Open tabs</h2>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Table</th><th>Area</th><th>Waiter</th><th>Opened</th><th class="right">Bill</th><th class="right">Paid</th><th class="right">Balance</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($tabs as $t): ?>
        <tr>
          <td><strong><?= e($t['table_code']) ?></strong></td>
          <td><?= e($t['area_name']) ?></td>
          <td><?= e($t['waiter_name'] ?: '-') ?></td>
          <td><?= e($t['opened_at']) ?></td>
          <td class="right"><?= e(rwf($t['total_amount'] ?? 0)) ?></td>
          <td class="right"><?= e(rwf($t['amount_paid'] ?? 0)) ?></td>
          <td class="right"><strong><?= e(rwf($t['balance'] ?? 0)) ?></strong></td>
          <td><a class="btn btn-light" href="<?= e(url('table/session?id=' . (int)$t['session_id'])) ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
