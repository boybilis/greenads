<?php

function get_po_linked_pr_ids(PDO $pdo, int $poId): array
{
    $prIds = [];

    $directStmt = $pdo->prepare("SELECT pr_id FROM tbl_purchase_orders WHERE po_id = ? AND pr_id IS NOT NULL");
    $directStmt->execute([$poId]);
    $prIds = array_merge($prIds, array_map('intval', $directStmt->fetchAll(PDO::FETCH_COLUMN)));

    $linkedStmt = $pdo->prepare("SELECT pr_id FROM tbl_purchase_order_prs WHERE po_id = ?");
    $linkedStmt->execute([$poId]);
    $prIds = array_merge($prIds, array_map('intval', $linkedStmt->fetchAll(PDO::FETCH_COLUMN)));

    $itemStmt = $pdo->prepare("
        SELECT DISTINCT pri.pr_id
        FROM tbl_purchase_order_items poi
        INNER JOIN tbl_purchase_request_items pri ON pri.pr_item_id = poi.pr_item_id
        WHERE poi.po_id = ?
    ");
    $itemStmt->execute([$poId]);
    $prIds = array_merge($prIds, array_map('intval', $itemStmt->fetchAll(PDO::FETCH_COLUMN)));

    return array_values(array_unique(array_filter($prIds)));
}

function mark_pr_linked_item_requests_status(PDO $pdo, array $prIds, string $status, array $allowedStatuses): int
{
    $prIds = array_values(array_unique(array_filter(array_map('intval', $prIds))));
    if (!$prIds || !$allowedStatuses) {
        return 0;
    }

    $prPlaceholders = implode(',', array_fill(0, count($prIds), '?'));
    $statusPlaceholders = implode(',', array_fill(0, count($allowedStatuses), '?'));
    $stmt = $pdo->prepare("
        UPDATE item_requests ir
        INNER JOIN tbl_purchase_request_items pri ON pri.item_request_id = ir.id
        SET ir.status = ?
        WHERE pri.pr_id IN ($prPlaceholders)
          AND TRIM(ir.status) IN ($statusPlaceholders)
    ");
    $stmt->execute(array_merge([$status], $prIds, $allowedStatuses));

    return $stmt->rowCount();
}

function sync_item_request_po_statuses(PDO $pdo): int
{
    $stmt = $pdo->prepare("
        UPDATE item_requests ir
        INNER JOIN tbl_purchase_request_items pri ON pri.item_request_id = ir.id
        INNER JOIN tbl_purchase_requests pr ON pr.pr_id = pri.pr_id
        SET ir.status = TRIM(pr.status)
        WHERE TRIM(pr.status) IN ('PO Requested', 'PO Approved', 'PO Fulfilled')
          AND TRIM(ir.status) IN ('PR Requested', 'Ordered', 'PO Requested', 'PO Approved', 'PO Fulfilled')
          AND TRIM(ir.status) <> TRIM(pr.status)
    ");
    $stmt->execute();

    return $stmt->rowCount();
}

function mark_po_linked_prs_fulfilled(PDO $pdo, int $poId): int
{
    $prIds = get_po_linked_pr_ids($pdo, $poId);
    if (!$prIds) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($prIds), '?'));
    $stmt = $pdo->prepare("
        UPDATE tbl_purchase_requests
        SET status = 'PO Fulfilled'
        WHERE pr_id IN ($placeholders)
          AND TRIM(status) IN ('PO Requested', 'PO Approved')
    ");
    $stmt->execute($prIds);
    mark_pr_linked_item_requests_status($pdo, $prIds, 'PO Fulfilled', ['PO Requested', 'PO Approved']);

    return $stmt->rowCount();
}

function mark_po_linked_prs_approved(PDO $pdo, int $poId): int
{
    $prIds = get_po_linked_pr_ids($pdo, $poId);
    if (!$prIds) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($prIds), '?'));
    $stmt = $pdo->prepare("
        UPDATE tbl_purchase_requests
        SET status = 'PO Approved'
        WHERE pr_id IN ($placeholders)
          AND TRIM(status) IN ('PO Requested', 'PO Fulfilled')
    ");
    $stmt->execute($prIds);
    mark_pr_linked_item_requests_status($pdo, $prIds, 'PO Approved', ['PR Requested', 'Ordered', 'PO Requested', 'PO Fulfilled']);

    return $stmt->rowCount();
}

function sync_verified_po_pr_statuses(PDO $pdo): int
{
    $poIds = $pdo->query("
        SELECT po_id
        FROM tbl_purchase_orders
        WHERE TRIM(fulfillment_status) = 'PO Verified'
    ")->fetchAll(PDO::FETCH_COLUMN);

    $updated = 0;
    foreach ($poIds as $poId) {
        $updated += mark_po_linked_prs_fulfilled($pdo, (int)$poId);
    }

    return $updated;
}

function sync_approved_po_pr_statuses(PDO $pdo): int
{
    $poIds = $pdo->query("
        SELECT po_id
        FROM tbl_purchase_orders
        WHERE TRIM(approval_status) = 'Approved'
          AND TRIM(COALESCE(fulfillment_status, 'Pending')) <> 'PO Verified'
    ")->fetchAll(PDO::FETCH_COLUMN);

    $updated = 0;
    foreach ($poIds as $poId) {
        $updated += mark_po_linked_prs_approved($pdo, (int)$poId);
    }

    return $updated;
}
