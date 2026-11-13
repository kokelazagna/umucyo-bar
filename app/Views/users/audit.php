<div class="orders__filters">
  <nav class="tabs" aria-label="User sections">
    <a class="tabs__tab" href="<?= e(url('user')) ?>">Users</a>
    <a class="tabs__tab tabs__tab--active" href="<?= e(url('user/audit')) ?>" aria-current="page">Audit log</a>
  </nav>
</div>

<div class="toolbar">
  <form method="get" action="<?= e(url('user/audit')) ?>" class="filters">
    <select name="action">
      <option value="">Any action</option>
      <?php foreach ($actions as $a): ?>
        <option value="<?= e($a) ?>" <?= $filters['action'] === $a ? 'selected' : '' ?>><?= e($a) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="user">
      <option value="0">Any user</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= $filters['user'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="check">From <input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
    <label class="check">To <input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    <button type="submit" class="btn btn-light">Filter</button>
  </form>
</div>

<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td><?= e($l['created_at']) ?></td>
        <td><?= e($l['full_name'] ?: '-') ?></td>
        <td><span class="badge badge-gray"><?= e($l['action']) ?></span></td>
        <td><?= e($l['entity_type'] ? $l['entity_type'] . ' #' . $l['entity_id'] : '') ?></td>
        <td><?= e($l['details'] ?: '') ?></td>
        <td><?= e($l['ip_address'] ?: '') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$logs): ?><tr><td colspan="6" class="muted">No audit entries found.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<!-- <?php render_pagination($logTotal, current_page(), PER_PAGE, 'audit entries'); ?>
<footer class="orders__footer">
  <p class="muted small">Sensitive operations are logged: logins, price changes, discounts, cancellations, stock adjustments, withdrawals and user changes. Showing the latest 300.</p>
  <p class="orders__results" aria-live="polite">Results: <?= count($logs) ?></p>
</footer> -->
