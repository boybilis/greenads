<?php
include_once('config.php');

header('Content-Type: application/json');

try {
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
            INDEX idx_inventory_out_reference_no (reference_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM tbl_inventory_out")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('stock_before', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_inventory_out ADD stock_before DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit");
    }
    if (!in_array('stock_after', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_inventory_out ADD stock_after DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER stock_before");
    }

    $stmt = $pdo->query("
        SELECT transaction_date, sku, item_name, stock_before, quantity, stock_after, unit, reference_no, remarks
        FROM tbl_inventory_out
        ORDER BY transaction_date DESC, id DESC
        LIMIT 100
    ");

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data[] = [
            htmlspecialchars($row['transaction_date'] ?? ''),
            htmlspecialchars($row['sku'] ?? ''),
            htmlspecialchars($row['item_name'] ?? ''),
            htmlspecialchars((float)$row['stock_before'] . ' ' . ($row['unit'] ?? '')),
            htmlspecialchars((float)$row['quantity'] . ' ' . ($row['unit'] ?? '')),
            htmlspecialchars((float)$row['stock_after'] . ' ' . ($row['unit'] ?? '')),
            htmlspecialchars($row['reference_no'] ?? ''),
            htmlspecialchars($row['remarks'] ?: '-')
        ];
    }

    echo json_encode(['data' => $data]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'data' => [],
        'error' => "Request failed."
    ]);
}
