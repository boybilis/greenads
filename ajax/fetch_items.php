<?php
session_start();
include_once('config.php');
header('Content-Type: application/json');

$columns = $pdo->query("SHOW COLUMNS FROM tbl_items")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('reorder_level', $columns, true)) {
    $pdo->exec("ALTER TABLE tbl_items ADD reorder_level INT NOT NULL DEFAULT 10");
}

$rows = $db->getAllRecords('tbl_items');

$data = [];

if ($rows && is_array($rows)) {
    foreach ($rows as $row) {

       $qty = (int)$row['quantity'];
       $reorderLevel = (int)($row['reorder_level'] ?? 10);

if ($qty > $reorderLevel) {
    $status = '<span class="badge badge-success">Available</span>';
} elseif ($qty > 0) {
    $status = '<span class="badge badge-warning">Low on Stock</span>';
} else {
    $status = '<span class="badge badge-danger">Out of Stock</span>';
}

        $description = !empty($row['description']) ? $row['description'] : '-';
		
		if ($_SESSION['user_type'] !== 'Manager') { 

        $action = '
            <a href="javascript:void(0)" class="editBtn" data-id="'.$row['id'].'">
                <span class="badge badge-warning">Edit</span>
            </a>
            |
            <a href="javascript:void(0)" class="deleteBtn" data-id="'.$row['id'].'">
                <span class="badge badge-danger">Delete</span>
            </a>
        '; }else{
			$action='';
		}

        $data[] = [
            $row['sku'],
            $row['material_name'],
            $description,
            $row['color'],
            $status,
            $action
        ];
    }
}

echo json_encode(["data" => $data]);
exit;
