<?php
// ============================================================================
// Dashboard chart business logic. The queries live in dashboard_model.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/dashboard_model.php';

// Sales per day for the last $days days (oldest first), ready for the bar chart:
//   [ ['label' => 'Mon', 'date' => '2026-11-02', 'value' => 1200.0, 'pct' => 80], ... ]
function get_sales_last_days(int $days, $conn): array
{
    $today = get_database_today($conn);
    $from  = date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' day'));
    $byDay = [];
    foreach (get_sales_by_day($from, $today, $conn)['data'] as $r) {
        $byDay[$r['d']] = (float)$r['t'];
    }

    $chart = [];
    $max   = 0.0;
    for ($i = $days - 1; $i >= 0; $i--) {
        $date    = date('Y-m-d', strtotime($today . ' -' . $i . ' day'));
        $value   = $byDay[$date] ?? 0.0;
        $chart[] = ['label' => date('D', strtotime($date)), 'date' => $date, 'value' => $value, 'pct' => 0];
        $max     = max($max, $value);
    }
    foreach ($chart as $i => $bar) {
        $chart[$i]['pct'] = $max > 0 ? max(3, (int)round($bar['value'] / $max * 100)) : 0;
    }
    return $chart;
}
