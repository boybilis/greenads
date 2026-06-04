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

$orId = (int)($_POST['or_id'] ?? 0);
if ($orId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid MR reference.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT or_id, or_no, or_status, user_code FROM tbl_or WHERE or_id = ? LIMIT 1");
    $stmt->execute([$orId]);
    $or = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$or) {
        echo json_encode(['status' => 'error', 'message' => 'Material request not found.']);
        exit;
    }

    $userType = $_SESSION['user_type'] ?? '';
    $isOwner = ($or['user_code'] ?? '') === ($_SESSION['user_code'] ?? '');
    if (!in_array($userType, ['Admin', 'Inventory'], true) && !$isOwner) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'You are not allowed to delete this MR.']);
        exit;
    }

    if ((int)$or['or_status'] !== 0) {
        echo json_encode(['status' => 'error', 'message' => 'Only pending material requests can be deleted.']);
        exit;
    }

    $pdo->beginTransaction();

    $delItems = $pdo->prepare("DELETE FROM tbl_or_items WHERE or_id = ?");
    $delItems->execute([$orId]);

    $delOr = $pdo->prepare("DELETE FROM tbl_or WHERE or_id = ?");
    $delOr->execute([$orId]);

    audit_log($pdo, 'DELETE', 'Material Request', $or['or_no'] ?: (string)$orId, 'Deleted pending material request.');

    $pdo->commit();

    echo json_encode(['status' => 'success', 'message' => 'Material request deleted.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('delete_or failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Delete failed.']);
}
?>
