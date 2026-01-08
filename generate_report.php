<?php
// generate_report_simple.php - Optimized and simplified version
require_once 'config.php';

// Increase limits for large datasets
set_time_limit(180); // 3 minutes
ini_set('memory_limit', '256M');

// Get parameters
$year = $_POST['year'] ?? date('Y');
$month = $_POST['month'] ?? date('n');
$report_type = $_POST['report_type'] ?? 'detailed';
$include_zero = isset($_POST['include_zero']) ? 1 : 0;

// Validate inputs
if (!is_numeric($year) || !is_numeric($month)) {
    die("Invalid year or month");
}

// Get date range
if ($month == 'all') {
    $start_date = "$year-01-01";
    $end_date = "$year-12-31";
    $period = "Year $year";
} else {
    $start_date = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
    $end_date = date("Y-m-t", strtotime($start_date));
    $month_name = date("F", strtotime($start_date));
    $period = "$month_name $year";
}

// Connect to database
$conn = getDBConnection();

// SIMPLIFIED QUERY - Step by step approach
// First, get all items with prices
$items_query = "
SELECT 
    i.id,
    i.fa_code,
    i.mat_type,
    i.prmode,
    i.description,
    COALESCE(pl.price, 0) as price
FROM items i
LEFT JOIN pricelists pl ON i.id = pl.item_id
ORDER BY i.fa_code
";

$items_result = $conn->query($items_query);
if (!$items_result) {
    die("Failed to fetch items: " . $conn->error);
}

// Pre-fetch receivings data for the period
$receivings_query = "
SELECT 
    item_id,
    SUM(beg_stock) as beg_stock,
    SUM(quantity) as received,
    SUM(other_in) as other_in,
    SUM(wip) as wip,
    SUM(other_out) as other_out
FROM receivings 
WHERE received_date BETWEEN '$start_date' AND '$end_date'
GROUP BY item_id
";

$receivings_result = $conn->query($receivings_query);
$receivings_data = [];
if ($receivings_result) {
    while ($row = $receivings_result->fetch_assoc()) {
        $receivings_data[$row['item_id']] = $row;
    }
}

// Pre-fetch beginning stocks (before start date)
$beg_stock_query = "
SELECT 
    item_id,
    SUM(beg_stock) as beg_stock
FROM receivings 
WHERE received_date < '$start_date'
GROUP BY item_id
";

$beg_stock_result = $conn->query($beg_stock_query);
$beg_stock_data = [];
if ($beg_stock_result) {
    while ($row = $beg_stock_result->fetch_assoc()) {
        $beg_stock_data[$row['item_id']] = $row['beg_stock'];
    }
}

// Pre-fetch issues for the period
$issues_query = "
SELECT 
    item_id,
    SUM(wip_issued) as issued
FROM requested_items 
WHERE created_at BETWEEN '$start_date' AND '$end_date'
GROUP BY item_id
";

$issues_result = $conn->query($issues_query);
$issues_data = [];
if ($issues_result) {
    while ($row = $issues_result->fetch_assoc()) {
        $issues_data[$row['item_id']] = $row['issued'];
    }
}

// Pre-fetch returns for the period
$returns_query = "
SELECT 
    ri.item_id,
    SUM(ri.request_qty) as returns
FROM requested_items ri
JOIN list_requesttype lrt ON ri.request_type_id = lrt.id
WHERE ri.created_at BETWEEN '$start_date' AND '$end_date'
AND (lrt.request_type LIKE '%return%' OR lrt.request_type LIKE '%Return%')
GROUP BY ri.item_id
";

$returns_result = $conn->query($returns_query);
$returns_data = [];
if ($returns_result) {
    while ($row = $returns_result->fetch_assoc()) {
        $returns_data[$row['item_id']] = $row['returns'];
    }
}

// Generate filename
$filename = "inventory_report_" . strtolower(str_replace(' ', '_', $period)) . "_" . date('Ymd_His') . ".csv";

// For Excel/CSV output
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Output BOM for UTF-8
echo "\xEF\xBB\xBF";

// Create output stream
$output = fopen('php://output', 'w');

// Write header row
if ($report_type == 'detailed') {
    $headers = [
        'fa_code', 'mat_type', 'prmode', 'description', 'price',
        'beg stock', 'received', 'other in', 'wip', 'return',
        'issued', 'other out', 'wip issued', 'end stock', 'end cost'
    ];
} else {
    $headers = [
        'fa_code', 'description', 'price',
        'beg stock', 'received', 'issued', 'end stock', 'end cost'
    ];
}

fputcsv($output, $headers);

// Process items one by one (memory efficient)
$total_items = 0;
$total_value = 0;
$processed_count = 0;

while ($item = $items_result->fetch_assoc()) {
    $item_id = $item['id'];
    
    // Get data from pre-fetched arrays
    $beg_stock = isset($beg_stock_data[$item_id]) ? floatval($beg_stock_data[$item_id]) : 0;
    
    $received = 0;
    $other_in = 0;
    $wip = 0;
    $other_out = 0;
    
    if (isset($receivings_data[$item_id])) {
        $rec = $receivings_data[$item_id];
        $received = floatval($rec['received'] ?? 0);
        $other_in = floatval($rec['other_in'] ?? 0);
        $wip = floatval($rec['wip'] ?? 0);
        $other_out = floatval($rec['other_out'] ?? 0);
    }
    
    $issued = isset($issues_data[$item_id]) ? floatval($issues_data[$item_id]) : 0;
    $returns = isset($returns_data[$item_id]) ? floatval($returns_data[$item_id]) : 0;
    $price = floatval($item['price'] ?? 0);
    
    // Skip zero items if requested
    if (!$include_zero && $beg_stock == 0 && $received == 0 && $other_in == 0 && 
        $wip == 0 && $returns == 0 && $issued == 0 && $other_out == 0) {
        continue;
    }
    
    // Calculate end stock
    if ($report_type == 'detailed') {
        $end_stock = $beg_stock + $received + $other_in + $wip - $returns - $issued - $other_out;
    } else {
        $end_stock = $beg_stock + $received - $issued;
    }
    
    $end_stock = max(0, $end_stock);
    $end_cost = $end_stock * $price;
    
    // Prepare data row
    if ($report_type == 'detailed') {
        $data_row = [
            $item['fa_code'],
            $item['mat_type'],
            $item['prmode'],
            $item['description'],
            number_format($price, 2),
            number_format($beg_stock, 2),
            number_format($received, 2),
            number_format($other_in, 2),
            number_format($wip, 2),
            number_format($returns, 2),
            number_format($issued, 2),
            number_format($other_out, 2),
            number_format($issued, 2), // wip issued same as issued
            number_format($end_stock, 2),
            number_format($end_cost, 2)
        ];
    } else {
        $data_row = [
            $item['fa_code'],
            $item['description'],
            number_format($price, 2),
            number_format($beg_stock, 2),
            number_format($received, 2),
            number_format($issued, 2),
            number_format($end_stock, 2),
            number_format($end_cost, 2)
        ];
    }
    
    // Write row
    fputcsv($output, $data_row);
    
    $total_items++;
    $total_value += $end_cost;
    $processed_count++;
    
    // Flush output every 50 rows
    if ($processed_count % 50 == 0) {
        flush();
        ob_flush();
    }
    
    // Break if processing too many items (safety limit)
    if ($processed_count > 10000) {
        fputcsv($output, []);
        fputcsv($output, ['NOTE: Report truncated to 10,000 items']);
        break;
    }
}

// Add summary rows
fputcsv($output, []);
fputcsv($output, ['REPORT SUMMARY']);
fputcsv($output, ['Total Items:', $total_items]);
fputcsv($output, ['Total Inventory Value:', number_format($total_value, 2)]);
fputcsv($output, ['Report Period:', $period]);
fputcsv($output, ['Date Range:', $start_date . ' to ' . $end_date]);
fputcsv($output, ['Generated On:', date('Y-m-d H:i:s')]);
fputcsv($output, ['Report Type:', $report_type . ' report']);

fclose($output);

// Log the report (optional)
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS report_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period VARCHAR(50),
            report_type VARCHAR(20),
            items_count INT,
            total_value DECIMAL(15,2),
            generated_by VARCHAR(50),
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $conn->query("
        INSERT INTO report_logs (period, report_type, items_count, total_value, generated_by) 
        VALUES ('$period', '$report_type', $total_items, $total_value, '{$_SERVER['REMOTE_ADDR']}')
    ");
} catch (Exception $e) {
    // Log error silently
}

$conn->close();
exit;
?>