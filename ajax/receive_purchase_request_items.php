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

if (!in_array($_SESSION['user_type'] ?? '', ['Admin', 'Inventory'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Admin/Inventory can receive items.']);
    exit;
}

$prId = (int)($_POST['pr_id'] ?? 0);
if ($prId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid PR reference.']);
    exit;
}

try {
    $prStmt = $pdo->prepare("SELECT pr_id, pr_ref_no, status FROM tbl_purchase_requests WHERE pr_id = ? LIMIT 1");
    $prStmt->execute([$prId]);
    $pr = $prStmt->fetch(PDO::FETCH_ASSOC);

    if (!$pr) {
        echo json_encode(['status' => 'error', 'message' => 'PR request not found.']);
        exit;
    }

    if (($pr['status'] ?? '') === 'PO Fulfilled' || ($pr['status'] ?? '') === 'Encoded') {
        echo json_encode(['status' => 'success', 'message' => 'Items already marked as received.']);
        exit;
    }

    if (($pr['status'] ?? '') !== 'PO Requested') {
        echo json_encode(['status' => 'error', 'message' => 'Only PO Requested items can be marked received.']);
        exit;
    }

    $pdo->beginTransaction();

    $updPr = $pdo->prepare("UPDATE tbl_purchase_requests SET status = 'PO Fulfilled' WHERE pr_id = ?");
    $updPr->execute([$prId]);

    $pdo->commit();

    audit_log($pdo, 'RECEIVE', 'Purchase Request', $pr['pr_ref_no'] ?: ('PR-' . str_pad((string)$prId, 6, '0', STR_PAD_LEFT)), 'purchase_request.status: "PO Requested" -> "PO Fulfilled"');

    echo json_encode(['status' => 'success', 'message' => 'Items received. PR is now PO Fulfilled and ready for encoding.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Request failed.']);
}
?>
