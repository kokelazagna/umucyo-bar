<?php
// $tables = [['name' => 'products', 'rows' => 8], ...]
$totalRows = 0;
foreach ($tables as $t) { $totalRows += $t['rows']; }
?>
<div class="cash-actions">
  <div class="panel">
    <h3>Download a backup</h3>
    <p class="muted small">Saves ALL data (<?= $tablesTotal ?> tables, <?= number_format($totalRows) ?> records) in one .sql file.
      Keep copies on a USB drive or another computer, and make one every day after closing the cash session.</p>
    <form method="post" action="<?= e(url('backup/download')) ?>">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary">Download backup (.sql)</button>
    </form>
  </div>

  <div class="panel">
    <h3>Restore a backup</h3>
    <div class="alert alert-warning">This REPLACES all current data (sales, stock, customers, users...) with the content of the file.
      A safety copy of the current data is saved first in <strong>storage/backups</strong>.</div>
    <form method="post" action="<?= e(url('backup/restore')) ?>" enctype="multipart/form-data"
          onsubmit="return confirm('Replace ALL current data with this backup?');">
      <?= csrf_field() ?>
      <label for="backup">Backup file (.sql made by this application)</label>
      <input type="file" id="backup" name="backup" accept=".sql" required>
      <label for="confirm">Type RESTORE to confirm</label>
      <input type="text" id="confirm" name="confirm" autocomplete="off" required placeholder="RESTORE">
      <p><button type="submit" class="btn btn-light text-red">Restore backup</button></p>
    </form>
  </div>
</div>

<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Table</th><th class="right">Records</th></tr></thead>
    <tbody>
    <?php foreach ($tables as $t): ?>
      <tr><td><?= e($t['name']) ?></td><td class="right"><?= e(number_format($t['rows'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($tablesTotal, $tablesPage, PER_PAGE, 'backup tables'); ?>
<footer class="orders__footer">
  <p class="muted small">Passwords are stored as secure hashes inside the backup. Keep backup files private.</p>
  <p class="orders__results" aria-live="polite">Tables: <?= $tablesTotal ?></p>
</footer>
