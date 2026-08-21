<?php
session_start();
require_once 'config.php';

function normalize_item_request_value($value, $emptyValue = '') {
    $value = trim((string)$value);
    $value = preg_replace('/\s+/u', ' ', $value);

    if ($value === '' || $value === '-') {
        return $emptyValue;
    }

    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function find_matching_inventory_item(PDO $pdo, $itemName, $itemColor) {
    $requested = [
        'name' => normalize_item_request_value($itemName),
        'color' => normalize_item_request_value($itemColor)
    ];

    $stmt = $pdo->query("
        SELECT sku, material_name, color, unit, description, quantity
        FROM tbl_items
    ");

    while ($item = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $inventory = [
            'name' => normalize_item_request_value($item['material_name'] ?? ''),
            'color' => normalize_item_request_value($item['color'] ?? '')
        ];

        $nameMatches = $requested['name'] !== ''
            && $inventory['name'] !== ''
            && (
                strpos($inventory['name'], $requested['name']) !== false
                || strpos($requested['name'], $inventory['name']) !== false
            );
        $colorMatches = $requested['color'] === $inventory['color'];

        if ($nameMatches && $colorMatches) {
            return $item;
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (($_SESSION['user_type'] ?? '') === 'Inventory') {
        exit("Inventory users are not allowed to create item requests.");
    }

  

    $requestId  = (int)($_POST['request_id'] ?? 0);
    $existingSku = trim((string)($_POST['existing_sku'] ?? ''));
    $item_name  = trim((string)($_POST['item_name'] ?? ''));
    $item_color = trim((string)($_POST['item_color'] ?? ''));
    $gsmSize    = trim((string)($_POST['gsm_size'] ?? ''));
    $requestQty = (float)($_POST['request_qty'] ?? 0);
    $unit       = trim((string)($_POST['unit'] ?? ''));
    $desc       = trim((string)($_POST['description'] ?? ''));
    $userCode   = $_SESSION['user_code'] ?? '';
    $username   = $_SESSION['username'] ?? '';

    if ($userCode === '' && $username === '') {
        exit("Session expired. Please log in again.");
    }

    if (empty($item_name)) {
        exit("Item name is required");
    }
    if ($requestQty <= 0 || $unit === '') {
        exit("Quantity and unit are required");
    }

    $selectedInventoryItem = null;
    if ($existingSku !== '') {
        $selectedStmt = $pdo->prepare("SELECT sku, material_name, color, unit, description, quantity FROM tbl_items WHERE sku = ? LIMIT 1");
        $selectedStmt->execute([$existingSku]);
        $selectedInventoryItem = $selectedStmt->fetch(PDO::FETCH_ASSOC);
        if (!$selectedInventoryItem) {
            exit("The selected inventory SKU is no longer available. Please search and select the item again.");
        }

        $item_name = trim((string)$selectedInventoryItem['material_name']);
        $item_color = trim((string)($selectedInventoryItem['color'] ?? ''));
        $unit = trim((string)($selectedInventoryItem['unit'] ?? $unit));
        if ($desc === '') {
            $desc = trim((string)($selectedInventoryItem['description'] ?? ''));
        }
    }

    $matchingItem = find_matching_inventory_item($pdo, $item_name, $item_color);
    if ($matchingItem && $selectedInventoryItem === null) {
        $sku = htmlspecialchars((string)($matchingItem['sku'] ?? ''), ENT_QUOTES, 'UTF-8');
        $quantity = (float)($matchingItem['quantity'] ?? 0);
        $stock = rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
        $existingUnit = htmlspecialchars((string)($matchingItem['unit'] ?? $unit), ENT_QUOTES, 'UTF-8');
        $existingDescription = trim((string)($matchingItem['description'] ?? ''));
        $existingDescription = $existingDescription !== '' ? $existingDescription : '-';
        $existingDescription = htmlspecialchars($existingDescription, ENT_QUOTES, 'UTF-8');
        exit(
            'A matching or similar item name with the same color already exists in the Item List as SKU ' . $sku .
            ' (on-hand: ' . $stock . ' ' . $existingUnit .
            '; description: ' . $existingDescription . '). ' .
            'Please use the existing inventory item instead of requesting a new one.'
        );
    }

    $columns = $pdo->query("SHOW COLUMNS FROM item_requests")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('request_qty', $columns, true)) {
        $pdo->exec("ALTER TABLE item_requests ADD request_qty DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER item_color");
    }
    if (!in_array('unit', $columns, true)) {
        $pdo->exec("ALTER TABLE item_requests ADD unit VARCHAR(50) DEFAULT NULL AFTER request_qty");
    }
    if (!in_array('gsm_size', $columns, true)) {
        $pdo->exec("ALTER TABLE item_requests ADD gsm_size VARCHAR(100) DEFAULT NULL AFTER item_color");
    }
    if (!in_array('existing_sku', $columns, true)) {
        $pdo->exec("ALTER TABLE item_requests ADD existing_sku VARCHAR(100) DEFAULT NULL AFTER item_name");
    }

    $requestedBy = $userCode !== '' ? $userCode : $username;

    if ($requestId > 0) {
        $stmt = $pdo->prepare("SELECT id, status, requested_by FROM item_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            exit("Item request not found.");
        }
        if (($existing['status'] ?? '') !== 'Pending') {
            exit("Only pending item requests can be edited.");
        }
        if (($_SESSION['user_type'] ?? '') !== 'Admin' && !in_array($existing['requested_by'], [$userCode, $username], true)) {
            exit("You are not allowed to edit this item request.");
        }

        $stmt = $pdo->prepare("
            UPDATE item_requests
            SET item_name = ?, existing_sku = ?, item_color = ?, gsm_size = ?, request_qty = ?, unit = ?, description = ?
            WHERE id = ?
        ");
        $stmt->execute([$item_name, $existingSku !== '' ? $existingSku : null, $item_color, $gsmSize, $requestQty, $unit, $desc, $requestId]);

        echo "success";
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO item_requests (item_name, existing_sku, item_color, gsm_size, request_qty, unit, description, requested_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // Store requester as user_code for consistent filtering.
    $stmt->execute([$item_name, $existingSku !== '' ? $existingSku : null, $item_color, $gsmSize, $requestQty, $unit, $desc, $requestedBy]);

    echo "success"; // IMPORTANT for toastr
}
