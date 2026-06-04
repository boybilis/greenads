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

$prId = (int)($_POST['pr_id'] ?? 0);
if ($prId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid PR reference.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT pr_id, pr_ref_no, status, requested_by_code FROM tbl_purchase_requests WHERE pr_id = ? LIMIT 1");
    $stmt->execute([$prId]);
    $pr = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pr) {
        echo json_encode(['status' => 'error', 'message' => 'Purchase request not found.']);
        exit;
    }

    $userType = $_SESSION['user_type'] ?? '';
    $isOwner = ($pr['requested_by_code'] ?? '') === ($_SESSION['user_code'] ?? '');
    if (!in_array($userType, ['Admin', 'Inventory', 'Purchasing'], true) && !$isOwner) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'You are not allowed to delete this PR.']);
        exit;
    }

    $poTableStmt = $pdo->prepare("SHOW TABLES LIKE 'tbl_purchase_orders'");
    $poTableStmt->execute();
    if ($poTableStmt->fetchColumn()) {
        $poCountStmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_purchase_orders WHERE pr_id = ?");
        $poCountStmt->execute([$prId]);
        if ((int)$poCountStmt->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete this PR because it already has a PO. Delete the PO first.']);
            exit;
        }
    }

    if (in_array($pr['status'] ?? '', ['PO Fulfilled', 'Encoded'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot delete fulfilled or encoded PR records.']);
        exit;
    }

    $pdo->beginTransaction();

    $delItems = $pdo->prepare("DELETE FROM tbl_purchase_request_items WHERE pr_id = ?");
    $delItems->execute([$prId]);

    $delPr = $pdo->prepare("DELETE FROM tbl_purchase_requests WHERE pr_id = ?");
    $delPr->execute([$prId]);

    audit_log($pdo, 'DELETE', 'Purchase Request', $pr['pr_ref_no'] ?: (string)$prId, 'Deleted purchase request.');

    $pdo->commit();

    echo json_encode(['status' => 'success', 'message' => 'Purchase request deleted.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('delete_purchase_request failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Delete failed.']);
}
?>
