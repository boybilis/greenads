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

$items = $_POST['items'] ?? [];
$payload = trim($_POST['pr_items_payload'] ?? '');
$requestedBy = $_SESSION['username'] ?? $_SESSION['user_code'];
$requestedByCode = $_SESSION['user_code'];

if ((!is_array($items) || count($items) === 0) && $payload !== '') {
    $decodedPayload = json_decode($payload, true);
    if (is_array($decodedPayload)) {
        $items = $decodedPayload;
    }
}

if (!is_array($items) || count($items) === 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'No materials were selected for PR request. Please wait for the material list to load, then try again.'
    ]);
    exit;
}

$requestRows = [];
foreach ($items as $item) {
    $sku = trim($item['sku'] ?? '');
    $requestQty = (float)($item['request_qty'] ?? 0);

    if ($sku !== '' && $requestQty > 0) {
        $requestRows[] = [
            'sku' => $sku,
            'request_qty' => $requestQty
        ];
    }
}

if (count($requestRows) === 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please enter a request quantity greater than zero.'
    ]);
    exit;
}

try {
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
            INDEX idx_pr_items_sku (sku),
            CONSTRAINT fk_pr_items_request
                FOREIGN KEY (pr_id) REFERENCES tbl_purchase_requests(pr_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM tbl_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reorder_level', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_items ADD reorder_level INT NOT NULL DEFAULT 10");
    }

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

    $itemStmt = $pdo->prepare("
        SELECT sku, material_name, description, quantity, unit, reorder_level
        FROM tbl_items
        WHERE sku = ?
    ");

    $reservedStmt = $pdo->prepare("
        SELECT COALESCE(SUM(oi.qty), 0)
        FROM tbl_or_items oi
        INNER JOIN tbl_or o ON o.or_id = oi.or_id
        WHERE o.or_status = 0 AND oi.sku = ?
    ");

    $insertItem = $pdo->prepare("
        INSERT INTO tbl_purchase_request_items
            (pr_id, sku, item_name, description, request_qty, unit, on_hand_qty, reserved_qty, available_qty, reorder_level)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($requestRows as $requestRow) {
        $itemStmt->execute([$requestRow['sku']]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new RuntimeException('Material not found: ' . $requestRow['sku']);
        }

        $reservedStmt->execute([$requestRow['sku']]);
        $reservedQty = (float)$reservedStmt->fetchColumn();
        $onHandQty = (float)$item['quantity'];
        $availableQty = $onHandQty - $reservedQty;
        $reorderLevel = (float)$item['reorder_level'];

        $insertItem->execute([
            $prId,
            $item['sku'],
            $item['material_name'],
            $item['description'],
            $requestRow['request_qty'],
            $item['unit'],
            $onHandQty,
            $reservedQty,
            $availableQty,
            $reorderLevel
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Purchase request saved: ' . $prRefNo,
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
