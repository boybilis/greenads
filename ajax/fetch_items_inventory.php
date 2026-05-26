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

       
        $details = !empty($row['material_name']) ? "<small>Item Name: </small>".$row['material_name'].
		"<br><small>Color: </small>".$row['color'].
		"<br><small>Description: </small>".$row['description'] : '-';
        $onHand = (float)$row['quantity'];
        $reserved = $reservedBySku[$row['sku']] ?? 0;
        $available = max(0, $onHand - $reserved);
        $quantity = "<small>On-hand: </small>".$onHand . ' ' ."<small>". $row['unit']."</small>".
		"<br><small>Reserved: </small>".$reserved . ' ' ."<small>". $row['unit']."</small>".
		"<br><small>Available: </small>".$available . ' ' ."<small>". $row['unit']."</small>";
		$cost = "<small>Unit Price: </small>".(double)$row['unit_price'].
		"<br><small>Total Price: </small>".((double)$row['unit_price']*(int)$row['quantity']);
		
		$qty = (float)$available;
		$reorderLevel = (int)($row['reorder_level'] ?? 10);

if ($qty > $reorderLevel) {
    $status = '<span class="badge badge-success">Available</span>';
} elseif ($qty > 0) {
    $status = '<span class="badge badge-warning">Low on Stock</span>';
} else {
    $status = '<span class="badge badge-danger">Out of Stock</span>';
}

        $data[] = [
            $row['sku'],
            $details,
			$quantity,
             $cost,
			 $status,
			 $row['location']
            
        ];
    }
}

echo json_encode(["data" => $data]);
exit;
