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

    $stmt = $pdo->query("
        SELECT supplier_id, supplier_name, supplier_owner, address, contact_no, email
        FROM tbl_suppliers
        ORDER BY supplier_name ASC
    ");

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row['supplier_name_display'] = htmlspecialchars($row['supplier_name']);
        $row['supplier_owner_display'] = htmlspecialchars($row['supplier_owner']);
        $row['contact_no_display'] = htmlspecialchars($row['contact_no']);
        $row['email_display'] = htmlspecialchars($row['email'] ?: '-');
        $row['action'] = '
            <a href="#" class="edit-supplier" data-id="' . (int)$row['supplier_id'] . '">
                <span class="badge badge-warning">Edit</span>
            </a>
            |
            <a href="#" class="delete-supplier" data-id="' . (int)$row['supplier_id'] . '">
                <span class="badge badge-danger">Delete</span>
            </a>
        ';
        $data[] = $row;
    }

    echo json_encode(['data' => $data]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'data' => [],
        'message' => "Request failed."
    ]);
}

