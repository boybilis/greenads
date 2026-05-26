<?php
require_once 'ajax/config.php';

$token = $_GET['token'] ?? '';

if (!$token) {
    die("<div style='text-align:center;margin-top:50px;'>Invalid QR Code</div>");
}

$stmt = $pdo->prepare("SELECT * FROM tbl_project WHERE public_token = ?");
$stmt->execute([$token]);
$project = $stmt->fetch();

if (!$project) {
    die("<div style='text-align:center;margin-top:50px;'>Project not found</div>");
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Project Profile</title>

    <!-- MOBILE OPTIMIZED VIEWPORT -->
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: #f1f3f5;
            font-size: 15px;
        }

        .card {
            border-radius: 12px;
        }

        .header-title {
            font-size: 18px;
            font-weight: 600;
            text-align: center;
        }

        .label {
            font-size: 13px;
            font-weight: 600;
            color: #666;
            margin-top: 10px;
        }

        .value {
            font-size: 15px;
        }

        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .file-item:last-child {
            border-bottom: none;
        }

        .file-name {
            font-size: 13px;
            margin-left: 8px;
        }

        .btn {
            font-size: 13px;
        }
    </style>
</head>

<body>
<div class="container py-3">

    <div class="card shadow-sm">

        <div class="card-body">

            <div class="title mb-3">
                <h1><?= htmlspecialchars($project['proj_name']) ?></h1>
            </div>

            <div class="label">Project Code</div>
            <div class="value"><h3><?= htmlspecialchars($project['proj_code']) ?></h3></div>

            <div class="label">Manager</div>
            <div class="value"><h5><?= htmlspecialchars($project['proj_mgr']) ?></h5></div>

            <div class="label">Owner</div>
            <div class="value"><h5><?= htmlspecialchars($project['proj_owner']) ?></h5></div>

            <div class="label">Description</div>
            <div class="value"><h5><?= htmlspecialchars($project['proj_desc']) ?></h5></div>

            <div class="label">Start Date</div>
            <div class="value"><h5><?= htmlspecialchars($project['proj_sd']) ?></h5></div>

            <div class="label">End Date</div>
            <div class="value"><h5><?= htmlspecialchars($project['proj_ed']) ?></h5></div>

        </div>

    </div>
	
	<?php
$stmt = $pdo->prepare("SELECT * FROM tbl_project_files WHERE proj_code = ?");
$stmt->execute([$project['proj_code']]);
$files = $stmt->fetchAll();
?>

<?php if ($files): ?>

<div class="card shadow-sm mt-3">

    <div class="card-body">

        <div class="header-title mb-2">Project Files</div>

        <?php foreach ($files as $f): ?>

            <?php $path = "proj_files/" . $f['file_path']; ?>

            <div class="file-item">

                <div class="d-flex align-items-center">

                    <?php if ($f['file_type'] === 'image'): ?>
                        <img src="<?= $path ?>" width="45" height="45" style="object-fit:cover;border-radius:6px;">
                    <?php else: ?>
                        📄
                    <?php endif; ?>

                    <div class="file-name">
                        <?= htmlspecialchars($f['file_name']) ?>
                    </div>

                </div>

                <a href="<?= $path ?>" target="_blank" class="btn btn-sm btn-primary">
                    View
                </a>

            </div>

        <?php endforeach; ?>

    </div>

</div>

<?php endif; ?>

</div>

</body>
</html>