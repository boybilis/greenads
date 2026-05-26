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

$supplierId = (int)($_POST['supplier_id'] ?? 0);

if ($supplierId <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid supplier.'
    ]);
    exit;
}

try {
    $nameStmt = $pdo->prepare("SELECT supplier_name FROM tbl_suppliers WHERE supplier_id = ?");
    $nameStmt->execute([$supplierId]);
    $supplierName = $nameStmt->fetchColumn();

    $stmt = $pdo->prepare("DELETE FROM tbl_suppliers WHERE supplier_id = ?");
    $stmt->execute([$supplierId]);
    audit_log($pdo, 'DELETE', 'Suppliers', (string)$supplierId, 'Deleted supplier ' . ($supplierName ?: '') . '.');

    echo json_encode([
        'status' => 'success',
        'message' => 'Supplier deleted successfully.'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
