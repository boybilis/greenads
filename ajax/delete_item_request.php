<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
    exit;
}

$requestId = (int)($_POST['id'] ?? 0);
if ($requestId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid item request reference.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, status, requested_by
        FROM item_requests
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        echo json_encode(['status' => 'error', 'message' => 'Item request not found.']);
        exit;
    }

    if (($request['status'] ?? '') !== 'Pending') {
        echo json_encode(['status' => 'error', 'message' => 'Only pending item requests can be deleted.']);
        exit;
    }

    $userType = $_SESSION['user_type'] ?? '';
    $userCode = $_SESSION['user_code'] ?? '';
    $username = $_SESSION['username'] ?? '';
    $isOwner = in_array($request['requested_by'] ?? '', [$userCode, $username], true);

    if ($userType !== 'Admin' && !$isOwner) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'You are not allowed to delete this item request.']);
        exit;
    }

    $deleteStmt = $pdo->prepare("DELETE FROM item_requests WHERE id = ?");
    $deleteStmt->execute([$requestId]);

    echo json_encode(['status' => 'success', 'message' => 'Item request deleted.']);
} catch (Throwable $e) {
    error_log('delete_item_request failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Delete failed: ' . $e->getMessage()]);
}
?>
