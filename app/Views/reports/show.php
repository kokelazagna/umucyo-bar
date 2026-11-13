<?php
// $report = the report array (see models/reports.php), $info = its catalog entry,
// $slug, $from, $to, $period, $by
if (!function_exists('report_cell')) {
    // Formats one table cell by its column type (escaped, ready to print).
    function report_cell($value, string $type): string
    {
        if ($value === '' || $value === null) {
            return '';
        }
        switch ($type) {
            case 'money':
            case 'int':  return e(number_format((float)$value, 0));
            case 'pct':  return e(number_format((float)$value, 1)) . '%';
            default:     return e($value);
        }
    }
}
$numeric = ['money', 'int', 'pct'];
$query   = 'r=' . urlencode($slug) . '&from=' . urlencode($from) . '&to=' . urlencode($to);
$colors  = ['orange', 'coral', 'primary', 'dark'];
?>
<style media="print">@page { size: A4 landscape; margin: 10mm; }</style>

<div class="orders__filters no-print">
  <?php if ($info['kind'] === 'range'): ?>
    <form method="get" action="<?= e(url('report/show')) ?>" class="filters">
      <input type="hidden" name="r" value="<?= e($slug) ?>">
      <label class="check">From <input type="date" name="from" value="<?= e($from) ?>"></label>
      <label class="check">To <input type="date" name="to" value="<?= e($to) ?>"></label>
      <button type="submit" class="btn">Show report</button>
    </form>
  <?php else: ?>
    <p class="muted small">Current snapshot, taken now.</p>
  <?php endif; ?>
  <div class="orders__actions">
    <button type="button" class="btn btn-light" onclick="window.print()">Print / Save as PDF</button>
    <a class="btn btn-light" href="<?= e(url('report/show?' . $query . '&export=xlsx')) ?>">Excel (.xlsx)</a>
    <a class="btn btn-light" href="<?= e(url('report/show?' . $query . '&export=csv')) ?>">CSV</a>
    <a class="btn btn-light" href="<?= e(url('report')) ?>">All reports</a>
  </div>
</div>

<div class="report-head">
  <h2 class="print-only"><?= e($report['title']) ?></h2>
  Umucyo Bar, Kicukiro, Kigali &middot; <?= e($period) ?> &middot; generated <?= e(date('Y-m-d H:i')) ?> by <?= e($by) ?>
</div>

<?php if ($report['summary']): ?>
  <div class="stats report-stats">
    <?php foreach ($report['summary'] as $i => $s): ?>
      <div class="stat">
        <span class="stat__icon stat__icon--<?= $colors[$i % 4] ?>"><svg class="icon"><use href="#i-reports"/></svg></span>
        <span>
          <span class="stat__label"><?= e($s[0]) ?></span>
          <strong class="stat__value"><?= report_cell($s[1], $s[2]) ?><?= $s[2] === 'money' ? ' <small>RWF</small>' : '' ?></strong>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <?php foreach ($report['columns'] as $c): ?>
          <th class="<?= in_array($c['type'], $numeric, true) ? 'right' : '' ?>"><?= e($c['label']) ?><?= $c['type'] === 'money' ? ' (RWF)' : '' ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($report['rows'] as $row): ?>
        <tr class="<?= !empty($row['_bold']) ? 'bold' : '' ?>">
          <?php foreach ($report['columns'] as $c): ?>
            <td class="<?= in_array($c['type'], $numeric, true) ? 'right' : '' ?>"><?= report_cell($row[$c['key']] ?? '', $c['type']) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$report['rows']): ?>
        <tr><td colspan="<?= count($report['columns']) ?>" class="muted">No data for this report and period.</td></tr>
      <?php endif; ?>
    </tbody>
    <?php if ($report['totals'] && $report['rows']): ?>
      <tfoot>
        <tr class="bold">
          <?php foreach ($report['columns'] as $c): ?>
            <td class="<?= in_array($c['type'], $numeric, true) ? 'right' : '' ?>"><?= report_cell($report['totals'][$c['key']] ?? '', $c['type']) ?></td>
          <?php endforeach; ?>
        </tr>
      </tfoot>
    <?php endif; ?>
  </table>
</div>
<?php render_pagination($rowsTotal ?? count($report['rows']), $rowsPage ?? 1, PER_PAGE, 'report rows'); ?>

<footer class="orders__footer">
  <div>
    <?php foreach ($report['notes'] as $note): ?><p class="muted small"><?= e($note) ?></p><?php endforeach; ?>
  </div>
  <p class="orders__results" aria-live="polite">Results: <?= count($report['rows']) ?></p>
</footer>
