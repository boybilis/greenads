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

$headerStmt = $pdo->prepare("
    SELECT
        po.po_id,
        po.po_ref_no,
        po.po_date,
        po.receipt_no,
        po.date_received,
        po.fulfillment_status,
        po.created_by,
        pr.pr_ref_no,
        s.supplier_name,
        s.supplier_owner,
        s.address,
        s.contact_no,
        s.email
    FROM tbl_purchase_orders po
    LEFT JOIN tbl_purchase_requests pr ON pr.pr_id = po.pr_id
    LEFT JOIN tbl_suppliers s ON s.supplier_id = po.supplier_id
    WHERE po.po_id = ?
");
$headerStmt->execute([$poId]);
$po = $headerStmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    die('PO request was not found.');
}

$itemStmt = $pdo->prepare("
    SELECT
        poi.sku,
        poi.item_name,
        COALESCE(poi.material_type, ti.material_type) AS material_type,
        poi.description,
        poi.color,
        poi.request_qty,
        poi.po_qty,
        poi.unit
    FROM tbl_purchase_order_items poi
    LEFT JOIN tbl_items ti ON ti.sku = poi.sku
    WHERE poi.po_id = ?
    ORDER BY poi.item_name ASC, poi.sku ASC
");
$itemStmt->execute([$poId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
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
        .logo-box { width: 95px; height: 70px; border: 1px solid #555; display: flex; align-items: center; justify-content: center; color: #777; font-size: 11px; text-align: center; }
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
        .signature-line { border-top: 1px solid #111; padding-top: 6px; text-align: center; }
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
            <div class="logo-box">Company<br>Image</div>
            <div class="company">
                <h1>Green Ads and Promats, Inc.</h1>
                <strong>PURCHASE ORDER</strong><br>
                PR #: <?= htmlspecialchars($po['pr_ref_no'] ?: '-'); ?>
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
                <div><strong><?= htmlspecialchars($po['supplier_name'] ?: '-'); ?></strong></div>
                <div>Owner: <?= htmlspecialchars($po['supplier_owner'] ?: '-'); ?></div>
                <div>Address: <?= htmlspecialchars($po['address'] ?: '-'); ?></div>
                <div>Contact: <?= htmlspecialchars($po['contact_no'] ?: '-'); ?></div>
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
                    <th style="width:55px;">Unit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $index => $item) { ?>
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
                        <td><?= htmlspecialchars($item['unit'] ?: ''); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <div class="signatures">
            <div class="signature-line">Prepared By</div>
            <div class="signature-line">Approved By</div>
        </div>
    </div>
</body>
</html>
