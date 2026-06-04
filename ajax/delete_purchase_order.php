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

if (!in_array($_SESSION['user_type'] ?? '', ['Admin', 'Purchasing'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Admin or Purchasing can delete PO records.']);
    exit;
}

$poId = (int)($_POST['po_id'] ?? 0);
if ($poId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid PO reference.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT po_id, po_ref_no, pr_id, fulfillment_status FROM tbl_purchase_orders WHERE po_id = ? LIMIT 1");
    $stmt->execute([$poId]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        echo json_encode(['status' => 'error', 'message' => 'Purchase order not found.']);
        exit;
    }

    if (strcasecmp((string)($po['fulfillment_status'] ?? ''), 'PO Verified') === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot delete a verified PO.']);
        exit;
    }

    $inventoryInTableStmt = $pdo->prepare("SHOW TABLES LIKE 'tbl_inventory_in'");
    $inventoryInTableStmt->execute();
    if ($inventoryInTableStmt->fetchColumn()) {
        $encodedStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM tbl_inventory_in ii
            INNER JOIN tbl_purchase_orders po ON po.po_ref_no = ii.po_code
            WHERE po.po_id = ?
        ");
        $encodedStmt->execute([$poId]);
        if ((int)$encodedStmt->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete this PO because received items were already encoded.']);
            exit;
        }
    }

    $pdo->beginTransaction();

    $delItems = $pdo->prepare("DELETE FROM tbl_purchase_order_items WHERE po_id = ?");
    $delItems->execute([$poId]);

    $delPo = $pdo->prepare("DELETE FROM tbl_purchase_orders WHERE po_id = ?");
    $delPo->execute([$poId]);

    $resetPr = $pdo->prepare("UPDATE tbl_purchase_requests SET status = 'Pending' WHERE pr_id = ? AND status = 'PO Requested'");
    $resetPr->execute([(int)$po['pr_id']]);

    audit_log($pdo, 'DELETE', 'Purchase Order', $po['po_ref_no'] ?: (string)$poId, 'Deleted purchase order; linked PR reset to Pending when applicable.');

    $pdo->commit();

    echo json_encode(['status' => 'success', 'message' => 'Purchase order deleted.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('delete_purchase_order failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Delete failed.']);
}
?>
