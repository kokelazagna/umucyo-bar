<div class="toolbar">
  <span class="muted">Outstanding balance = purchases not yet fully paid (cancelled purchases are excluded).</span>
  <a class="btn btn-primary" href="<?= e(url('supplier/create')) ?>">+ New supplier</a>
</div>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr><th>Code</th><th>Supplier</th><th>Phone</th><th>Email</th><th>Purchases</th><th>Total purchased</th><th>Outstanding</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php foreach ($suppliers as $s): ?>
      <tr>
        <td><?= e($s['supplier_code']) ?></td>
        <td><strong><?= e($s['name']) ?></strong>
          <?php if ($s['business_name']): ?><div class="muted small"><?= e($s['business_name']) ?></div><?php endif; ?>
          <?php if ($s['address']): ?><div class="muted small"><?= e($s['address']) ?></div><?php endif; ?>
        </td>
        <td><?= e($s['phone'] ?: '-') ?></td>
        <td><?= e($s['email'] ?: '-') ?></td>
        <td><?= (int)$s['purchase_count'] ?></td>
        <td><?= e(rwf($s['total_purchased'])) ?></td>
        <td><?= (float)$s['outstanding'] > 0 ? '<strong class="text-red">' . e(rwf($s['outstanding'])) . '</strong>' : e(rwf(0)) ?></td>
        <td><span class="badge badge-<?= $s['status'] === 'active' ? 'green' : 'gray' ?>"><?= e($s['status']) ?></span></td>
        <td class="actions">
          <a class="btn btn-light" href="<?= e(url('supplier/history?id=' . (int)$s['id'])) ?>">History</a>
          <a class="btn btn-light" href="<?= e(url('supplier/edit?id=' . (int)$s['id'])) ?>">Edit</a>
          <form method="post" action="<?= e(url('supplier/toggle')) ?>" style="display:inline"
                onsubmit="return confirm('Change the status of this supplier?');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="btn btn-light"><?= $s['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$suppliers): ?><tr><td colspan="9" class="muted">No suppliers yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($total, $page, PER_PAGE, 'suppliers'); ?>
