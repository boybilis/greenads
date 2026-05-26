<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');
if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['data' => [], 'message' => 'Unauthorized.']);
    exit;
}


$prId = (int)($_GET['pr_id'] ?? 0);
//$prId = 1;
if ($prId <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid PR reference.'
    ]);
    exit;
}

try {
    $headerStmt = $pdo->prepare("
        SELECT pr_id, pr_ref_no, request_date, requested_by, status
        FROM tbl_purchase_requests
        WHERE pr_id = ?
    ");
    $headerStmt->execute([$prId]);
    $header = $headerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$header) {
        echo json_encode([
            'status' => 'error',
            'message' => 'PR request was not found.'
        ]);
        exit;
    }

    $itemStmt = $pdo->prepare("
    SELECT 
        pri.sku,
        pri.item_name,
        pri.description,
        ti.color,
        pri.request_qty,
        pri.unit,
        pri.available_qty
    FROM tbl_purchase_request_items pri
    LEFT JOIN tbl_items ti ON pri.sku = ti.sku
    WHERE pri.pr_id = ?
    ORDER BY pri.item_name ASC, pri.sku ASC
");
$itemStmt->execute([$prId]);

$items = [];

while ($row = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
    $items[] = [
        'sku' => $row['sku'],
        'item_name' => $row['item_name'],
        'description' => $row['description'],
        'color' => $row['color'] ?? 'N/A',
        'request_qty' => (float)$row['request_qty'],
        'unit' => $row['unit'],
        'available_qty' => (float)$row['available_qty']
    ];
}

    echo json_encode([
        'status' => 'success',
        'header' => [
            'pr_id' => (int)$header['pr_id'],
            'pr_ref_no' => $header['pr_ref_no'],
            'request_date' => $header['request_date'],
            'requested_by' => $header['requested_by'],
            'status' => $header['status']
        ],
        'items' => $items
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => "Request failed."
    ]);
}
