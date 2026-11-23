<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
    exit;
}
if (($_SESSION['user_type'] ?? '') !== 'Purchasing') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only purchasing can edit a PO request.']);
    exit;
}

$poId = (int)($_POST['po_id'] ?? 0);
$supplierId = (int)($_POST['supplier_id'] ?? 0);
$items = json_decode((string)($_POST['items'] ?? ''), true);
if ($poId <= 0 || $supplierId <= 0 || !is_array($items) || !$items) {
    echo json_encode(['status' => 'error', 'message' => 'Please provide a valid supplier and PO items.']);
    exit;
}

try {
    $poStmt = $pdo->prepare("SELECT po_ref_no, approval_status, fulfillment_status FROM tbl_purchase_orders WHERE po_id = ? LIMIT 1");
    $poStmt->execute([$poId]);
    $po = $poStmt->fetch(PDO::FETCH_ASSOC);
    if (!$po) {
        echo json_encode(['status' => 'error', 'message' => 'PO request not found.']);
        exit;
    }
    if (($po['approval_status'] ?? 'Pending') === 'Approved') {
        echo json_encode(['status' => 'error', 'message' => 'Admin must undo PO approval before it can be edited.']);
        exit;
    }
    if (strcasecmp(trim((string)$po['fulfillment_status']), 'PO Verified') === 0) {
        echo json_encode(['status' => 'error', 'message' => 'A verified PO cannot be edited.']);
        exit;
    }

    $supplierStmt = $pdo->prepare('SELECT supplier_id FROM tbl_suppliers WHERE supplier_id = ?');
    $supplierStmt->execute([$supplierId]);
    if (!$supplierStmt->fetchColumn()) {
        echo json_encode(['status' => 'error', 'message' => 'Supplier was not found.']);
        exit;
    }

    $submitted = [];
    foreach ($items as $item) {
        $poItemId = (int)($item['po_item_id'] ?? 0);
        $poQty = (float)($item['po_qty'] ?? 0);
        $unitPrice = max(0, (float)($item['unit_price'] ?? 0));
        if ($poItemId > 0 && $poQty > 0) {
            $submitted[$poItemId] = [$poQty, $unitPrice];
        }
    }
    if (!$submitted) {
        echo json_encode(['status' => 'error', 'message' => 'Enter at least one valid PO item quantity.']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($submitted), '?'));
    $ownedStmt = $pdo->prepare("SELECT po_item_id FROM tbl_purchase_order_items WHERE po_id = ? AND po_item_id IN ($placeholders)");
    $ownedStmt->execute(array_merge([$poId], array_keys($submitted)));
    if (count($ownedStmt->fetchAll(PDO::FETCH_COLUMN)) !== count($submitted)) {
        echo json_encode(['status' => 'error', 'message' => 'One or more PO items are invalid.']);
        exit;
    }

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE tbl_purchase_orders SET supplier_id = ? WHERE po_id = ?')->execute([$supplierId, $poId]);
    $updateItem = $pdo->prepare('UPDATE tbl_purchase_order_items SET po_qty = ?, unit_price = ?, line_total = ? WHERE po_item_id = ? AND po_id = ?');
    foreach ($submitted as $poItemId => [$poQty, $unitPrice]) {
        $updateItem->execute([$poQty, $unitPrice, $poQty * $unitPrice, $poItemId, $poId]);
    }
    $pdo->commit();

    audit_log($pdo, 'UPDATE', 'Purchase Order', $po['po_ref_no'] ?: (string)$poId, 'Purchasing updated pending PO supplier, quantities, and/or unit prices.');
    echo json_encode(['status' => 'success', 'message' => 'PO request updated and is ready for admin approval.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to update PO request.']);
}
