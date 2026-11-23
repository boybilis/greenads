<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');
include_once('purchase_order_status_helper.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
    exit;
}
if (($_SESSION['user_type'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only admin can undo PO approval.']);
    exit;
}

$poId = (int)($_POST['po_id'] ?? 0);
if ($poId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid PO reference.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT po_id, po_ref_no, approval_status, fulfillment_status FROM tbl_purchase_orders WHERE po_id = ? LIMIT 1");
    $stmt->execute([$poId]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$po) {
        echo json_encode(['status' => 'error', 'message' => 'PO request not found.']);
        exit;
    }
    if (strcasecmp(trim((string)$po['fulfillment_status']), 'PO Verified') === 0) {
        echo json_encode(['status' => 'error', 'message' => 'A verified PO can no longer be returned for editing.']);
        exit;
    }
    if (($po['approval_status'] ?? 'Pending') !== 'Approved') {
        echo json_encode(['status' => 'success', 'message' => 'PO approval is already pending.']);
        exit;
    }

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE tbl_purchase_orders SET approval_status = 'Pending' WHERE po_id = ?")->execute([$poId]);
    $prIds = get_po_linked_pr_ids($pdo, $poId);
    $updatedPrCount = 0;
    if ($prIds) {
        $placeholders = implode(',', array_fill(0, count($prIds), '?'));
        $updatePr = $pdo->prepare("UPDATE tbl_purchase_requests SET status = 'PO Requested' WHERE pr_id IN ($placeholders) AND TRIM(status) = 'PO Approved'");
        $updatePr->execute($prIds);
        $updatedPrCount = $updatePr->rowCount();
        mark_pr_linked_item_requests_status($pdo, $prIds, 'PO Requested', ['PO Approved']);
    }
    $pdo->commit();

    audit_log($pdo, 'UNDO APPROVAL', 'Purchase Order', $po['po_ref_no'] ?: (string)$poId, 'approval_status: Approved -> Pending; linked PR(s) returned to PO Requested: ' . $updatedPrCount);
    echo json_encode(['status' => 'success', 'message' => 'PO approval was undone. Purchasing can now edit and resubmit it.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to undo PO approval.']);
}
