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

    $poColumns = $pdo->query("SHOW COLUMNS FROM tbl_purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('approval_status', $poColumns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_orders ADD approval_status VARCHAR(30) NOT NULL DEFAULT 'Pending'");
    }

    $approvedPoStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT po.po_id)
        FROM tbl_purchase_orders po
        LEFT JOIN tbl_purchase_order_prs popr ON popr.po_id = po.po_id
        WHERE (po.pr_id = ? OR popr.pr_id = ?)
          AND COALESCE(po.approval_status, 'Pending') = 'Approved'
    ");
    $approvedPoStmt->execute([$prId, $prId]);
    if ((int)$approvedPoStmt->fetchColumn() === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'The PO must be approved by Admin before items can be received.'
        ]);
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
