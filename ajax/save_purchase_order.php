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

$prId = (int)($_POST['pr_id'] ?? 0);
$supplierId = (int)($_POST['supplier_id'] ?? 0);
$itemsPayload = trim($_POST['items'] ?? '');
$items = json_decode($itemsPayload, true);
$createdBy = $_SESSION['username'] ?? $_SESSION['user_code'];

if ($prId <= 0 || $supplierId <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please select a valid PR and supplier.'
    ]);
    exit;
}

if (!is_array($items) || count($items) === 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please enter at least one PO quantity.'
    ]);
    exit;
}

$requestedItems = [];
foreach ($items as $item) {
    $sku = trim($item['sku'] ?? '');
    $poQty = (float)($item['po_qty'] ?? 0);

    if ($sku !== '' && $poQty > 0) {
        $requestedItems[$sku] = $poQty;
    }
}

if (count($requestedItems) === 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please enter at least one PO quantity greater than zero.'
    ]);
    exit;
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_purchase_orders (
            po_id INT AUTO_INCREMENT PRIMARY KEY,
            po_ref_no VARCHAR(30) DEFAULT NULL UNIQUE,
            pr_id INT NOT NULL,
            supplier_id INT NOT NULL,
            po_date DATE NOT NULL,
            created_by VARCHAR(100) DEFAULT NULL,
            created_by_code VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_po_ref_no (po_ref_no),
            INDEX idx_po_pr_id (pr_id),
            INDEX idx_po_supplier_id (supplier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_purchase_order_items (
            po_item_id INT AUTO_INCREMENT PRIMARY KEY,
            po_id INT NOT NULL,
            po_ref_no VARCHAR(30) DEFAULT NULL,
            pr_item_id INT NOT NULL,
            sku VARCHAR(100) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            material_type VARCHAR(100) DEFAULT NULL,
            description TEXT NULL,
            color VARCHAR(100) DEFAULT NULL,
            request_qty DECIMAL(12,2) NOT NULL,
            po_qty DECIMAL(12,2) NOT NULL,
            unit VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_po_items_po_id (po_id),
            INDEX idx_po_items_sku (sku),
            CONSTRAINT fk_po_items_order
                FOREIGN KEY (po_id) REFERENCES tbl_purchase_orders(po_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $poItemColumns = $pdo->query("SHOW COLUMNS FROM tbl_purchase_order_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('po_ref_no', $poItemColumns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_order_items ADD po_ref_no VARCHAR(30) DEFAULT NULL AFTER po_id");
    }
    if (!in_array('material_type', $poItemColumns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_order_items ADD material_type VARCHAR(100) DEFAULT NULL AFTER item_name");
    }

    $prStmt = $pdo->prepare("
        SELECT pr_id, pr_ref_no
        FROM tbl_purchase_requests
        WHERE pr_id = ?
    ");
    $prStmt->execute([$prId]);
    $pr = $prStmt->fetch(PDO::FETCH_ASSOC);

    if (!$pr) {
        echo json_encode([
            'status' => 'error',
            'message' => 'PR request was not found.'
        ]);
        exit;
    }

    $supplierStmt = $pdo->prepare("
        SELECT supplier_id, supplier_name, supplier_owner, address, contact_no, email
        FROM tbl_suppliers
        WHERE supplier_id = ?
    ");
    $supplierStmt->execute([$supplierId]);
    $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);

    if (!$supplier) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Supplier was not found.'
        ]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($requestedItems), '?'));
    $itemStmt = $pdo->prepare("
        SELECT
            pri.pr_item_id,
            pri.sku,
            pri.item_name,
            ti.material_type,
            pri.description,
            ti.color,
            pri.request_qty,
            pri.unit
        FROM tbl_purchase_request_items pri
        LEFT JOIN tbl_items ti ON ti.sku = pri.sku
        WHERE pri.pr_id = ?
          AND pri.sku IN ($placeholders)
    ");
    $itemStmt->execute(array_merge([$prId], array_keys($requestedItems)));

    $poRows = [];
    while ($row = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
        $poQty = (float)$requestedItems[$row['sku']];

        $row['po_qty'] = $poQty;
        $poRows[] = $row;
    }

    if (count($poRows) !== count($requestedItems)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'One or more PO items were not found in the selected PR.'
        ]);
        exit;
    }

    $pdo->beginTransaction();

    $headerStmt = $pdo->prepare("
        INSERT INTO tbl_purchase_orders
            (pr_id, supplier_id, po_date, created_by, created_by_code)
        VALUES
            (?, ?, CURDATE(), ?, ?)
    ");
    $headerStmt->execute([
        $prId,
        $supplierId,
        $createdBy,
        $_SESSION['user_code']
    ]);

    $poId = (int)$pdo->lastInsertId();
    $poRefNo = 'PO-' . str_pad((string)$poId, 6, '0', STR_PAD_LEFT);

    $updateStmt = $pdo->prepare("
        UPDATE tbl_purchase_orders
        SET po_ref_no = ?
        WHERE po_id = ?
    ");
    $updateStmt->execute([$poRefNo, $poId]);

    $insertItemStmt = $pdo->prepare("
        INSERT INTO tbl_purchase_order_items
            (po_id, po_ref_no, pr_item_id, sku, item_name, material_type, description, color, request_qty, po_qty, unit)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($poRows as $row) {
        $insertItemStmt->execute([
            $poId,
            $poRefNo,
            (int)$row['pr_item_id'],
            $row['sku'],
            $row['item_name'],
            $row['material_type'],
            $row['description'],
            $row['color'],
            (float)$row['request_qty'],
            (float)$row['po_qty'],
            $row['unit']
        ]);
    }

    $updatePrStatusStmt = $pdo->prepare("
        UPDATE tbl_purchase_requests
        SET status = 'PO Requested'
        WHERE pr_id = ?
    ");
    $updatePrStatusStmt->execute([$prId]);

    $pdo->commit();
    audit_log($pdo, 'CREATE', 'Purchase Order', $poRefNo, 'Created PO from PR ' . $pr['pr_ref_no'] . '; purchase_request.status -> "PO Requested".');

    $printItems = array_map(function($row) {
        return [
            'sku' => $row['sku'],
            'item_name' => $row['item_name'],
            'material_type' => $row['material_type'],
            'description' => $row['description'],
            'color' => $row['color'] ?? 'N/A',
            'request_qty' => (float)$row['request_qty'],
            'po_qty' => (float)$row['po_qty'],
            'unit' => $row['unit']
        ];
    }, $poRows);

    echo json_encode([
        'status' => 'success',
        'message' => 'Purchase order saved: ' . $poRefNo,
        'purchase_order' => [
            'po_id' => $poId,
            'po_ref_no' => $poRefNo,
            'po_date' => date('Y-m-d'),
            'pr_id' => (int)$pr['pr_id'],
            'pr_ref_no' => $pr['pr_ref_no'],
            'created_by' => $createdBy,
            'supplier' => [
                'supplier_id' => (int)$supplier['supplier_id'],
                'supplier_name' => $supplier['supplier_name'],
                'supplier_owner' => $supplier['supplier_owner'],
                'address' => $supplier['address'],
                'contact_no' => $supplier['contact_no'],
                'email' => $supplier['email']
            ],
            'items' => $printItems
        ]
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => "Request failed."
    ]);
}
