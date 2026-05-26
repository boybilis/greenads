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

$supplierName = trim($_POST['supplier_name'] ?? '');
$supplierId = (int)($_POST['supplier_id'] ?? 0);
$supplierOwner = trim($_POST['supplier_owner'] ?? '');
$address = trim($_POST['address'] ?? '');
$contactNo = trim($_POST['contact_no'] ?? '');
$email = trim($_POST['email'] ?? '');
$createdBy = $_SESSION['username'] ?? $_SESSION['user_code'];

if ($supplierName === '' || $supplierOwner === '' || $address === '' || $contactNo === '') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please complete all required fields.'
    ]);
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please enter a valid email address.'
    ]);
    exit;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_suppliers (
            supplier_id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_name VARCHAR(255) NOT NULL,
            supplier_owner VARCHAR(255) NOT NULL,
            address TEXT NOT NULL,
            contact_no VARCHAR(100) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            created_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_supplier_name (supplier_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $dup = $pdo->prepare("
        SELECT COUNT(*)
        FROM tbl_suppliers
        WHERE supplier_name = ? AND supplier_id <> ?
    ");
    $dup->execute([$supplierName, $supplierId]);
    if ((int)$dup->fetchColumn() > 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Supplier already exists.'
        ]);
        exit;
    }

    if ($supplierId > 0) {
        $beforeStmt = $pdo->prepare("SELECT supplier_name, supplier_owner, address, contact_no, email FROM tbl_suppliers WHERE supplier_id = ? LIMIT 1");
        $beforeStmt->execute([$supplierId]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare("
            UPDATE tbl_suppliers
            SET supplier_name = ?,
                supplier_owner = ?,
                address = ?,
                contact_no = ?,
                email = ?
            WHERE supplier_id = ?
        ");
        $stmt->execute([
            $supplierName,
            $supplierOwner,
            $address,
            $contactNo,
            $email !== '' ? $email : null,
            $supplierId
        ]);

        $message = 'Supplier updated successfully.';
        $after = [
            'supplier_name' => $supplierName,
            'supplier_owner' => $supplierOwner,
            'address' => $address,
            'contact_no' => $contactNo,
            'email' => $email
        ];
        audit_log($pdo, 'UPDATE', 'Suppliers', (string)$supplierId, audit_changed_fields($before, $after, ['supplier_name', 'supplier_owner', 'address', 'contact_no', 'email']));
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO tbl_suppliers
                (supplier_name, supplier_owner, address, contact_no, email, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $supplierName,
            $supplierOwner,
            $address,
            $contactNo,
            $email !== '' ? $email : null,
            $createdBy
        ]);

        $message = 'Supplier saved successfully.';
        audit_log($pdo, 'CREATE', 'Suppliers', (string)$pdo->lastInsertId(), 'Added supplier ' . $supplierName . '.');
    }

    echo json_encode([
        'status' => 'success',
        'message' => $message
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
