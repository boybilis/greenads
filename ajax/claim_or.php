<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Session expired. Please log in again.'
    ]);
    exit;
}

$orId = (int)($_POST['or_id'] ?? 0);

if ($orId <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid material request.'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE tbl_or
        SET or_status = 3
        WHERE or_id = ? AND or_status = 1
    ");
    $stmt->execute([$orId]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Only approved material requests can be claimed.');
    }

    $refStmt = $pdo->prepare("SELECT or_no FROM tbl_or WHERE or_id = ?");
    $refStmt->execute([$orId]);
    $orNo = $refStmt->fetchColumn();
    audit_log($pdo, 'CLAIM', 'Material Request', $orNo ?: (string)$orId, 'or_status: "Approved" -> "Approved and Claimed"');

    echo json_encode([
        'status' => 'success',
        'message' => 'Material request marked as approved and claimed.'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => "Request failed."
    ]);
}
?>
