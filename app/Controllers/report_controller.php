<?php
// ============================================================================
// Reports (manager / owner; the stock reports are also open to the stock manager).
// ============================================================================

require_once __DIR__ . '/../services/report_service.php';
require_once __DIR__ . '/../services/audit_service.php';
require_once __DIR__ . '/../helpers/xlsx_helper.php';

function handleReportRequest($action, $data, $conn) {
    require_role(['manager', 'stock_manager']);
    switch ($action) {
        case 'index':
            return report_index();
        case 'show':
            return report_show($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

// The list of reports
function report_index(): void
{
    $groups = [];
    foreach (get_report_catalog() as $slug => $report) {
        if (has_role($report['roles'])) {
            $groups[$report['group']][$slug] = $report;
        }
    }
    render('reports/index', ['title' => 'Reports', 'active' => 'report', 'groups' => $groups]);
}

function report_show($conn): void
{
    $catalog = get_report_catalog();
    $slug    = get_text('r');
    if (!isset($catalog[$slug])) {
        flash('danger', 'Report not found.');
        redirect('report');
    }
    $info = $catalog[$slug];
    if (!has_role($info['roles'])) {
        http_response_code(403);
        echo '<h1>403 - You do not have permission to open this report</h1>';
        return;
    }

    // dates: the ones typed in the filter, or the default period of this report
    list($defaultFrom, $defaultTo) = report_default_dates($info['default']);
    $from = is_valid_date(get_text('from')) ? get_text('from') : $defaultFrom;
    $to   = is_valid_date(get_text('to'))   ? get_text('to')   : $defaultTo;
    if ($from > $to) {
        list($from, $to) = [$to, $from];
    }

    $report = run_report($slug, $from, $to, $conn);
    $period = $info['kind'] === 'range' ? ($from === $to ? $from : $from . ' to ' . $to) : 'as of ' . date('Y-m-d');
    $by     = (string)(current_user()['full_name'] ?? '');

    $export = get_text('export');
    if ($export === 'xlsx' || $export === 'csv') {
        audit_log($conn, current_user_id(), 'report_export', 'report', null, $slug . ' ' . $period . ' (' . $export . ')');
        report_download($report, $slug, $export, $period, $by, $from, $to);
        return;
    }

    // Paginate the display rows only (all rows are used for totals/summary/export)
    $rowsAll   = $report['rows'] ?? [];
    $rowsTotal = count($rowsAll);
    $rowsPage  = current_page();
    $rowsSlice = array_slice($rowsAll, ($rowsPage - 1) * PER_PAGE, PER_PAGE);
    $report['rows'] = $rowsSlice;

    render('reports/show', [
        'title'  => $report['title'],
        'active' => 'report',
        'report' => $report,
        'slug'   => $slug,
        'info'   => $info,
        'from'   => $from,
        'to'     => $to,
        'period' => $period,
        'by'     => $by,
        'rowsTotal'  => $rowsTotal,
        'rowsPage'   => $rowsPage,
    ]);
}

// ---------- private helpers for this file ----------

// Sends the report as a downloaded Excel or CSV file and stops.
function report_download(array $report, string $slug, string $format, string $period, string $by, string $from, string $to): void
{
    $matrix   = report_to_matrix($report, $period, $by);
    $fileName = 'bssbms_' . $slug . '_' . $from . '_' . $to;

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");               // UTF-8 marker so Excel reads accents correctly
        foreach ($matrix as $row) {
            $line = [];
            foreach ($row[0] as $cell) {
                $line[] = $cell['v'];
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    $binary = xlsx_build($matrix, $report['title']);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '.xlsx"');
    header('Content-Length: ' . strlen($binary));
    echo $binary;
    exit;
}

// The dates shown first when a report is opened.
function report_default_dates(string $kind): array
{
    $today = date('Y-m-d');
    switch ($kind) {
        case 'month': return [date('Y-m-01'), $today];
        case 'weeks': return [date('Y-m-d', strtotime('-55 days')), $today];
        case 'year':  return [date('Y-01-01'), $today];
        default:      return [$today, $today];
    }
}
