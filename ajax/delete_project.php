<?php
session_start();
require_once('config.php');
require_once('audit_helper.php');

header('Content-Type: application/json');

if (($_SESSION['user_type'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Admin can delete projects.']);
    exit;
}

$projCode = trim((string)($_POST['proj_code'] ?? ''));
if ($projCode === '') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid project code.']);
    exit;
}

function project_delete_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function project_delete_placeholders(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

try {
    $projectColumns = $pdo->query('SHOW COLUMNS FROM tbl_project')->fetchAll(PDO::FETCH_COLUMN);
    $hasApprovalColumn = in_array('proj_approval_status', $projectColumns, true);

    $pdo->beginTransaction();

    $approvalSelect = $hasApprovalColumn ? 'COALESCE(proj_approval_status, 1)' : '1';
    $projectStmt = $pdo->prepare("SELECT proj_code, proj_name, {$approvalSelect} AS approval_status FROM tbl_project WHERE proj_code = ? FOR UPDATE");
    $projectStmt->execute([$projCode]);
    $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        throw new RuntimeException('Project not found.');
    }
    if ((int)$project['approval_status'] !== 1) {
        throw new RuntimeException('Only approved projects can be deleted here.');
    }

    $orRows = [];
    if (project_delete_table_exists($pdo, 'tbl_or')) {
        $stmt = $pdo->prepare('SELECT or_id, or_no FROM tbl_or WHERE proj_code = ? FOR UPDATE');
        $stmt->execute([$projCode]);
        $orRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $orIds = array_values(array_map('intval', array_column($orRows, 'or_id')));
    $orNos = array_values(array_filter(array_column($orRows, 'or_no'), 'strlen'));

    $prRows = [];
    if (project_delete_table_exists($pdo, 'tbl_purchase_requests')) {
        $stmt = $pdo->prepare('SELECT pr_id, pr_ref_no FROM tbl_purchase_requests WHERE proj_code = ? FOR UPDATE');
        $stmt->execute([$projCode]);
        $prRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $prIds = array_values(array_map('intval', array_column($prRows, 'pr_id')));

    $poIds = [];
    if ($prIds && project_delete_table_exists($pdo, 'tbl_purchase_orders')) {
        $marks = project_delete_placeholders($prIds);

        $stmt = $pdo->prepare("SELECT po_id FROM tbl_purchase_orders WHERE pr_id IN ($marks)");
        $stmt->execute($prIds);
        $poIds = array_merge($poIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        if (project_delete_table_exists($pdo, 'tbl_purchase_order_prs')) {
            $stmt = $pdo->prepare("SELECT po_id FROM tbl_purchase_order_prs WHERE pr_id IN ($marks)");
            $stmt->execute($prIds);
            $poIds = array_merge($poIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }

        if (project_delete_table_exists($pdo, 'tbl_purchase_order_items') && project_delete_table_exists($pdo, 'tbl_purchase_request_items')) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT poi.po_id
                FROM tbl_purchase_order_items poi
                INNER JOIN tbl_purchase_request_items pri ON pri.pr_item_id = poi.pr_item_id
                WHERE pri.pr_id IN ($marks)
            ");
            $stmt->execute($prIds);
            $poIds = array_merge($poIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
    }
    $poIds = array_values(array_unique(array_filter($poIds)));

    // Never cascade-delete a grouped PO that also contains another project's PR.
    if ($poIds && project_delete_table_exists($pdo, 'tbl_purchase_requests')) {
        $marks = project_delete_placeholders($poIds);
        $linkedProjects = [];

        if (project_delete_table_exists($pdo, 'tbl_purchase_order_prs')) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT pr.proj_code
                FROM tbl_purchase_order_prs popr
                INNER JOIN tbl_purchase_requests pr ON pr.pr_id = popr.pr_id
                WHERE popr.po_id IN ($marks)
            ");
            $stmt->execute($poIds);
            $linkedProjects = array_merge($linkedProjects, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT pr.proj_code
            FROM tbl_purchase_orders po
            INNER JOIN tbl_purchase_requests pr ON pr.pr_id = po.pr_id
            WHERE po.po_id IN ($marks)
        ");
        $stmt->execute($poIds);
        $linkedProjects = array_merge($linkedProjects, $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (project_delete_table_exists($pdo, 'tbl_purchase_order_items') && project_delete_table_exists($pdo, 'tbl_purchase_request_items')) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT pr.proj_code
                FROM tbl_purchase_order_items poi
                INNER JOIN tbl_purchase_request_items pri ON pri.pr_item_id = poi.pr_item_id
                INNER JOIN tbl_purchase_requests pr ON pr.pr_id = pri.pr_id
                WHERE poi.po_id IN ($marks)
            ");
            $stmt->execute($poIds);
            $linkedProjects = array_merge($linkedProjects, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        foreach (array_unique($linkedProjects) as $linkedProject) {
            if ((string)$linkedProject !== $projCode) {
                throw new RuntimeException('Cannot delete this project because one of its POs is grouped with a PR from another project. Separate or delete that PO first.');
            }
        }
    }

    $poRows = [];
    if ($poIds) {
        $marks = project_delete_placeholders($poIds);
        $stmt = $pdo->prepare("SELECT po_id, po_ref_no FROM tbl_purchase_orders WHERE po_id IN ($marks) FOR UPDATE");
        $stmt->execute($poIds);
        $poRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $poRefs = array_values(array_filter(array_column($poRows, 'po_ref_no'), 'strlen'));

    // Net reversal per SKU: restore MR stock-outs, then remove encoded PO stock-ins.
    $stockAdjustments = [];
    if ($orNos && project_delete_table_exists($pdo, 'tbl_inventory_out')) {
        $marks = project_delete_placeholders($orNos);
        $stmt = $pdo->prepare("SELECT sku, SUM(quantity) AS qty FROM tbl_inventory_out WHERE reference_no IN ($marks) GROUP BY sku");
        $stmt->execute($orNos);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stockAdjustments[$row['sku']] = ($stockAdjustments[$row['sku']] ?? 0) + (float)$row['qty'];
        }
    }
    if ($poRefs && project_delete_table_exists($pdo, 'tbl_inventory_in')) {
        $marks = project_delete_placeholders($poRefs);
        $stmt = $pdo->prepare("SELECT sku, SUM(quantity) AS qty FROM tbl_inventory_in WHERE po_code IN ($marks) GROUP BY sku");
        $stmt->execute($poRefs);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stockAdjustments[$row['sku']] = ($stockAdjustments[$row['sku']] ?? 0) - (float)$row['qty'];
        }
    }

    $lockItem = $pdo->prepare('SELECT quantity FROM tbl_items WHERE sku = ? FOR UPDATE');
    $updateItem = $pdo->prepare('UPDATE tbl_items SET quantity = quantity + ? WHERE sku = ?');
    foreach ($stockAdjustments as $sku => $adjustment) {
        if (abs($adjustment) < 0.00001) {
            continue;
        }
        $lockItem->execute([$sku]);
        $currentQty = $lockItem->fetchColumn();
        if ($currentQty === false) {
            throw new RuntimeException('Cannot adjust inventory because item ' . $sku . ' no longer exists.');
        }
        if ((float)$currentQty + $adjustment < -0.00001) {
            throw new RuntimeException('Cannot delete this project because received stock for ' . $sku . ' has already been consumed.');
        }
        $updateItem->execute([$adjustment, $sku]);
    }

    if ($orNos && project_delete_table_exists($pdo, 'tbl_inventory_out')) {
        $marks = project_delete_placeholders($orNos);
        $pdo->prepare("DELETE FROM tbl_inventory_out WHERE reference_no IN ($marks)")->execute($orNos);
    }
    if ($poRefs && project_delete_table_exists($pdo, 'tbl_inventory_in')) {
        $marks = project_delete_placeholders($poRefs);
        $pdo->prepare("DELETE FROM tbl_inventory_in WHERE po_code IN ($marks)")->execute($poRefs);
    }

    if ($poIds) {
        $marks = project_delete_placeholders($poIds);
        if (project_delete_table_exists($pdo, 'tbl_purchase_order_items')) {
            $pdo->prepare("DELETE FROM tbl_purchase_order_items WHERE po_id IN ($marks)")->execute($poIds);
        }
        if (project_delete_table_exists($pdo, 'tbl_purchase_order_prs')) {
            $pdo->prepare("DELETE FROM tbl_purchase_order_prs WHERE po_id IN ($marks)")->execute($poIds);
        }
        $pdo->prepare("DELETE FROM tbl_purchase_orders WHERE po_id IN ($marks)")->execute($poIds);
    }

    if ($prIds) {
        $marks = project_delete_placeholders($prIds);
        if (project_delete_table_exists($pdo, 'item_requests') && project_delete_table_exists($pdo, 'tbl_purchase_request_items')) {
            $stmt = $pdo->prepare("SELECT sku FROM tbl_purchase_request_items WHERE pr_id IN ($marks) AND sku LIKE 'REQ-%'");
            $stmt->execute($prIds);
            $requestIds = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sku) {
                $requestId = (int)substr((string)$sku, 4);
                if ($requestId > 0) {
                    $requestIds[] = $requestId;
                }
            }
            $requestIds = array_values(array_unique($requestIds));
            if ($requestIds) {
                $requestMarks = project_delete_placeholders($requestIds);
                $pdo->prepare("UPDATE item_requests SET status = 'Pending' WHERE id IN ($requestMarks) AND status = 'Ordered'")->execute($requestIds);
            }
        }
        if (project_delete_table_exists($pdo, 'tbl_purchase_request_items')) {
            $pdo->prepare("DELETE FROM tbl_purchase_request_items WHERE pr_id IN ($marks)")->execute($prIds);
        }
        $pdo->prepare("DELETE FROM tbl_purchase_requests WHERE pr_id IN ($marks)")->execute($prIds);
    }

    if ($orIds) {
        $marks = project_delete_placeholders($orIds);
        if (project_delete_table_exists($pdo, 'tbl_or_items')) {
            $pdo->prepare("DELETE FROM tbl_or_items WHERE or_id IN ($marks)")->execute($orIds);
        }
        $pdo->prepare("DELETE FROM tbl_or WHERE or_id IN ($marks)")->execute($orIds);
    }

    $projectFiles = [];
    if (project_delete_table_exists($pdo, 'tbl_project_files')) {
        $stmt = $pdo->prepare('SELECT file_path FROM tbl_project_files WHERE proj_code = ?');
        $stmt->execute([$projCode]);
        $projectFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('DELETE FROM tbl_project_files WHERE proj_code = ?')->execute([$projCode]);
    }

    $deleteProject = $pdo->prepare('DELETE FROM tbl_project WHERE proj_code = ?');
    $deleteProject->execute([$projCode]);
    if ($deleteProject->rowCount() !== 1) {
        throw new RuntimeException('Project deletion did not complete.');
    }

    $pdo->commit();

    $projectFilesDir = realpath(__DIR__ . '/../proj_files');
    if ($projectFilesDir !== false) {
        foreach ($projectFiles as $filePath) {
            $safeName = basename((string)$filePath);
            $fullPath = $projectFilesDir . DIRECTORY_SEPARATOR . $safeName;
            if ($safeName !== '' && is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    audit_log(
        $pdo,
        'DELETE',
        'Project',
        $projCode,
        'Deleted approved project and connected records; inventory reversed for ' . count($stockAdjustments) . ' item(s).'
    );

    echo json_encode(['status' => 'success', 'message' => 'Project and all connected transactions were deleted. Inventory was adjusted.']);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('delete_project failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Project deletion failed. No changes were applied.']);
}
?>
