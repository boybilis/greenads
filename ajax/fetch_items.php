<?php
session_start();
include_once('config.php');
header('Content-Type: application/json');
if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['data' => [], 'message' => 'Unauthorized.']);
    exit;
}


$columns = $pdo->query("SHOW COLUMNS FROM tbl_items")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('reorder_level', $columns, true)) {
    $pdo->exec("ALTER TABLE tbl_items ADD reorder_level INT NOT NULL DEFAULT 10");
}

$rows = $db->getAllRecords('tbl_items');

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

$data = [];

if ($rows && is_array($rows)) {
    foreach ($rows as $row) {

       $onHand = (float)$row['quantity'];
       $reserved = $reservedBySku[$row['sku']] ?? 0;
       $qty = max(0, $onHand - $reserved);
       $reorderLevel = (int)($row['reorder_level'] ?? 10);

if ($qty > $reorderLevel) {
    $status = '<span class="badge badge-success">Available</span>';
} elseif ($qty > 0) {
    $status = '<span class="badge badge-warning">Low on Stock</span>';
} else {
    $status = '<span class="badge badge-danger">Out of Stock</span>';
}

        $description = !empty($row['description']) ? $row['description'] : '-';
        $unit = $row['unit'] ?? '';
        $quantity = number_format($qty, 2) . ' ' . htmlspecialchars($unit);
		
		if ($_SESSION['user_type'] !== 'Manager') { 

        $action = '
            <a href="javascript:void(0)" class="editBtn" data-id="'.$row['id'].'">
                <span class="badge badge-warning">Edit</span>
            </a>
        '; }else{
			$action='';
		}

        if (in_array($_SESSION['user_type'] ?? '', ['Admin', 'Inventory'], true)) {
            $action .= '
                |
                <a href="javascript:void(0)" class="deleteBtn" data-id="'.$row['id'].'">
                    <span class="badge badge-danger">Delete</span>
                </a>
            ';
        }

        $data[] = [
            $row['sku'],
            $row['material_name'],
            $description,
            $row['color'],
            $quantity,
            $status,
            $action
        ];
    }
}

echo json_encode(["data" => $data]);
exit;
