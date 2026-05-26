<?php
include_once('config.php');
header('Content-Type: application/json');

$columns = $pdo->query("SHOW COLUMNS FROM tbl_items")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('reorder_level', $columns, true)) {
    $pdo->exec("ALTER TABLE tbl_items ADD reorder_level INT NOT NULL DEFAULT 10");
}

$reservedStmt = $pdo->query("
    SELECT oi.sku, SUM(oi.qty) AS reserved_qty
    FROM tbl_or_items oi
    INNER JOIN tbl_or o ON o.or_id = oi.or_id
    WHERE o.or_status = 0
    GROUP BY oi.sku
");
$reservedBySku = [];
while ($reservedRow = $reservedStmt->fetch(PDO::FETCH_ASSOC)) {
    $reservedBySku[$reservedRow['sku']] = (float)$reservedRow['reserved_qty'];
}

$rows = $db->getAllRecords('tbl_items');

$data = [];

if ($rows && is_array($rows)) {
    foreach ($rows as $row) {

       
        $description = !empty($row['description']) ? $row['description'] : '-';
        $reserved = $reservedBySku[$row['sku']] ?? 0;
        $available = max(0, (float)$row['quantity'] - $reserved);
        $quantity = 'Available: ' . $available . ' ' . $row['unit'] . ' | On-hand: ' . (float)$row['quantity'] . ' | Reserved: ' . $reserved;

        $data[] = [
            $row['sku'],
            $row['material_name'],
			$row['color'],
            $description,
            $quantity,
            $row['unit'],
            $row['unit_price']
        ];
    }
}

echo json_encode(["data" => $data]);
exit;
