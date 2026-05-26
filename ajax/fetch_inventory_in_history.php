<?php
include_once('config.php');

header('Content-Type: application/json');

try {
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
            INDEX idx_inventory_in_receipt_no (receipt_no),
            INDEX idx_inventory_in_po_code (po_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM tbl_inventory_in")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('stock_before', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_inventory_in ADD stock_before DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit_price");
    }
    if (!in_array('stock_after', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_inventory_in ADD stock_after DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER stock_before");
    }

    $stmt = $pdo->query("
        SELECT receipt_date, sku, item_name, stock_before, quantity, stock_after, unit, unit_price, receipt_no, po_code
        FROM tbl_inventory_in
        ORDER BY receipt_date DESC, id DESC
        LIMIT 100
    ");

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data[] = [
            htmlspecialchars($row['receipt_date'] ?? ''),
            htmlspecialchars($row['sku'] ?? ''),
            htmlspecialchars($row['item_name'] ?? ''),
            htmlspecialchars((float)$row['stock_before'] . ' ' . ($row['unit'] ?? '')),
            htmlspecialchars((float)$row['quantity'] . ' ' . ($row['unit'] ?? '')),
            htmlspecialchars((float)$row['stock_after'] . ' ' . ($row['unit'] ?? '')),
            htmlspecialchars(number_format((float)$row['unit_price'], 2)),
            htmlspecialchars($row['receipt_no'] ?? ''),
            htmlspecialchars($row['po_code'] ?: '-')
        ];
    }

    echo json_encode(['data' => $data]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'data' => [],
        'error' => $e->getMessage()
    ]);
}
