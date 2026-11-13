<?php
// Page header: title + date on the left, signed-in user chip on the right.
$me = current_user();
$name = $me['full_name'] ?? '';
$parts = preg_split('/\s+/', trim($name)) ?: [];
$initials = '';
foreach (array_slice($parts, 0, 2) as $p) { $initials .= strtoupper(substr($p, 0, 1)); }
?>
<header class="orders__header">
  <div>
    <h1 class="orders__title"><?= e($title ?? '') ?></h1>
    <p class="orders__subtitle"><?= e(date('l')) ?> &nbsp;|&nbsp; <?= e(date('j F Y')) ?></p>
  </div>
  <div class="orders__tools">
    <?php if (($active ?? '') === 'pos' && !empty($session['id'])): ?>
      <div class="sessionchip">Cash session #<?= (int)$session['id'] ?> &middot; opened <?= e(date('d M, H:i', strtotime((string)$session['opened_at']))) ?></div>
    <?php endif; ?>
    <div class="userchip">
      <span class="avatar avatar--fallback" data-initials="<?= e($initials ?: '?') ?>"></span>
      <span class="userchip__name"><?= e($name) ?></span>
    </div>
  </div>
</header>
