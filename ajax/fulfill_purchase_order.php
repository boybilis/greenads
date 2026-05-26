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

$poId = (int)($_POST['po_id'] ?? 0);
$receiptNo = trim($_POST['receipt_no'] ?? '');
$dateReceived = trim($_POST['date_received'] ?? '');

if ($poId <= 0 || $receiptNo === '' || $dateReceived === '') {
    echo json_encode(['status' => 'error', 'message' => 'Please complete receipt no. and date received.']);
    exit;
}

try {
    $columns = $pdo->query("SHOW COLUMNS FROM tbl_purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('receipt_no', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_orders ADD receipt_no VARCHAR(100) DEFAULT NULL AFTER po_date");
    }
    if (!in_array('date_received', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_orders ADD date_received DATE DEFAULT NULL AFTER receipt_no");
    }
    if (!in_array('fulfillment_status', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_orders ADD fulfillment_status VARCHAR(30) NOT NULL DEFAULT 'Pending' AFTER date_received");
    }

    $beforeStmt = $pdo->prepare("SELECT po_id, po_ref_no, pr_id, receipt_no, date_received, fulfillment_status FROM tbl_purchase_orders WHERE po_id = ? LIMIT 1");
    $beforeStmt->execute([$poId]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$before) {
        echo json_encode(['status' => 'error', 'message' => 'PO request not found.']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE tbl_purchase_orders
        SET receipt_no = ?,
            date_received = ?,
            fulfillment_status = 'Fulfilled'
        WHERE po_id = ?
    ");
    $stmt->execute([$receiptNo, $dateReceived, $poId]);

    $updatePrStmt = $pdo->prepare("
        UPDATE tbl_purchase_requests
        SET status = 'PO Fulfilled'
        WHERE pr_id = ?
    ");
    $updatePrStmt->execute([(int)$before['pr_id']]);

    $after = [
        'receipt_no' => $receiptNo,
        'date_received' => $dateReceived,
        'fulfillment_status' => 'Fulfilled'
    ];
    audit_log($pdo, 'FULFILL', 'Purchase Order', $before['po_ref_no'] ?: (string)$poId, audit_changed_fields($before, $after, ['receipt_no', 'date_received', 'fulfillment_status']) . '; purchase_request.status -> "PO Fulfilled".');

    echo json_encode(['status' => 'success', 'message' => 'PO marked as fulfilled.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Request failed."]);
}
?>
