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
