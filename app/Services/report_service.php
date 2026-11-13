<?php
// ============================================================================
// The 15 business reports (business logic only: catalog, assembly, export).
// Every query lives in report_model.
//
// Every report returns the SAME array shape, so one page, one print layout and one
// Excel / CSV export can serve all of them:
//   [ 'title'   => 'Gross profit report',
//     'columns' => [ ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ... ],
//     'rows'    => [ ['date' => '2026-11-08', ...], ... ],
//     'totals'  => [ 'date' => 'TOTAL', ... ]  or null,
//     'summary' => [ ['Revenue', 1000, 'money'], ... ],   // the cards above the table
//     'notes'   => [ 'text shown under the table', ... ] ]
// column types: text, date, int, money (RWF), pct (percent)
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/report_model.php';

// The list of reports: slug => details.
//   kind    'range' = has a date filter,  'snapshot' = the situation right now
//   default the dates shown first: today / month / weeks / year
//   roles   who may open it (the owner can open all)
function get_report_catalog(): array
{
    $manager = ['manager'];
    $stock   = ['manager', 'stock_manager'];
    return [
        'daily_sales'      => ['title' => 'Daily sales report',      'group' => 'Sales',               'kind' => 'range',    'default' => 'today', 'roles' => $manager, 'desc' => 'Every completed sale in the period, with payments by method.'],
        'weekly_sales'     => ['title' => 'Weekly sales report',     'group' => 'Sales',               'kind' => 'range',    'default' => 'weeks', 'roles' => $manager, 'desc' => 'Sales grouped by week (Monday to Sunday).'],
        'monthly_sales'    => ['title' => 'Monthly sales report',    'group' => 'Sales',               'kind' => 'range',    'default' => 'year',  'roles' => $manager, 'desc' => 'Sales grouped by month.'],
        'product_sales'    => ['title' => 'Product sales report',    'group' => 'Sales',               'kind' => 'range',    'default' => 'month', 'roles' => $manager, 'desc' => 'Quantity, revenue, cost and profit for each product.'],
        'purchases'        => ['title' => 'Purchase report',         'group' => 'Purchases and stock', 'kind' => 'range',    'default' => 'month', 'roles' => $stock,   'desc' => 'Purchases from suppliers with payments and balances.'],
        'stock_balance'    => ['title' => 'Stock balance report',    'group' => 'Purchases and stock', 'kind' => 'snapshot', 'default' => 'today', 'roles' => $stock,   'desc' => 'Current stock of every product, valued at average cost.'],
        'low_stock'        => ['title' => 'Low-stock report',        'group' => 'Purchases and stock', 'kind' => 'snapshot', 'default' => 'today', 'roles' => $stock,   'desc' => 'Products at or below their minimum stock level.'],
        'expired'          => ['title' => 'Expired-product report',  'group' => 'Purchases and stock', 'kind' => 'snapshot', 'default' => 'today', 'roles' => $stock,   'desc' => 'Products that expired or expire within 30 days.'],
        'damaged'          => ['title' => 'Damaged-stock report',    'group' => 'Purchases and stock', 'kind' => 'range',    'default' => 'month', 'roles' => $stock,   'desc' => 'Damaged, expired and missing stock written off.'],
        'supplier_balance' => ['title' => 'Supplier balance report', 'group' => 'Purchases and stock', 'kind' => 'snapshot', 'default' => 'today', 'roles' => $stock,   'desc' => 'What the business still owes each supplier.'],
        'customer_debt'    => ['title' => 'Customer debt report',    'group' => 'Money',               'kind' => 'snapshot', 'default' => 'today', 'roles' => $manager, 'desc' => 'Customers who owe money, with credit limits.'],
        'daily_expense'    => ['title' => 'Daily expense report',    'group' => 'Money',               'kind' => 'range',    'default' => 'today', 'roles' => $manager, 'desc' => 'Operating expenses in the period, by category.'],
        'gross_profit'     => ['title' => 'Gross profit report',     'group' => 'Money',               'kind' => 'range',    'default' => 'month', 'roles' => $manager, 'desc' => 'Revenue minus the actual cost of the goods sold.'],
        'net_profit'       => ['title' => 'Net profit report',       'group' => 'Money',               'kind' => 'range',    'default' => 'month', 'roles' => $manager, 'desc' => 'Gross profit minus operating expenses (owner withdrawals excluded).'],
        'cash_reconciliation' => ['title' => 'Cash reconciliation report', 'group' => 'Money',         'kind' => 'range',    'default' => 'today', 'roles' => $manager, 'desc' => 'Cash sessions: expected cash, counted cash and variance.'],
    ];
}

// Builds the report with this slug for the period $from .. $to (YYYY-MM-DD).
function run_report(string $slug, string $from, string $to, $conn): array
{
    switch ($slug) {
        case 'daily_sales':         return build_daily_sales_report($from, $to, $conn);
        case 'weekly_sales':        return build_grouped_sales_report($from, $to, 'week', $conn);
        case 'monthly_sales':       return build_grouped_sales_report($from, $to, 'month', $conn);
        case 'product_sales':       return build_product_sales_report($from, $to, $conn);
        case 'purchases':           return build_purchases_report($from, $to, $conn);
        case 'stock_balance':       return build_stock_balance_report($conn);
        case 'low_stock':           return build_low_stock_report($conn);
        case 'expired':             return build_expired_report($conn);
        case 'damaged':             return build_damaged_report($from, $to, $conn);
        case 'supplier_balance':    return build_supplier_balance_report($conn);
        case 'customer_debt':       return build_customer_debt_report($conn);
        case 'daily_expense':       return build_daily_expense_report($from, $to, $conn);
        case 'gross_profit':        return build_gross_profit_report($from, $to, $conn);
        case 'net_profit':          return build_net_profit_report($from, $to, $conn);
        case 'cash_reconciliation': return build_cash_reconciliation_report($from, $to, $conn);
    }
    throw new RuntimeException('Unknown report.');
}

// ---------- small helpers shared by the reports ----------

function make_report(string $title, array $columns, array $rows, ?array $totals = null, array $summary = [], array $notes = []): array
{
    return ['title' => $title, 'columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'summary' => $summary, 'notes' => $notes];
}

function make_report_column(string $key, string $label, string $type = 'text'): array
{
    return ['key' => $key, 'label' => $label, 'type' => $type];
}

function sum_report_rows(array $rows, string $key): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float)$row[$key];
    }
    return $total;
}

// 25 of 100 -> 25.0 (0 when $whole is 0)
function percent_of(float $part, float $whole): float
{
    return $whole > 0.0 ? round($part / $whole * 100, 1) : 0.0;
}

// ['2026-11-08 00:00:00', '2026-11-08 23:59:59']
function report_day_bounds(string $from, string $to): array
{
    return [$from . ' 00:00:00', $to . ' 23:59:59'];
}

// ---------- 1 to 3: sales ----------

function build_daily_sales_report(string $from, string $to, $conn): array
{
    list($a, $b) = report_day_bounds($from, $to);
    $rows = query_daily_sales($a, $b, $conn)['data'];

    $totals = [
        'receipt' => 'TOTAL', 'time' => '', 'customer' => '', 'cashier' => '',
        'subtotal' => sum_report_rows($rows, 'subtotal'), 'discount' => sum_report_rows($rows, 'discount'),
        'total' => sum_report_rows($rows, 'total'), 'paid' => sum_report_rows($rows, 'paid'), 'credit' => sum_report_rows($rows, 'credit'),
    ];

    $byMethod = ['cash' => 0.0, 'mobile_money' => 0.0, 'bank' => 0.0];
    foreach (query_payments_by_method($a, $b, $conn)['data'] as $p) {
        $byMethod[$p['method']] = (float)$p['amount'];
    }
    $cancelled = (int)(query_count_cancelled_sales($a, $b, $conn)['data'][0]['total'] ?? 0);

    return make_report('Daily sales report', [
        make_report_column('receipt', 'Receipt'), make_report_column('time', 'Time'),
        make_report_column('customer', 'Customer'), make_report_column('cashier', 'Sold by'),
        make_report_column('subtotal', 'Subtotal', 'money'), make_report_column('discount', 'Discount', 'money'),
        make_report_column('total', 'Total', 'money'), make_report_column('paid', 'Paid now', 'money'),
        make_report_column('credit', 'On credit', 'money'),
    ], $rows, $totals, [
        ['Number of sales', count($rows), 'int'],
        ['Sales revenue', $totals['total'], 'money'],
        ['Collected in cash', $byMethod['cash'], 'money'],
        ['Collected by Mobile Money', $byMethod['mobile_money'], 'money'],
        ['Collected by bank', $byMethod['bank'], 'money'],
        ['Sold on credit (not cash received)', $totals['credit'], 'money'],
    ], ['Cancelled sales are not included (' . $cancelled . ' cancelled in this period).']);
}

// $mode: 'week' or 'month'
function build_grouped_sales_report(string $from, string $to, string $mode, $conn): array
{
    list($a, $b) = report_day_bounds($from, $to);
    if ($mode === 'week') {
        $groupBy = 'DATE_SUB(DATE(sale_date), INTERVAL WEEKDAY(sale_date) DAY)';
        $label   = 'Week starting (Monday)';
        $title   = 'Weekly sales report';
    } else {
        $groupBy = "DATE_FORMAT(sale_date, '%Y-%m')";
        $label   = 'Month';
        $title   = 'Monthly sales report';
    }
    $rows = query_grouped_sales($groupBy, $a, $b, $conn)['data'];
    $totals = [
        'period' => 'TOTAL', 'sales' => sum_report_rows($rows, 'sales'), 'subtotal' => sum_report_rows($rows, 'subtotal'),
        'discount' => sum_report_rows($rows, 'discount'), 'revenue' => sum_report_rows($rows, 'revenue'),
        'credit' => sum_report_rows($rows, 'credit'),
    ];
    return make_report($title, [
        make_report_column('period', $label), make_report_column('sales', 'Sales', 'int'),
        make_report_column('subtotal', 'Subtotal', 'money'), make_report_column('discount', 'Discounts', 'money'),
        make_report_column('revenue', 'Revenue', 'money'), make_report_column('credit', 'On credit', 'money'),
    ], $rows, $totals, [
        ['Sales revenue', $totals['revenue'], 'money'], ['Number of sales', $totals['sales'], 'int'],
    ], ['Cancelled sales are not included.']);
}

// ---------- 4: product sales ----------

function build_product_sales_report(string $from, string $to, $conn): array
{
    list($a, $b) = report_day_bounds($from, $to);
    $rows = query_product_sales($a, $b, $conn)['data'];
    foreach ($rows as $i => $row) {
        $rows[$i]['margin'] = percent_of((float)$row['profit'], (float)$row['revenue']);
    }
    $revenue = sum_report_rows($rows, 'revenue');
    $profit  = sum_report_rows($rows, 'profit');
    $totals  = [
        'code' => 'TOTAL', 'product' => '', 'qty' => '', 'revenue' => $revenue, 'cost' => sum_report_rows($rows, 'cost'),
        'profit' => $profit, 'margin' => percent_of($profit, $revenue),
    ];
    return make_report('Product sales report', [
        make_report_column('code', 'Code'), make_report_column('product', 'Product'),
        make_report_column('qty', 'Quantity sold (base units)', 'int'), make_report_column('revenue', 'Revenue', 'money'),
        make_report_column('cost', 'Cost of goods', 'money'), make_report_column('profit', 'Gross profit', 'money'),
        make_report_column('margin', 'Margin', 'pct'),
    ], $rows, $totals, [
        ['Products sold', count($rows), 'int'], ['Revenue', $revenue, 'money'], ['Gross profit', $profit, 'money'],
    ], ['Revenue here is before sale-level discounts. Cost is the weighted average cost at the moment of each sale.']);
}

// ---------- 5 to 9 and 11: purchases and stock ----------

function build_purchases_report(string $from, string $to, $conn): array
{
    $rows = query_purchases($from, $to, $conn)['data'];
    $totals = [
        'ref' => 'TOTAL', 'date' => '', 'supplier' => '', 'status' => '', 'total' => sum_report_rows($rows, 'total'),
        'paid' => sum_report_rows($rows, 'paid'), 'balance' => sum_report_rows($rows, 'balance'),
    ];
    return make_report('Purchase report', [
        make_report_column('ref', 'Reference'), make_report_column('date', 'Date', 'date'),
        make_report_column('supplier', 'Supplier'), make_report_column('status', 'Status'),
        make_report_column('total', 'Total cost', 'money'), make_report_column('paid', 'Paid', 'money'),
        make_report_column('balance', 'Balance owed', 'money'),
    ], $rows, $totals, [
        ['Purchases', count($rows), 'int'], ['Total cost', $totals['total'], 'money'],
        ['Still owed to suppliers', $totals['balance'], 'money'],
    ], ['Cancelled purchases are not included. Only confirmed purchases have added stock.']);
}

function build_stock_balance_report($conn): array
{
    $rows = query_stock_balance($conn)['data'];
    $value  = sum_report_rows($rows, 'value');
    $retail = sum_report_rows($rows, 'retail');
    $totals = [
        'code' => 'TOTAL', 'product' => '', 'category' => '', 'unit' => '', 'qty' => '', 'avg_cost' => '',
        'value' => $value, 'price' => '', 'retail' => $retail, 'status' => '',
    ];
    return make_report('Stock balance report', [
        make_report_column('code', 'Code'), make_report_column('product', 'Product'),
        make_report_column('category', 'Category'), make_report_column('unit', 'Unit'),
        make_report_column('qty', 'In stock', 'int'), make_report_column('avg_cost', 'Avg cost', 'money'),
        make_report_column('value', 'Stock value (cost)', 'money'), make_report_column('price', 'Selling price', 'money'),
        make_report_column('retail', 'Value at selling price', 'money'), make_report_column('status', 'Status'),
    ], $rows, $totals, [
        ['Products', count($rows), 'int'], ['Stock value at cost', $value, 'money'],
        ['Value at selling price', $retail, 'money'], ['Potential gross profit', $retail - $value, 'money'],
    ], ['Stock is counted in the base unit (bottle or can). Value uses the weighted average cost.']);
}

function build_low_stock_report($conn): array
{
    $rows = query_low_stock($conn)['data'];
    return make_report('Low-stock report', [
        make_report_column('code', 'Code'), make_report_column('product', 'Product'),
        make_report_column('category', 'Category'), make_report_column('unit', 'Unit'),
        make_report_column('qty', 'In stock', 'int'), make_report_column('min_stock', 'Minimum level', 'int'),
        make_report_column('shortfall', 'Short by', 'int'),
    ], $rows, null, [['Products to reorder', count($rows), 'int']], ['Active products at or below their minimum stock level.']);
}

function build_expired_report($conn): array
{
    $rows = query_expired($conn)['data'];
    foreach ($rows as $i => $row) {
        $days = (int)$row['days'];
        $rows[$i]['state'] = $days < 0 ? 'EXPIRED ' . abs($days) . ' day(s) ago'
                           : ($days === 0 ? 'Expires today' : 'Expires in ' . $days . ' day(s)');
    }
    $value  = sum_report_rows($rows, 'value');
    $totals = ['code' => 'TOTAL', 'product' => '', 'expiry' => '', 'state' => '', 'qty' => '', 'unit' => '', 'value' => $value];
    return make_report('Expired-product report', [
        make_report_column('code', 'Code'), make_report_column('product', 'Product'),
        make_report_column('expiry', 'Expiry date', 'date'), make_report_column('state', 'State'),
        make_report_column('qty', 'In stock', 'int'), make_report_column('unit', 'Unit'),
        make_report_column('value', 'Value at cost', 'money'),
    ], $rows, $totals, [['Products listed', count($rows), 'int'], ['Stock value at risk', $value, 'money']],
        ['Lists products with stock that expired or expire within 30 days. Record expired stock with a Stock Adjustment.']);
}

function build_damaged_report(string $from, string $to, $conn): array
{
    list($a, $b) = report_day_bounds($from, $to);
    $rows = query_damaged($a, $b, $conn)['data'];
    $value  = sum_report_rows($rows, 'value');
    $totals = [
        'date' => 'TOTAL', 'code' => '', 'product' => '', 'type' => '', 'qty' => sum_report_rows($rows, 'qty'),
        'value' => $value, 'reason' => '', 'user' => '',
    ];
    return make_report('Damaged-stock report', [
        make_report_column('date', 'Date'), make_report_column('code', 'Code'), make_report_column('product', 'Product'),
        make_report_column('type', 'Type'), make_report_column('qty', 'Quantity', 'int'),
        make_report_column('value', 'Loss at cost', 'money'), make_report_column('reason', 'Reason'),
        make_report_column('user', 'Responsible user'),
    ], $rows, $totals, [['Adjustments', count($rows), 'int'], ['Total loss at cost', $value, 'money']],
        ['Damaged, expired and missing stock written off in the period.']);
}

function build_supplier_balance_report($conn): array
{
    $rows = query_supplier_balances($conn)['data'];
    $totals = [
        'code' => 'TOTAL', 'supplier' => '', 'phone' => '', 'purchases' => sum_report_rows($rows, 'purchases'),
        'total' => sum_report_rows($rows, 'total'), 'paid' => sum_report_rows($rows, 'paid'),
        'balance' => sum_report_rows($rows, 'balance'),
    ];
    return make_report('Supplier balance report', [
        make_report_column('code', 'Code'), make_report_column('supplier', 'Supplier'), make_report_column('phone', 'Phone'),
        make_report_column('purchases', 'Purchases', 'int'), make_report_column('total', 'Total purchased', 'money'),
        make_report_column('paid', 'Paid', 'money'), make_report_column('balance', 'Outstanding balance', 'money'),
    ], $rows, $totals, [['Suppliers owed', count($rows), 'int'], ['Total outstanding', $totals['balance'], 'money']],
        ['Outstanding balance = total purchase cost minus amount paid (cancelled purchases excluded).']);
}

// ---------- 10: customers ----------

function build_customer_debt_report($conn): array
{
    $rows = query_customer_debts($conn)['data'];
    foreach ($rows as $i => $row) {
        $over = ((float)$row['credit_limit'] > 0 && (float)$row['debt'] > (float)$row['credit_limit']);
        $rows[$i]['flag'] = $over ? 'OVER LIMIT' : '';
    }
    $debt   = sum_report_rows($rows, 'debt');
    $totals = [
        'code' => 'TOTAL', 'customer' => '', 'phone' => '', 'credit_limit' => '', 'debt' => $debt,
        'oldest' => '', 'flag' => '', 'status' => '',
    ];
    return make_report('Customer debt report', [
        make_report_column('code', 'Code'), make_report_column('customer', 'Customer'), make_report_column('phone', 'Phone'),
        make_report_column('credit_limit', 'Credit limit', 'money'), make_report_column('debt', 'Debt', 'money'),
        make_report_column('oldest', 'Oldest unpaid debt'), make_report_column('flag', 'Warning'), make_report_column('status', 'Status'),
    ], $rows, $totals, [['Customers with debt', count($rows), 'int'], ['Total outstanding debt', $debt, 'money']],
        ['Credit sales are tracked separately from cash received.']);
}

// ---------- 12 to 14: money ----------

function build_daily_expense_report(string $from, string $to, $conn): array
{
    $rows = query_expenses($from, $to, $conn)['data'];
    foreach ($rows as $i => $row) {
        $rows[$i]['method'] = str_replace('_', ' ', $row['method']);
    }
    $total   = sum_report_rows($rows, 'amount');
    $totals  = ['code' => 'TOTAL', 'date' => '', 'category' => '', 'description' => '', 'method' => '', 'user' => '', 'amount' => $total];
    $summary = [['Total expenses', $total, 'money']];
    foreach (query_expenses_by_category($from, $to, $conn)['data'] as $c) {
        $summary[] = [$c['name'], (float)$c['amt'], 'money'];
    }
    return make_report('Daily expense report', [
        make_report_column('code', 'ID'), make_report_column('date', 'Date', 'date'), make_report_column('category', 'Category'),
        make_report_column('description', 'Description'), make_report_column('method', 'Method'),
        make_report_column('user', 'Recorded by'), make_report_column('amount', 'Amount', 'money'),
    ], $rows, $totals, $summary, ['Owner withdrawals are not business expenses and are not listed here.']);
}

// Revenue (after discounts) and cost of goods sold, one entry per day:
//   ['2026-11-08' => ['sales' => 3, 'revenue' => 1000.0, 'cogs' => 600.0], ...]
function get_revenue_and_cost_by_day(string $from, string $to, $conn): array
{
    list($a, $b) = report_day_bounds($from, $to);
    $days = [];
    foreach (query_revenue_by_day($a, $b, $conn)['data'] as $r) {
        $days[$r['d']] = ['sales' => (int)$r['sales'], 'revenue' => (float)$r['revenue'], 'cogs' => 0.0];
    }
    foreach (query_cost_by_day($a, $b, $conn)['data'] as $r) {
        if (isset($days[$r['d']])) {
            $days[$r['d']]['cogs'] = (float)$r['cogs'];
        }
    }
    ksort($days);
    return $days;
}

function build_gross_profit_report(string $from, string $to, $conn): array
{
    $days = get_revenue_and_cost_by_day($from, $to, $conn);
    $rows = [];
    $sales = 0;
    $revenue = 0.0;
    $cogs = 0.0;
    foreach ($days as $date => $v) {
        $profit = $v['revenue'] - $v['cogs'];
        $rows[] = ['date' => $date, 'sales' => $v['sales'], 'revenue' => $v['revenue'], 'cogs' => $v['cogs'],
                   'gp' => $profit, 'margin' => percent_of($profit, $v['revenue'])];
        $sales   += $v['sales'];
        $revenue += $v['revenue'];
        $cogs    += $v['cogs'];
    }
    $profit = $revenue - $cogs;
    $totals = ['date' => 'TOTAL', 'sales' => $sales, 'revenue' => $revenue, 'cogs' => $cogs, 'gp' => $profit,
               'margin' => percent_of($profit, $revenue)];
    return make_report('Gross profit report', [
        make_report_column('date', 'Date', 'date'), make_report_column('sales', 'Sales', 'int'),
        make_report_column('revenue', 'Revenue (after discounts)', 'money'), make_report_column('cogs', 'Cost of goods sold', 'money'),
        make_report_column('gp', 'Gross profit', 'money'), make_report_column('margin', 'Margin', 'pct'),
    ], $rows, $totals, [
        ['Revenue', $revenue, 'money'], ['Cost of goods sold', $cogs, 'money'],
        ['Gross profit', $profit, 'money'], ['Margin', $totals['margin'], 'pct'],
    ], ['Cost of goods sold uses the actual weighted average cost saved on each sale, not the selling price.']);
}

function build_net_profit_report(string $from, string $to, $conn): array
{
    list($a, $b) = report_day_bounds($from, $to);
    $revenue = 0.0;
    $cogs = 0.0;
    foreach (get_revenue_and_cost_by_day($from, $to, $conn) as $v) {
        $revenue += $v['revenue'];
        $cogs    += $v['cogs'];
    }
    $gross = $revenue - $cogs;

    $expenses = query_expenses_by_category($from, $to, $conn)['data'];
    $expenseTotal = sum_report_rows($expenses, 'amt');
    $net = $gross - $expenseTotal;

    $rows = [
        ['line' => 'Sales revenue (after discounts)',        'amount' => $revenue],
        ['line' => 'Less: cost of goods sold (actual cost)', 'amount' => $cogs],
        ['line' => 'GROSS PROFIT',                           'amount' => $gross, '_bold' => true],
    ];
    foreach ($expenses as $x) {
        $rows[] = ['line' => 'Less: ' . $x['name'], 'amount' => (float)$x['amt']];
    }
    $rows[] = ['line' => 'Total operating expenses', 'amount' => $expenseTotal, '_bold' => true];
    $rows[] = ['line' => 'NET PROFIT',               'amount' => $net,          '_bold' => true];

    $writeOff = query_stock_write_off($a, $b, $conn);

    return make_report('Net profit report', [
        make_report_column('line', 'Description'), make_report_column('amount', 'Amount (RWF)', 'money'),
    ], $rows, null, [
        ['Gross profit', $gross, 'money'], ['Operating expenses', $expenseTotal, 'money'],
        ['Net profit', $net, 'money'], ['Net margin', percent_of($net, $revenue), 'pct'],
    ], [
        'Net profit = gross profit minus operating expenses. Owner withdrawals are NOT expenses and are not deducted.',
        'For information: stock written off in this period (damaged, expired, missing) was ' . number_format($writeOff, 0) . ' RWF at cost; it is not deducted above.',
    ]);
}

function build_cash_reconciliation_report(string $from, string $to, $conn): array
{
    list($a, $b) = report_day_bounds($from, $to);
    $rows = query_cash_reconciliation($a, $b, $conn)['data'];
    $variance = 0.0;
    foreach ($rows as $i => $row) {
        $expected = (float)$row['opening'] + (float)$row['sales'] + (float)$row['debts'] + (float)$row['deposits']
                  - (float)$row['suppliers'] - (float)$row['expenses'] - (float)$row['withdrawals'] - (float)$row['refunds'];
        $rows[$i]['expected'] = $expected;
        $rows[$i]['session']  = '#' . $row['id'];
        if ($row['status'] === 'closed' && $row['counted'] !== null) {
            $rows[$i]['variance'] = (float)$row['counted'] - $expected;
            $variance += $rows[$i]['variance'];
        } else {
            $rows[$i]['variance'] = '';
            $rows[$i]['counted']  = '';
        }
    }
    return make_report('Cash reconciliation report', [
        make_report_column('session', 'Session'), make_report_column('opened', 'Opened'),
        make_report_column('opening', 'Opening cash', 'money'), make_report_column('sales', '+ Cash sales', 'money'),
        make_report_column('debts', '+ Debt payments', 'money'), make_report_column('deposits', '+ Deposits', 'money'),
        make_report_column('suppliers', '- Suppliers', 'money'), make_report_column('expenses', '- Expenses', 'money'),
        make_report_column('withdrawals', '- Owner withdrawals', 'money'), make_report_column('refunds', '- Refunds', 'money'),
        make_report_column('expected', 'Expected cash', 'money'), make_report_column('counted', 'Counted cash', 'money'),
        make_report_column('variance', 'Variance', 'money'), make_report_column('status', 'Status'),
    ], $rows, null, [['Cash sessions', count($rows), 'int'], ['Total variance (closed sessions)', $variance, 'money']], [
        'Expected cash = opening + cash sales + debt payments + deposits - supplier payments - expenses - withdrawals - refunds.',
        'Credit sales, Mobile Money and bank payments are not counted in the drawer.',
    ]);
}

// ---------- export (Excel / CSV) ----------

// Turns a report into plain rows for Excel / CSV. Each row = [cells, style]
// where cells = list of ['v' => value, 't' => type] and style = '' | 'bold' | 'header' | 'title'
function report_to_matrix(array $report, string $period, string $by): array
{
    $matrix   = [];
    $matrix[] = [[['v' => $report['title'], 't' => 'text']], 'title'];
    $matrix[] = [[['v' => 'Umucyo Bar - ' . $period . ' - generated by ' . $by . ' on ' . date('Y-m-d H:i'), 't' => 'text']], ''];
    $matrix[] = [[], ''];

    foreach ($report['summary'] as $s) {
        $matrix[] = [[['v' => $s[0], 't' => 'text'], ['v' => $s[1], 't' => $s[2]]], 'bold'];
    }
    if ($report['summary']) {
        $matrix[] = [[], ''];
    }

    $head = [];
    foreach ($report['columns'] as $c) {
        $head[] = ['v' => $c['label'], 't' => 'text'];
    }
    $matrix[] = [$head, 'header'];

    foreach ($report['rows'] as $row) {
        $cells = [];
        foreach ($report['columns'] as $c) {
            $cells[] = ['v' => $row[$c['key']] ?? '', 't' => $c['type']];
        }
        $matrix[] = [$cells, !empty($row['_bold']) ? 'bold' : ''];
    }
    if ($report['totals']) {
        $cells = [];
        foreach ($report['columns'] as $c) {
            $cells[] = ['v' => $report['totals'][$c['key']] ?? '', 't' => $c['type']];
        }
        $matrix[] = [$cells, 'bold'];
    }
    if ($report['notes']) {
        $matrix[] = [[], ''];
        foreach ($report['notes'] as $note) {
            $matrix[] = [[['v' => $note, 't' => 'text']], ''];
        }
    }
    return $matrix;
}
