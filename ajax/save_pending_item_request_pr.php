<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Session expired. Please log in again.'
    ]);
    exit;
}

$requestedBy = $_SESSION['username'] ?? $_SESSION['user_code'];
$requestedByCode = $_SESSION['user_code'];

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pendingStmt = $pdo->query("
        SELECT id, item_name, item_color, description
        FROM item_requests
        WHERE status = 'Pending'
        ORDER BY id ASC
    ");
    $pendingItems = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($pendingItems) === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'No pending item requests found.'
        ]);
        exit;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_purchase_requests (
            pr_id INT AUTO_INCREMENT PRIMARY KEY,
            pr_ref_no VARCHAR(30) DEFAULT NULL UNIQUE,
            request_date DATE NOT NULL,
            requested_by VARCHAR(100) DEFAULT NULL,
            requested_by_code VARCHAR(100) DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_purchase_requests_ref (pr_ref_no),
            INDEX idx_purchase_requests_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_purchase_request_items (
            pr_item_id INT AUTO_INCREMENT PRIMARY KEY,
            pr_id INT NOT NULL,
            sku VARCHAR(100) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            request_qty DECIMAL(12,2) NOT NULL,
            unit VARCHAR(50) DEFAULT NULL,
            on_hand_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            reserved_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            available_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pr_items_pr_id (pr_id),
            INDEX idx_pr_items_sku (sku)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->beginTransaction();

    $header = $pdo->prepare("
        INSERT INTO tbl_purchase_requests
            (request_date, requested_by, requested_by_code, status)
        VALUES
            (CURDATE(), ?, ?, 'Pending')
    ");
    $header->execute([$requestedBy, $requestedByCode]);

    $prId = (int)$pdo->lastInsertId();
    $prRefNo = 'PR-' . str_pad((string)$prId, 6, '0', STR_PAD_LEFT);

    $updateRef = $pdo->prepare("
        UPDATE tbl_purchase_requests
        SET pr_ref_no = ?
        WHERE pr_id = ?
    ");
    $updateRef->execute([$prRefNo, $prId]);

    $insertItem = $pdo->prepare("
        INSERT INTO tbl_purchase_request_items
            (pr_id, sku, item_name, description, request_qty, unit, on_hand_qty, reserved_qty, available_qty, reorder_level)
        VALUES
            (?, ?, ?, ?, 1, '', 0, 0, 0, 0)
    ");

    $updateItemRequest = $pdo->prepare("
        UPDATE item_requests
        SET status = 'Ordered'
        WHERE id = ?
    ");

    foreach ($pendingItems as $item) {
        $sku = 'REQ-' . str_pad((string)$item['id'], 6, '0', STR_PAD_LEFT);
        $description = 'Color: ' . ($item['item_color'] ?: 'N/A');
        if (!empty($item['description'])) {
            $description .= "\n" . $item['description'];
        }

        $insertItem->execute([
            $prId,
            $sku,
            $item['item_name'],
            $description
        ]);

        $updateItemRequest->execute([(int)$item['id']]);
    }

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Purchase request created: ' . $prRefNo,
        'pr_ref_no' => $prRefNo
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
