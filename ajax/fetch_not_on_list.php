<?php
session_start();
include_once('config.php');
include_once('item_request_status_helper.php');
header('Content-Type: application/json');

$userType = $_SESSION['user_type'] ?? '';
$userCode = $_SESSION['user_code'] ?? '';
$username = $_SESSION['username'] ?? '';

ensure_item_request_link_schema($pdo);
sync_encoded_item_requests($pdo);

$columns = $pdo->query("SHOW COLUMNS FROM item_requests")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('request_qty', $columns, true)) {
    $pdo->exec("ALTER TABLE item_requests ADD request_qty DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER item_color");
}
if (!in_array('unit', $columns, true)) {
    $pdo->exec("ALTER TABLE item_requests ADD unit VARCHAR(50) DEFAULT NULL AFTER request_qty");
}

if ($userType === 'Manager') {
    $stmt = $pdo->prepare("
        SELECT *
        FROM item_requests
        WHERE requested_by = ? OR requested_by = ?
        ORDER BY id DESC
    ");
    $stmt->execute([$userCode, $username]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $rows = $db->getAllRecords('item_requests');
}

$data = [];

if ($rows && is_array($rows)) {
    foreach ($rows as $row) {


		$rawStatus=$row['status'];
		$status=$rawStatus;
		
		if($status=='Pending'){
			$status='<span class="badge bg-warning text-dark">Pending</span>';
		}elseif($status=='Ordered'){
			$status='<span class="badge bg-info">Ordered</span>';
		}elseif($status=='Now available'){
			$status='<span class="badge bg-success">Now available</span>';
		}

       $desc = !empty($row['description']) ? htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8') : '-';
$description = "Color: " . htmlspecialchars($row['item_color'] ?? '', ENT_QUOTES, 'UTF-8') .
    "<br>Qty: " . number_format((float)($row['request_qty'] ?? 1), 2) . " " . htmlspecialchars($row['unit'] ?? '', ENT_QUOTES, 'UTF-8') .
    "<br>" . $desc;
        $canCreatePr = !in_array($userType, ['Inventory', 'Purchasing'], true)
            && ($userType === 'Admin' || in_array($row['requested_by'] ?? '', [$userCode, $username], true));
        $createPrButton = $canCreatePr
            ? '<a href="#" class="create-item-request-pr" data-id="' . (int)$row['id'] . '"><span class="badge badge-success">Create PR</span></a>'
            : '';
        $editButton = '<a href="#" class="edit-item-request"'
            . ' data-id="' . (int)$row['id'] . '"'
            . ' data-name="' . htmlspecialchars($row['item_name'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
            . ' data-color="' . htmlspecialchars($row['item_color'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
            . ' data-qty="' . htmlspecialchars((string)($row['request_qty'] ?? 1), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-unit="' . htmlspecialchars($row['unit'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
            . ' data-description="' . htmlspecialchars($row['description'] ?? '', ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="badge badge-warning">Edit</span></a>';
        $deleteButton = '<a href="#" class="delete-item-request" data-id="' . (int)$row['id'] . '"><span class="badge badge-danger">Delete</span></a>';
		
		

        $data[] = [
            htmlspecialchars($row['item_name'] ?? '', ENT_QUOTES, 'UTF-8'),
            $description,
            $status,
            $rawStatus === 'Pending'
                ? trim($createPrButton . ' ' . $editButton . ' ' . $deleteButton)
                : '<span class="text-muted">-</span>'
        ];
    }
}

echo json_encode(["data" => $data]);
exit;
