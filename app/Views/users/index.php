<?php
$roleLabel = ['owner' => 'Owner / Administrator', 'manager' => 'Manager', 'cashier' => 'Cashier', 'stock_manager' => 'Stock Manager'];
?>
<div class="orders__filters">
  <nav class="tabs" aria-label="User sections">
    <a class="tabs__tab tabs__tab--active" href="<?= e(url('user')) ?>" aria-current="page">Users</a>
    <a class="tabs__tab" href="<?= e(url('user/audit')) ?>">Audit log</a>
  </nav>
  <div class="orders__actions">
    <a class="btn btn-primary" href="<?= e(url('user/create')) ?>">+ New user</a>
  </div>
</div>

<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Email / phone</th><th>Last login</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><strong><?= e($u['full_name']) ?></strong> <?= (int)$u['id'] === $me ? '<span class="badge badge-gray">You</span>' : '' ?></td>
        <td><?= e($u['username']) ?></td>
        <td><?= e($roleLabel[$u['role_name']] ?? $u['role_name']) ?></td>
        <td><?= e($u['email'] ?: '-') ?><div class="muted small"><?= e($u['phone'] ?: '') ?></div></td>
        <td><?= e($u['last_login_at'] ?: 'Never') ?></td>
        <td><span class="badge badge-<?= $u['status'] === 'active' ? 'green' : 'gray' ?>"><?= e($u['status']) ?></span></td>
        <td class="actions">
          <a class="btn btn-light" href="<?= e(url('user/edit?id=' . (int)$u['id'])) ?>">Edit</a>
          <?php if ((int)$u['id'] !== $me): ?>
            <form method="post" action="<?= e(url('user/toggle')) ?>" style="display:inline"
                  onsubmit="return confirm('Change the status of this user?');">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button type="submit" class="btn btn-light"><?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($total, $page, PER_PAGE, 'users'); ?>

<footer class="orders__footer">
  <p class="muted small">Role-based access: each role only sees and opens the pages it needs.</p>
  <p class="orders__results" aria-live="polite">Results: <?= count($users) ?></p>
</footer>
