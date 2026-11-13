<?php
// $groups = reports the user may open, grouped:  ['Sales' => ['daily_sales' => [...], ...], ...]
$colors = ['orange', 'coral', 'primary', 'dark'];
?>
<p class="dash__welcome">Choose a report. Every report can be filtered by date, printed or saved as PDF, and exported to Excel.</p>

<?php foreach ($groups as $group => $items): ?>
  <div class="dash__section">
    <h2 class="dash__heading"><?= e($group) ?></h2>
    <div class="report-grid">
      <?php $i = 0; foreach ($items as $slug => $r): ?>
        <a class="report-card" href="<?= e(url('report/show?r=' . $slug)) ?>">
          <span class="stat__icon stat__icon--<?= $colors[$i++ % 4] ?>"><svg class="icon"><use href="#i-reports"/></svg></span>
          <span>
            <strong><?= e($r['title']) ?></strong>
            <span class="report-card__desc"><?= e($r['desc']) ?></span>
            <span class="report-card__kind"><?= $r['kind'] === 'range' ? 'Date range' : 'Current snapshot' ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
