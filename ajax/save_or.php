<?php
session_start();
include_once('config.php');
header('Content-Type: application/json');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

if (!isset($_SESSION['user_code'])) {
    echo json_encode(["status" => "error", "message" => "Session expired. Please login again."]);
    exit;
}

$or_id       = $_POST['or_id'] ?? '';
$or_date     = $_POST['or_date'] ?? '';
$dept_code   = $_POST['dept_code'] ?? '';
$proj_code   = $_POST['proj_code'] ?? '';
$remarks     = $_POST['remarks'] ?? '';
$prepared_by = $_SESSION['username'];
$user_code   = $_SESSION['user_code'];
$grand_total = (float)($_POST['grand_total'] ?? 0);
$items       = $_POST['items'] ?? [];

if ($or_date == '' || $dept_code == '' || $proj_code == '' || empty($items)) {
    echo json_encode(["status" => "error", "message" => "Please complete all required fields."]);
    exit;
}

try {
    $colExists = $conn->query("SHOW COLUMNS FROM tbl_project LIKE 'proj_approval_status'");
    if ($colExists && $colExists->num_rows > 0) {
        $approvalStmt = $conn->prepare("SELECT COALESCE(proj_approval_status, 1) AS approval_status FROM tbl_project WHERE proj_code = ? LIMIT 1");
        $approvalStmt->bind_param("s", $proj_code);
        $approvalStmt->execute();
        $approvalRes = $approvalStmt->get_result();
        $approvalRow = $approvalRes->fetch_assoc();

        if (!$approvalRow) {
            echo json_encode(["status" => "error", "message" => "Project not found."]);
            exit;
        }

        if ((int)$approvalRow['approval_status'] !== 1) {
            echo json_encode(["status" => "error", "message" => "Project is pending Admin approval. Material Request is not allowed yet."]);
            exit;
        }
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Request failed."]);
    exit;
}

$conn->begin_transaction();

try {
    $isUpdate = ($or_id != '');

    if ($isUpdate) {

        // UPDATE HEADER
        $sql = "UPDATE tbl_or 
                SET or_date = ?, dept_code = ?, proj_code = ?, remarks = ?, grand_total = ?
                WHERE or_id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssdi", $or_date, $dept_code, $proj_code, $remarks, $grand_total, $or_id);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update Order Request.");
        }

        // get existing OR number
        $orNoResult = $conn->query("SELECT or_no, or_status FROM tbl_or WHERE or_id = " . (int)$or_id . " FOR UPDATE");
        $orRow = $orNoResult->fetch_assoc();
        if (!$orRow) {
            throw new Exception("Material Request not found.");
        }
        if ((int)$orRow['or_status'] !== 0) {
            throw new Exception("Only pending Material Requests can be edited.");
        }
        $or_no = $orRow['or_no'];

        // DELETE OLD ITEMS
        $del = $conn->prepare("DELETE FROM tbl_or_items WHERE or_id = ?");
        $del->bind_param("i", $or_id);
        $del->execute();

    } else {

        // CREATE NEW OR NO.
        $result = $conn->query("SELECT or_id FROM tbl_or ORDER BY or_id DESC LIMIT 1");
        $lastId = 0;

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $lastId = (int)$row['or_id'];
        }

        $nextId = $lastId + 1;
        $or_no = "MR-" . str_pad($nextId, 6, "0", STR_PAD_LEFT);

        // INSERT HEADER
        $sql = "INSERT INTO tbl_or 
                (or_no, or_date, dept_code, proj_code, remarks, prepared_by, user_code, grand_total, or_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "sssssssd",
            $or_no,
            $or_date,
            $dept_code,
            $proj_code,
            $remarks,
            $prepared_by,
            $user_code,
            $grand_total
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to save Order Request.");
        }

        $or_id = $stmt->insert_id;
    }

    $stockSql = "
        SELECT
            i.sku,
            i.material_name,
            i.description,
            i.quantity,
            i.unit,
            COALESCE(i.reorder_level, 0) AS reorder_level,
            COALESCE(r.reserved_qty, 0) AS reserved_qty
        FROM tbl_items i
        LEFT JOIN (
            SELECT oi.sku, SUM(oi.qty) AS reserved_qty
            FROM tbl_or_items oi
            INNER JOIN tbl_or o ON o.or_id = oi.or_id
            WHERE o.or_status = 0 AND o.or_id <> ?
            GROUP BY oi.sku
        ) r ON r.sku = i.sku
        WHERE i.sku = ?
    ";
    $stockStmt = $conn->prepare($stockSql);
    if (!$stockStmt) {
        throw new Exception("Failed to prepare stock statement.");
    }

    $availableBySku = [];
    $itemMetaBySku = [];
    $excessBySku = [];
    $allocatedItems = [];
    $allocatedGrandTotal = 0.0;

    foreach ($items as $item) {
        $sku = trim((string)($item['sku'] ?? ''));
        $item_name = trim((string)($item['item_name'] ?? ''));
        $qty = (float)($item['qty'] ?? 0);
        $unit = trim((string)($item['unit'] ?? ''));
        $unit_price = (float)($item['unit_price'] ?? 0);

        if ($sku === '' || $item_name === '' || $qty <= 0 || $unit === '') {
            continue;
        }

        if (!isset($availableBySku[$sku])) {
            $stockStmt->bind_param("is", $or_id, $sku);
            $stockStmt->execute();
            $stockRes = $stockStmt->get_result();
            $stockRow = $stockRes->fetch_assoc();

            if (!$stockRow) {
                throw new Exception("Item not found: " . $sku);
            }

            if ($stockRow['unit'] !== $unit) {
                throw new Exception("Unit mismatch for item: " . $sku);
            }

            $availableQty = (float)$stockRow['quantity'] - (float)$stockRow['reserved_qty'];
            if ($availableQty < 0) {
                $availableQty = 0;
            }
            $availableBySku[$sku] = $availableQty;
            $itemMetaBySku[$sku] = [
                'item_name' => $stockRow['material_name'] !== '' ? $stockRow['material_name'] : $item_name,
                'description' => $stockRow['description'] ?? '',
                'unit' => $stockRow['unit'],
                'on_hand_qty' => (float)$stockRow['quantity'],
                'reserved_qty' => (float)$stockRow['reserved_qty'],
                'reorder_level' => (float)$stockRow['reorder_level']
            ];
        }

        $allocQty = min($qty, max(0, (float)$availableBySku[$sku]));
        $remainingQty = $qty - $allocQty;

        if ($allocQty > 0) {
            $lineAmount = $allocQty * $unit_price;
            $allocatedItems[] = [
                'sku' => $sku,
                'item_name' => $item_name,
                'qty' => $allocQty,
                'unit' => $unit,
                'unit_price' => $unit_price,
                'amount' => $lineAmount
            ];
            $allocatedGrandTotal += $lineAmount;
            $availableBySku[$sku] -= $allocQty;
        }

        if ($remainingQty > 0) {
            if (!isset($excessBySku[$sku])) {
                $excessBySku[$sku] = [
                    'sku' => $sku,
                    'item_name' => $itemMetaBySku[$sku]['item_name'],
                    'description' => $itemMetaBySku[$sku]['description'],
                    'unit' => $itemMetaBySku[$sku]['unit'],
                    'request_qty' => 0.0,
                    'on_hand_qty' => $itemMetaBySku[$sku]['on_hand_qty'],
                    'reserved_qty' => $itemMetaBySku[$sku]['reserved_qty'],
                    'available_qty' => $itemMetaBySku[$sku]['on_hand_qty'] - $itemMetaBySku[$sku]['reserved_qty'],
                    'reorder_level' => $itemMetaBySku[$sku]['reorder_level']
                ];
            }
            $excessBySku[$sku]['request_qty'] += $remainingQty;
        }
    }

    if (count($allocatedItems) === 0) {
        throw new Exception("No available stock to save in Material Request.");
    }

    // INSERT AVAILABLE ITEMS INTO MR
    $itemSql = "INSERT INTO tbl_or_items 
                (or_id, sku, item_name, qty, unit, unit_price, amount)
                VALUES (?, ?, ?, ?, ?, ?, ?)";

    $itemStmt = $conn->prepare($itemSql);

    foreach ($allocatedItems as $item) {
        $sku        = $item['sku'];
        $item_name  = $item['item_name'];
        $qty        = (float)$item['qty'];
        $unit       = $item['unit'];
        $unit_price = (float)$item['unit_price'];
        $amount     = (float)$item['amount'];

        $itemStmt->bind_param(
            "issdsdd",
            $or_id,
            $sku,
            $item_name,
            $qty,
            $unit,
            $unit_price,
            $amount
        );

        if (!$itemStmt->execute()) {
            throw new Exception("Failed to save OR item.");
        }
    }

    $updateOrTotal = $conn->prepare("UPDATE tbl_or SET grand_total = ? WHERE or_id = ?");
    $updateOrTotal->bind_param("di", $allocatedGrandTotal, $or_id);
    if (!$updateOrTotal->execute()) {
        throw new Exception("Failed to update Material Request total.");
    }

    $createdPrRefNo = null;
    if (count($excessBySku) > 0) {
        $conn->query("
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

        $conn->query("
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
                INDEX idx_pr_items_sku (sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $insertPrHeader = $conn->prepare("
            INSERT INTO tbl_purchase_requests (request_date, requested_by, requested_by_code, status)
            VALUES (CURDATE(), ?, ?, 'Pending')
        ");
        $insertPrHeader->bind_param("ss", $prepared_by, $user_code);
        if (!$insertPrHeader->execute()) {
            throw new Exception("Failed to create Purchase Request header.");
        }

        $prId = (int)$insertPrHeader->insert_id;
        $createdPrRefNo = "PR-" . str_pad((string)$prId, 6, "0", STR_PAD_LEFT);

        $updatePrRef = $conn->prepare("UPDATE tbl_purchase_requests SET pr_ref_no = ? WHERE pr_id = ?");
        $updatePrRef->bind_param("si", $createdPrRefNo, $prId);
        if (!$updatePrRef->execute()) {
            throw new Exception("Failed to assign Purchase Request reference.");
        }

        $insertPrItem = $conn->prepare("
            INSERT INTO tbl_purchase_request_items
                (pr_id, sku, item_name, description, request_qty, unit, on_hand_qty, reserved_qty, available_qty, reorder_level)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($excessBySku as $excessItem) {
            $reqQty = (float)$excessItem['request_qty'];
            if ($reqQty <= 0) {
                continue;
            }

            $prSku = $excessItem['sku'];
            $prItemName = $excessItem['item_name'];
            $prDescription = $excessItem['description'];
            $prUnit = $excessItem['unit'];
            $onHandQty = (float)$excessItem['on_hand_qty'];
            $reservedQty = (float)$excessItem['reserved_qty'];
            $availableQty = (float)$excessItem['available_qty'];
            $reorderLevel = (float)$excessItem['reorder_level'];

            $insertPrItem->bind_param(
                "isssdsdddd",
                $prId,
                $prSku,
                $prItemName,
                $prDescription,
                $reqQty,
                $prUnit,
                $onHandQty,
                $reservedQty,
                $availableQty,
                $reorderLevel
            );

            if (!$insertPrItem->execute()) {
                throw new Exception("Failed to insert Purchase Request item.");
            }
        }
    }

    $conn->commit();

    $saveMessage = ($isUpdate ? "Material Request updated successfully." : "Material Request saved successfully.");
    if ($createdPrRefNo !== null) {
        $saveMessage .= " Excess quantity was moved to Purchase Request " . $createdPrRefNo . ".";
    }

    echo json_encode([
        "status" => "success",
        "message" => $saveMessage,
        "or_no" => $or_no,
        "pr_ref_no" => $createdPrRefNo
    ]);
    exit;

} catch (Exception $e) {
    $conn->rollback();

    echo json_encode([
        "status" => "error",
        "message" => "Request failed."
    ]);
    exit;
}
?>
