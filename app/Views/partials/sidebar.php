<?php
// Menu items: label, route, allowed roles (owner always allowed), built yet?, icon
$menu = [
    ['Dashboard',        'dashboard',   ['manager','cashier','stock_manager'], true,  'home'],
    ['Point of Sale',    'pos',         ['manager','cashier'],                 true,  'payments'],
    ['Sales History',    'sale',       ['manager','cashier'],                 true,  'orders'],
    ['Floor & Tables',   'table',      ['manager','cashier'],                 true,  'home'],
    ['Customers & Debts','customer',   ['manager','cashier'],                 true,  'customers'],
    ['Products',         'product',    ['manager','stock_manager'],           true,  'products'],
    ['Categories',       'category',  ['manager','stock_manager'],           true,  'categories'],
    ['Suppliers',        'supplier',   ['manager','stock_manager'],           true,  'suppliers'],
    ['Purchases',        'purchase',   ['manager','stock_manager'],           true,  'purchases'],
    ['Stock Control',    'stock',       ['manager','stock_manager'],           true,  'stock'],
    ['Expenses',         'expense',    ['manager'],                           true,  'expenses'],
    ['Cash Management',  'cash',        ['manager','cashier'],                 true,  'cash'],
    ['Reports',          'report',     ['manager','stock_manager'],           true,  'reports'],
    ['Settings',         'settings',    ['manager'],                           true,  'sliders'],
    ['Users',            'user',       [],                                    true,  'customers'],
    ['Audit Log',         'user/audit', [],                                    true,  'audit'],
    ['Backup & Restore', 'backup',      [],                                    true,  'sliders'],
];
?>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
  <div class="sidebar__logo">
    <svg viewBox="0 0 36 36" aria-label="Company logo" role="img">
      <circle cx="18" cy="18" r="12" fill="none" stroke="#FFB347" stroke-width="8" stroke-dasharray="45 75.4" transform="rotate(100 18 18)"/>
      <circle cx="18" cy="18" r="12" fill="none" stroke="#FF9A3C" stroke-width="8" stroke-dasharray="17 75.4" stroke-dashoffset="-45" transform="rotate(100 18 18)"/>
      <circle cx="18" cy="18" r="12" fill="none" stroke="#F2706B" stroke-width="8" stroke-dasharray="11 75.4" stroke-dashoffset="-62" transform="rotate(100 18 18)"/>
    </svg>
    <span class="sidebar__brand">Umucyo Bar</span>
  </div>

  <nav>
    <ul class="sidebar__nav">
      <?php foreach ($menu as $item): ?>
        <?php if (!has_role($item[2])) continue; ?>
        <?php $isActive = (($active ?? '') === $item[1]); ?>
        <li>
          <?php if ($item[3]): ?>
            <a href="<?= e(url($item[1])) ?>" class="sidebar__item<?= $isActive ? ' sidebar__item--active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?> title="<?= e($item[0]) ?>">
              <svg class="icon"><use href="#i-<?= e($item[4]) ?>"/></svg><span class="sidebar__label"><?= e($item[0]) ?></span>
            </a>
          <?php else: ?>
            <span class="sidebar__item sidebar__item--disabled" title="<?= e($item[0]) ?> (soon)">
              <svg class="icon"><use href="#i-<?= e($item[4]) ?>"/></svg><span class="sidebar__label"><?= e($item[0]) ?></span><small class="sidebar__soon">soon</small>
            </span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <ul class="sidebar__bottom">
    <li>
      <form method="post" action="<?= e(url('auth/logout')) ?>" class="sidebar__form">
        <?= csrf_field() ?>
        <button type="submit" class="sidebar__item" title="Log out">
          <svg class="icon"><use href="#i-logout"/></svg><span class="sidebar__label">Log out</span>
        </button>
      </form>
    </li>
  </ul>
</aside>
