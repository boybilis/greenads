<?php
session_start();
include_once('ajax/config.php');

$poId = (int)($_GET['po_id'] ?? 0);
if ($poId <= 0) {
    die('Invalid PO reference.');
}

$poColumns = $pdo->query("SHOW COLUMNS FROM tbl_purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('receipt_no', $poColumns, true)) {
    $pdo->exec("ALTER TABLE tbl_purchase_orders ADD receipt_no VARCHAR(100) DEFAULT NULL AFTER po_date");
}
if (!in_array('date_received', $poColumns, true)) {
    $pdo->exec("ALTER TABLE tbl_purchase_orders ADD date_received DATE DEFAULT NULL AFTER receipt_no");
}
if (!in_array('fulfillment_status', $poColumns, true)) {
    $pdo->exec("ALTER TABLE tbl_purchase_orders ADD fulfillment_status VARCHAR(30) NOT NULL DEFAULT 'Pending' AFTER date_received");
}
if (!in_array('approval_status', $poColumns, true)) {
    $pdo->exec("ALTER TABLE tbl_purchase_orders ADD approval_status VARCHAR(30) NOT NULL DEFAULT 'Pending' AFTER fulfillment_status");
}
$pdo->exec("
    CREATE TABLE IF NOT EXISTS tbl_purchase_order_prs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT NOT NULL,
        pr_id INT NOT NULL,
        pr_ref_no VARCHAR(30) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_po_pr (po_id, pr_id),
        INDEX idx_po_prs_po_id (po_id),
        INDEX idx_po_prs_pr_id (pr_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$poItemColumns = $pdo->query("SHOW COLUMNS FROM tbl_purchase_order_items")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('pr_ref_no', $poItemColumns, true)) {
    $pdo->exec("ALTER TABLE tbl_purchase_order_items ADD pr_ref_no VARCHAR(30) DEFAULT NULL AFTER pr_item_id");
}

$headerStmt = $pdo->prepare("
    SELECT
        po.po_id,
        po.po_ref_no,
        po.po_date,
        po.receipt_no,
        po.date_received,
        po.fulfillment_status,
        COALESCE(po.approval_status, 'Pending') AS approval_status,
        po.created_by,
        COALESCE(GROUP_CONCAT(DISTINCT popr.pr_ref_no ORDER BY popr.pr_id SEPARATOR ', '), pr.pr_ref_no) AS pr_ref_no,
        s.supplier_name,
        s.supplier_owner,
        s.address,
        s.contact_no,
        s.email
    FROM tbl_purchase_orders po
    LEFT JOIN tbl_purchase_requests pr ON pr.pr_id = po.pr_id
    LEFT JOIN tbl_purchase_order_prs popr ON popr.po_id = po.po_id
    LEFT JOIN tbl_suppliers s ON s.supplier_id = po.supplier_id
    WHERE po.po_id = ?
    GROUP BY po.po_id, po.po_ref_no, po.po_date, po.receipt_no, po.date_received, po.fulfillment_status, po.approval_status, po.created_by, pr.pr_ref_no, s.supplier_name, s.supplier_owner, s.address, s.contact_no, s.email
");
$headerStmt->execute([$poId]);
$po = $headerStmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    die('PO request was not found.');
}
if (($po['approval_status'] ?? 'Pending') !== 'Approved' && (($_SESSION['user_type'] ?? '') !== 'Admin')) {
    die('This PO is pending admin approval.');
}
$isApproved = strcasecmp((string)($po['approval_status'] ?? 'Pending'), 'Approved') === 0;

$itemStmt = $pdo->prepare("
    SELECT
        poi.sku,
        COALESCE(poi.pr_ref_no, pr.pr_ref_no) AS pr_ref_no,
        poi.item_name,
        COALESCE(poi.material_type, ti.material_type) AS material_type,
        poi.description,
        poi.color,
        poi.request_qty,
        poi.po_qty,
        COALESCE(poi.unit_price, 0) AS unit_price,
        COALESCE(poi.line_total, (poi.po_qty * COALESCE(poi.unit_price, 0))) AS line_total,
        poi.unit
    FROM tbl_purchase_order_items poi
    LEFT JOIN tbl_purchase_request_items pri ON pri.pr_item_id = poi.pr_item_id
    LEFT JOIN tbl_purchase_requests pr ON pr.pr_id = pri.pr_id
    LEFT JOIN tbl_items ti ON ti.sku = poi.sku
    WHERE poi.po_id = ?
    ORDER BY COALESCE(poi.pr_ref_no, pr.pr_ref_no) ASC, poi.item_name ASC, poi.sku ASC
");
$itemStmt->execute([$poId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
$grandTotal = 0;
foreach ($items as $item) {
    $grandTotal += (float)($item['line_total'] ?? 0);
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($po['po_ref_no']); ?></title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; color: #111; font-size: 12px; }
        .toolbar { padding: 10px 14mm; background: #f4f4f4; border-bottom: 1px solid #ddd; }
        .toolbar button { padding: 7px 14px; cursor: pointer; }
        .sheet { width: 210mm; min-height: 297mm; padding: 14mm; margin: 0 auto; background: #fff; }
        .header { display: flex; justify-content: space-between; gap: 18px; border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 16px; }
        .logo-box { width: 115px; height: 75px; display: flex; align-items: center; justify-content: center; }
        .logo-box img { max-width: 115px; max-height: 75px; object-fit: contain; }
        .company { flex: 1; }
        h1 { margin: 0 0 6px; font-size: 22px; letter-spacing: 0; }
        .meta { text-align: right; line-height: 1.6; min-width: 165px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 16px; }
        .section-title { font-weight: bold; margin-bottom: 6px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #333; padding: 7px; vertical-align: top; }
        th { background: #f2f2f2; text-align: left; }
        .center { text-align: center; }
        .right { text-align: right; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-top: 42px; }
        .signature-block { align-items: center; display: flex; flex-direction: column; justify-content: flex-end; min-height: 130px; }
        .approval-signature { display: block; width: 100px; height: 100px; object-fit: contain; }
        .signature-line { border-top: 1px solid #111; padding-top: 6px; text-align: center; width: 100%; }
        @media print {
            .toolbar { display: none; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .sheet { width: auto; min-height: auto; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print PO</button>
    </div>
    <div class="sheet">
        <div class="header">
            <div class="logo-box">
                <img src="dist/img/greenads_logo.png" alt="Green Ads and Promats Logo">
            </div>
            <div class="company">
                <h1>Green Ads and Promats, Inc.</h1>
                <strong>PURCHASE ORDER</strong>
            </div>
            <div class="meta">
                <div><strong>PO #:</strong> <?= htmlspecialchars($po['po_ref_no']); ?></div>
                <div><strong>Date:</strong> <?= htmlspecialchars($po['po_date']); ?></div>
                <div><strong>Status:</strong> <?= htmlspecialchars($po['fulfillment_status'] ?: 'Pending'); ?></div>
                <div><strong>Receipt #:</strong> <?= htmlspecialchars($po['receipt_no'] ?: '-'); ?></div>
                <div><strong>Received:</strong> <?= htmlspecialchars($po['date_received'] ?: '-'); ?></div>
            </div>
        </div>

        <div class="grid">
            <div>
                <div class="section-title">Supplier Details</div>
                <div><strong>VENDOR NAME:</strong> <?= htmlspecialchars($po['supplier_name'] ?: '-'); ?></div>
                <div><strong>CONTACT PERSON:</strong> <?= htmlspecialchars($po['supplier_owner'] ?: '-'); ?></div>
                <div>Address: <?= htmlspecialchars($po['address'] ?: '-'); ?></div>
                <div><?= htmlspecialchars($po['contact_no'] ?: '-'); ?></div>
                <div>Email: <?= htmlspecialchars($po['email'] ?: '-'); ?></div>
            </div>
            <div>
                <div class="section-title">Prepared By</div>
                <div><?= htmlspecialchars($po['created_by'] ?: '-'); ?></div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="center" style="width:36px;">#</th>
                    <th style="width:95px;">SKU</th>
                    <th>Item</th>
                    <th class="right" style="width:85px;">PO Qty</th>
                    <th class="right" style="width:100px;">Unit Price</th>
                    <th class="right" style="width:110px;">Total Price</th>
                    <th style="width:55px;">Unit</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $lastPrRef = null;
                foreach ($items as $index => $item) {
                    $itemPrRef = $item['pr_ref_no'] ?: '-';
                    if ($itemPrRef !== $lastPrRef) {
                        $lastPrRef = $itemPrRef;
                ?>
                    <tr>
                        <td colspan="7" style="background:#f7f7f7;font-weight:bold;">
                            PR #: <?= htmlspecialchars($itemPrRef); ?>
                        </td>
                    </tr>
                <?php } ?>
                    <tr>
                        <td class="center"><?= $index + 1; ?></td>
                        <td><?= htmlspecialchars($item['sku']); ?></td>
                        <td>
                            <strong><?= htmlspecialchars($item['item_name']); ?></strong><br>
                            Type: <?= htmlspecialchars($item['material_type'] ?: '-'); ?><br>
                            <?= htmlspecialchars($item['description'] ?: '-'); ?><br>
                            Color: <?= htmlspecialchars($item['color'] ?: 'N/A'); ?>
                        </td>
                        <td class="right"><?= number_format((float)$item['po_qty'], 2); ?></td>
                        <td class="right"><?= number_format((float)$item['unit_price'], 2); ?></td>
                        <td class="right"><?= number_format((float)$item['line_total'], 2); ?></td>
                        <td><?= htmlspecialchars($item['unit'] ?: ''); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="5" class="right">Grand Total</th>
                    <th class="right"><?= number_format($grandTotal, 2); ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>

        <div class="signatures">
            <div class="signature-block">
                <div class="signature-line">Prepared By</div>
            </div>
            <div class="signature-block">
                <?php if ($isApproved) { ?>
                    <img class="approval-signature" src="dist/img/sir%20bong%20e%20signtransparent.png" alt="Admin approval signature">
                <?php } ?>
                <div class="signature-line">Approved By</div>
            </div>
        </div>
    </div>
</body>
</html>
