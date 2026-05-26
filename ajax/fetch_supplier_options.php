<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');
if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['data' => [], 'message' => 'Unauthorized.']);
    exit;
}


try {
    $stmt = $pdo->query("
        SELECT supplier_id, supplier_name
        FROM tbl_suppliers
        ORDER BY supplier_name ASC
    ");

    echo json_encode([
        'status' => 'success',
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => "Request failed.",
        'data' => []
    ]);
}
