<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
    exit;
}

if (($_SESSION['user_type'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only admin can approve purchase orders.']);
    exit;
}

$poId = (int)($_POST['po_id'] ?? 0);
if ($poId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid PO reference.']);
    exit;
}

try {
    $columns = $pdo->query("SHOW COLUMNS FROM tbl_purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('fulfillment_status', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_orders ADD fulfillment_status VARCHAR(30) NOT NULL DEFAULT 'Pending' AFTER date_received");
        $columns[] = 'fulfillment_status';
    }
    if (!in_array('approval_status', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_orders ADD approval_status VARCHAR(30) NOT NULL DEFAULT 'Pending' AFTER fulfillment_status");
    }

    $beforeStmt = $pdo->prepare("SELECT po_id, po_ref_no, approval_status FROM tbl_purchase_orders WHERE po_id = ? LIMIT 1");
    $beforeStmt->execute([$poId]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$before) {
        echo json_encode(['status' => 'error', 'message' => 'PO request not found.']);
        exit;
    }

    if (($before['approval_status'] ?? 'Pending') === 'Approved') {
        echo json_encode(['status' => 'success', 'message' => 'PO is already approved.']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE tbl_purchase_orders
        SET approval_status = 'Approved'
        WHERE po_id = ?
    ");
    $stmt->execute([$poId]);

    $after = ['approval_status' => 'Approved'];
    audit_log($pdo, 'APPROVE', 'Purchase Order', $before['po_ref_no'] ?: (string)$poId, audit_changed_fields($before, $after, ['approval_status']));

    echo json_encode(['status' => 'success', 'message' => 'PO approved successfully.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Request failed.']);
}
?>
