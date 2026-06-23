<?php
include_once('config.php');

header('Content-Type: application/json');

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
    echo json_encode([
        'data' => [],
        'summary' => ['grand_total_price' => '0.00', 'total_value' => '0.00'],
        'message' => 'Invalid month or year.'
    ]);
    exit;
}

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate = date('Y-m-t', strtotime($startDate));

try {
    $itemColumns = $pdo->query("SHOW COLUMNS FROM tbl_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('created_at', $itemColumns, true)) {
        $pdo->exec("ALTER TABLE tbl_items ADD created_at DATETIME DEFAULT NULL");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_inventory_in (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(100) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL,
            stock_before DECIMAL(12,2) NOT NULL DEFAULT 0,
            stock_after DECIMAL(12,2) NOT NULL DEFAULT 0,
            receipt_no VARCHAR(100) NOT NULL,
            receipt_date DATE NOT NULL,
            po_code VARCHAR(100) DEFAULT NULL,
            created_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_inventory_in_sku (sku),
            INDEX idx_inventory_in_receipt_date (receipt_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_inventory_out (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(100) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            stock_before DECIMAL(12,2) NOT NULL DEFAULT 0,
            stock_after DECIMAL(12,2) NOT NULL DEFAULT 0,
            reference_no VARCHAR(100) NOT NULL,
            transaction_date DATE NOT NULL,
            remarks TEXT DEFAULT NULL,
            created_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_inventory_out_sku (sku),
            INDEX idx_inventory_out_transaction_date (transaction_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $sql = "
        SELECT
            i.sku,
            i.material_name,
            i.unit,
            COALESCE(i.unit_price, 0) AS unit_price,
            COALESCE(i.quantity, 0) AS current_qty,
            COALESCE(mi.in_qty, 0) AS in_qty,
            COALESCE(mo.out_qty, 0) AS out_qty,
            COALESCE(ai.after_in_qty, 0) AS after_in_qty,
            COALESCE(ao.after_out_qty, 0) AS after_out_qty
        FROM tbl_items i
        LEFT JOIN (
            SELECT sku, SUM(quantity) AS in_qty
            FROM tbl_inventory_in
            WHERE receipt_date BETWEEN ? AND ?
            GROUP BY sku
        ) mi ON mi.sku = i.sku
        LEFT JOIN (
            SELECT sku, SUM(quantity) AS out_qty
            FROM tbl_inventory_out
            WHERE transaction_date BETWEEN ? AND ?
            GROUP BY sku
        ) mo ON mo.sku = i.sku
        LEFT JOIN (
            SELECT sku, SUM(quantity) AS after_in_qty
            FROM tbl_inventory_in
            WHERE receipt_date > ?
            GROUP BY sku
        ) ai ON ai.sku = i.sku
        LEFT JOIN (
            SELECT sku, SUM(quantity) AS after_out_qty
            FROM tbl_inventory_out
            WHERE transaction_date > ?
            GROUP BY sku
        ) ao ON ao.sku = i.sku
        WHERE i.created_at IS NULL OR DATE(i.created_at) <= ?
        ORDER BY i.material_name ASC, i.sku ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$startDate, $endDate, $startDate, $endDate, $endDate, $endDate, $endDate]);

    $data = [];
    $totalValue = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $currentQty = (float)$row['current_qty'];
        $inQty = (float)$row['in_qty'];
        $outQty = (float)$row['out_qty'];
        $afterIn = (float)$row['after_in_qty'];
        $afterOut = (float)$row['after_out_qty'];
        $unitPrice = (float)$row['unit_price'];

        $monthEndInventory = $currentQty - $afterIn + $afterOut;
        $beginning = $monthEndInventory - $inQty + $outQty;
        $remaining = ($beginning + $inQty) - $outQty;
        $lineValue = $remaining * $unitPrice;
        $totalValue += $lineValue;

        $unit = trim((string)($row['unit'] ?? ''));
        $data[] = [
            'sku' => htmlspecialchars($row['sku'] ?? ''),
            'item_name' => htmlspecialchars($row['material_name'] ?? ''),
            'beginning_inventory' => number_format($beginning, 2) . ($unit !== '' ? ' ' . htmlspecialchars($unit) : ''),
            'in_qty' => number_format($inQty, 2) . ($unit !== '' ? ' ' . htmlspecialchars($unit) : ''),
            'out_qty' => number_format($outQty, 2) . ($unit !== '' ? ' ' . htmlspecialchars($unit) : ''),
            'remaining_inventory' => number_format($remaining, 2) . ($unit !== '' ? ' ' . htmlspecialchars($unit) : ''),
            'unit_price' => number_format($unitPrice, 2),
            'total_price' => number_format($lineValue, 2),
            'total_value' => number_format($lineValue, 2)
        ];
    }

    echo json_encode([
        'data' => $data,
        'summary' => [
            'period' => date('F Y', strtotime($startDate)),
            'grand_total_price' => number_format($totalValue, 2),
            'total_value' => number_format($totalValue, 2)
        ]
    ]);
} catch (Exception $e) {
    error_log('fetch_inventory_monthly_summary failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'data' => [],
        'summary' => ['grand_total_price' => '0.00', 'total_value' => '0.00'],
        'message' => 'Request failed.'
    ]);
}
