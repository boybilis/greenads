<?php

function ensure_item_request_link_schema(PDO $pdo): void
{
    $columns = $pdo->query("SHOW COLUMNS FROM tbl_purchase_request_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('item_request_id', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_request_items ADD item_request_id INT DEFAULT NULL AFTER pr_id");
    }

    $indexStmt = $pdo->query("SHOW INDEX FROM tbl_purchase_request_items WHERE Key_name = 'idx_pr_items_item_request_id'");
    if (!$indexStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("CREATE INDEX idx_pr_items_item_request_id ON tbl_purchase_request_items (item_request_id)");
    }

    $pdo->exec("
        UPDATE tbl_purchase_request_items
        SET item_request_id = CAST(SUBSTRING(sku, 5) AS UNSIGNED)
        WHERE item_request_id IS NULL
          AND sku REGEXP '^REQ-[0-9]+$'
    ");
}

function sync_encoded_item_requests(PDO $pdo, ?int $prId = null): int
{
    $wherePr = $prId !== null ? ' AND pr.pr_id = ?' : '';
    $params = $prId !== null ? [$prId] : [];

    // Recover legacy links only when the requester and item name identify one row.
    $unlinkedStmt = $pdo->prepare("
        SELECT pri.pr_item_id, pri.item_name, pr.requested_by, pr.requested_by_code
        FROM tbl_purchase_request_items pri
        INNER JOIN tbl_purchase_requests pr ON pr.pr_id = pri.pr_id
        WHERE pr.status = 'Encoded'
          AND pri.item_request_id IS NULL
          {$wherePr}
    ");
    $unlinkedStmt->execute($params);

    $candidateStmt = $pdo->prepare("
        SELECT id
        FROM item_requests
        WHERE status IN ('PR Requested', 'Ordered', 'PO Requested', 'PO Approved', 'PO Fulfilled')
          AND item_name = ?
          AND (requested_by = ? OR requested_by = ?)
        ORDER BY id ASC
        LIMIT 2
    ");
    $linkStmt = $pdo->prepare("
        UPDATE tbl_purchase_request_items
        SET item_request_id = ?
        WHERE pr_item_id = ?
          AND item_request_id IS NULL
    ");

    foreach ($unlinkedStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $candidateStmt->execute([
            $line['item_name'],
            $line['requested_by_code'] ?? '',
            $line['requested_by'] ?? ''
        ]);
        $candidateIds = $candidateStmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($candidateIds) === 1) {
            $linkStmt->execute([(int)$candidateIds[0], (int)$line['pr_item_id']]);
        }
    }

    $updateParams = $prId !== null ? [$prId] : [];
    $availableStmt = $pdo->prepare("
        UPDATE item_requests ir
        INNER JOIN tbl_purchase_request_items pri ON pri.item_request_id = ir.id
        INNER JOIN tbl_purchase_requests pr ON pr.pr_id = pri.pr_id
        SET ir.status = 'Now available'
        WHERE pr.status = 'Encoded'
          AND ir.status IN ('PR Requested', 'Ordered', 'PO Requested', 'PO Approved', 'PO Fulfilled')
          {$wherePr}
    ");
    $availableStmt->execute($updateParams);

    return $availableStmt->rowCount();
}

?>
