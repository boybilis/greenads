<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');

header('Content-Type: application/json');

if (($_SESSION['user_type'] ?? '') !== 'Inventory') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Inventory users can encode PR requests.']);
    exit;
}

$prId = (int)($_POST['pr_id'] ?? 0);

if ($prId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid PR request.']);
    exit;
}

try {
    $prItemColumns = $pdo->query("SHOW COLUMNS FROM tbl_purchase_request_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('item_request_id', $prItemColumns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_request_items ADD item_request_id INT DEFAULT NULL AFTER pr_id");
        $pdo->exec("CREATE INDEX idx_pr_items_item_request_id ON tbl_purchase_request_items (item_request_id)");
    }
    $pdo->exec("
        UPDATE tbl_purchase_request_items
        SET item_request_id = CAST(SUBSTRING(sku, 5) AS UNSIGNED)
        WHERE item_request_id IS NULL
          AND sku REGEXP '^REQ-[0-9]+$'
    ");

    $stmt = $pdo->prepare("SELECT pr_ref_no, status FROM tbl_purchase_requests WHERE pr_id = ? LIMIT 1");
    $stmt->execute([$prId]);
    $pr = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pr) {
        echo json_encode(['status' => 'error', 'message' => 'PR request not found.']);
        exit;
    }

    if (($pr['status'] ?? '') !== 'PO Fulfilled') {
        echo json_encode(['status' => 'error', 'message' => 'Only PO Fulfilled requests can be encoded.']);
        exit;
    }

    $pdo->beginTransaction();

    $update = $pdo->prepare("UPDATE tbl_purchase_requests SET status = 'Encoded' WHERE pr_id = ?");
    $update->execute([$prId]);

    $availableStmt = $pdo->prepare("
        UPDATE item_requests ir
        INNER JOIN tbl_purchase_request_items pri ON pri.item_request_id = ir.id
        SET ir.status = 'Now available'
        WHERE pri.pr_id = ?
          AND ir.status = 'Ordered'
    ");
    $availableStmt->execute([$prId]);

    $pdo->commit();

    audit_log($pdo, 'ENCODE', 'Purchase Request', $pr['pr_ref_no'] ?: (string)$prId, 'status: "PO Fulfilled" -> "Encoded"');

    echo json_encode(['status' => 'success', 'message' => 'PR request marked as encoded.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Request failed."]);
}
?>
