<div class="panel" style="max-width:520px">
  <h3>Open cash session</h3>
  <p class="muted">No cash session is open. Count the money in the drawer and enter it as the opening balance. You cannot sell until a session is open.</p>
  <form method="post" action="<?= e(url('cash/open')) ?>" class="inline-form" data-online-only>
    <?= csrf_field() ?>
    <input type="number" name="opening_balance" min="0" step="1" required placeholder="Opening cash (RWF)" value="<?= e($suggested) ?>">
    <button type="submit" class="btn btn-primary">Open session</button>
  </form>
  <p class="muted small">Suggested: the cash counted when the last session was closed.</p>
</div>

<h3>Recent sessions</h3>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>#</th><th>Opened</th><th>By</th><th>Closed</th><th>Opening</th><th>Expected</th><th>Counted</th><th>Variance</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
      <tr>
        <td><?= (int)$h['id'] ?></td>
        <td><?= e($h['opened_at']) ?></td>
        <td><?= e($h['opened_name'] ?: '-') ?></td>
        <td><?= e($h['closed_at'] ?: '-') ?></td>
        <td><?= e(rwf($h['opening_balance'])) ?></td>
        <td><?= $h['expected_closing'] !== null ? e(rwf($h['expected_closing'])) : '-' ?></td>
        <td><?= $h['counted_closing'] !== null ? e(rwf($h['counted_closing'])) : '-' ?></td>
        <td><?php if ($h['variance'] !== null): ?>
              <span class="<?= abs((float)$h['variance']) > 0.009 ? 'text-red' : 'text-green' ?>"><?= e(number_format((float)$h['variance'], 0)) ?> RWF</span>
            <?php else: ?>-<?php endif; ?></td>
        <td><span class="badge badge-<?= $h['status'] === 'open' ? 'yellow' : 'gray' ?>"><?= e($h['status']) ?></span></td>
        <td><a class="btn btn-light" href="<?= e(url('cash/session?id=' . (int)$h['id'])) ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$history): ?><tr><td colspan="10" class="muted">No cash sessions yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($total, current_page(), PER_PAGE, 'cash sessions'); ?>
