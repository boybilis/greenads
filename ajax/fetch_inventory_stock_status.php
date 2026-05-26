<?php
include_once('config.php');

header('Content-Type: application/json');

$type = $_GET['type'] ?? '';

if ($type === 'low') {
    $where = 'available_qty > 0 AND available_qty <= reorder_level';
} elseif ($type === 'out') {
    $where = 'available_qty <= 0';
} else {
    echo json_encode(['data' => []]);
    exit;
}

try {
    $columns = $pdo->query("SHOW COLUMNS FROM tbl_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reorder_level', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_items ADD reorder_level INT NOT NULL DEFAULT 10");
    }

    $stmt = $pdo->prepare("
        SELECT sku, material_name, description, quantity, reserved_qty, available_qty, unit
        FROM (
            SELECT
                i.sku,
                i.material_name,
                i.description,
                i.quantity,
                COALESCE(r.reserved_qty, 0) AS reserved_qty,
                (i.quantity - COALESCE(r.reserved_qty, 0)) AS available_qty,
                i.unit,
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
        WHERE $where
        ORDER BY material_name ASC, sku ASC
    ");
    $stmt->execute();

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data[] = [
            htmlspecialchars($row['sku'] ?? ''),
            htmlspecialchars($row['material_name'] ?? ''),
            htmlspecialchars($row['description'] ?: '-'),
            htmlspecialchars('Available: ' . (float)$row['available_qty'] . ' ' . ($row['unit'] ?? '') . ' | Reserved: ' . (float)$row['reserved_qty'])
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
