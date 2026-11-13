<div class="toolbar">
  <form method="get" action="<?= e(url('customer')) ?>" class="filters">
    <input type="text" name="q" placeholder="Search name, phone, code" value="<?= e($filters['q']) ?>">
    <select name="status">
      <option value="">Any status</option>
      <?php foreach (['active', 'inactive', 'blocked'] as $st): ?>
        <option value="<?= e($st) ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="check"><input type="checkbox" name="debt" value="1" <?= $filters['debt'] ? 'checked' : '' ?>> With debt only</label>
    <button type="submit" class="btn btn-light">Filter</button>
  </form>
  <a class="btn btn-primary" href="<?= e(url('customer/create')) ?>">+ New customer</a>
</div>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr><th>Code</th><th>Customer</th><th>Phone</th><th>Credit limit</th><th>Current debt</th><th>Available credit</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php foreach ($customers as $c): ?>
      <?php
        $limit = (float)$c['credit_limit'];
        $debt  = (float)$c['current_debt'];
        $over  = $limit > 0 && $debt > $limit;
        $avail = max($limit - $debt, 0);
      ?>
      <tr>
        <td><?= e($c['customer_code']) ?></td>
        <td><strong><?= e($c['full_name']) ?></strong>
          <?php if ($c['address']): ?><div class="muted small"><?= e($c['address']) ?></div><?php endif; ?></td>
        <td><?= e($c['phone'] ?: '-') ?></td>
        <td><?= $limit > 0 ? e(rwf($limit)) : '<span class="muted">No credit</span>' ?></td>
        <td><?= $debt > 0 ? '<strong class="text-red">' . e(rwf($debt)) . '</strong>' : e(rwf(0)) ?>
          <?php if ($over): ?><span class="badge badge-red">Over limit</span><?php endif; ?></td>
        <td><?= $limit > 0 ? e(rwf($avail)) : '-' ?></td>
        <td><span class="badge badge-<?= $c['status'] === 'active' ? 'green' : ($c['status'] === 'blocked' ? 'red' : 'gray') ?>"><?= e($c['status']) ?></span></td>
        <td class="actions">
          <a class="btn btn-light" href="<?= e(url('customer/statement?id=' . (int)$c['id'])) ?>"><?= $debt > 0 ? 'Statement / Pay' : 'Statement' ?></a>
          <a class="btn btn-light" href="<?= e(url('customer/edit?id=' . (int)$c['id'])) ?>">Edit</a>
          <?php if ($isManager): ?>
            <form method="post" action="<?= e(url('customer/toggle')) ?>" style="display:inline"
                  onsubmit="return confirm('Change the status of this customer?');">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button type="submit" class="btn btn-light"><?= $c['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$customers): ?><tr><td colspan="8" class="muted">No customers found.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($total, $page, PER_PAGE, 'customers'); ?>
