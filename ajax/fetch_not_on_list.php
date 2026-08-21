<?php
session_start();
include_once('config.php');
include_once('item_request_status_helper.php');
include_once('purchase_order_status_helper.php');
header('Content-Type: application/json');

$userType = $_SESSION['user_type'] ?? '';
$userCode = $_SESSION['user_code'] ?? '';
$username = $_SESSION['username'] ?? '';

ensure_item_request_link_schema($pdo);
sync_encoded_item_requests($pdo);
sync_item_request_po_statuses($pdo);

$columns = $pdo->query("SHOW COLUMNS FROM item_requests")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('request_qty', $columns, true)) {
    $pdo->exec("ALTER TABLE item_requests ADD request_qty DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER item_color");
}
if (!in_array('unit', $columns, true)) {
    $pdo->exec("ALTER TABLE item_requests ADD unit VARCHAR(50) DEFAULT NULL AFTER request_qty");
}
if (!in_array('gsm_size', $columns, true)) {
    $pdo->exec("ALTER TABLE item_requests ADD gsm_size VARCHAR(100) DEFAULT NULL AFTER item_color");
}
if (!in_array('existing_sku', $columns, true)) {
    $pdo->exec("ALTER TABLE item_requests ADD existing_sku VARCHAR(100) DEFAULT NULL AFTER item_name");
}

// Recover the final inventory SKU for older new-item requests after encoding.
$pdo->exec("
    UPDATE item_requests ir
    INNER JOIN tbl_purchase_request_items pri ON pri.item_request_id = ir.id
    SET ir.existing_sku = pri.sku
    WHERE (ir.existing_sku IS NULL OR TRIM(ir.existing_sku) = '')
      AND pri.sku NOT REGEXP '^REQ-[0-9]+$'
");

if ($userType === 'Manager') {
    $stmt = $pdo->prepare("
        SELECT ir.*
        FROM item_requests ir
        WHERE (ir.requested_by = ? OR ir.requested_by = ?)
          AND NOT EXISTS (
              SELECT 1
              FROM tbl_or_items oi
              WHERE oi.sku = ir.existing_sku
          )
        ORDER BY ir.id DESC
    ");
    $stmt->execute([$userCode, $username]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $rows = $pdo->query("
        SELECT ir.*
        FROM item_requests ir
        WHERE NOT EXISTS (
            SELECT 1
            FROM tbl_or_items oi
            WHERE oi.sku = ir.existing_sku
        )
        ORDER BY ir.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$data = [];

if ($rows && is_array($rows)) {
    foreach ($rows as $row) {


		$rawStatus=$row['status'];
		$status=$rawStatus;
		
		if($status=='Pending'){
			$status='<span class="status-capsule status-warning">Pending</span>';
		}elseif(in_array($status, ['PR Requested', 'Ordered'], true)){
			$status='<span class="status-capsule status-info">PR Requested</span>';
		}elseif($status=='PO Requested'){
			$status='<span class="status-capsule status-primary">PO Requested</span>';
		}elseif($status=='PO Approved'){
			$status='<span class="status-capsule status-info">PO Approved</span>';
		}elseif($status=='PO Fulfilled'){
			$status='<span class="status-capsule status-warning">PO Fulfilled</span>';
		}elseif($status=='Now available'){
			$status='<span class="status-capsule status-success">Now Available</span>';
		}else{
			$status='<span class="status-capsule status-secondary">' . htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8') . '</span>';
		}

       $desc = !empty($row['description']) ? htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8') : '-';
$description = (!empty($row['existing_sku']) ? "Existing SKU: " . htmlspecialchars($row['existing_sku'], ENT_QUOTES, 'UTF-8') . "<br>" : '') .
    "Color: " . htmlspecialchars($row['item_color'] ?? '', ENT_QUOTES, 'UTF-8') .
    "<br>GSM / Size: " . htmlspecialchars(($row['gsm_size'] ?? '') !== '' ? $row['gsm_size'] : '-', ENT_QUOTES, 'UTF-8') .
    "<br>Qty: " . number_format((float)($row['request_qty'] ?? 1), 2) . " " . htmlspecialchars($row['unit'] ?? '', ENT_QUOTES, 'UTF-8') .
    "<br>" . $desc;
        $canCreatePr = !in_array($userType, ['Inventory', 'Purchasing'], true)
            && ($userType === 'Admin' || in_array($row['requested_by'] ?? '', [$userCode, $username], true));
        $createPrButton = $canCreatePr
            ? '<a href="#" class="create-item-request-pr d-block mb-1" data-id="' . (int)$row['id'] . '" data-name="' . htmlspecialchars($row['item_name'] ?? '', ENT_QUOTES, 'UTF-8') . '"><span class="badge badge-success">Create PR</span></a>'
            : '';
        $editButton = '<a href="#" class="edit-item-request d-block mb-1"'
            . ' data-id="' . (int)$row['id'] . '"'
            . ' data-name="' . htmlspecialchars($row['item_name'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
            . ' data-existing-sku="' . htmlspecialchars($row['existing_sku'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
            . ' data-color="' . htmlspecialchars($row['item_color'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
            . ' data-gsm-size="' . htmlspecialchars($row['gsm_size'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
            . ' data-qty="' . htmlspecialchars((string)($row['request_qty'] ?? 1), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-unit="' . htmlspecialchars($row['unit'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
            . ' data-description="' . htmlspecialchars($row['description'] ?? '', ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="badge badge-warning">Edit</span></a>';
        $deleteButton = '<a href="#" class="delete-item-request d-block" data-id="' . (int)$row['id'] . '"><span class="badge badge-danger">Delete</span></a>';
		
		

        $data[] = [
            '<div class="d-flex flex-column align-items-start">'
                . '<div class="mb-2">' . $status . '</div>'
                . ($rawStatus === 'Pending' ? trim($createPrButton . $editButton . $deleteButton) : '')
                . '</div>',
            htmlspecialchars($row['item_name'] ?? '', ENT_QUOTES, 'UTF-8'),
            $description
        ];
    }
}

echo json_encode(["data" => $data]);
exit;
