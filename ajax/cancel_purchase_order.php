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
    echo json_encode(['status' => 'error', 'message' => 'Only Admin or Purchasing can cancel PO requests.']);
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

    $columns = $pdo->query("SHOW COLUMNS FROM tbl_purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('approval_status', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_orders ADD approval_status VARCHAR(30) NOT NULL DEFAULT 'Pending'");
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT po_id, po_ref_no, pr_id, fulfillment_status, COALESCE(approval_status, 'Pending') AS approval_status
        FROM tbl_purchase_orders
        WHERE po_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$poId]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        throw new RuntimeException('Purchase order not found.');
    }

    if (strcasecmp((string)$po['approval_status'], 'Approved') === 0) {
        throw new RuntimeException('Approved PO requests cannot be cancelled. Use the existing delete process if allowed.');
    }

    if (strcasecmp((string)($po['fulfillment_status'] ?? ''), 'PO Verified') === 0) {
        throw new RuntimeException('Cannot cancel a verified PO.');
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
            throw new RuntimeException('Cannot cancel this PO because received items were already encoded.');
        }
    }

    $linkedPrIds = [(int)$po['pr_id']];
    $linkedStmt = $pdo->prepare("SELECT pr_id FROM tbl_purchase_order_prs WHERE po_id = ?");
    $linkedStmt->execute([$poId]);
    $linkedPrIds = array_merge($linkedPrIds, array_map('intval', $linkedStmt->fetchAll(PDO::FETCH_COLUMN)));

    $itemLinkedStmt = $pdo->prepare("
        SELECT DISTINCT pri.pr_id
        FROM tbl_purchase_order_items poi
        INNER JOIN tbl_purchase_request_items pri ON pri.pr_item_id = poi.pr_item_id
        WHERE poi.po_id = ?
    ");
    $itemLinkedStmt->execute([$poId]);
    $linkedPrIds = array_merge($linkedPrIds, array_map('intval', $itemLinkedStmt->fetchAll(PDO::FETCH_COLUMN)));

    $linkedPrIds = array_values(array_unique(array_filter($linkedPrIds)));
    if (!$linkedPrIds) {
        throw new RuntimeException('No linked PR was found for this PO.');
    }

    $pdo->prepare("DELETE FROM tbl_purchase_order_items WHERE po_id = ?")->execute([$poId]);
    $pdo->prepare("DELETE FROM tbl_purchase_order_prs WHERE po_id = ?")->execute([$poId]);
    $pdo->prepare("DELETE FROM tbl_purchase_orders WHERE po_id = ?")->execute([$poId]);

    $placeholders = implode(',', array_fill(0, count($linkedPrIds), '?'));
    $resetPr = $pdo->prepare("
        UPDATE tbl_purchase_requests
        SET status = 'Pending'
        WHERE pr_id IN ($placeholders)
          AND TRIM(status) NOT IN ('PO Fulfilled', 'Encoded')
    ");
    $resetPr->execute($linkedPrIds);

    $verifyDelete = $pdo->prepare("SELECT COUNT(*) FROM tbl_purchase_orders WHERE po_id = ?");
    $verifyDelete->execute([$poId]);
    if ((int)$verifyDelete->fetchColumn() > 0) {
        throw new RuntimeException('PO cancellation did not complete. Please try again.');
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    audit_log($pdo, 'CANCEL', 'Purchase Order', $po['po_ref_no'] ?: (string)$poId, 'Cancelled pending PO request; linked PR reset to Pending.');

    echo json_encode(['status' => 'success', 'message' => 'PO request cancelled. Linked PR can now be edited.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('cancel_purchase_order failed: ' . $e->getMessage());
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Cancel failed.';
    echo json_encode(['status' => 'error', 'message' => $message]);
}
