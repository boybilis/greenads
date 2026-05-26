<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');

try {
    $columns = $pdo->query("SHOW COLUMNS FROM tbl_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reorder_level', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_items ADD reorder_level INT NOT NULL DEFAULT 10");
    }

    $stmt = $pdo->query("
        SELECT
            sku,
            material_name,
            description,
            unit,
            quantity,
            reserved_qty,
            available_qty,
            reorder_level,
            GREATEST(reorder_level - available_qty, 1) AS suggested_qty
        FROM (
            SELECT
                i.sku,
                i.material_name,
                i.description,
                i.unit,
                i.quantity,
                COALESCE(r.reserved_qty, 0) AS reserved_qty,
                (i.quantity - COALESCE(r.reserved_qty, 0)) AS available_qty,
                i.reorder_level
            FROM tbl_items i
            LEFT JOIN (
                SELECT oi.sku, SUM(oi.qty) AS reserved_qty
                FROM tbl_or_items oi
                INNER JOIN tbl_or o ON o.or_id = oi.or_id
                WHERE o.or_status = 0
                GROUP BY oi.sku
            ) r ON r.sku = i.sku
        ) stock
        WHERE available_qty > 0 AND available_qty <= reorder_level
        ORDER BY material_name ASC, sku ASC
    ");

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data[] = [
            'sku' => $row['sku'],
            'material_name' => $row['material_name'],
            'description' => $row['description'],
            'unit' => $row['unit'],
            'quantity' => (float)$row['quantity'],
            'reserved_qty' => (float)$row['reserved_qty'],
            'available_qty' => (float)$row['available_qty'],
            'reorder_level' => (float)$row['reorder_level'],
            'suggested_qty' => (float)$row['suggested_qty']
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data' => $data
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'data' => []
    ]);
}
