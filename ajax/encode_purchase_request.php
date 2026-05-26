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

    $update = $pdo->prepare("UPDATE tbl_purchase_requests SET status = 'Encoded' WHERE pr_id = ?");
    $update->execute([$prId]);

    audit_log($pdo, 'ENCODE', 'Purchase Request', $pr['pr_ref_no'] ?: (string)$prId, 'status: "PO Fulfilled" -> "Encoded"');

    echo json_encode(['status' => 'success', 'message' => 'PR request marked as encoded.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Request failed."]);
}
?>
