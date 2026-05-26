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

    if ($or_id != '') {

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

    $requestedBySku = [];
    foreach ($items as $item) {
        $sku = $item['sku'] ?? '';
        $unit = $item['unit'] ?? '';
        $qty = (float)($item['qty'] ?? 0);

        if ($sku == '' || $qty <= 0) {
            continue;
        }

        if (!isset($requestedBySku[$sku])) {
            $requestedBySku[$sku] = [
                'qty' => 0,
                'unit' => $unit
            ];
        }

        if ($requestedBySku[$sku]['unit'] !== $unit) {
            throw new Exception("Unit mismatch in request rows for item: " . $sku);
        }

        $requestedBySku[$sku]['qty'] += $qty;
    }

    foreach ($requestedBySku as $sku => $request) {
        $stockSql = "
            SELECT
                i.quantity,
                i.unit,
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
        $stockStmt->bind_param("is", $or_id, $sku);
        $stockStmt->execute();
        $stockRes = $stockStmt->get_result();
        $stockRow = $stockRes->fetch_assoc();

        if (!$stockRow) {
            throw new Exception("Item not found: " . $sku);
        }

        if ($stockRow['unit'] !== $request['unit']) {
            throw new Exception("Unit mismatch for item: " . $sku);
        }

        $availableQty = (float)$stockRow['quantity'] - (float)$stockRow['reserved_qty'];
        if ($request['qty'] > $availableQty) {
            throw new Exception("Requested quantity exceeds available stock for " . $sku . ". Available: " . $availableQty . " " . $request['unit']);
        }
    }

    // INSERT ITEMS AGAIN
    $itemSql = "INSERT INTO tbl_or_items 
                (or_id, sku, item_name, qty, unit, unit_price, amount)
                VALUES (?, ?, ?, ?, ?, ?, ?)";

    $itemStmt = $conn->prepare($itemSql);

    foreach ($items as $item) {
        $sku        = $item['sku'] ?? '';
        $item_name  = $item['item_name'] ?? '';
        $qty        = (float)($item['qty'] ?? 0);
        $unit       = $item['unit'] ?? '';
        $unit_price = (float)($item['unit_price'] ?? 0);
        $amount     = (float)($item['amount'] ?? 0);

        if ($sku == '' || $item_name == '' || $qty <= 0) {
            continue;
        }

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

    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => ($or_id != '' ? "Material Request updated successfully." : "Material Request saved successfully."),
        "or_no" => $or_no
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
