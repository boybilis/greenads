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
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_purchase_order_prs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_id INT NOT NULL,
            pr_id INT NOT NULL,
            pr_ref_no VARCHAR(30) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_po_pr (po_id, pr_id),
            INDEX idx_po_prs_po_id (po_id),
            INDEX idx_po_prs_pr_id (pr_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

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

    $linkedPrIds = [(int)$po['pr_id']];
    $linkTableStmt = $pdo->prepare("SHOW TABLES LIKE 'tbl_purchase_order_prs'");
    $linkTableStmt->execute();
    if ($linkTableStmt->fetchColumn()) {
        $linkedStmt = $pdo->prepare("SELECT pr_id FROM tbl_purchase_order_prs WHERE po_id = ?");
        $linkedStmt->execute([$poId]);
        $linkedPrIds = array_values(array_unique(array_filter(array_map('intval', $linkedStmt->fetchAll(PDO::FETCH_COLUMN)))));
        if (count($linkedPrIds) === 0) {
            $linkedPrIds = [(int)$po['pr_id']];
        }
    }

    $delItems = $pdo->prepare("DELETE FROM tbl_purchase_order_items WHERE po_id = ?");
    $delItems->execute([$poId]);

    $delLinks = $pdo->prepare("DELETE FROM tbl_purchase_order_prs WHERE po_id = ?");
    $delLinks->execute([$poId]);

    $delPo = $pdo->prepare("DELETE FROM tbl_purchase_orders WHERE po_id = ?");
    $delPo->execute([$poId]);

    $placeholders = implode(',', array_fill(0, count($linkedPrIds), '?'));
    $resetPr = $pdo->prepare("UPDATE tbl_purchase_requests SET status = 'Pending' WHERE pr_id IN ($placeholders) AND status IN ('PO Requested', 'PO Approved')");
    $resetPr->execute($linkedPrIds);
    mark_pr_linked_item_requests_status($pdo, $linkedPrIds, 'PR Requested', ['PO Requested', 'PO Approved']);

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
