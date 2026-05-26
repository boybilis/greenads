<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');

try {
    $stmt = $pdo->query("
    SELECT
        i.sku,
        i.material_name,
        i.color,
        i.quantity,
        COALESCE(r.reserved_qty, 0) AS reserved_qty,
        (i.quantity - COALESCE(r.reserved_qty, 0)) AS available_qty,
        i.unit,
        i.unit_price
    FROM tbl_items i
    LEFT JOIN (
        SELECT oi.sku, SUM(oi.qty) AS reserved_qty
        FROM tbl_or_items oi
        INNER JOIN tbl_or o ON o.or_id = oi.or_id
        WHERE o.or_status = 0
        GROUP BY oi.sku
    ) r ON r.sku = i.sku
    ORDER BY i.material_name ASC, i.sku ASC
");

    echo json_encode([
        'status' => 'success',
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
