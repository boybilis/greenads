<?php
session_start();

// 🔒 Block access if not logged in
if (!isset($_SESSION['user_code'])) {
    header("Location: index.html");
    exit;
}
include_once("ajax/config.php");
$usr_code=$_SESSION['user_code'];
$hasProjectApprovalColumn = false;
try {
    $projectCols = $pdo->query("SHOW COLUMNS FROM tbl_project")->fetchAll(PDO::FETCH_COLUMN);
    $hasProjectApprovalColumn = in_array('proj_approval_status', $projectCols, true);
} catch (Exception $e) {
    $hasProjectApprovalColumn = false;
}
$projectApprovalFilterSql = $hasProjectApprovalColumn ? "COALESCE(proj_approval_status, 1) = 1" : "1 = 1";

$purchaseRequestLowStockItems = [];
try {
    $columns = $pdo->query("SHOW COLUMNS FROM tbl_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reorder_level', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_items ADD reorder_level INT NOT NULL DEFAULT 10");
    }

    $prItemsStmt = $pdo->query("
        SELECT
            sku,
            material_name,
            description,
            unit,
            available_qty,
            reorder_level,
            GREATEST(reorder_level - available_qty, 1) AS suggested_qty
        FROM (
            SELECT
                i.sku,
                i.material_name,
                i.description,
                i.unit,
                i.quantity,
                COALESCE(r.reserved_qty, 0) AS reserved_qty,
                (i.quantity - COALESCE(r.reserved_qty, 0)) AS available_qty,
                i.reorder_level
            FROM tbl_items i
            LEFT JOIN (
                SELECT oi.sku, SUM(oi.qty) AS reserved_qty
                FROM tbl_or_items oi
                INNER JOIN tbl_or o ON o.or_id = oi.or_id
                WHERE o.or_status = 0
                GROUP BY oi.sku
            ) r ON r.sku = i.sku
        ) stock
        WHERE available_qty > 0 AND available_qty <= reorder_level
        ORDER BY material_name ASC, sku ASC
    ");
    $purchaseRequestLowStockItems = $prItemsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $purchaseRequestLowStockItems = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="x-ua-compatible" content="ie=edge">

  <title>Green Ads and Promats, Inc. | Dashboard </title>

  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <link rel="stylesheet" href="plugins/toastr/toastr.min.css">
  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <!-- Google Font: Source Sans Pro -->
  <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">
  <!-- data tables -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
  
  <style>
  /* Modal background (full screen) */
.modal {
  display: none;
  position: fixed;
  z-index: 9999;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.6); /* ✅ dark transparent */
  overflow-y: auto; /* scroll whole modal */
   backdrop-filter: blur(5px);
}

/* Modal content */
.modal-content {
  background: #fff;
  width: 100%;
  max-width: 600px;
  margin: 40px auto;
  padding: 20px;
  border-radius: 10px;
}

/* ✅ Mobile spacing */
@media (max-width: 768px) {
  .modal-content {
    margin: 20px;          /* space from screen edges */
    padding: 15px;
  }
}

/* Close button */
.close {
  position: fixed;
  top: 15px;
  right: 20px;
  font-size: 28px;
  cursor: pointer;
  z-index: 10000;
}



.modal-header {
  position: sticky;
  border-bottom: 1px solid #ddd;
}



table.dataTable th,
table.dataTable td {
    width: 20%; /* adjust based on number of columns */
	 white-space: nowrap;
}
  </style>
  <style>
  .item-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    width: 100%;
    background: #fff;
    border: 1px solid #ced4da;
    z-index: 9999;
    display: none;
    max-height: 200px;
    overflow-y: auto;
  }

  .item-option {
    padding: 8px 10px;
    cursor: pointer;
    border-bottom: 1px solid #f1f1f1;
  }

  .item-option:hover {
    background: #f8f9fa;
  }
  
  small {
  color: #6c757d; /* Bootstrap gray */
}
</style>
  
</head>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed layout-footer-fixed">
<div class="wrapper">
  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
      </li>
    
    
    </ul>

   

    <!-- Right navbar links -->
    
  </nav>
  <!-- /.navbar -->

  <!-- Main Sidebar Container -->
  <aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <a href="index3.html" class="brand-link">
      <img src="dist/img/AdminLTELogo.png" alt="AdminLTE Logo" class="brand-image img-circle elevation-3"
           style="opacity: .8">
      <span class="brand-text font-weight-light"><small>Green Ads & Promats, Inc.</small></span>
    </a>

    <!-- Sidebar -->
    <div class="sidebar">
      <!-- Sidebar user panel (optional) -->
  <div class="user-panel mt-3 pb-3 mb-3 d-flex">
    
    <div class="image">
        <img src="dist/img/user2-160x160.jpg" class="img-circle elevation-2" alt="User Image">
    </div>

    <div class="info">
        <a href="#" class="d-block">
            <?= htmlspecialchars($_SESSION['username'] ?? 'Guest'); ?>
        </a>

        <small class="text-muted">
            <?= htmlspecialchars($_SESSION['user_type'] ?? 'User'); ?>
        </small>
    </div>

</div>

      <!-- Sidebar Menu -->
      <nav class="mt-2">
        <ul class="nav nav-pills nav-sidebar flex-column" id="side-menu" data-widget="treeview" role="menu" data-accordion="false">
          <!-- Add icons to the links using the .nav-icon class
               with font-awesome or any other icon font library -->
			<?php $sidebarUserType = $_SESSION['user_type'] ?? ''; ?>
			   
			<?php if ($sidebarUserType === 'Admin') { ?>   
           <li class="nav-item">
            <a href="#" class="nav-link" data-target="dashboard" id="showDashboard">
              <i class="nav-icon fas fa-tachometer-alt"></i>
              <p>
                Dashboard
              </p>
            </a>
         </li>
			<?php } ?>
		 
		  <?php if ($sidebarUserType !== 'Inventory' && $sidebarUserType !== 'Purchasing') { ?>
		   <li class="nav-item">
            <a href="#" class="nav-link" data-target="project"  id="showProject">
              <i class="nav-icon fas fa-file"></i>
              <p>Projects</p>
            </a>
          </li>
		  <?php } ?>
		  <?php if ($sidebarUserType !== 'Purchasing') { ?>
		   <li class="nav-item">
            <a href="#" data-target="product" class="nav-link" id="showProduct">
              <i class="nav-icon fas fa-file"></i>
              <p>Items</p>
            </a>
          </li>
		  <?php } ?>
		  <?php if ($sidebarUserType !== 'Purchasing') { ?>
		   <li class="nav-item">
            <a href="#" data-target="order" class="nav-link" id="showOrder">
              <i class="nav-icon fas fa-file"></i>
              <p>Material Requests</p>
            </a>
          </li>
		  <?php } ?>
		  
		  <?php if (isset($_SESSION['user_type']) && ($_SESSION['user_type'] === 'Manager' || $_SESSION['user_type'] === 'Purchasing')){}else { ?>   
		   <li class="nav-item">
            <a href="#" data-target="inventory" class="nav-link" id="showInventory">
              <i class="nav-icon fas fa-file"></i>
              <p>Inventory</p>
            </a>
          </li>
		  <?php } ?>
		  
		  	  <?php 
if (!isset($_SESSION['user_type']) || 
    ($_SESSION['user_type'] !== 'Manager' && $_SESSION['user_type'] !== 'Inventory')) { 
?>   
  <li class="nav-item">
    <a href="#" data-target="purchasing" class="nav-link" id="showPO">
      <i class="nav-icon fas fa-file"></i>
      <p>Purchasing</p>
    </a>
  </li>
<?php } ?>
		  
		  <?php if ($sidebarUserType !== 'Inventory' && $sidebarUserType !== 'Purchasing') { ?>
		   <li class="nav-item">
            <a href="#" data-target="report" class="nav-link" id="showReport">
              <i class="nav-icon fas fa-file"></i>
              <p>Reports</p>
            </a>
          </li>
		  <?php } ?>
		  <li class="nav-header">MANAGE</li>
		   <li class="nav-item">
            <a href="#" data-target="setting" class="nav-link" id="showSetting">
              <i class="nav-icon fas fa-file"></i>
              <p>Settings</p>
            </a>
          </li>
		  <li class="nav-item">
  <a href="#" class="nav-link" id="logoutBtn">
    <i class="nav-icon fas fa-sign-out-alt"></i>
    <p>Log-out</p>
  </a>
</li>
		  
         
        </ul>
      </nav>
      <!-- /.sidebar-menu -->
    </div>
    <!-- /.sidebar -->
  </aside>

  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    <!-- Content Header (Page header) -->
   
    <!-- /.content-header -->

    <!-- Main content -->
    <section class="content" id="dashboard">
      <div class="container-fluid">
	  <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">Dashboard</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.html">Home</a></li>
              <li class="breadcrumb-item active">Dashboard</li>
            </ol>
          </div><!-- /.col -->
        </div><!-- /.row -->
      </div><!-- /.container-fluid -->
    </div>
        <!-- Info boxes -->
		
		<?php
		$dashboardMaterialCount = (int) $pdo->query("SELECT COUNT(*) FROM tbl_items")->fetchColumn();
		if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin') {
			$dashboardProjectCount = (int) $pdo->query("SELECT COUNT(*) FROM tbl_project")->fetchColumn();
		} else {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_project WHERE proj_mgr = ?");
			$stmt->execute([$usr_code]);
			$dashboardProjectCount = (int) $stmt->fetchColumn();
		}
		if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin') {
			$dashboardMrCount = (int) $pdo->query("SELECT COUNT(*) FROM tbl_or")->fetchColumn();
		} else {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_or WHERE user_code = ?");
			$stmt->execute([$usr_code]);
			$dashboardMrCount = (int) $stmt->fetchColumn();
		}
		$dashboardInventoryValue = (float) $pdo->query("SELECT COALESCE(SUM(quantity * unit_price), 0) FROM tbl_items")->fetchColumn();

		$recapYear = (int)date('Y');
		$recapLabels = [];
		$recapProjectCounts = array_fill(1, 12, 0);
		$recapProjectCosts = array_fill(1, 12, 0.0);
		$recapMrCosts = array_fill(1, 12, 0.0);

		for ($month = 1; $month <= 12; $month++) {
			$recapLabels[] = date('M', mktime(0, 0, 0, $month, 1));
		}

		if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin') {
			$stmt = $pdo->prepare("
				SELECT MONTH(proj_sd) AS month_no, COUNT(*) AS project_count, COALESCE(SUM(proj_cost), 0) AS project_cost
				FROM tbl_project
				WHERE YEAR(proj_sd) = ?
				GROUP BY MONTH(proj_sd)
			");
			$stmt->execute([$recapYear]);
		} else {
			$stmt = $pdo->prepare("
				SELECT MONTH(proj_sd) AS month_no, COUNT(*) AS project_count, COALESCE(SUM(proj_cost), 0) AS project_cost
				FROM tbl_project
				WHERE YEAR(proj_sd) = ? AND proj_mgr = ?
				GROUP BY MONTH(proj_sd)
			");
			$stmt->execute([$recapYear, $usr_code]);
		}

		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$monthNo = (int)$row['month_no'];
			if ($monthNo >= 1 && $monthNo <= 12) {
				$recapProjectCounts[$monthNo] = (int)$row['project_count'];
				$recapProjectCosts[$monthNo] = (float)$row['project_cost'];
			}
		}

		if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin') {
			$stmt = $pdo->prepare("
				SELECT MONTH(o.or_date) AS month_no, COALESCE(SUM(o.grand_total), 0) AS mr_cost
				FROM tbl_or o
				WHERE YEAR(o.or_date) = ? AND o.or_status IN (1, 3)
				GROUP BY MONTH(o.or_date)
			");
			$stmt->execute([$recapYear]);
		} else {
			$stmt = $pdo->prepare("
				SELECT MONTH(o.or_date) AS month_no, COALESCE(SUM(o.grand_total), 0) AS mr_cost
				FROM tbl_or o
				INNER JOIN tbl_project p ON p.proj_code = o.proj_code
				WHERE YEAR(o.or_date) = ? AND o.or_status IN (1, 3) AND p.proj_mgr = ?
				GROUP BY MONTH(o.or_date)
			");
			$stmt->execute([$recapYear, $usr_code]);
		}

		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$monthNo = (int)$row['month_no'];
			if ($monthNo >= 1 && $monthNo <= 12) {
				$recapMrCosts[$monthNo] = (float)$row['mr_cost'];
			}
		}

		$recapProfits = [];
		for ($month = 1; $month <= 12; $month++) {
			$recapProfits[] = $recapProjectCosts[$month] - $recapMrCosts[$month];
		}

		$recapProjectCountValues = array_values($recapProjectCounts);
		$recapProjectCostValues = array_values($recapProjectCosts);
		$recapMrCostValues = array_values($recapMrCosts);
		$recapTotalProjects = array_sum($recapProjectCounts);
		$recapTotalProjectCost = array_sum($recapProjectCosts);
		$recapTotalMrCost = array_sum($recapMrCosts);
		$recapTotalProfit = $recapTotalProjectCost - $recapTotalMrCost;
		$recapMaxProjects = max(1, max($recapProjectCounts));

		if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin') {
			$stmt = $pdo->prepare("
				SELECT
					p.proj_code,
					p.proj_name,
					p.proj_sd,
					p.proj_cost,
					COALESCE(SUM(CASE WHEN o.or_status IN (1, 3) THEN o.grand_total ELSE 0 END), 0) AS mr_cost
				FROM tbl_project p
				LEFT JOIN tbl_or o ON o.proj_code = p.proj_code
				WHERE YEAR(p.proj_sd) = ?
				GROUP BY p.proj_code, p.proj_name, p.proj_sd, p.proj_cost
				ORDER BY MONTH(p.proj_sd), p.proj_code
			");
			$stmt->execute([$recapYear]);
		} else {
			$stmt = $pdo->prepare("
				SELECT
					p.proj_code,
					p.proj_name,
					p.proj_sd,
					p.proj_cost,
					COALESCE(SUM(CASE WHEN o.or_status IN (1, 3) THEN o.grand_total ELSE 0 END), 0) AS mr_cost
				FROM tbl_project p
				LEFT JOIN tbl_or o ON o.proj_code = p.proj_code
				WHERE YEAR(p.proj_sd) = ? AND p.proj_mgr = ?
				GROUP BY p.proj_code, p.proj_name, p.proj_sd, p.proj_cost
				ORDER BY MONTH(p.proj_sd), p.proj_code
			");
			$stmt->execute([$recapYear, $usr_code]);
		}

		$recapProjectChartLabels = [];
		$recapProjectChartCosts = [];
		$recapProjectChartMrCosts = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$monthLabel = !empty($row['proj_sd']) ? date('M', strtotime($row['proj_sd'])) : '-';
			$recapProjectChartLabels[] = $monthLabel . ' - ' . $row['proj_code'];
			$recapProjectChartCosts[] = (float)$row['proj_cost'];
			$recapProjectChartMrCosts[] = (float)$row['mr_cost'];
		}
		?>
       <div class="row">
          <div class="col-lg-3 col-6">
            <!-- small card -->
            <div class="small-box bg-info">
              <div class="inner">
                <h3><?= number_format($dashboardMaterialCount); ?></h3>

                <p>Materials</p>
              </div>
              <div class="icon">
                <i class="fas fa-shopping-cart"></i>
              </div>

            </div>
          </div>
          <!-- ./col -->
          <div class="col-lg-3 col-6">
            <!-- small card -->
            <div class="small-box bg-success">
              <div class="inner">
                <h3><?= number_format($dashboardProjectCount); ?></h3>

                <p>Projects</p>
              </div>
              <div class="icon">
                <i class="ion ion-stats-bars"></i>
              </div>
            
            </div>
          </div>
          <!-- ./col -->
          <div class="col-lg-3 col-6">
            <!-- small card -->
            <div class="small-box bg-warning">
              <div class="inner">
                <h3><?= number_format($dashboardMrCount); ?></h3>

                <p>MR Requests</p>
              </div>
              <div class="icon">
                <i class="fas fa-user-plus"></i>
              </div>
            
            </div>
          </div>
          <!-- ./col -->
          <div class="col-lg-3 col-6">
            <!-- small card -->
            <div class="small-box bg-danger">
              <div class="inner">
                <h3><?= number_format($dashboardInventoryValue, 2); ?></h3>

                <p>Total Inventory Value</p>
              </div>
              <div class="icon">
                <i class="fas fa-chart-pie"></i>
              </div>
           
            </div>
          </div>
          <!-- ./col -->
        </div>
        <!-- /.row -->

        <div class="row">
          <div class="col-md-12">
            <div class="card">
              <div class="card-header">
                <h5 class="card-title">Monthly Project Costing Recap</h5>

                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                  </button>
                  <button type="button" class="btn btn-tool" data-card-widget="remove">
                    <i class="fas fa-times"></i>
                  </button>
                </div>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
                <div class="row">
                  <div class="col-md-8">
                    <p class="text-center">
                      <strong>Project Cost vs Approved MR Cost by Project: Jan <?= $recapYear; ?> - Dec <?= $recapYear; ?></strong>
                    </p>

                    <div class="chart">
                      <!-- Sales Chart Canvas -->
                      <canvas id="salesChart" height="180" style="height: 180px;"></canvas>
                    </div>
                    <!-- /.chart-responsive -->
                  </div>
                  <!-- /.col -->
                  <div class="col-md-4">
                    <p class="text-center">
                      <strong>Projects Per Month</strong>
                    </p>

                    <div style="max-height: 180px; overflow-y: auto; padding-right: 8px;">
                      <?php foreach ($recapLabels as $index => $label): ?>
                        <?php
                        $projectCount = $recapProjectCountValues[$index];
                        $barWidth = min(100, ($projectCount / $recapMaxProjects) * 100);
                        ?>
                        <div class="progress-group">
                          <?= htmlspecialchars($label); ?>
                          <span class="float-right"><b><?= number_format($projectCount); ?></b></span>
                          <div class="progress progress-sm">
                            <div class="progress-bar bg-primary" style="width: <?= $barWidth; ?>%"></div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <!-- /.col -->
                </div>
                <!-- /.row -->
              </div>
              <!-- ./card-body -->
              <div class="card-footer">
                <div class="row">
                  <div class="col-sm-3 col-6">
                    <div class="description-block border-right">
                      <span class="description-percentage text-info"><i class="fas fa-folder"></i></span>
                      <h5 class="description-header"><?= number_format($recapTotalProjects); ?></h5>
                      <span class="description-text">TOTAL PROJECTS</span>
                    </div>
                    <!-- /.description-block -->
                  </div>
                  <!-- /.col -->
                  <div class="col-sm-3 col-6">
                    <div class="description-block border-right">
                      <span class="description-percentage text-success"><i class="fas fa-caret-up"></i></span>
                      <h5 class="description-header">PHP <?= number_format($recapTotalProjectCost, 2); ?></h5>
                      <span class="description-text">PROJECT COST</span>
                    </div>
                    <!-- /.description-block -->
                  </div>
                  <!-- /.col -->
                  <div class="col-sm-3 col-6">
                    <div class="description-block border-right">
                      <span class="description-percentage text-warning"><i class="fas fa-caret-left"></i></span>
                      <h5 class="description-header">PHP <?= number_format($recapTotalMrCost, 2); ?></h5>
                      <span class="description-text">MR COST</span>
                    </div>
                    <!-- /.description-block -->
                  </div>
                  <!-- /.col -->
                  <div class="col-sm-3 col-6">
                    <div class="description-block">
                      <span class="description-percentage <?= $recapTotalProfit >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <i class="fas <?= $recapTotalProfit >= 0 ? 'fa-caret-up' : 'fa-caret-down'; ?>"></i>
                      </span>
                      <h5 class="description-header">PHP <?= number_format($recapTotalProfit, 2); ?></h5>
                      <span class="description-text">PROFIT</span>
                    </div>
                    <!-- /.description-block -->
                  </div>
                </div>
                <!-- /.row -->
              </div>
              <!-- /.card-footer -->
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
        </div>
        <!-- /.row -->

        <!-- Main row -->
        <div class="row">
          <!-- Left col -->
          <div class="col-md-8">
            
           

            <!-- TABLE: LATEST MATERIAL REQUESTS -->
            <div class="card">
              <div class="card-header border-transparent">
                <h3 class="card-title">Latest Material Requests</h3>

                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                  </button>
                  <button type="button" class="btn btn-tool" data-card-widget="remove">
                    <i class="fas fa-times"></i>
                  </button>
                </div>
              </div>
              <!-- /.card-header -->
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table m-0">
                    <thead>
                    <tr>
                      <th>MR No.</th>
                      <th>Project</th>
                      <th>Status</th>
                      <th>Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin') {
                        $latestMrs = $db->getAllRecords("tbl_or", "*", "", "ORDER BY or_date DESC, or_id DESC", "LIMIT 7");
                    } else {
                        $stmt = $pdo->prepare("
                            SELECT *
                            FROM tbl_or
                            WHERE user_code = ?
                            ORDER BY or_date DESC, or_id DESC
                            LIMIT 7
                        ");
                        $stmt->execute([$usr_code]);
                        $latestMrs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                    ?>
                    <?php if ($latestMrs): ?>
                      <?php foreach ($latestMrs as $mr): ?>
                        <?php
                        if ((int)$mr['or_status'] === 0) {
                            $mrStatus = '<span class="badge badge-warning">Pending</span>';
                        } elseif ((int)$mr['or_status'] === 1) {
                            $mrStatus = '<span class="badge badge-success">Approved</span>';
                        } elseif ((int)$mr['or_status'] === 2) {
                            $mrStatus = '<span class="badge badge-danger">Cancelled</span>';
                        } elseif ((int)$mr['or_status'] === 3) {
                            $mrStatus = '<span class="badge badge-primary">Approved and Claimed</span>';
                        } else {
                            $mrStatus = '<span class="badge badge-secondary">Unknown</span>';
                        }
                        ?>
                        <tr>
                          <td><?= htmlspecialchars($mr['or_no']); ?></td>
                          <td><?= htmlspecialchars($mr['proj_code']); ?></td>
                          <td><?= $mrStatus; ?></td>
                          <td><?= number_format((float)$mr['grand_total'], 2); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="4" class="text-center text-muted">No material requests found.</td>
                      </tr>
                    <?php endif; ?>
                    </tbody>
                  </table>
                </div>
                <!-- /.table-responsive -->
              </div>
              <!-- /.card-body -->
             
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->

          <div class="col-md-4">
           
            <!-- PRODUCT LIST -->
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">Recently Added Materials</h3>

                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                  </button>
                  <button type="button" class="btn btn-tool" data-card-widget="remove">
                    <i class="fas fa-times"></i>
                  </button>
                </div>
              </div>
              <!-- /.card-header -->
              <div class="card-body p-0">
                <ul class="products-list product-list-in-card pl-2 pr-2">
                  <?php
                  $recentMaterials = $db->getAllRecords("tbl_items", "*", "", "ORDER BY id DESC", "LIMIT 4");
                  ?>
                  <?php if ($recentMaterials): ?>
                    <?php foreach ($recentMaterials as $material): ?>
                      <li class="item">
                        <!--<div class="product-img">
                          <img src="dist/img/default-150x150.png" alt="Material" class="img-size-50">
                        </div> -->
                        <div style="margin:0px,5px,0px,5px;">
                          <a href="javascript:void(0)" class="product-title">
                            <?= htmlspecialchars($material['material_name'] ?? 'Unnamed Material'); ?>
                            <span class="badge badge-info float-right">PHP <?= number_format((float)($material['unit_price'] ?? 0), 2); ?></span>
                          </a>
                          <span class="product-description">
                            <?= htmlspecialchars($material['sku'] ?? ''); ?>
                            <?php if (!empty($material['color'])) { ?>
                              | <?= htmlspecialchars($material['color']); ?>
                            <?php } ?>
                            | Qty: <?= htmlspecialchars((float)($material['quantity'] ?? 0) . ' ' . ($material['unit'] ?? '')); ?>
                            <br>
                            <?= htmlspecialchars(!empty($material['description']) ? $material['description'] : '-'); ?>
                          </span>
                        </div>
                      </li>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <li class="item text-center text-muted p-3">No materials found.</li>
                  <?php endif; ?>
                </ul>
              </div>
              <!-- /.card-body -->
              <div class="card-footer text-center">
                <a href="#" class="uppercase nav-link" data-target="product">View All Materials</a>
              </div>
              <!-- /.card-footer -->
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
        </div>
        <!-- /.row -->
      </div><!--/. container-fluid -->
    </section>
	
	<section class="content" id="project">
	 <div class="container-fluid">
	  <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">Project</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.html">Home</a></li>
              <li class="breadcrumb-item active">Project</li>
            </ol>
          </div><!-- /.col -->
        </div><!-- /.row -->
		<hr>
		<?php
		if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin') {
			$totalProjects = (int) $pdo->query("SELECT COUNT(*) FROM tbl_project")->fetchColumn();
			$ongoingProjects = (int) $pdo->query("SELECT COUNT(*) FROM tbl_project WHERE proj_status = 0")->fetchColumn();
			$doneProjects = (int) $pdo->query("SELECT COUNT(*) FROM tbl_project WHERE proj_status = 1")->fetchColumn();
		} else {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_project WHERE proj_mgr = ?");
			$stmt->execute([$usr_code]);
			$totalProjects = (int) $stmt->fetchColumn();

			$stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_project WHERE proj_mgr = ? AND proj_status = 0");
			$stmt->execute([$usr_code]);
			$ongoingProjects = (int) $stmt->fetchColumn();

			$stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_project WHERE proj_mgr = ? AND proj_status = 1");
			$stmt->execute([$usr_code]);
			$doneProjects = (int) $stmt->fetchColumn();
		}
		?>
		<div class="row">
          
		  
		  <div class="col-md-4 col-sm-6 col-12">
            <div class="info-box bg-primary">
              <!--<span class="info-box-icon bg-info"><i class="far fa-envelope"></i></span>-->

              <div class="info-box-content">
                <span class="info-box-text">Total Projects</span>
                <span class="info-box-number"><?= htmlspecialchars($totalProjects); ?></span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <!-- /.col -->
		  
		  
		  
		  
          <div class="col-md-4 col-sm-6 col-12">
            <div class="info-box bg-warning">
              <!--<span class="info-box-icon bg-success"><i class="far fa-flag"></i></span>-->

              <div class="info-box-content">
                <span class="info-box-text">On-Going</span>
                <span class="info-box-number"><?= htmlspecialchars($ongoingProjects); ?></span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <!-- /.col -->
		  
		  
		  
		  
		  
		  
          <div class="col-md-4 col-sm-6 col-12">
            <div class="info-box bg-success">
             <!-- <span class="info-box-icon bg-warning"><i class="far fa-copy"></i></span> -->

              <div class="info-box-content">
                <span class="info-box-text">Projects Done</span>
                <span class="info-box-number"><?= htmlspecialchars($doneProjects); ?></span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <!-- /.col -->
        
        </div>
		<div class="row">
		
		<?php if (isset($_SESSION['user_type']) && in_array($_SESSION['user_type'], ['Admin', 'Manager'], true)) { ?>
		<div class="col-md-4">
            <!-- general form elements -->
            <div class="card card-info">
              <div class="card-header">
                <h3 class="card-title">Add New Project</h3>
              </div>
              <!-- /.card-header -->
              <!-- form start -->
              <?php include("pages/project_form.php"); ?>
            </div>
			
			</div> <!-- end add project form div -->
		
		
		<div class="col-md-8">
		
		<?php } else { ?>
		<div class="col-md-12">
			<?php } ?>
		<!-- TABLE: LATEST ORDERS -->
            <div class="card">
              <div class="card-header border-primary">
                <h3 class="card-title">Project List</h3>

               
              </div>
              <!-- /.card-header -->
              <div class="card-body p-3">
                <div class="table-responsive">
                  <table class="table m-0" id="project-list">
    <thead>
        <tr>
            <th>Project Code</th>
            <th>Project Name</th>
            <th>Project Manager</th>
            <th>Project Owner</th>
            <th>Project Cost</th>
            <th>Description</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Approval</th>
            <th>Status</th>
            <th>Attachments</th>
            <th>Action</th>
        </tr>
    </thead>

    <tbody>
        <!-- AJAX or PHP rows here -->
    </tbody>
</table>
                </div>
                <!-- /.table-responsive -->
              </div>
              <!-- /.card-body -->
              <div class="card-footer clearfix">
              
                
              </div>
              <!-- /.card-footer -->
            </div>
            <!-- /.card -->
			</div>
			<!-- .col-md-8 -->
		
		</div> <!-- end row project details-->
		
      </div><!-- /.container-fluid -->
    </div>
	</div> <!-- end container fluid -->
	</section>
	
	
	<section class="content" id="product">
	 <div class="container-fluid">
	  <div class="content-header">
      <div class="container-fluid">
	  
	  
	  
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">Items</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.html">Home</a></li>
              <li class="breadcrumb-item active">Items</li>
            </ol>
          </div><!-- /.col -->
        </div><!-- /.row -->
		<hr>
		<?php
		$itemColumns = $pdo->query("SHOW COLUMNS FROM tbl_items")->fetchAll(PDO::FETCH_COLUMN);
		if (!in_array('reorder_level', $itemColumns, true)) {
			$pdo->exec("ALTER TABLE tbl_items ADD reorder_level INT NOT NULL DEFAULT 10");
		}
		$itemCount = (int) $pdo->query("SELECT COUNT(*) FROM tbl_items")->fetchColumn();
		$lowStockCount = (int) $pdo->query("
			SELECT COUNT(*)
			FROM tbl_items i
			LEFT JOIN (
				SELECT oi.sku, SUM(oi.qty) AS reserved_qty
				FROM tbl_or_items oi
				INNER JOIN tbl_or o ON o.or_id = oi.or_id
				WHERE o.or_status = 0
				GROUP BY oi.sku
			) r ON r.sku = i.sku
			WHERE (i.quantity - COALESCE(r.reserved_qty, 0)) > 0
			  AND (i.quantity - COALESCE(r.reserved_qty, 0)) <= i.reorder_level
		")->fetchColumn();
		$outOfStockCount = (int) $pdo->query("
			SELECT COUNT(*)
			FROM tbl_items i
			LEFT JOIN (
				SELECT oi.sku, SUM(oi.qty) AS reserved_qty
				FROM tbl_or_items oi
				INNER JOIN tbl_or o ON o.or_id = oi.or_id
				WHERE o.or_status = 0
				GROUP BY oi.sku
			) r ON r.sku = i.sku
			WHERE (i.quantity - COALESCE(r.reserved_qty, 0)) <= 0
		")->fetchColumn();
		?>
		<div class="row">
          
		  
		  <div class="col-md-4 col-sm-6 col-12">
            <div class="info-box bg-info">
              <!--<span class="info-box-icon bg-info"><i class="far fa-envelope"></i></span>-->

              <div class="info-box-content">
                <span class="info-box-text">Item Count</span>
                <span class="info-box-number"><?= htmlspecialchars($itemCount); ?></span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <!-- /.col -->
		  
		  
		  
		  
          <div class="col-md-4 col-sm-6 col-12">
            <div class="info-box bg-success">
              <!--<span class="info-box-icon bg-success"><i class="far fa-flag"></i></span>-->

              <div class="info-box-content">
                <span class="info-box-text">Low in Stock</span>
                <span class="info-box-number"><?= htmlspecialchars($lowStockCount); ?></span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <!-- /.col -->
		  
		  
		  
		  
		  
		  
          <div class="col-md-4 col-sm-6 col-12">
            <div class="info-box bg-warning">
             <!-- <span class="info-box-icon bg-warning"><i class="far fa-copy"></i></span> -->

              <div class="info-box-content">
                <span class="info-box-text">Out of Stock</span>
                <span class="info-box-number"><?= htmlspecialchars($outOfStockCount); ?></span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <!-- /.col -->
        
        </div>
        <!-- /.row -->
		<div class="row">
		<div class="col-12">
		<!-- TABLE: LATEST ORDERS -->
            <div class="card">
              <div class="card-header border-info">
                <h3 class="card-title">Latest Item List</h3>

                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                  </button>
                  <button type="button" class="btn btn-tool" data-card-widget="remove">
                    <i class="fas fa-times"></i>
                  </button>
                </div>
              </div>
              <!-- /.card-header -->
              <div class="card-body p-3">
                <div class="table-responsive">
                  <table class="table m-0" id="stock-list">
  <thead>
    <tr>
      <th>SKU</th>
      <th>Item Name</th>
      <th>Description</th>
      <th>Color</th>
      <th>Status</th>
      <th>Action</th>
    </tr>
  </thead>
  <tbody></tbody>
</table>
				    </div>
                <!-- /.table-responsive -->
              </div>
              <!-- /.card-body -->
              <div class="card-footer clearfix">
			  
			  
               <?php if ($_SESSION['user_type'] !== 'Manager') { ?>

<button class="btn btn-primary" data-toggle="modal" data-target="#productModal">
  Add Product
</button>

<?php } else {?>
<button class="btn btn-warning" data-toggle="modal" data-target="#itemRequestModal">
  Request Materials for Purchase
</button>

<?php } ?>
                <a href="javascript:void(0)" class="btn btn-sm btn-secondary float-right">View All</a>
              </div>
              <!-- /.card-footer -->
            </div>
            <!-- /.card -->
			</div>
			<!-- .col-12 -->
		</div>	
		
			<div class="row">
		<div class="col-6">
		<!-- TABLE: LATEST ORDERS -->
            <div class="card">
              <div class="card-header border-warning">
                <h3 class="card-title">Items for Purchase Request</h3>

               
              </div>
              <!-- /.card-header -->
              <div class="card-body p-3">
                <div class="table-responsive">
                  <table class="table m-0 table-striped" id="items-po">
                    <thead>
                    <tr>
                      <th>Product Name</th>
                      <th>Description</th>
                      <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    
                    </tbody>
                  </table>
                </div>
                <!-- /.table-responsive -->
              </div>
              <!-- /.card-body -->
              <div class="card-footer clearfix">
                <button type="button" class="btn btn-sm btn-success float-right" id="createPendingItemPrBtn">
                  Create PR Request
                </button>
              </div>
              <!-- /.card-footer -->
            </div>
            <!-- /.card -->
			</div>
			<!-- .col-12 -->
		<div class="col-6">
            <div class="card">
              <div class="card-header bg-warning border-warning">
                <h3 class="card-title">Fabric Unit Converter</h3>
              </div>
              <div class="card-body p-3 bg-light">
                <div class="form-row">
                  <div class="form-group col-md-4">
                    <label>Convert From</label>
                    <select class="form-control" id="fabricConvertFrom">
                      <option value="kg">Kilos</option>
                      <option value="roll">Rolls</option>
                    </select>
                  </div>
                  <div class="form-group col-md-4">
                    <label>Quantity</label>
                    <input type="number" class="form-control" id="fabricConvertQty" min="0" step="0.01">
                  </div>
                  <div class="form-group col-md-4">
                    <label>Yards</label>
                    <input type="text" class="form-control" id="fabricConvertResult" readonly>
                  </div>
                </div>

                <div class="form-row">
                  <div class="form-group col-md-6">
                    <label>Total Cost</label>
                    <input type="number" class="form-control" id="fabricConvertTotalCost" min="0" step="0.01">
                  </div>
                  <div class="form-group col-md-6">
                    <label>Unit Price per Yard</label>
                    <input type="text" class="form-control" id="fabricConvertUnitPrice" readonly>
                  </div>
                </div>

                <div class="form-row" id="kgConverterFields">
                  <div class="form-group col-md-6">
                    <label>GSM</label>
                    <input type="number" class="form-control" id="fabricConvertGsm" min="0" step="0.01">
                  </div>
                  <div class="form-group col-md-6">
                    <label>Width Inches</label>
                    <input type="number" class="form-control" id="fabricConvertWidth" min="0" step="0.01">
                  </div>
                </div>

                <div class="form-row d-none" id="rollConverterFields">
                  <div class="form-group col-md-12">
                    <label>Yards per Roll</label>
                    <input type="number" class="form-control" id="fabricConvertYardsPerRoll" min="0" step="0.01">
                  </div>
                </div>
              </div>
              <div class="card-footer clearfix">
                <button type="button" class="btn btn-sm btn-info" id="calculateFabricYardsBtn">
                  Convert to Yards
                </button>
                <button type="button" class="btn btn-sm btn-default" id="resetFabricConverterBtn">
                  Reset
                </button>
                <button type="button" class="btn btn-sm btn-secondary float-right" id="useFabricYardsBtn">
                  Use in Product
                </button>
              </div>
            </div>
			</div>
			<!-- .col-12 -->
			</div>
			<!-- .row -->
		
		
		
		
		
		
      </div><!-- /.container-fluid -->
    </div>
	</div> <!-- end container fluid -->
	</section>
	
	<section class="content" id="order">
	 <div class="container-fluid">
	  <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">Material Requests</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.html">Home</a></li>
              <li class="breadcrumb-item active">Material</li>
            </ol>
          </div><!-- /.col -->
        </div><!-- /.row -->
		<hr>
		<div class="row">
		
		<div class="col-md-12">
            <!-- general form elements -->
           
             
              <!-- /.card-header -->
              <!-- form start -->
      
  <div class="card card-info">
   <div class="card-header d-flex justify-content-between align-items-center">
  <h3 class="card-title mb-0">New Material Request Form</h3>

 
</div>
 <form id="orForm">
    <div class="card-body">
      <div class="row">
        <div class="col-md-3">
          <div class="form-group">
            <label>MR No.</label>
			<input type="hidden" name="or_id" id="or_id">
			<input type="hidden" name="prepared_by" value="<?php echo htmlspecialchars($_SESSION['username']); ?>">
<input type="hidden" name="user_code" value="<?php echo htmlspecialchars($_SESSION['user_code']); ?>">
            <input type="text" class="form-control" name="or_no" readonly>
          </div>
        </div>

        <div class="col-md-3">
          <div class="form-group">
            <label>Request Date</label>
            <input type="date" 
       class="form-control" 
       name="or_date" 
       value="<?php echo date('Y-m-d'); ?>" 
       required>
          </div>
        </div>

        <div class="col-md-3">
          <div class="form-group">
            <label>Requesting Department</label>
            <input type="text" 
       class="form-control" 
       name="dept_code" 
       value="<?php echo htmlspecialchars($_SESSION['user_dept']); ?>" 
       readonly>
          </div>
        </div>
		<div class="col-md-3">
          <div class="form-group">
            <label>Project</label>
           <?php
		   
		   if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin') { 
            $projs = $db->getAllRecords("tbl_project", "proj_code, proj_name", "AND " . $projectApprovalFilterSql, "ORDER BY proj_code ASC");
           }else{ 
			
			$stmt = $pdo->prepare(
    "SELECT proj_code, proj_name 
    FROM tbl_project 
    WHERE proj_mgr = ? AND " . $projectApprovalFilterSql . "
    ORDER BY proj_code ASC"
);
            $stmt->execute([$usr_code]);

$projs = $stmt->fetchAll(PDO::FETCH_ASSOC);
		   }
			?>
           <select name="proj_code" id="proj_code" class="form-control" required>
    <option value="">Select Project</option>

    <?php foreach($projs as $row): ?>
        <option value="<?= htmlspecialchars($row['proj_code']); ?>">
            <?= htmlspecialchars($row['proj_code'] . ' : ' . $row['proj_name']); ?>
        </option>
    <?php endforeach; ?>
</select>
          </div>
        </div>
		
      </div>

      <div class="row">
        <div class="col-md-12">
          <div class="form-group">
            <label>Remarks</label>
            <textarea class="form-control" name="remarks" rows="2"></textarea>
          </div>
        </div>
      </div>

      <hr>

      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0">Materials List</h5>
        <button type="button" class="btn btn-primary btn-sm" id="addItemRow">Add Item</button>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered" id="orItemsTable">
          <thead>
            <tr>
              <th style="width: 20%;">SKU</th>
              <th style="width: 25%;">Item Name</th>
              <th style="width: 15%;">Qty</th>
              <th style="width: 15%;">Unit</th>
              <th style="width: 15%;">Unit Price</th>
              <th style="width: 15%;">Amount</th>
              <th style="width: 10%;">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><input type="text" class="form-control sku" name="items[0][sku]" required readonly></td>
              <td style="position:relative;">
  <input type="text" class="form-control item_name" placeholder="...Type Item Name" name="items[0][item_name]" autocomplete="off" required>
  <div class="item-dropdown"></div>
</td>
              <td><input type="number" class="form-control qty" name="items[0][qty]" min="1" value="1" required></td>
              <td>
                <select class="form-control unit" name="items[0][unit]" required>
                  <option value="">-Select-</option>
                  <option value="yard">Yards</option>
                  <option value="kg">Kilos / kg</option>
                  <option value="roll">Rolls</option>
                  <option value="bdl">Bundle</option>
                  <option value="pcs">Pieces / pcs</option>
                </select>
              </td>
              <td><input type="number" class="form-control unit_price" name="items[0][unit_price]" min="0" step="0.01" value="0.00" required></td>
              <td><input type="text" class="form-control amount" name="items[0][amount]" value="0.00" readonly></td>
              <td class="text-center">
                <button type="button" class="btn btn-danger btn-sm removeRow">Remove</button>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="5" class="text-right">Grand Total</th>
              <th>
                <input type="text" class="form-control" id="grandTotal" name="grand_total" value="0.00" readonly>
              </th>
              <th></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

 <div class="card-footer d-flex align-items-center">

  <!-- LEFT -->
  <small class="text-muted">
    Logged in as: 
    <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> 
    |
    <?php echo htmlspecialchars($_SESSION['user_dept']); ?>
  </small>

  <!-- RIGHT -->
  <div class="ml-auto">
  <button type="button" class="btn btn-info" id="approveOrBtn" style="display:none;">
  <i class="fas fa-check"></i> Approve MR
</button>
  
  
    <button type="submit" class="btn btn-success" id="saveorBtn">
      <i class="fas fa-save"></i> Save MR
    </button>
  </div>

</div>
	</form>
  </div>

            
			
			</div> <!-- end add project form div -->
		
		
		<div class="col-md-12">
		<!-- TABLE: LATEST ORDERS -->
            <div class="card">
              <div class="card-header border-transparent">
                <h3 class="card-title">Material Request List</h3>

                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                  </button>
                  <button type="button" class="btn btn-tool" data-card-widget="remove">
                    <i class="fas fa-times"></i>
                  </button>
                </div>
              </div>
              <!-- /.card-header -->
              <div class="card-body p-3">
                <div class="table-responsive">
                  <table class="table table-bordered table-striped m-0" id="or-list">
  <thead>
    <tr>
      <th>MR No.</th>
      <th>MR Date</th>
      <th>Department</th>
      <th>Project Code</th>
      <th>Prepared By</th>
      <th>Grand Total</th>
      <th>Status</th>
      <th>Action</th>
    </tr>
  </thead>
</table>
                </div>
                <!-- /.table-responsive -->
              </div>
              <!-- /.card-body -->
              <div class="card-footer clearfix">
              
                
              </div>
              <!-- /.card-footer -->
            </div>
            <!-- /.card -->
			</div>
			<!-- .col-md-8 -->
		
		</div> <!-- end row project details-->
		
      </div><!-- /.container-fluid -->
    </div>
	</div> <!-- end container fluid -->
	</section>
	
	
	<section class="content" id="inventory">
	 <div class="container-fluid">
	  <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">Inventory</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.html">Home</a></li>
              <li class="breadcrumb-item active">Inventory</li>
            </ol>
          </div><!-- /.col -->
        </div><!-- /.row -->
		<hr>
		<div class="row mb-2">
		  <div class="col-2">
		  <button class="form-control btn-success mb-2 inv-in">IN</button>
		  <button class="form-control btn-warning  mb-2 inv-out">OUT</button>
		  </div>
		  <div class="col-10">
		<!-- TABLE: LATEST ORDERS -->
            <div class="card">
              <div class="card-header border-transparent bg-info">
                <h3 class="card-title">Inventory List</h3>

                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                  </button>
                  <button type="button" class="btn btn-tool" data-card-widget="remove">
                    <i class="fas fa-times"></i>
                  </button>
                </div>
              </div>
              <!-- /.card-header -->
              <div class="card-body p-3">
                <div class="table-responsive">
  <table class="table m-0" id="inventory-list">
  <thead>
    <tr>
      <th>SKU</th>
      <th>Item Details</th>
      <th>Stocks</th>
      <th>Cost</th>
      <th>Status</th>
	  <th>Location</th>
    </tr>
  </thead>
  <tbody></tbody>
</table>
				    </div>
                <!-- /.table-responsive -->
              </div>
              <!-- /.card-body -->
             
              <!-- /.card-footer -->
            </div>
            <!-- /.card -->
			</div>
			<!-- .col-10 -->
		
		</div>
		
		<div class="row">
		<div class="col-6">
		<!-- TABLE: LATEST ORDERS -->
            <div class="card">
              <div class="card-header border-transparent bg-success">
                <h3 class="card-title">Low in Stock</h3>

                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                  </button>
                  <button type="button" class="btn btn-tool" data-card-widget="remove">
                    <i class="fas fa-times"></i>
                  </button>
                </div>
              </div>
              <!-- /.card-header -->
              <div class="card-body p-3">
                <div class="table-responsive">
                  <table class="table m-0 table-striped" id="inv-low-stock">
                    <thead>
                    <tr>
                      <th>SKU</th>
                      <th>Product Name</th>
                      <th>Description</th>
                      <th>Quantity</th>
                    </tr>
                    </thead>
                    <tbody>
                    </tbody>
                  </table>
                </div>
                <!-- /.table-responsive -->
              </div>
              <!-- /.card-body -->
              <div class="card-footer clearfix">
               <!-- <a href="javascript:void(0)" class="btn btn-sm btn-info float-left">Place New Order</a> -->
                <a href="javascript:void(0)" class="btn btn-sm btn-secondary float-right open-pr-request">PR Request</a>
              </div>
              <!-- /.card-footer -->
            </div>
            <!-- /.card -->
			</div>
			<!-- .col-6 -->
		<div class="col-6">
            <div class="card">
              <div class="card-header border-warning bg-light">
                <h3 class="card-title">Generated PR Requests</h3>
              </div>
              <div class="card-body p-3">
                <div class="table-responsive">
                  <table class="table table-bordered table-striped m-0" id="inventory-pr-list">
                    <thead>
                      <tr>
                        <th>PR No.</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Total Qty</th>
                        <th>Status</th>
                        <th>Action</th>
                      </tr>
                    </thead>
                    <tbody></tbody>
                  </table>
                </div>
              </div>
            </div>
			</div>
			<!-- .col-6 -->
		</div>

		<div class="row">
		<div class="col-6">
            <div class="card">
              <div class="card-header border-transparent bg-success">
                <h3 class="card-title">Inventory IN History</h3>

                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                  </button>
                  <button type="button" class="btn btn-tool" data-card-widget="remove">
                    <i class="fas fa-times"></i>
                  </button>
                </div>
              </div>
              <div class="card-body p-3">
                <div class="table-responsive">
                  <table class="table m-0 table-striped" id="inv-in-history">
                    <thead>
                    <tr>
                      <th>Date</th>
                      <th>SKU</th>
                      <th>Item</th>
                      <th>Before</th>
                      <th>Quantity</th>
                      <th>After</th>
                      <th>Unit Price</th>
                      <th>Receipt No.</th>
                      <th>PO Code</th>
                    </tr>
                    </thead>
                    <tbody>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
			</div>

			<div class="col-6">
            <div class="card">
              <div class="card-header border-transparent bg-warning">
                <h3 class="card-title">Inventory OUT History</h3>

                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                  </button>
                  <button type="button" class="btn btn-tool" data-card-widget="remove">
                    <i class="fas fa-times"></i>
                  </button>
                </div>
              </div>
              <div class="card-body p-3">
                <div class="table-responsive">
                  <table class="table m-0 table-striped" id="inv-out-history">
                    <thead>
                    <tr>
                      <th>Date</th>
                      <th>SKU</th>
                      <th>Item</th>
                      <th>Before</th>
                      <th>Quantity</th>
                      <th>After</th>
                      <th>Reference No.</th>
                      <th>Remarks</th>
                    </tr>
                    </thead>
                    <tbody>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
			</div>
		</div>
		
		
		
		
      </div><!-- /.container-fluid -->
    </div>
	</div> <!-- end container fluid -->
	</section>
	
	<section class="content" id="report">
	 <div class="container-fluid">
	  <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">Reports</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.html">Home</a></li>
              <li class="breadcrumb-item active">Report</li>
            </ol>
          </div><!-- /.col -->
        </div><!-- /.row -->
		<hr>
		<?php
		if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin') {
			$reportProjects = $db->getAllRecords("tbl_project", "proj_code, proj_name", "AND " . $projectApprovalFilterSql, "ORDER BY proj_code ASC");
		} else {
			$stmt = $pdo->prepare(
				"SELECT proj_code, proj_name
				FROM tbl_project
				WHERE proj_mgr = ? AND " . $projectApprovalFilterSql . "
				ORDER BY proj_code ASC"
			);
			$stmt->execute([$usr_code]);
			$reportProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
		}
		?>
		<div class="row">
		  <div class="col-md-4">
			<div class="form-group">
			  <label>Project</label>
			  <select id="report_proj_code" class="form-control">
				<?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin') { ?>
				  <option value="">All Projects</option>
				<?php } else { ?>
				  <option value="">All Assigned Projects</option>
				<?php } ?>
				<?php foreach ($reportProjects as $project): ?>
				  <option value="<?= htmlspecialchars($project['proj_code']); ?>">
					<?= htmlspecialchars($project['proj_code'] . ' : ' . $project['proj_name']); ?>
				  </option>
				<?php endforeach; ?>
			  </select>
			</div>
		  </div>

		  <div class="col-md-4">
			<div class="info-box bg-info">
			  <div class="info-box-content">
				<span class="info-box-text">Approved MR Count</span>
				<span class="info-box-number" id="report_mr_count">0</span>
			  </div>
			</div>
		  </div>

		  <div class="col-md-4">
			<div class="info-box bg-success">
			  <div class="info-box-content">
				<span class="info-box-text">Total Project MR Cost</span>
				<span class="info-box-number" id="report_grand_total">0.00</span>
			  </div>
			</div>
		  </div>
		</div>

		<div class="row">
		  <div class="col-md-12">
			<div class="card">
			  <div class="card-header border-transparent">
				<h3 class="card-title">Approved Material Request History</h3>
			  </div>
			  <div class="card-body p-3">
				<div class="table-responsive">
				  <table class="table table-bordered table-striped m-0" id="project-mr-report">
					<thead>
					  <tr>
						<th>MR No.</th>
						<th>MR Date</th>
						<th>Project Code</th>
						<th>Department</th>
						<th>Prepared By</th>
						<th>Remarks</th>
						<th>Grand Total</th>
					  </tr>
					</thead>
					<tbody>
					</tbody>
				  </table>
				</div>
			  </div>
			</div>
		  </div>
		</div>
      </div><!-- /.container-fluid -->
    </div>
	</div> <!-- end container fluid -->
	</section>
	
	
	
	<section class="content" id="setting">
	 <div class="container-fluid">
	  <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">Settings</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard">Home</a></li>
              <li class="breadcrumb-item active">Settings</li>
            </ol>
          </div><!-- /.col -->
        </div><!-- /.row -->
      </div><!-- /.container-fluid -->
    </div>
	<hr>
	<div class="row">
	  <div class="col-md-6">
		<div class="card">
		  <div class="card-header border-warning">
			<h3 class="card-title">Change Password</h3>
		  </div>
		  <form id="changePasswordForm">
			<div class="card-body">
			  <div class="form-group">
				<label>Existing Password</label>
				<input type="password" class="form-control" name="current_password" required>
			  </div>
			  <div class="form-group">
				<label>New Password</label>
				<input type="password" class="form-control" name="new_password" required>
			  </div>
			  <div class="form-group">
				<label>Confirm Password</label>
				<input type="password" class="form-control" name="confirm_password" required>
			  </div>
			</div>
			<div class="card-footer text-right">
			  <button type="submit" class="btn btn-warning" id="changePasswordBtn">
				Change Password
			  </button>
			</div>
		  </form>
		</div>
	  </div>
	  <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin') { ?>
	  <div class="col-md-6">
		<div class="card">
		  <div class="card-header border-danger">
			<h3 class="card-title">Database Backup</h3>
		  </div>
		  <div class="card-body">
			<?php
			$lastBackup = null;
			try {
				$auditTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'tbl_audit_logs'");
				if ($auditTableExistsStmt->fetchColumn()) {
					$lastBackupStmt = $pdo->query("
						SELECT created_at, user_name, user_code, reference_no
						FROM tbl_audit_logs
						WHERE action = 'BACKUP' AND module = 'Database'
						ORDER BY audit_id DESC
						LIMIT 1
					");
					$lastBackup = $lastBackupStmt->fetch(PDO::FETCH_ASSOC);
				}
			} catch (Exception $e) {
				$lastBackup = null;
			}
			?>
			<p class="text-muted mb-2">Download a full SQL backup of the current database.</p>
			<?php if ($lastBackup) { ?>
			  <div class="alert alert-light border py-2">
				<div><strong>Last Backup:</strong> <?= htmlspecialchars($lastBackup['created_at']); ?></div>
				<div><strong>File:</strong> <?= htmlspecialchars($lastBackup['reference_no'] ?: '-'); ?></div>
				<div><strong>Downloaded By:</strong> <?= htmlspecialchars(($lastBackup['user_name'] ?: '-') . ' (' . ($lastBackup['user_code'] ?: '-') . ')'); ?></div>
			  </div>
			<?php } else { ?>
			  <div class="alert alert-warning py-2">No database backup has been downloaded yet.</div>
			<?php } ?>
			<a href="ajax/backup_database.php" class="btn btn-danger">
			  Backup Database
			</a>
		  </div>
		</div>
	  </div>
	  <?php } ?>
	</div>

	<?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin') { ?>
	<div class="row">
	  <div class="col-md-12">
		<div class="card">
		  <div class="card-header border-info">
			<h3 class="card-title">User List</h3>
			<div class="card-tools">
			  <button type="button" class="btn btn-sm btn-primary" id="addUserBtn">
				Add User
			  </button>
			</div>
		  </div>
		  <div class="card-body p-3">
			<div class="table-responsive">
			  <table class="table table-bordered table-striped m-0" id="user-list">
				<thead>
				  <tr>
					<th>User Code</th>
					<th>Name</th>
					<th>User Type</th>
					<th>Department</th>
					<th>Action</th>
				  </tr>
				</thead>
				<tbody></tbody>
			  </table>
			</div>
		  </div>
		</div>
	  </div>
	</div>
	<div class="row">
	  <div class="col-md-12">
		<div class="card">
		  <div class="card-header border-secondary">
			<h3 class="card-title">Audit Trail Logs</h3>
			<div class="card-tools">
			  <button type="button" class="btn btn-sm btn-secondary" id="openAuditReportModalBtn">
				Print Audit Trail
			  </button>
			</div>
		  </div>
		  <div class="card-body p-3">
			<div class="table-responsive">
			  <table class="table table-bordered table-striped m-0" id="audit-log-list">
				<thead>
				  <tr>
					<th>Date/Time</th>
					<th>User</th>
					<th>Type</th>
					<th>Action</th>
					<th>Module</th>
					<th>Reference</th>
					<th>Description</th>
				  </tr>
				</thead>
				<tbody></tbody>
			  </table>
			</div>
		  </div>
		</div>
	  </div>
	</div>
	<?php } ?>
	</div> <!-- end container fluid -->
	</section>
	
	<section class="content" id="purchasing">
	 <div class="container-fluid">
	  <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">Purchasing</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard">Home</a></li>
              <li class="breadcrumb-item active">Purchasing</li>
            </ol>
          </div><!-- /.col -->
        </div><!-- /.row -->
		<hr>
		<div class="row">
		  <div class="col-md-4">
			<div class="card card-info">
			  <div class="card-header">
				<h3 class="card-title">Supplier Registration</h3>
			  </div>

			  <form id="supplierForm">
				<div class="card-body">
				  <input type="hidden" name="supplier_id" id="supplier_id">

				  <div class="form-group">
					<label>Supplier Name</label>
					<input type="text" class="form-control" name="supplier_name" id="supplier_name" required>
				  </div>

				  <div class="form-group">
					<label>Supplier Owner</label>
					<input type="text" class="form-control" name="supplier_owner" id="supplier_owner" required>
				  </div>

				  <div class="form-group">
					<label>Address</label>
					<textarea class="form-control" name="address" id="supplier_address" rows="2" required></textarea>
				  </div>

				  <div class="row">
					<div class="col-md-6">
					  <div class="form-group">
						<label>Contact No.</label>
						<input type="text" class="form-control" name="contact_no" id="supplier_contact_no" required>
					  </div>
					</div>

					<div class="col-md-6">
					  <div class="form-group">
						<label>Email</label>
						<input type="email" class="form-control" name="email" id="supplier_email">
					  </div>
					</div>
				  </div>
				</div>

				<div class="card-footer text-right">
				  <button type="button" class="btn btn-secondary d-none" id="cancelSupplierEditBtn">
					Cancel
				  </button>
				  <button type="submit" class="btn btn-success" id="saveSupplierBtn">
					<i class="fas fa-save"></i> Save Supplier
				  </button>
				</div>
			  </form>
			</div>
		  </div>

		  <div class="col-md-8">
			<div class="card">
			  <div class="card-header border-info">
				<h3 class="card-title">Supplier List</h3>
			  </div>
			  <div class="card-body p-3">
				<div class="table-responsive">
				  <table class="table table-bordered table-striped m-0" id="supplier-list">
					<thead>
					  <tr>
						<th>Supplier</th>
						<th>Owner</th>
						<th>Contact</th>
						<th>Email</th>
						<th>Action</th>
					  </tr>
					</thead>
					<tbody></tbody>
				  </table>
				</div>
			  </div>
			</div>
		  </div>
		</div>
		<div class="row">
		  <div class="col-md-12">
			<div class="card">
			  <div class="card-header border-warning">
				<h3 class="card-title">Purchase Request List</h3>
			  </div>
			  <div class="card-body p-3">
				<div class="table-responsive">
				  <table class="table table-bordered table-striped m-0" id="purchase-request-list">
					<thead>
					  <tr>
						<th>PR No.</th>
						<th>Date</th>
						<th>Requested By</th>
						<th>Items</th>
						<th>Total Qty</th>
						<th>Status</th>
						<th>Action</th>
					  </tr>
					</thead>
					<tbody></tbody>
				  </table>
				</div>
			  </div>
			</div>
		  </div>
		</div>
		<div class="row">
		  <div class="col-md-12">
			<div class="card">
			  <div class="card-header border-success">
				<h3 class="card-title">PO Request List</h3>
			  </div>
			  <div class="card-body p-3">
				<div class="table-responsive">
				  <table class="table table-bordered table-striped m-0" id="purchase-order-list">
					<thead>
					  <tr>
						<th>PO No.</th>
						<th>PR No.</th>
						<th>Date</th>
						<th>Supplier</th>
						<th>Items</th>
						<th>Total PO Qty</th>
						<th>Status</th>
						<th>Receipt No.</th>
						<th>Date Received</th>
						<th>Created By</th>
						<th>Action</th>
					  </tr>
					</thead>
					<tbody></tbody>
				  </table>
				</div>
			  </div>
			</div>
		  </div>
		</div>
      </div><!-- /.container-fluid -->
    </div>
	</div> <!-- end container fluid -->
	</section>
	
	
	
	
    <!-- /.content -->
  </div>
  <!-- /.content-wrapper -->

  <!-- Control Sidebar -->
  <aside class="control-sidebar control-sidebar-dark">
    <!-- Control sidebar content goes here -->
  </aside>
  <!-- /.control-sidebar -->

<!--modals -->
<div class="modal fade" id="productModal" tabindex="-1" role="dialog" aria-labelledby="productModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      
      <div class="modal-header">
        <h5 class="modal-title" id="productModalLabel">Add Material</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form id="productForm">
        <div class="modal-body">

          <div class="form-group">
		  <input type="hidden" class="form-control" name="id" id="id">
            <label>SKU <small class="text-muted">(Stock Keeping Unit)</small></label>
            <input type="text" class="form-control" name="sku" id="sku" placeholder="Auto-generated" readonly>
          </div>

          <div class="form-group">
            <label>Material Name</label>
            <input type="text" class="form-control" name="material_name" id="material_name" required>
          </div>

          <div class="form-group">
            <label>Material Type <small class="text-muted">(Optional)</small></label>
            <input type="text" class="form-control" name="material_type" id="material_type">
          </div>

          <div class="form-group">
            <label>Category</label>
            <select class="form-control" name="category" id="category" required>
              <option value="">-Select-</option>
              <option value="fab">Fabric</option>
              <option value="threads">Threads</option>
              <option value="acc">Accessories</option>
            </select>
          </div>

          <div class="form-group">
            <label>Color</label>
            <input type="text" class="form-control" name="color" id="color" required>
          </div>

          <div class="form-group">
            <label>GSM</label>
            <input type="text" class="form-control" name="gsm" id="gsm">
          </div>

          <div class="form-group">
            <label>Description</label>
            <textarea class="form-control" name="description" id="description" rows="3"></textarea>
          </div>

          <div class="row">
            <div class="col-md-4">
              <div class="form-group">
                <label>Quantity</label>
                <input type="number" class="form-control" name="quantity" id="quantity" value="0">
              </div>
            </div>

            <div class="col-md-4">
              <div class="form-group">
                <label>Unit</label>
                <select class="form-control" name="unit" id="unit" required>
                  <option value="">-Select-</option>
                  <option value="yard">Yards</option>
                  <option value="kg">Kilos / kg</option>
                  <option value="roll">Rolls</option>
                  <option value="bdl">Bundle</option>
                  <option value="pcs">Pieces / pcs</option>
                </select>
              </div>
            </div>

            <div class="col-md-4">
              <div class="form-group">
                <label>Unit Price</label>
                <input type="number" class="form-control" name="unit_price" id="unit_price" step="0.01">
              </div>
            </div>

            <div class="col-md-4">
              <div class="form-group">
                <label>Reorder Level</label>
                <input type="number" class="form-control" name="reorder_level" id="reorder_level" min="0" value="10">
              </div>
            </div>

            <div class="col-md-4">
              <div class="form-group">
                <label>Location</label>
                <input type="text" class="form-control" name="location" id="location">
              </div>
            </div>
          </div>

        </div>

        <div class="modal-footer justify-content-between">
          <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-info" id="saveBtn">Save Product</button>
        </div>
      </form>

    </div>
  </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" role="dialog" aria-labelledby="userModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="userModalLabel">Edit User</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form id="userForm">
        <input type="hidden" name="mode" id="user_mode" value="edit">
        <div class="modal-body">
          <div class="form-group">
            <label>User Code</label>
            <input type="text" class="form-control" name="user_code" id="edit_user_code" readonly>
          </div>
          <div class="form-group">
            <label>Name</label>
            <input type="text" class="form-control" name="user_name" id="edit_user_name" required>
          </div>
          <div class="form-group">
            <label>User Type</label>
            <select class="form-control" name="user_type" id="edit_user_type" required>
              <option value="">Select Type</option>
              <option value="Admin">Admin</option>
              <option value="Manager">Manager</option>
              <option value="Inventory">Inventory</option>
              <option value="Purchasing">Purchasing</option>
            </select>
          </div>
          <div class="form-group">
            <label>Department</label>
            <input type="text" class="form-control" name="user_dept" id="edit_user_dept" required>
          </div>
          <div class="form-group" id="userPasswordGroup" style="display:none;">
            <label>Password</label>
            <input type="password" class="form-control" name="pword" id="edit_user_password">
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary" id="saveUserBtn">
            <i class="fas fa-save"></i> Save User
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="auditReportModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Audit Trail Date Range</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form id="auditReportForm">
        <div class="modal-body">
          <div class="form-group">
            <label>Start Date</label>
            <input type="date" class="form-control" id="audit_start_date" required>
          </div>
          <div class="form-group">
            <label>End Date</label>
            <input type="date" class="form-control" id="audit_end_date" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">View Report</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="encodePrModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Encode PR Items: <span id="encodePrRef"></span></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="encode_pr_id">
        <div class="row mb-2">
          <div class="col-md-4"><strong>PO No.:</strong> <span id="encodePoRef"></span></div>
          <div class="col-md-4"><strong>Receipt No.:</strong> <span id="encodeReceiptNo"></span></div>
          <div class="col-md-4"><strong>Date Received:</strong> <span id="encodeDateReceived"></span></div>
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-striped m-0">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Item</th>
                <th>Qty</th>
                <th>Unit</th>
                <th>Unit Price</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="encodePrItemsBody">
              <tr>
                <td colspan="6" class="text-center text-muted">Loading items...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!--modal upload files -->
<div class="modal fade" id="uploadModal">
  <div class="modal-dialog">
    <form id="uploadForm" enctype="multipart/form-data">
      <div class="modal-content">

        <div class="modal-header">
          <h5 class="modal-title">Upload Project File</h5>
          <button type="button" class="btn-close" data-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="proj_code" id="proj_code">

          <input type="file"
                 name="file"
                 class="form-control"
                 accept=".jpg,.jpeg,.png,.pdf"
                 required>
        </div>

        <div class="modal-footer">
          <button class="btn btn-success">Upload</button>
        </div>

      </div>
    </form>
  </div>
</div>

<!-- modal view files -->
<div class="modal fade" id="viewModal">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Project Files</h5>
        <button class="btn-close" data-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div id="fileList">Loading...</div>
      </div>

    </div>
  </div>
</div>
<!-- end modal view files -->


<!--qr modal -->
<div class="modal fade" id="qrModal">
  <div class="modal-dialog">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Project QR Code</h5>
        <button type="button" class="btn-close" data-dismiss="modal"></button>
      </div>

      <div class="modal-body text-center" id="printArea">

        <img id="qrImage" style="width:260px;"><br>

        <div style="margin-top:10px; font-size:13px;">
          <b id="qrText"></b>
        </div>

      </div>

      <div class="modal-footer">
        <button class="btn btn-primary" onclick="printQR()">Print</button>
      </div>

    </div>
  </div>
</div>

<!-- end qr modal -->

<!-- item request modal -->
<div class="modal fade" id="itemRequestModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Request New Item</h5>
        <button type="button" class="btn-close" data-dismiss="modal"></button>
      </div>

      <form id="itemRequestForm">
        <div class="modal-body">

          <div class="mb-3">
            <label class="form-label">Item Name</label>
            <input type="text" name="item_name" class="form-control" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Item Color</label>
            <input type="text" name="item_color" class="form-control">
          </div>

          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3"></textarea>
          </div>

        </div>

        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Submit Request</button>
        </div>
      </form>

    </div>
  </div>
</div>
<!-- end item request modal -->

<!-- inventory in modal -->
<div class="modal fade" id="inventoryInModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Inventory In</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form id="inventoryInForm">
        <div class="modal-body">

          <div class="form-group" style="position:relative;">
            <label>Item</label>
            <input type="hidden" name="sku" id="inv_in_sku">
            <input type="text" class="form-control" id="inv_in_item_search" placeholder="Type item name, SKU, or color" autocomplete="off" required>
            <div class="item-dropdown inv-item-dropdown"></div>
          </div>

          <div class="row">
            <div class="col-md-6">
              <div class="form-group">
                <label>Current Quantity</label>
                <input type="text" class="form-control" id="inv_in_current_qty" readonly>
              </div>
            </div>

            <div class="col-md-6">
              <div class="form-group">
                <label>Unit</label>
                <input type="text" class="form-control" name="unit" id="inv_in_unit" readonly>
              </div>
            </div>
          </div>

          <div class="row">
            <div class="col-md-6">
              <div class="form-group">
                <label>Additional Quantity</label>
                <input type="number" class="form-control" name="quantity" id="inv_in_quantity" min="0.01" step="0.01" required>
              </div>
            </div>

            <div class="col-md-6">
              <div class="form-group">
                <label>Unit Price</label>
                <input type="number" class="form-control" name="unit_price" id="inv_in_unit_price" min="0" step="0.01" required>
              </div>
            </div>
          </div>

          <div class="form-group">
            <label>Receipt Number</label>
            <input type="text" class="form-control" name="receipt_no" id="inv_in_receipt_no" required>
          </div>

          <div class="row">
            <div class="col-md-6">
              <div class="form-group">
                <label>Date</label>
                <input type="date" class="form-control" name="receipt_date" id="inv_in_receipt_date" value="<?php echo date('Y-m-d'); ?>" required>
              </div>
            </div>

            <div class="col-md-6">
              <div class="form-group">
                <label>PO Code</label>
                <input type="text" class="form-control" name="po_code" id="inv_in_po_code">
              </div>
            </div>
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-success" id="saveInventoryInBtn">
            <i class="fas fa-save"></i> Save
          </button>
        </div>
      </form>

    </div>
  </div>
</div>
<!-- end inventory in modal -->

<!-- inventory out modal -->
<div class="modal fade" id="inventoryOutModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Inventory Out</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form id="inventoryOutForm">
        <div class="modal-body">

          <div class="form-group" style="position:relative;">
            <label>Item</label>
            <input type="hidden" name="sku" id="inv_out_sku">
            <input type="text" class="form-control" id="inv_out_item_search" placeholder="Type item name, SKU, or color" autocomplete="off" required>
            <div class="item-dropdown inv-out-item-dropdown"></div>
          </div>

          <div class="row">
            <div class="col-md-6">
              <div class="form-group">
                <label>Current Quantity</label>
                <input type="text" class="form-control" id="inv_out_current_qty" readonly>
              </div>
            </div>

            <div class="col-md-6">
              <div class="form-group">
                <label>Unit</label>
                <input type="text" class="form-control" name="unit" id="inv_out_unit" readonly>
              </div>
            </div>
          </div>

          <div class="form-group">
            <label>Quantity Out</label>
            <input type="number" class="form-control" name="quantity" id="inv_out_quantity" min="0.01" step="0.01" required>
          </div>

          <div class="row">
            <div class="col-md-6">
              <div class="form-group">
                <label>Reference Number</label>
                <input type="text" class="form-control" name="reference_no" id="inv_out_reference_no" required>
              </div>
            </div>

            <div class="col-md-6">
              <div class="form-group">
                <label>Date</label>
                <input type="date" class="form-control" name="transaction_date" id="inv_out_transaction_date" value="<?php echo date('Y-m-d'); ?>" required>
              </div>
            </div>
          </div>

          <div class="form-group">
            <label>Remarks</label>
            <textarea class="form-control" name="remarks" id="inv_out_remarks" rows="2"></textarea>
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-warning" id="saveInventoryOutBtn">
            <i class="fas fa-save"></i> Save
          </button>
        </div>
      </form>

    </div>
  </div>
</div>
<!-- end inventory out modal -->

<!-- purchase request modal -->
<div class="modal fade" id="purchaseRequestModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Low Stock Purchase Request</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form id="purchaseRequestForm">
        <input type="hidden" name="pr_items_payload" id="pr_items_payload">
        <div class="modal-body">
          <div class="alert alert-info py-2">
            This creates a PR reference for Purchasing. The actual supplier PO will be created later by Purchasing.
          </div>

          <div class="table-responsive">
            <table class="table table-bordered table-striped m-0" id="purchaseRequestItemsTable">
              <thead>
                <tr>
                  <th>SKU</th>
                  <th>Material</th>
                  <th>Available</th>
                  <th>Reorder Level</th>
                  <th>Unit</th>
                  <th style="width: 160px;">Request Qty</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($purchaseRequestLowStockItems)): ?>
                  <tr>
                    <td colspan="6" class="text-center text-muted">No low stock materials found.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($purchaseRequestLowStockItems as $index => $item): ?>
                    <tr>
                      <td>
                        <?= htmlspecialchars($item['sku']); ?>
                        <input type="hidden" name="items[<?= (int)$index; ?>][sku]" value="<?= htmlspecialchars($item['sku']); ?>">
                      </td>
                      <td>
                        <strong><?= htmlspecialchars($item['material_name']); ?></strong>
                        <br><small class="text-muted"><?= htmlspecialchars($item['description'] ?: '-'); ?></small>
                      </td>
                      <td><?= htmlspecialchars((float)$item['available_qty'] . ' ' . ($item['unit'] ?? '')); ?></td>
                      <td><?= htmlspecialchars((float)$item['reorder_level']); ?></td>
                      <td><?= htmlspecialchars($item['unit'] ?? ''); ?></td>
                      <td>
                        <input type="number"
                               class="form-control form-control-sm pr-request-qty"
                               data-index="<?= (int)$index; ?>"
                               name="items[<?= (int)$index; ?>][request_qty]"
                               min="0"
                               step="0.01"
                               value="<?= htmlspecialchars((float)$item['suggested_qty']); ?>"
                               required>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-success" id="savePurchaseRequestBtn" <?= empty($purchaseRequestLowStockItems) ? 'disabled' : ''; ?>>
            <i class="fas fa-save"></i> Save PR Request
          </button>
        </div>
      </form>

    </div>
  </div>
</div>
<!-- end purchase request modal -->

<!-- purchase request details modal -->
<div class="modal fade" id="purchaseRequestDetailsModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Purchase Request Details: <span id="prDetailsRefNo"></span></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped m-0">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Material</th>
                <th>Request Qty</th>
                <th>Available</th>
                <th>Unit</th>
              </tr>
            </thead>
            <tbody id="purchaseRequestDetailsBody">
              <tr>
                <td colspan="5" class="text-center text-muted">Loading request details...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>
<!-- end purchase request details modal -->

<!-- PO modal -->
<div class="modal fade" id="poModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl" role="document">
    <div class="modal-content">

      <!-- Header -->
      <div class="modal-header">
        <h5 class="modal-title">Create Purchase Order</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <!-- Body -->
      <div class="modal-body">
	
        <!-- Supplier -->
        <div class="form-group">
          <label for="supplierSelect">Supplier</label>
          <select id="supplierSelect" class="form-control">
            <option value="">Select Supplier</option>
            <?php
            $poSupplierStmt = $pdo->query("
                SELECT supplier_id, supplier_name
                FROM tbl_suppliers
                ORDER BY supplier_name ASC
            ");
            foreach ($poSupplierStmt->fetchAll(PDO::FETCH_ASSOC) as $poSupplier) {
            ?>
              <option value="<?= (int)$poSupplier['supplier_id']; ?>">
                <?= htmlspecialchars($poSupplier['supplier_name']); ?>
              </option>
            <?php } ?>
          </select>
        </div>

        <!-- Items Table -->
		<h6 class="text-muted">PR #: <span id="poPrRef"></span></h6>
        <div class="table-responsive">
          <table class="table table-bordered table-sm">
            <thead class="thead-light">
              <tr>
                <th>Item</th>
                <th width="120">Requested</th>
                <th width="150">PO Qty</th>
              </tr>
            </thead>
            <tbody id="poItemsBody">
              <tr>
                <td colspan="3" class="text-center text-muted">
                  No items loaded
                </td>
              </tr>
            </tbody>
          </table>
        </div>

      </div>

      <!-- Footer -->
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="savePoBtn">
          Save PO
        </button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">
          Close
        </button>
      </div>

    </div>
  </div>
</div>
<!-- end POmodal -->

<div class="modal fade" id="fulfillPoModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Fulfill Purchase Order</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form id="fulfillPoForm">
        <div class="modal-body">
          <input type="hidden" name="po_id" id="fulfill_po_id">
          <div class="form-group">
            <label>PO No.</label>
            <input type="text" class="form-control" id="fulfill_po_ref" readonly>
          </div>
          <div class="form-group">
            <label>Receipt No.</label>
            <input type="text" class="form-control" name="receipt_no" id="receipt_no" required>
          </div>
          <div class="form-group">
            <label>Date Received</label>
            <input type="date" class="form-control" name="date_received" id="date_received" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-success" id="saveFulfillPoBtn">
            Save Fulfillment
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

  <!-- Main Footer -->
  <footer class="main-footer">
    <strong>Copyright &copy; 2026 | <a href="#">Green Ads and Promats, Inc. </a> | </strong>
    All rights reserved.
    <div class="float-right d-none d-sm-inline-block">
      <b>Version</b> 1.0
    </div>
  </footer>
</div>
<!-- ./wrapper -->

<!-- REQUIRED SCRIPTS -->
<!-- jQuery -->
<script src="plugins/jquery/jquery.min.js"></script>
<!-- Bootstrap -->
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- overlayScrollbars -->
<script src="plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
<!--<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>-->
<!-- AdminLTE App -->
<script src="dist/js/adminlte.js"></script>

<!-- OPTIONAL SCRIPTS -->
<script src="dist/js/demo.js"></script>

<!-- PAGE PLUGINS -->
<!-- jQuery Mapael -->
<script src="plugins/jquery-mousewheel/jquery.mousewheel.js"></script>
<script src="plugins/raphael/raphael.min.js"></script>
<script src="plugins/jquery-mapael/jquery.mapael.min.js"></script>
<script src="plugins/jquery-mapael/maps/usa_states.min.js"></script>
<!-- ChartJS -->
<script src="plugins/chart.js/Chart.min.js"></script>
<script src="plugins/toastr/toastr.min.js"></script>
<!--datatables -->

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<!-- PAGE SCRIPTS -->
<script src="dist/js/pages/dashboard2.js"></script>

<script>
let ortable, itemspo, stocktable, inventorytable, invLowStockTable, invInHistoryTable, invOutHistoryTable, projectMrReportTable, supplierTable, purchaseRequestTable, inventoryPurchaseRequestTable, purchaseOrderTable, userTable, auditLogTable;
let encodePrItemsCache = {};
let pendingEncodeAfterProductSave = null;
let purchaseRequestItemsCache = <?= json_encode(array_map(function($item) {
    return [
        'sku' => $item['sku'],
        'material_name' => $item['material_name'],
        'description' => $item['description'],
        'available_qty' => (float)$item['available_qty'],
        'reorder_level' => (float)$item['reorder_level'],
        'unit' => $item['unit'],
        'suggested_qty' => (float)$item['suggested_qty']
    ];
}, $purchaseRequestLowStockItems)); ?>;
$(document).ready(function() {
   // $('.table').DataTable();

	if ($('#salesChart').length && typeof Chart !== 'undefined') {
		Object.keys(Chart.instances || {}).forEach(function(key) {
			let chart = Chart.instances[key];
			if (chart && chart.chart && chart.chart.canvas && chart.chart.canvas.id === 'salesChart') {
				chart.destroy();
			}
		});

		let monthlyRecapCanvas = $('#salesChart').get(0).getContext('2d');
		new Chart(monthlyRecapCanvas, {
			type: 'bar',
			data: {
				labels: <?= json_encode($recapProjectChartLabels); ?>,
				datasets: [
					{
						label: 'Project Cost',
						backgroundColor: 'rgba(40, 167, 69, 0.75)',
						borderColor: 'rgba(40, 167, 69, 1)',
						data: <?= json_encode($recapProjectChartCosts); ?>
					},
					{
						label: 'Approved MR Cost',
						backgroundColor: 'rgba(255, 193, 7, 0.75)',
						borderColor: 'rgba(255, 193, 7, 1)',
						data: <?= json_encode($recapProjectChartMrCosts); ?>
					}
				]
			},
			options: {
				maintainAspectRatio: false,
				responsive: true,
				legend: {
					display: true
				},
				tooltips: {
					callbacks: {
						label: function(tooltipItem, data) {
							let label = data.datasets[tooltipItem.datasetIndex].label || '';
							let value = Number(tooltipItem.yLabel || 0).toLocaleString('en-PH', {
								minimumFractionDigits: 2,
								maximumFractionDigits: 2
							});
							return label + ': PHP ' + value;
						}
					}
				},
				scales: {
					yAxes: [{
						ticks: {
							beginAtZero: true,
							callback: function(value) {
								return Number(value).toLocaleString('en-PH');
							}
						}
					}]
				}
			}
		});
	}
	
 stocktable = $('#stock-list').DataTable({
        ajax: 'ajax/fetch_items.php',
        responsive: true,
        autoWidth: false
    });
	
	itemspo = $('#items-po').DataTable({
        ajax: 'ajax/fetch_not_on_list.php',
        responsive: true,
        autoWidth: false
    });
	
	
	
	inventorytable = $('#inventory-list').DataTable({
        ajax: 'ajax/fetch_items_inventory.php',
        responsive: true,
        autoWidth: false
    });

	invLowStockTable = $('#inv-low-stock').DataTable({
        ajax: 'ajax/fetch_inventory_stock_status.php?type=low',
        responsive: true,
        autoWidth: false
    });

	invInHistoryTable = $('#inv-in-history').DataTable({
        ajax: 'ajax/fetch_inventory_in_history.php',
        responsive: true,
        autoWidth: false,
        order: [[0, 'desc']]
    });

	invOutHistoryTable = $('#inv-out-history').DataTable({
        ajax: 'ajax/fetch_inventory_out_history.php',
        responsive: true,
        autoWidth: false,
        order: [[0, 'desc']]
    });

    inventoryPurchaseRequestTable = $('#inventory-pr-list').DataTable({
        ajax: 'ajax/fetch_my_purchase_requests.php',
        responsive: true,
        autoWidth: false,
        order: [[0, 'desc']],
        columns: [
            { data: 'pr_ref_no' },
            { data: 'request_date' },
            { data: 'item_count' },
            { data: 'total_qty' },
            { data: 'status_badge' },
            { data: 'action' }
        ]
    });

	projectMrReportTable = $('#project-mr-report').DataTable({
        ajax: {
            url: 'ajax/fetch_project_mr_report.php',
            type: 'GET',
            data: function(d) {
                d.proj_code = $('#report_proj_code').val();
            },
            dataSrc: function(json) {
                let summary = json.summary || {};
                $('#report_mr_count').text(summary.count || 0);
                $('#report_grand_total').text(summary.grand_total || '0.00');
                return json.data || [];
            }
        },
        responsive: true,
        autoWidth: false,
        ordering: true,
        columns: [
            { data: 'or_no' },
            { data: 'or_date' },
            { data: 'proj_code' },
            { data: 'dept_code' },
            { data: 'prepared_by' },
            { data: 'remarks' },
            { data: 'grand_total' }
        ]
    });

	$('#report_proj_code').on('change', function() {
        projectMrReportTable.ajax.reload();
    });

	supplierTable = $('#supplier-list').DataTable({
        ajax: 'ajax/fetch_suppliers.php',
        responsive: true,
        autoWidth: false,
        columns: [
            { data: 'supplier_name_display' },
            { data: 'supplier_owner_display' },
            { data: 'contact_no_display' },
            { data: 'email_display' },
            { data: 'action' }
        ]
    });

    if ($('#user-list').length) {
        userTable = $('#user-list').DataTable({
            ajax: 'ajax/fetch_users.php',
            responsive: true,
            autoWidth: false,
            columns: [
                { data: 'user_code_display' },
                { data: 'user_name_display' },
                { data: 'user_type_display' },
                { data: 'user_dept_display' },
                { data: 'action' }
            ]
        });
    }

    if ($('#audit-log-list').length) {
        auditLogTable = $('#audit-log-list').DataTable({
            ajax: 'ajax/fetch_audit_logs.php',
            responsive: true,
            autoWidth: false,
            order: [[0, 'desc']],
            columns: [
                { data: 'created_at' },
                { data: 'user' },
                { data: 'user_type' },
                { data: 'action' },
                { data: 'module' },
                { data: 'reference_no' },
                { data: 'description' }
            ]
        });
    }

	purchaseRequestTable = $('#purchase-request-list').DataTable({
        ajax: 'ajax/fetch_purchase_requests.php',
        responsive: true,
        autoWidth: false,
        order: [[0, 'desc']],
        columns: [
            { data: 'pr_ref_no' },
            { data: 'request_date' },
            { data: 'requested_by' },
            { data: 'item_count' },
            { data: 'total_qty' },
            { data: 'status_badge' },
            { data: 'action' }
        ]
    });

	purchaseOrderTable = $('#purchase-order-list').DataTable({
        ajax: 'ajax/fetch_purchase_orders.php',
        responsive: true,
        autoWidth: false,
        order: [[0, 'desc']],
        columns: [
            { data: 'po_ref_no' },
            { data: 'pr_ref_no' },
            { data: 'po_date' },
            { data: 'supplier_name' },
            { data: 'item_count' },
            { data: 'total_po_qty' },
            { data: 'fulfillment_status' },
            { data: 'receipt_no' },
            { data: 'date_received' },
            { data: 'created_by' },
            { data: 'action' }
        ]
    });

	let inventoryInItems = [];

	function escapeHtml(value) {
		return String(value ?? '').replace(/[&<>"']/g, function (char) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			}[char];
		});
	}

	function loadInventoryInItems() {
		$.ajax({
			url: 'ajax/fetch_inventory_items.php',
			type: 'GET',
			dataType: 'json',
			success: function(res) {
				inventoryInItems = res.data || [];
			},
			error: function(xhr) {
				console.error(xhr.responseText);
				toastr.error('Failed to load inventory items.');
			}
		});
	}

	loadInventoryInItems();

	$(document).on('click', '.inv-in', function(e) {
		e.preventDefault();
		$('#inventoryInForm')[0].reset();
		$('#inv_in_sku').val('');
		$('#inv_in_current_qty').val('');
		$('.inv-item-dropdown').hide().empty();
		$('#inventoryInModal').modal('show');
		loadInventoryInItems();
	});

	$(document).on('click', '.inv-out', function(e) {
		e.preventDefault();
		$('#inventoryOutForm')[0].reset();
		$('#inv_out_sku').val('');
		$('#inv_out_current_qty').val('');
		$('.inv-out-item-dropdown').hide().empty();
		$('#inventoryOutModal').modal('show');
		loadInventoryInItems();
	});

	$(document).on('input', '#inv_in_item_search', function() {
		let keyword = $(this).val().toLowerCase().trim();
		let dropdown = $('.inv-item-dropdown');

		$('#inv_in_sku').val('');
		$('#inv_in_current_qty').val('');
		$('#inv_in_unit').val('');
		$('#inv_in_unit_price').val('');

		if (keyword.length === 0) {
			dropdown.hide().empty();
			return;
		}

		let filtered = inventoryInItems.filter(function(item) {
			return String(item.material_name || '').toLowerCase().includes(keyword) ||
				String(item.sku || '').toLowerCase().includes(keyword) ||
				String(item.color || '').toLowerCase().includes(keyword);
		});

		let html = '';
		if (filtered.length > 0) {
			filtered.forEach(function(item) {
				html += `
					<div class="item-option inv-item-option"
						 data-sku="${escapeHtml(item.sku)}">
						<strong>${escapeHtml(item.material_name)}</strong> - ${escapeHtml(item.color)}<br>
						<small>${escapeHtml(item.sku)} | Available: ${escapeHtml(item.available_qty)} ${escapeHtml(item.unit)} | On-hand: ${escapeHtml(item.quantity)} | ${escapeHtml(item.unit_price)}</small>
					</div>
				`;
			});
		} else {
			html = '<div class="item-option">No matching item found</div>';
		}

		dropdown.html(html).show();
	});

	$(document).on('click', '.inv-item-option', function() {
		let sku = $(this).data('sku');
		let item = inventoryInItems.find(function(row) {
			return row.sku === sku;
		});

		if (!item) {
			return;
		}

		$('#inv_in_sku').val(item.sku);
		$('#inv_in_item_search').val(item.material_name + ' - ' + item.color + ' (' + item.sku + ')');
		$('#inv_in_current_qty').val('On-hand: ' + item.quantity + ' | Reserved: ' + item.reserved_qty + ' | Available: ' + item.available_qty);
		$('#inv_in_unit').val(item.unit);
		$('#inv_in_unit_price').val(item.unit_price);
		$('.inv-item-dropdown').hide().empty();
	});

	$(document).on('input', '#inv_out_item_search', function() {
		let keyword = $(this).val().toLowerCase().trim();
		let dropdown = $('.inv-out-item-dropdown');

		$('#inv_out_sku').val('');
		$('#inv_out_current_qty').val('');
		$('#inv_out_unit').val('');

		if (keyword.length === 0) {
			dropdown.hide().empty();
			return;
		}

		let filtered = inventoryInItems.filter(function(item) {
			return String(item.material_name || '').toLowerCase().includes(keyword) ||
				String(item.sku || '').toLowerCase().includes(keyword) ||
				String(item.color || '').toLowerCase().includes(keyword);
		});

		let html = '';
		if (filtered.length > 0) {
			filtered.forEach(function(item) {
				html += `
					<div class="item-option inv-out-item-option"
						 data-sku="${escapeHtml(item.sku)}">
						<strong>${escapeHtml(item.material_name)}</strong> - ${escapeHtml(item.color)}<br>
						<small>${escapeHtml(item.sku)} | Available: ${escapeHtml(item.available_qty)} ${escapeHtml(item.unit)} | On-hand: ${escapeHtml(item.quantity)}</small>
					</div>
				`;
			});
		} else {
			html = '<div class="item-option">No matching item found</div>';
		}

		dropdown.html(html).show();
	});

	$(document).on('click', '.inv-out-item-option', function() {
		let sku = $(this).data('sku');
		let item = inventoryInItems.find(function(row) {
			return row.sku === sku;
		});

		if (!item) {
			return;
		}

		$('#inv_out_sku').val(item.sku);
		$('#inv_out_item_search').val(item.material_name + ' - ' + item.color + ' (' + item.sku + ')');
		$('#inv_out_current_qty').val(item.available_qty);
		$('#inv_out_unit').val(item.unit);
		$('.inv-out-item-dropdown').hide().empty();
	});

	$('#inventoryInForm').on('submit', function(e) {
		e.preventDefault();

		if ($('#inv_in_sku').val() === '') {
			toastr.error('Please select an existing item.');
			return;
		}

		let btn = $('#saveInventoryInBtn');

		$.ajax({
			url: 'ajax/save_inventory_in.php',
			type: 'POST',
			data: $(this).serialize(),
			dataType: 'json',
			beforeSend: function() {
				btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
			},
			success: function(res) {
				if (res.status === 'success') {
					toastr.success(res.message);
					$('#inventoryInModal').modal('hide');
					$('#inventoryInForm')[0].reset();
					loadInventoryInItems();
					stocktable.ajax.reload(null, false);
					inventorytable.ajax.reload(null, false);
					invLowStockTable.ajax.reload(null, false);
					invInHistoryTable.ajax.reload(null, false);
				} else {
					toastr.error(res.message);
				}
			},
			error: function(xhr) {
				console.error(xhr.responseText);
				toastr.error('Inventory update failed.');
			},
			complete: function() {
				btn.prop('disabled', false).html('<i class="fas fa-save"></i> Save');
			}
		});
	});

	$('#inventoryOutForm').on('submit', function(e) {
		e.preventDefault();

		if ($('#inv_out_sku').val() === '') {
			toastr.error('Please select an existing item.');
			return;
		}

		let currentQty = parseFloat($('#inv_out_current_qty').val()) || 0;
		let qtyOut = parseFloat($('#inv_out_quantity').val()) || 0;

		if (qtyOut > currentQty) {
			toastr.error('Quantity out cannot exceed current stock.');
			return;
		}

		let btn = $('#saveInventoryOutBtn');

		$.ajax({
			url: 'ajax/save_inventory_out.php',
			type: 'POST',
			data: $(this).serialize(),
			dataType: 'json',
			beforeSend: function() {
				btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
			},
			success: function(res) {
				if (res.status === 'success') {
					toastr.success(res.message);
					$('#inventoryOutModal').modal('hide');
					$('#inventoryOutForm')[0].reset();
					loadInventoryInItems();
					stocktable.ajax.reload(null, false);
					inventorytable.ajax.reload(null, false);
					invLowStockTable.ajax.reload(null, false);
					invOutHistoryTable.ajax.reload(null, false);
				} else {
					toastr.error(res.message);
				}
			},
			error: function(xhr) {
				console.error(xhr.responseText);
				toastr.error('Inventory update failed.');
			},
			complete: function() {
				btn.prop('disabled', false).html('<i class="fas fa-save"></i> Save');
			}
		});
	});

	$(document).on('click', function(e) {
		if (!$(e.target).closest('#inv_in_item_search, .inv-item-dropdown').length) {
			$('.inv-item-dropdown').hide();
		}
		if (!$(e.target).closest('#inv_out_item_search, .inv-out-item-dropdown').length) {
			$('.inv-out-item-dropdown').hide();
		}
	});
	//project list
	let projecttable = $('#project-list').DataTable({

    ajax: {
        url: 'ajax/fetch_projects.php',
        type: 'GET',
        dataSrc: ''
    },

    columns: [

       
        { data: 'proj_code' },
        { data: 'proj_name' },
        { data: 'proj_mgr' },
        { data: 'proj_owner' },
        {
            data: 'proj_cost',
            render: function (data) {
                return '₱ ' + parseFloat(data).toLocaleString('en-PH', {
                    minimumFractionDigits: 2
                });
            }
        },

        // =========================
        // DESCRIPTION (TRIMMED)
        // =========================
        {
            data: 'proj_desc',
            render: function (data) {
                if (!data) return '';
                return data.length > 60
                    ? data.substring(0, 60) + '...'
                    : data;
            }
        },

        // =========================
        // START DATE
        // =========================
        { data: 'proj_sd' },

        // =========================
        // END DATE
        // =========================
        { data: 'proj_ed' },

        // =========================
        // APPROVAL STATUS
        // =========================
        {
            data: 'proj_approval_status',
            render: function (data) {
                if (parseInt(data, 10) === 1) {
                    return '<span class="badge bg-success">Approved</span>';
                }
                return '<span class="badge bg-warning text-dark">Pending Admin Approval</span>';
            }
        },

        // =========================
        // STATUS (0 = Ongoing, 1 = Completed)
        // =========================
        {
            data: 'proj_status',
            render: function (data) {

                if (data == 0) {
                    return '<span class="badge bg-warning">Ongoing</span>';
                }

                if (data == 1) {
                    return '<span class="badge bg-success">Completed</span>';
                }

                return '<span class="badge bg-secondary">Unknown</span>';
            }
        },

        // =========================
        // ATTACHMENTS (FILE COUNT)
        // =========================
        {
            data: 'file_count',
            render: function (data) {

                if (data > 0) {
                    return `<span class="badge bg-info">${data} file(s)</span>`;
                }

                return '<span class="text-muted">No files</span>';
            }
        },

        // =========================
        // ACTION BUTTONS
        // =========================
        {
            data: null,
            orderable: false,
            render: function (data) {
                const isAdmin = "<?= $_SESSION['user_type'] ?? '' ?>" === 'Admin';
                const pendingApproval = parseInt(data.proj_approval_status, 10) !== 1;

                let actions = '';
                if (isAdmin && pendingApproval) {
                    actions += `<button class="btn btn-sm btn-warning approve-project-btn mr-1" data-id="${data.proj_code}">Approve</button>`;
                }

                actions += `
                    <button class="btn btn-sm btn-primary view-btn" data-id="${data.proj_code}">
                        View
                    </button>

                    <button class="btn btn-sm btn-success upload-btn" data-id="${data.proj_code}">
                        Upload
                    </button>
					 <button class="btn btn-sm btn-info qr-btn" data-token="${data.public_token}" data-name="${data.proj_name}">
                        QR-Code
                    </button>
                `;

                return actions;
            }
        }
    ],

    // =========================
    // ROW COLOR BY STATUS
    // =========================
    rowCallback: function (row, data) {

        if (data.proj_status == 0) {
            $(row).addClass('table-warning'); // Ongoing
        }

        if (data.proj_status == 1) {
            $(row).addClass('table-success'); // Completed
        }
    }
});

// end project list
	//------------order list
  ortable = $('#or-list').DataTable({
    ajax:'ajax/fetch_or.php',
    responsive: true,
    autoWidth: false,
    ordering: true,
    columns: [
      { data: 'or_no' },
      { data: 'or_date' },
      { data: 'dept_code' },
      { data: 'proj_code' },
      { data: 'prepared_by' },
      { data: 'grand_total' },
      { data: 'status_badge' },
      { data: 'action' }
    ]
  });
  
  //----------------------------
 
	
	//----------------------
	
	 $('section.content').addClass('d-none');
	 
	  const userType = "<?= $_SESSION['user_type'] ?? '' ?>";
    const defaultSectionByUserType = {
        Admin: 'dashboard',
        Manager: 'project',
        Inventory: 'inventory',
        Purchasing: 'purchasing'
    };
    const defaultSection = defaultSectionByUserType[userType] || 'project';

    $('#' + defaultSection).removeClass('d-none');
    $('#side-menu .nav-link[data-target="' + defaultSection + '"]').addClass('active');


    $('#side-menu .nav-link').on('click', function(e) {
        e.preventDefault();

        let target = $(this).data('target');

        // Hide all sections
        $('section').addClass('d-none');

        // Remove active from all links
        $('#side-menu .nav-link').removeClass('active');

        // Add active to clicked
        $(this).addClass('active');

        // Show selected section
        $('#' + target).removeClass('d-none');
    });

});

</script>


<script>
$(document).ready(function () {

 function buildSkuPreview() {
    if ($('#id').val()) {
        return;
    }

    let words = $('#material_name').val().trim().split(/\s+/).filter(Boolean);
    let materialCode = (words[0] || '').replace(/[^a-z0-9]/gi, '').substring(0, 3).toUpperCase();
    if (words[1]) {
        materialCode += words[1].replace(/[^a-z0-9]/gi, '').substring(0, 1).toUpperCase();
    }

    const colorMap = {
        black: { single: 'BLK', combo: 'BL' },
        white: { single: 'WHT', combo: 'WH' },
        red: { single: 'RED', combo: 'RD' },
        green: { single: 'GRN', combo: 'GR' },
        blue: { single: 'BLU', combo: 'BL' },
        yellow: { single: 'YEL', combo: 'YL' },
        orange: { single: 'ORG', combo: 'OR' },
        purple: { single: 'PUR', combo: 'PR' },
        pink: { single: 'PNK', combo: 'PK' },
        brown: { single: 'BRN', combo: 'BR' },
        gray: { single: 'GRY', combo: 'GY' },
        grey: { single: 'GRY', combo: 'GY' },
        navy: { single: 'NVY', combo: 'NV' },
        beige: { single: 'BGE', combo: 'BG' },
        cream: { single: 'CRM', combo: 'CR' },
        gold: { single: 'GLD', combo: 'GD' },
        silver: { single: 'SLV', combo: 'SL' },
        maroon: { single: 'MRN', combo: 'MR' },
        denim: { single: 'DNM', combo: 'DN' }
    };
    let colorWords = $('#color').val().trim().split(/[\s\/,-]+/).filter(Boolean);
    let colorCode = '';
    if (colorWords.length <= 1) {
        let color = (colorWords[0] || '').toLowerCase();
        colorCode = colorMap[color] ? colorMap[color].single : color.replace(/[^a-z0-9]/gi, '').substring(0, 3).toUpperCase();
    } else {
        colorWords.slice(0, 2).forEach(function(color) {
            color = color.toLowerCase();
            colorCode += colorMap[color] ? colorMap[color].combo : color.replace(/[^a-z0-9]/gi, '').substring(0, 2).toUpperCase();
        });
    }
    let gsm = $('#gsm').val().replace(/[^a-z0-9]/gi, '');
    let parts = [materialCode, colorCode].filter(Boolean);
    let sku = parts.join('-');

    if (gsm && gsm !== '0') {
        sku += '-' + gsm;
    }

    $('#sku').val(sku);
 }

$('#material_name, #color, #gsm').on('input blur', buildSkuPreview);

 function calculateFabricYards(showMessage) {
    let from = $('#fabricConvertFrom').val();
    let qty = parseFloat($('#fabricConvertQty').val()) || 0;
    let yards = 0;

    if (qty <= 0) {
        $('#fabricConvertResult').val('');
        if (showMessage) {
            toastr.warning('Enter quantity to convert.');
        }
        return 0;
    }

    if (from === 'kg') {
        let gsmValue = parseFloat($('#fabricConvertGsm').val()) || 0;
        let widthInches = parseFloat($('#fabricConvertWidth').val()) || 0;

        if (gsmValue <= 0 || widthInches <= 0) {
            $('#fabricConvertResult').val('');
            if (showMessage) {
                toastr.warning('Enter GSM and width inches.');
            }
            return 0;
        }

        let widthMeter = widthInches * 0.0254;
        yards = (qty * 1000) / (gsmValue * widthMeter * 0.9144);
    } else {
        let yardsPerRoll = parseFloat($('#fabricConvertYardsPerRoll').val()) || 0;

        if (yardsPerRoll <= 0) {
            $('#fabricConvertResult').val('');
            if (showMessage) {
                toastr.warning('Enter yards per roll.');
            }
            return 0;
        }

        yards = qty * yardsPerRoll;
    }

    yards = Math.round(yards * 100) / 100;
    $('#fabricConvertResult').val(yards.toFixed(2));
    let totalCost = parseFloat($('#fabricConvertTotalCost').val()) || 0;
    if (totalCost > 0 && yards > 0) {
        $('#fabricConvertUnitPrice').val((totalCost / yards).toFixed(2));
    } else {
        $('#fabricConvertUnitPrice').val('');
    }
    return yards;
 }

 $('#fabricConvertFrom').on('change', function() {
    let from = $(this).val();
    $('#kgConverterFields').toggleClass('d-none', from !== 'kg');
    $('#rollConverterFields').toggleClass('d-none', from !== 'roll');
    $('#fabricConvertResult').val('');
 });

 $('#fabricConvertQty, #fabricConvertGsm, #fabricConvertWidth, #fabricConvertYardsPerRoll, #fabricConvertTotalCost').on('input', function() {
    calculateFabricYards(false);
 });

 $('#calculateFabricYardsBtn').on('click', function() {
    calculateFabricYards(true);
 });

 $('#resetFabricConverterBtn').on('click', function() {
    $('#fabricConvertFrom').val('kg').trigger('change');
    $('#fabricConvertQty').val('');
    $('#fabricConvertGsm').val('');
    $('#fabricConvertWidth').val('');
    $('#fabricConvertYardsPerRoll').val('');
    $('#fabricConvertResult').val('');
    $('#fabricConvertTotalCost').val('');
    $('#fabricConvertUnitPrice').val('');
 });

 $('#useFabricYardsBtn').on('click', function() {
    let yards = calculateFabricYards(true);

    if (yards <= 0) {
        return;
    }

    $('#quantity').val(yards.toFixed(2));
    $('#unit').val('yard');

    if ($('#fabricConvertUnitPrice').val()) {
        $('#unit_price').val($('#fabricConvertUnitPrice').val());
    }

    if ($('#fabricConvertFrom').val() === 'kg' && $('#fabricConvertGsm').val()) {
        $('#gsm').val($('#fabricConvertGsm').val()).trigger('input');
    }

    if ($('#fabricConvertFrom').val() === 'kg' && $('#fabricConvertWidth').val()) {
        let widthText = 'Width: ' + $('#fabricConvertWidth').val() + ' inches';
        let description = $('#description').val().trim();

        if (!description.toLowerCase().includes('width:')) {
            $('#description').val(description ? description + '\n' + widthText : widthText);
        }
    }

    toastr.success('Converted yards placed in product form.');
 });

 $("#productForm").on("submit", function(e) {
			e.preventDefault();
			
			$.ajax({
			  type: "POST",
			  url: "ajax/save_product.php",
			  data: new FormData(this), // Data sent to server, a set of key/value pairs (i.e. form fields and values)
			  contentType: false, // The content type used when sending data to the server.
			  cache: false, // To unable request pages to be cached
			  processData: false, // To send DOMDocument or non processed data file it is set to false
			  success: function(data) {
				
				if (data == 1 || parseInt(data) == 1) {
                    let pendingEncode = pendingEncodeAfterProductSave;
                    let generatedSku = $('#sku').val();
                    pendingEncodeAfterProductSave = null;
                $('#productForm')[0].reset();
					 $('#id').val('');
					$('#productModal').modal('hide');
				  toastr.success("Saved successfully!");
				  
				stocktable.ajax.reload(null, false);
				inventorytable.ajax.reload(null, false);
				invLowStockTable.ajax.reload(null, false);
                if (pendingEncode && generatedSku) {
                    if (encodePrItemsCache[pendingEncode.poItemId]) {
                        encodePrItemsCache[pendingEncode.poItemId].item_exists = true;
                        encodePrItemsCache[pendingEncode.poItemId].inventory_sku = generatedSku;
                    }
                    setTimeout(function() {
                        pendingEncode.row.find('.encode-pr-item').trigger('click');
                    }, 300);
                }
				  
					 
					
				} else {
				 toastr.error("Something went wrong!");
				  
				 //  $('#mg').hide(3000);
				 //  window.location.reload();
				}
			  },
			  error: function(data) {
				toastr.warning("Contact your Administrator!");
			  }
			});
		  });

    $(document).on('click', '.editBtn', function(e) {
        e.preventDefault();

        $.ajax({
            url: 'ajax/get_item.php',
            type: 'GET',
            data: { id: $(this).data('id') },
            dataType: 'json',
            success: function(res) {
                if (res.status !== 'success') {
                    toastr.error(res.message || 'Unable to load item.');
                    return;
                }

                let item = res.data;

                $('#productModalLabel').text('Edit Material');
                $('#id').val(item.id || '');
                $('#sku').val(item.sku || '');
                $('#material_name').val(item.material_name || '');
                $('#material_type').val(item.material_type || '');
                $('#category').val(item.category || '');
                $('#color').val(item.color || '');
                $('#gsm').val(item.gsm || '');
                $('#description').val(item.description || '');
                $('#quantity').val(item.quantity || 0);
                $('#unit').val(item.unit || '');
                $('#unit_price').val(item.unit_price || '');
                $('#reorder_level').val(item.reorder_level || 10);
                $('#location').val(item.location || '');

                $('#productModal').modal('show');
            },
            error: function(xhr) {
                console.error(xhr.responseText);
                toastr.error('Failed to load item.');
            }
        });
    });

    $('#productModal').on('hidden.bs.modal', function() {
        $('#productModalLabel').text('Add Material');
        $('#productForm')[0].reset();
        $('#id').val('');
        pendingEncodeAfterProductSave = null;
    });
   
});
</script>


<script>
$(document).ready(function () {
    let itemIndex = 1;
    let itemsList = [];

    loadInventoryItems();

    function loadInventoryItems() {
        $.ajax({
            url: "ajax/fetch_items_po.php",
            type: "GET",
            dataType: "json",
            success: function (response) {
                itemsList = (response.data || []).map(row => ({
                    sku: row[0],
                    item_name: row[1],
                    color: row[2],
                    description: row[3],
                    stock: row[4],
                    unit: row[5],
                    unit_price: row[6]
                }));

                console.log("Inventory loaded:", itemsList);
            },
            error: function (xhr) {
                console.error("Failed to load inventory:", xhr.responseText);
                alert("Failed to load inventory items.");
            }
        });
    }

    function getUnitFromStock(stockText) {
        if (!stockText) return "";

        let availableMatch = stockText.match(/Available:\s*[\d.]+\s*([^|]+)/i);
        if (availableMatch) {
            return availableMatch[1].trim().toLowerCase();
        }

        let parts = stockText.trim().split(" ");
        if (parts.length > 1) {
            return parts.slice(1).join(" ").toLowerCase();
        }
        return "";
    }

    function normalizeUnit(unit) {
        unit = unit.toLowerCase().trim();

        if (unit.includes("yard")) return "yard";
        if (unit.includes("kg") || unit.includes("kilo")) return "kg";
        if (unit.includes("roll")) return "roll";
        if (unit.includes("bundle") || unit.includes("bdl")) return "bdl";
        if (unit.includes("piece") || unit.includes("pcs")) return "pcs";

        return "";
    }

    function computeRowAmount(row) {
        let qty = parseFloat(row.find(".qty").val()) || 0;
        let unitPrice = parseFloat(row.find(".unit_price").val()) || 0;
        let amount = qty * unitPrice;

        row.find(".amount").val(amount.toFixed(2));
        computeGrandTotal();
    }

    function computeGrandTotal() {
        let total = 0;

        $("#orItemsTable tbody tr").each(function () {
            total += parseFloat($(this).find(".amount").val()) || 0;
        });

        $("#grandTotal").val(total.toFixed(2));
    }

    function getRowHtml(index) {
        return `
            <tr>
              <td><input type="text" class="form-control sku" name="items[${index}][sku]" required></td>

              <td style="position:relative;">
                <input type="text" class="form-control item_name" name="items[${index}][item_name]" autocomplete="off" required>
                <div class="item-dropdown"></div>
              </td>

              <td><input type="number" class="form-control qty" name="items[${index}][qty]" min="1" value="1" required></td>

              <td>
                <select class="form-control unit" name="items[${index}][unit]" required>
                  <option value="">-Select-</option>
                  <option value="yard">Yards</option>
                  <option value="kg">Kilos / kg</option>
                  <option value="roll">Rolls</option>
                  <option value="bdl">Bundle</option>
                  <option value="pcs">Pieces / pcs</option>
                </select>
              </td>

              <td><input type="number" class="form-control unit_price" name="items[${index}][unit_price]" min="0" step="0.01" value="0.00" required></td>
              <td><input type="text" class="form-control amount" name="items[${index}][amount]" value="0.00" readonly></td>
              <td class="text-center">
                <button type="button" class="btn btn-danger btn-sm removeRow">Remove</button>
              </td>
            </tr>
        `;
    }

    $("#addItemRow").on("click", function () {
        $("#orItemsTable tbody").append(getRowHtml(itemIndex));
        itemIndex++;
    });

    $(document).on("click", ".removeRow", function () {
        if ($("#orItemsTable tbody tr").length > 1) {
            $(this).closest("tr").remove();
            computeGrandTotal();
        } else {
            alert("At least one item row is required.");
        }
    });

    $(document).on("input", ".qty, .unit_price", function () {
        let row = $(this).closest("tr");
        computeRowAmount(row);
    });

    $(document).on("input", ".item_name", function () {
        let input = $(this);
        let keyword = input.val().toLowerCase().trim();
        let dropdown = input.siblings(".item-dropdown");
        let row = input.closest("tr");
        let skuValue = row.find(".sku").val().toLowerCase().trim();

        if (keyword.length === 0) {
            dropdown.hide().empty();
            return;
        }

        let filtered = itemsList.filter(item =>
            String(item.item_name || '').toLowerCase().includes(keyword) ||
            String(item.sku || '').toLowerCase().includes(keyword) ||
            String(item.color || '').toLowerCase().includes(keyword)
        );

        let html = "";

        if (filtered.length > 0) {
            filtered.forEach(item => {
                html += `
                    <div class="item-option"
                         data-sku="${item.sku}">
                        <strong>${item.item_name}</strong> - ${item.color}<br>
                        <small>${item.sku} | ${item.description} | ${item.stock}</small>
                    </div>
                `;
            });
        } else {
            html = `<div class="item-option">No matching item found</div>`;
        }

        dropdown.html(html).show();
    });

    $(document).on("click", ".item-option", function () {
        let sku = $(this).data("sku");
        if (!sku) return;

        let item = itemsList.find(i => i.sku === sku);
        let row = $(this).closest("tr");

        if (item) {
            row.find(".sku").val(item.sku);
            row.find(".item_name").val(item.item_name);

            let detectedUnit = normalizeUnit(getUnitFromStock(item.stock));
            if (detectedUnit !== "") {
                row.find(".unit").val(detectedUnit);
            } else if (item.unit) {
                row.find(".unit").val(item.unit);
            }

            row.find(".unit_price").val(parseFloat(item.unit_price || 0).toFixed(2));
            computeRowAmount(row);
        }

        row.find(".item-dropdown").hide().empty();
    });

    $(document).on("input", ".sku", function () {
        let input = $(this);
        let sku = input.val().toLowerCase().trim();
        let row = input.closest("tr");

        if (!sku) return;

        let item = itemsList.find(i => i.sku.toLowerCase() === sku);

        if (item) {
            row.find(".item_name").val(item.item_name);

            let detectedUnit = normalizeUnit(getUnitFromStock(item.stock));
            if (detectedUnit !== "") {
                row.find(".unit").val(detectedUnit);
            } else if (item.unit) {
                row.find(".unit").val(item.unit);
            }
            row.find(".unit_price").val(parseFloat(item.unit_price || 0).toFixed(2));
            computeRowAmount(row);
        }
    });

    $(document).on("click", function (e) {
        if (!$(e.target).closest(".item_name, .item-dropdown").length) {
            $(".item-dropdown").hide();
        }
    });

    $("#poForm").on("submit", function (e) {
        e.preventDefault();

        $.ajax({
            url: "save_po.php",
            type: "POST",
            data: $(this).serialize(),
            success: function (response) {
                console.log("Saved:", response);
                alert("PO saved successfully.");
            },
            error: function (xhr) {
                console.error("Save failed:", xhr.responseText);
                alert("Failed to save PO.");
            }
        });
    });


$("#logoutBtn").on("click", function(e) {
  e.preventDefault();

  $.ajax({
    url: "ajax/logout.php",
    type: "POST",
    dataType: "json",
    success: function(res) {
      if (res.status === "success") {
        toastr.success("Logged out successfully");

        setTimeout(function () {
          window.location.href = "index.html";
        }, 1000);
      }
    },
    error: function() {
      toastr.error("Logout failed");
    }
  });
});



//OR submission saving
$("#orForm").on("submit", function(e) {
  e.preventDefault();

  let btn = $("#saveorBtn");

  $.ajax({
    url: "ajax/save_or.php",
    type: "POST",
    data: $(this).serialize(),
    dataType: "json",

    beforeSend: function() {
      btn.prop("disabled", true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
    },

    success: function(res) {
      if (res.status === "success") {
        toastr.success("OR saved successfully. OR No: " + res.or_no);

         $("#orForm")[0].reset();
        $("#orItemsTable tbody").html("");
        $("#grandTotal").val("0.00");
		ortable.ajax.reload(null, false);

      } else {
        toastr.error(res.message);
      }
    },

    error: function(xhr) {
      console.log(xhr.responseText);
      toastr.error("Save failed. Check console.");
    },

    complete: function() {
      btn.prop("disabled", false).html('<i class="fas fa-save"></i> Save MR');
    }
  });
});

$(document).on("click", "#approveOrBtn", function(e) {
  e.preventDefault();

  let orId = $(this).data("id");
  if (!orId) {
    toastr.error("No material request selected.");
    return;
  }

  if (!confirm("Approve this material request and deduct inventory?")) {
    return;
  }

  let btn = $(this);

  $.ajax({
    url: "ajax/approve_or.php",
    type: "POST",
    data: { or_id: orId },
    dataType: "json",
    beforeSend: function() {
      btn.prop("disabled", true).html('<i class="fas fa-spinner fa-spin"></i> Approving...');
    },
    success: function(res) {
      if (res.status === "success") {
        toastr.success(res.message);
        $("#approveOrBtn").hide().removeData("id");
        $("#orForm")[0].reset();
        $("#orItemsTable tbody").html("");
        $("#grandTotal").val("0.00");
        $("#orForm input, #orForm textarea, #orForm select").prop("readonly", false);
        $("#orForm select").prop("disabled", false);
        $("#addItemRow").show();
        $("#saveorBtn").show().html('<i class="fas fa-save"></i> Save MR');
        ortable.ajax.reload(null, false);
        stocktable.ajax.reload(null, false);
        inventorytable.ajax.reload(null, false);
        invLowStockTable.ajax.reload(null, false);
        invOutHistoryTable.ajax.reload(null, false);
      } else {
        toastr.error(res.message);
      }
    },
    error: function(xhr) {
      console.error(xhr.responseText);
      toastr.error("Approval failed.");
    },
    complete: function() {
      btn.prop("disabled", false).html('<i class="fas fa-check"></i> Approve MR');
    }
  });
});

$(document).on("click", ".claim-or", function(e) {
  e.preventDefault();

  let orId = $(this).data("id");

  if (!confirm("Mark this material request as claimed?")) {
    return;
  }

  $.ajax({
    url: "ajax/claim_or.php",
    type: "POST",
    data: { or_id: orId },
    dataType: "json",
    success: function(res) {
      if (res.status === "success") {
        toastr.success(res.message);
        ortable.ajax.reload(null, false);
      } else {
        toastr.error(res.message || "Claim failed.");
      }
    },
    error: function(xhr) {
      console.error(xhr.responseText);
      toastr.error("Claim failed.");
    }
  });
});


//edit or
$(document).on("click", ".edit-or", function(e) {
  e.preventDefault();

  let or_id = $(this).data("id");

  $.ajax({
    url: "ajax/get_or.php",
    type: "POST",
    data: { or_id: or_id },
    dataType: "json",
    success: function(res) {
  if (res.status === "success") {

    let data = res.data;
    let items = res.items; // important

	$("input[name='or_id']").val(data.or_id);
    $("input[name='or_no']").val(data.or_no);
    $("input[name='or_date']").val(data.or_date);
    $("#proj_code").val(data.proj_code);
    $("textarea[name='remarks']").val(data.remarks);

    $("#orItemsTable tbody").empty();

    let grandTotal = 0;

    $.each(items, function(index, item) {

      let row = `
        <tr>
          <td>
            <input type="text" class="form-control sku" 
                   name="items[${index}][sku]" 
                   value="${item.sku}" readonly>
          </td>

          <td style="position:relative;">
            <input type="text" class="form-control item_name" 
                   name="items[${index}][item_name]" 
                   value="${item.item_name}" autocomplete="off" required>
            <div class="item-dropdown"></div>
          </td>

          <td>
            <input type="number" class="form-control qty" 
                   name="items[${index}][qty]" 
                   value="${item.qty}" min="1" required>
          </td>

          <td>
            <select class="form-control unit" name="items[${index}][unit]" required>
              <option value="">-Select-</option>
              <option value="yard" ${item.unit == 'yard' ? 'selected' : ''}>Yards</option>
              <option value="kg" ${item.unit == 'kg' ? 'selected' : ''}>Kilos / kg</option>
              <option value="roll" ${item.unit == 'roll' ? 'selected' : ''}>Rolls</option>
              <option value="bdl" ${item.unit == 'bdl' ? 'selected' : ''}>Bundle</option>
              <option value="pcs" ${item.unit == 'pcs' ? 'selected' : ''}>Pieces / pcs</option>
            </select>
          </td>

          <td>
            <input type="number" class="form-control unit_price" 
                   name="items[${index}][unit_price]" 
                   value="${item.unit_price}" step="0.01" required>
          </td>

          <td>
            <input type="text" class="form-control amount" 
                   name="items[${index}][amount]" 
                   value="${item.amount}" readonly>
          </td>

          <td class="text-center">
            <button type="button" class="btn btn-danger btn-sm removeRow">Remove</button>
          </td>
        </tr>
      `;

      $("#orItemsTable tbody").append(row);
      grandTotal += parseFloat(item.amount || 0);
    });

    $("#grandTotal").val(grandTotal.toFixed(2));
	  $("#addItemRow").show();
        $(".removeRow").show();
        $("#saveorBtn").show();

        // show approve button
        $("#approveOrBtn").hide();
	$("#saveorBtn").html('<i class="fas fa-save"></i> Update OR');

    toastr.success("OR loaded for editing");
  } else {
    toastr.error(res.message);
  }
}
  });
});



//view order Request
$(document).on("click", ".view-or", function(e) {
  e.preventDefault();

  let or_id = $(this).data("id");

  $.ajax({
    url: "ajax/get_or.php",
    type: "POST",
    data: { or_id: or_id },
    dataType: "json",
    success: function(res) {
      if (res.status === "success") {

        let data = res.data;
        let items = res.items;

        $("#or_id").val(data.or_id);
        $("input[name='or_no']").val(data.or_no);
        $("input[name='or_date']").val(data.or_date);
        $("input[name='dept_code']").val(data.dept_code);
       $("#proj_code").val(data.proj_code);
        $("textarea[name='remarks']").val(data.remarks);

        $("#orItemsTable tbody").empty();

        let grandTotal = 0;

        $.each(items, function(index, item) {
          let row = `
            <tr>
              <td><input type="text" class="form-control sku" value="${item.sku}" readonly></td>
              <td><input type="text" class="form-control item_name" value="${item.item_name}" readonly></td>
              <td><input type="number" class="form-control qty" value="${item.qty}" readonly></td>
              <td><input type="text" class="form-control unit" value="${item.unit}" readonly></td>
              <td><input type="text" class="form-control unit_price" value="${item.unit_price}" readonly></td>
              <td><input type="text" class="form-control amount" value="${item.amount}" readonly></td>
              <td class="text-center">
                <span class="badge badge-secondary">View Only</span>
              </td>
            </tr>
          `;

          $("#orItemsTable tbody").append(row);
          grandTotal += parseFloat(item.amount || 0);
        });

        $("#grandTotal").val(grandTotal.toFixed(2));

        // make header fields readonly
        $("#orForm input, #orForm textarea, #orForm select").prop("readonly", true);
        $("#orForm select").prop("disabled", true);

        // hide add/remove/save
        $("#addItemRow").hide();
        $(".removeRow").hide();
        $("#saveorBtn").hide();

        // show approve button
		var userDept = "<?= $_SESSION['user_dept'] ?>";
		
		
		if (userDept === 'Project' || parseInt(data.or_status, 10) !== 0) {
    $("#approveOrBtn").hide();
}else{
$("#approveOrBtn").show().data("id", data.or_id);
}
        toastr.info("OR loaded for approval.");
      }
    }
  });
});
//end view order request

//save project form
$('#project-form').on('submit', function(e){
    e.preventDefault();

    let form = this;
    let formData = new FormData(form);

    let btn = $('#project-form button[type="submit"]');
    btn.prop('disabled', true);

    $.ajax({
        url: 'ajax/save_project.php',
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        dataType: 'json',

        success: function(res){
            if(res.status === 'success'){
                toastr.success(res.message);
                form.reset();
                projecttable.ajax.reload(null, false);
            } else {
                toastr.error(res.message);
            }
        },

        error: function(xhr){
            toastr.error('Something went wrong');
            console.log(xhr.responseText); // debug
        },

        complete: function(){
            btn.prop('disabled', false);
        }
    });
});

//end save project

$(document).on('click', '.approve-project-btn', function() {
    const projCode = $(this).data('id');
    if (!projCode) {
        return;
    }

    $.ajax({
        url: 'ajax/approve_project.php',
        type: 'POST',
        dataType: 'json',
        data: { proj_code: projCode },
        success: function(res) {
            if (res.status === 'success') {
                toastr.success(res.message);
                projecttable.ajax.reload(null, false);
            } else {
                toastr.error(res.message || 'Approval failed.');
            }
        },
        error: function() {
            toastr.error('Approval failed.');
        }
    });
});

//save supplier form
$('#supplierForm').on('submit', function(e){
    e.preventDefault();

    let form = this;
    let btn = $('#saveSupplierBtn');

    $.ajax({
        url: 'ajax/save_supplier.php',
        type: 'POST',
        data: $(form).serialize(),
        dataType: 'json',
        beforeSend: function(){
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        },
        success: function(res){
            if(res.status === 'success'){
                toastr.success(res.message);
                resetSupplierForm();
                supplierTable.ajax.reload(null, false);
            } else {
                toastr.error(res.message);
            }
        },
        error: function(xhr){
            console.log(xhr.responseText);
            toastr.error('Supplier save failed.');
        },
        complete: function(){
            btn.prop('disabled', false);
            if ($('#supplier_id').val()) {
                btn.html('<i class="fas fa-save"></i> Update Supplier');
            } else {
                btn.html('<i class="fas fa-save"></i> Save Supplier');
            }
        }
    });
});

//end save supplier

function resetSupplierForm() {
    $('#supplierForm')[0].reset();
    $('#supplier_id').val('');
    $('#cancelSupplierEditBtn').addClass('d-none');
    $('#saveSupplierBtn').removeClass('btn-warning').addClass('btn-success').html('<i class="fas fa-save"></i> Save Supplier');
}

$(document).on('click', '.edit-supplier', function(e) {
    e.preventDefault();

    let tr = $(this).closest('tr');
    if (tr.hasClass('child')) {
        tr = tr.prev();
    }

    let data = supplierTable.row(tr).data();
    if (!data) {
        toastr.error('Unable to load supplier row.');
        return;
    }

    $('#supplier_id').val(data.supplier_id);
    $('#supplier_name').val(data.supplier_name);
    $('#supplier_owner').val(data.supplier_owner);
    $('#supplier_address').val(data.address);
    $('#supplier_contact_no').val(data.contact_no);
    $('#supplier_email').val(data.email);
    $('#cancelSupplierEditBtn').removeClass('d-none');
    $('#saveSupplierBtn').removeClass('btn-success').addClass('btn-warning').html('<i class="fas fa-save"></i> Update Supplier');
});

$(document).on('click', '#cancelSupplierEditBtn', function() {
    resetSupplierForm();
});

$(document).on('click', '.delete-supplier', function(e) {
    e.preventDefault();

    let supplierId = $(this).data('id');
    if (!confirm('Delete this supplier?')) {
        return;
    }

    $.ajax({
        url: 'ajax/delete_supplier.php',
        type: 'POST',
        data: { supplier_id: supplierId },
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                toastr.success(res.message);
                resetSupplierForm();
                supplierTable.ajax.reload(null, false);
            } else {
                toastr.error(res.message);
            }
        },
        error: function(xhr) {
            console.log(xhr.responseText);
            toastr.error('Supplier delete failed.');
        }
    });
});

$(document).on('click', '.edit-user', function(e) {
    e.preventDefault();

    let tr = $(this).closest('tr');
    if (tr.hasClass('child')) {
        tr = tr.prev();
    }

    let data = userTable.row(tr).data();
    if (!data) {
        toastr.error('Unable to load user row.');
        return;
    }

    $('#edit_user_code').val(data.user_code);
    $('#edit_user_code').prop('readonly', true);
    $('#edit_user_name').val(data.user_name);
    $('#edit_user_type').val(data.user_type);
    $('#edit_user_dept').val(data.user_dept);
    $('#edit_user_password').val('');
    $('#userPasswordGroup').hide();
    $('#user_mode').val('edit');
    $('#userModalLabel').text('Edit User');
    $('#saveUserBtn').html('<i class="fas fa-save"></i> Save User');
    $('#userModal').modal('show');
});

$(document).on('click', '#addUserBtn', function() {
    $('#userForm')[0].reset();
    $('#user_mode').val('add');
    $('#edit_user_code').prop('readonly', false);
    $('#userPasswordGroup').show();
    $('#edit_user_password').prop('required', true);
    $('#userModalLabel').text('Add User');
    $('#saveUserBtn').html('<i class="fas fa-save"></i> Add User');
    $('#userModal').modal('show');
});

$('#userModal').on('hidden.bs.modal', function() {
    $('#userForm')[0].reset();
    $('#user_mode').val('edit');
    $('#edit_user_code').prop('readonly', true);
    $('#edit_user_password').prop('required', false);
    $('#userPasswordGroup').hide();
    $('#userModalLabel').text('Edit User');
    $('#saveUserBtn').html('<i class="fas fa-save"></i> Save User');
});

$('#userForm').on('submit', function(e) {
    e.preventDefault();

    let btn = $('#saveUserBtn');

    $.ajax({
        url: 'ajax/save_user.php',
        type: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        beforeSend: function() {
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        },
        success: function(res) {
            if (res.status === 'success') {
                toastr.success(res.message);
                $('#userModal').modal('hide');
                userTable.ajax.reload(null, false);
            } else {
                toastr.error(res.message);
            }
        },
        error: function(xhr) {
            console.log(xhr.responseText);
            toastr.error('User save failed.');
        },
        complete: function() {
            btn.prop('disabled', false).html('<i class="fas fa-save"></i> Save User');
        }
    });
});

$(document).on('click', '.reset-user-password', function(e) {
    e.preventDefault();

    let userCode = $(this).data('code');
    if (!confirm('Reset this user password to their default user type password?')) {
        return;
    }

    $.ajax({
        url: 'ajax/reset_user_password.php',
        type: 'POST',
        data: { user_code: userCode },
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                toastr.success(res.message);
            } else {
                toastr.error(res.message);
            }
        },
        error: function(xhr) {
            console.log(xhr.responseText);
            toastr.error('Password reset failed.');
        }
    });
});

$(document).on('click', '.delete-user', function(e) {
    e.preventDefault();

    let userCode = $(this).data('code');
    if (!confirm('Delete this user?')) {
        return;
    }

    $.ajax({
        url: 'ajax/delete_user.php',
        type: 'POST',
        data: { user_code: userCode },
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                toastr.success(res.message);
                userTable.ajax.reload(null, false);
            } else {
                toastr.error(res.message);
            }
        },
        error: function(xhr) {
            console.log(xhr.responseText);
            toastr.error('User delete failed.');
        }
    });
});

$('#changePasswordForm').on('submit', function(e) {
    e.preventDefault();

    let form = this;
    let btn = $('#changePasswordBtn');

    $.ajax({
        url: 'ajax/change_password.php',
        type: 'POST',
        data: $(form).serialize(),
        dataType: 'json',
        beforeSend: function() {
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Changing...');
        },
        success: function(res) {
            if (res.status === 'success') {
                toastr.success(res.message);
                form.reset();
            } else {
                toastr.error(res.message);
            }
        },
        error: function(xhr) {
            console.log(xhr.responseText);
            toastr.error('Password change failed.');
        },
        complete: function() {
            btn.prop('disabled', false).html('Change Password');
        }
    });
});

$(document).on('click', '#openAuditReportModalBtn', function() {
    let today = new Date().toISOString().slice(0, 10);
    $('#audit_start_date').val(today);
    $('#audit_end_date').val(today);
    $('#auditReportModal').modal('show');
});

$('#auditReportForm').on('submit', function(e) {
    e.preventDefault();

    let startDate = $('#audit_start_date').val();
    let endDate = $('#audit_end_date').val();

    if (!startDate || !endDate) {
        toastr.error('Please select start and end dates.');
        return;
    }

    if (startDate > endDate) {
        toastr.error('Start date cannot be later than end date.');
        return;
    }

    window.open('audit_trail_report?start_date=' + encodeURIComponent(startDate) + '&end_date=' + encodeURIComponent(endDate), '_blank');
    $('#auditReportModal').modal('hide');
});

function renderPurchaseRequestItems(items) {
    let tbody = $('#purchaseRequestItemsTable tbody');
    tbody.empty();
    purchaseRequestItemsCache = items || [];
    $('#pr_items_payload').val('');

    if (!items.length) {
        tbody.html('<tr><td colspan="6" class="text-center text-muted">No low stock materials found.</td></tr>');
        $('#savePurchaseRequestBtn').prop('disabled', true);
        return;
    }

    $('#savePurchaseRequestBtn').prop('disabled', false);

    items.forEach(function(item, index) {
        let suggestedQty = parseFloat(item.suggested_qty || 1);
        if (suggestedQty <= 0) {
            suggestedQty = 1;
        }

        tbody.append(`
            <tr>
                <td>
                    ${escapeHtml(item.sku)}
                    <input type="hidden" name="items[${index}][sku]" value="${escapeHtml(item.sku)}">
                </td>
                <td>
                    <strong>${escapeHtml(item.material_name)}</strong>
                    <br><small class="text-muted">${escapeHtml(item.description || '-')}</small>
                </td>
                <td>${escapeHtml(item.available_qty)} ${escapeHtml(item.unit || '')}</td>
                <td>${escapeHtml(item.reorder_level)}</td>
                <td>${escapeHtml(item.unit || '')}</td>
                <td>
                    <input type="number" class="form-control form-control-sm pr-request-qty" data-index="${index}" name="items[${index}][request_qty]" min="0" step="0.01" value="${suggestedQty}" required>
                </td>
            </tr>
        `);
    });
}

function refreshPurchaseRequestPayload() {
    let payload = [];

    $('.pr-request-qty').each(function() {
        let index = Number($(this).data('index'));
        let item = purchaseRequestItemsCache[index];
        let requestQty = parseFloat($(this).val() || 0);

        if (item && requestQty > 0) {
            payload.push({
                sku: item.sku,
                request_qty: requestQty
            });
        }
    });

    $('#pr_items_payload').val(JSON.stringify(payload));
}

$(document).on('click', '.open-pr-request', function(e) {
    e.preventDefault();
    $('#purchaseRequestModal').modal('show');
});

$('#purchaseRequestForm').on('submit', function(e) {
    e.preventDefault();

    let btn = $('#savePurchaseRequestBtn');
    refreshPurchaseRequestPayload();

    $.ajax({
        url: 'ajax/save_purchase_request.php',
        type: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        beforeSend: function() {
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        },
        success: function(res) {
            if (res.status === 'success') {
                toastr.success(res.message);
                $('#purchaseRequestModal').modal('hide');
                $('#purchaseRequestForm')[0].reset();
                if (purchaseRequestTable) {
                    purchaseRequestTable.ajax.reload(null, false);
                }
                if (inventoryPurchaseRequestTable) {
                    inventoryPurchaseRequestTable.ajax.reload(null, false);
                }
            } else {
                toastr.error(res.message || 'Failed to save PR request.');
            }
        },
        error: function(xhr) {
            console.log(xhr.responseText);
            toastr.error('Failed to save PR request.');
        },
        complete: function() {
            btn.prop('disabled', false).html('<i class="fas fa-save"></i> Save PR Request');
        }
    });
});

$(document).on('click', '.view-pr-request', function (e) {
    e.preventDefault();

    const $btn = $(this);
    const prId = $btn.data('id');

    if (!prId) {
        toastr.error('Invalid request ID.');
        return;
    }

    // Disable button to prevent spam clicks
    $btn.prop('disabled', true);

    // Reset modal content
    $('#prDetailsRefNo').text('Loading...');
    $('#purchaseRequestDetailsBody').html(`
        <tr>
            <td colspan="5" class="text-center text-muted">
                Loading request details...
            </td>
        </tr>
    `);

    $('#purchaseRequestDetailsModal').modal('show');

    // Safe HTML escape
    function escapeHtml(text) {
        return $('<div>').text(text ?? '').html();
    }

    function renderPrDetails(refNo, rows) {
        $('#prDetailsRefNo').text(refNo || '');

        if (!Array.isArray(rows) || rows.length === 0) {
            $('#purchaseRequestDetailsBody').html(`
                <tr>
                    <td colspan="5" class="text-center text-muted">
                        No request items found.
                    </td>
                </tr>
            `);
            return;
        }

        const html = rows.map(item => `
           <tr>
    <td>${escapeHtml(item.sku)}</td>

    <td>
        <strong>${escapeHtml(item.item_name)}</strong><br>
 <small>
            Color: ${escapeHtml(item.color || 'N/A')}
        </small><br>
        <small class="text-muted">
            ${escapeHtml(item.description || '--')}
        </small>
    </td>

    <td>${escapeHtml(item.request_qty)}</td>

    <td>${escapeHtml(item.available_qty)}</td>

    <td>${escapeHtml(item.unit)}</td>
</tr>
        `).join('');

        $('#purchaseRequestDetailsBody').html(html);
    }

    $.ajax({
        url: 'ajax/fetch_purchase_request_details.php',
        method: 'GET',
        data: { pr_id: prId },
        dataType: 'json'
    })
    .done(function (res) {
        if (!res || res.status !== 'success') {
            toastr.error(res?.message || 'Failed to load PR details.');
            $('#purchaseRequestDetailsBody').html(`
                <tr>
                    <td colspan="5" class="text-center text-danger">
                        Unable to load request details.
                    </td>
                </tr>
            `);
            return;
        }

        const refNo = res.header?.pr_ref_no || '';
        const items = res.items || [];

        renderPrDetails(refNo, items);
    })
    .fail(function (xhr) {
        console.error(xhr.responseText);
        toastr.error('Failed to load PR details.');

        $('#purchaseRequestDetailsBody').html(`
            <tr>
                <td colspan="5" class="text-center text-danger">
                    Unable to load request details.
                </td>
            </tr>
        `);
    })
    .always(function () {
        // Re-enable button after request finishes
        $btn.prop('disabled', false);
    });
});


//open modal project Upload
$(document).on('click', '.upload-btn', function () {
    let proj_code = $(this).data('id');

    $('#proj_code').val(proj_code);
    $('#uploadModal').modal('show');
});

//open view files modal
$(document).on('click', '.view-btn', function () {
    let proj_code = $(this).data('id');

    $('#viewModal').modal('show');
    $('#fileList').html("Loading...");

    $.ajax({
        url: 'ajax/get_project_files.php',
        type: 'POST',
        data: { proj_code: proj_code },
        success: function (res) {
            $('#fileList').html(res);
        }
    });
});

//upload Files
$(document).on('submit', '#uploadForm', function (e) {
    e.preventDefault();

    let formData = new FormData(this);

    $.ajax({
        url: 'ajax/upload_project_file.php',
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        dataType: 'json',

        beforeSend: function () {
            toastr.info("Uploading file...");
        },

        success: function (res) {
            if (res.status === "success") {
                toastr.success(res.message);

                $('#uploadModal').modal('hide');
                $('#uploadForm')[0].reset();

                // optional: refresh file list if view modal is open
                if ($('#viewModal').hasClass('show')) {
                    $('.view-btn[data-id="' + res.proj_code + '"]').click();
                }

            } else {
                toastr.error(res.message);
            }
        },

        error: function () {
            toastr.error("Upload failed. Server error.");
        }
    });
});

//qrcode generation
$(document).on('click', '.qr-btn', function () {

    let token = $(this).data('token');
	let projname = $(this).data('name');

    let url = window.location.origin + "/project_profile?token=" + token;

    $('#qrImage').attr(
        'src',
        "https://api.qrserver.com/v1/create-qr-code/?size=400x400&margin=10&data=" + encodeURIComponent(url)
    );

    $('#qrText').text(projname);

    $('#qrModal').modal('show');
});


// submit item request
$('#itemRequestForm').on('submit', function(e){
    e.preventDefault();

    $.ajax({
        url: 'ajax/save_item_request.php',
        type: 'POST',
        data: $(this).serialize(),
        success: function(res){

            if(res === "success"){
                toastr.success("Item request submitted!");
					itemspo.ajax.reload(null, false);
                $('#itemRequestModal').modal('hide');
                $('#itemRequestForm')[0].reset();

            } else {
                toastr.error(res);
            }

        },
        error: function(){
            toastr.error("Something went wrong!");
        }
    });
});
//end submit item

$(document).on('click', '#createPendingItemPrBtn', function(e) {
    e.preventDefault();

    if (!confirm('Create a PR request from all pending item requests?')) {
        return;
    }

    const btn = $('#createPendingItemPrBtn');

    $.ajax({
        url: 'ajax/save_pending_item_request_pr.php',
        type: 'POST',
        dataType: 'json',
        beforeSend: function() {
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Creating...');
        },
        success: function(res) {
            if (res.status === 'success') {
                toastr.success(res.message);
                itemspo.ajax.reload(null, false);
                if (purchaseRequestTable) {
                    purchaseRequestTable.ajax.reload(null, false);
                }
                if (inventoryPurchaseRequestTable) {
                    inventoryPurchaseRequestTable.ajax.reload(null, false);
                }
            } else {
                toastr.error(res.message || 'Failed to create PR request.');
            }
        },
        error: function(xhr) {
            console.error(xhr.responseText);
            toastr.error('Failed to create PR request.');
        },
        complete: function() {
            btn.prop('disabled', false).html('Create PR Request');
        }
    });
});

$(document).on('click', '.create-po-request', function (e) {
    e.preventDefault();

    const prId = $(this).data('id');

    if (!prId) {
        toastr.error('Invalid PR ID');
        return;
    }

    $('#poItemsBody').html(`
        <tr>
            <td colspan="5" class="text-center text-muted">
                Loading items...
            </td>
        </tr>
    `);
    $('#supplierSelect').val('');
    $('#poModal').modal('show');

    $.ajax({
        url: 'ajax/fetch_purchase_request_details.php',
        method: 'GET',
        data: { pr_id: prId },
        dataType: 'json'
    })
    .done(function (res) {
        if (res.status !== 'success') {
            toastr.error('Failed to load items');
            return;
        }

       let html = '';

(res.items || []).forEach(item => {
    html += `
        <tr data-sku="${item.sku}">
            <td>
                <strong>${item.item_name}</strong><br>

                <small class="text-muted">
                    ${item.description || ''}
                </small><br>

                <small>
                    SKU: ${item.sku} | 
                    Color: ${item.color || 'N/A'}
                </small>
            </td>

            <td>${item.request_qty} ${item.unit}</td>

            <td>
                <input type="number" class="form-control po-qty" 
                       value="${item.request_qty}" min="0.01" step="0.01">
            </td>
        </tr>
    `;
});

        $('#poItemsBody').html(html);
        $('#poModal').data('pr-id', prId); // store PR ID
		$('#poPrRef').text(res.header.pr_ref_no || '');
    })
    .fail(() => {
        toastr.error('Error loading PR items');
    });
});

function formatPoQty(value) {
    return Number(value || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function printPurchaseOrder(po, previewWin) {
    po = po || {};
    const supplier = po.supplier || {};
    let rows = '';

    (po.items || []).forEach(function(item, index) {
        rows += '<tr>' +
            '<td class="center">' + (index + 1) + '</td>' +
            '<td>' + escapeHtml(item.sku) + '</td>' +
            '<td><strong>' + escapeHtml(item.item_name) + '</strong><br>' +
            '<span>' + escapeHtml(item.description || '-') + '</span><br>' +
            '<span>Color: ' + escapeHtml(item.color || 'N/A') + '</span></td>' +
            '<td class="right">' + formatPoQty(item.request_qty) + '</td>' +
            '<td class="right">' + formatPoQty(item.po_qty) + '</td>' +
            '<td>' + escapeHtml(item.unit || '') + '</td>' +
            '</tr>';
    });

    const printable =
        '<!doctype html><html><head><title>' + escapeHtml(po.po_ref_no || 'Purchase Order') + '</title>' +
        '<style>@page{size:A4;margin:14mm}*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;color:#111;font-size:12px}.sheet{width:210mm;min-height:297mm;padding:14mm;margin:0 auto;background:#fff}.header{display:flex;justify-content:space-between;gap:18px;border-bottom:2px solid #111;padding-bottom:12px;margin-bottom:16px}.logo-box{width:95px;height:70px;border:1px solid #555;display:flex;align-items:center;justify-content:center;color:#777;font-size:11px;text-align:center}.company{flex:1}h1{margin:0 0 6px;font-size:22px;letter-spacing:0}.meta{text-align:right;line-height:1.6;min-width:165px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:16px}.section-title{font-weight:bold;margin-bottom:6px;text-transform:uppercase}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid #333;padding:7px;vertical-align:top}th{background:#f2f2f2;text-align:left}.center{text-align:center}.right{text-align:right}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-top:42px}.signature-line{border-top:1px solid #111;padding-top:6px;text-align:center}@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}.sheet{width:auto;min-height:auto;padding:0}}</style>' +
        '</head><body onload="window.print();"><div class="sheet">' +
        '<div class="header"><div class="logo-box">Company<br>Image</div><div class="company"><h1>Green Ads and Promats, Inc.</h1><strong>PURCHASE ORDER</strong><br>PR #: ' + escapeHtml(po.pr_ref_no || '') + '</div><div class="meta"><div><strong>PO #:</strong> ' + escapeHtml(po.po_ref_no || '') + '</div><div><strong>Date:</strong> ' + escapeHtml(po.po_date || '') + '</div></div></div>' +
        '<div class="grid"><div><div class="section-title">Supplier Details</div><div><strong>' + escapeHtml(supplier.supplier_name || '') + '</strong></div><div>Owner: ' + escapeHtml(supplier.supplier_owner || '-') + '</div><div>Address: ' + escapeHtml(supplier.address || '-') + '</div><div>Contact: ' + escapeHtml(supplier.contact_no || '-') + '</div><div>Email: ' + escapeHtml(supplier.email || '-') + '</div></div><div><div class="section-title">Prepared By</div><div>' + escapeHtml(po.created_by || '') + '</div></div></div>' +
        '<table><thead><tr><th class="center" style="width:36px;">#</th><th style="width:95px;">SKU</th><th>Item</th><th style="width:85px;" class="right">Requested</th><th style="width:85px;" class="right">PO Qty</th><th style="width:55px;">Unit</th></tr></thead><tbody>' + rows + '</tbody></table>' +
        '<div class="signatures"><div class="signature-line">Prepared By</div><div class="signature-line">Approved By</div></div></div></body></html>';

    const win = previewWin || window.open('', '_blank', 'width=900,height=700');
    if (!win) {
        toastr.warning('PO saved, but the print window was blocked by the browser.');
        return;
    }

    win.document.open();
    win.document.write(printable);
    win.document.close();
}

$(document).on('click', '#savePoBtn', function () {
    const prId = $('#poModal').data('pr-id');
    const supplierId = $('#supplierSelect').val();
    const items = [];

    if (!prId) {
        toastr.error('Invalid PR reference.');
        return;
    }

    if (!supplierId) {
        toastr.error('Please select a supplier.');
        return;
    }

    $('#poItemsBody tr').each(function() {
        const sku = $(this).data('sku');
        const poQty = Number($(this).find('.po-qty').val() || 0);

        if (sku && poQty > 0) {
            items.push({
                sku: sku,
                po_qty: poQty
            });
        }
    });

    if (items.length === 0) {
        toastr.error('Please enter at least one PO quantity greater than zero.');
        return;
    }

    const $btn = $('#savePoBtn');

    $.ajax({
        url: 'ajax/save_purchase_order.php',
        type: 'POST',
        dataType: 'json',
        data: {
            pr_id: prId,
            supplier_id: supplierId,
            items: JSON.stringify(items)
        },
        beforeSend: function() {
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        },
        success: function(res) {
            if (res.status === 'success') {
                toastr.success(res.message || 'Purchase order saved.');
                $('#poModal').modal('hide');
                if (purchaseRequestTable) {
                    purchaseRequestTable.ajax.reload(null, false);
                }
                if (inventoryPurchaseRequestTable) {
                    inventoryPurchaseRequestTable.ajax.reload(null, false);
                }
                if (purchaseOrderTable) {
                    purchaseOrderTable.ajax.reload(null, false);
                }
            } else {
                toastr.error(res.message || 'Failed to save purchase order.');
            }
        },
        error: function(xhr) {
            console.error(xhr.responseText);
            toastr.error('Failed to save purchase order.');
        },
        complete: function() {
            $btn.prop('disabled', false).html('Save PO');
        }
    });
});

$(document).on('click', '.fulfill-po', function(e) {
    e.preventDefault();

    $('#fulfillPoForm')[0].reset();
    $('#fulfill_po_id').val($(this).data('id'));
    $('#fulfill_po_ref').val($(this).data('ref'));
    $('#date_received').val(new Date().toISOString().slice(0, 10));
    $('#fulfillPoModal').modal('show');
});

$('#fulfillPoForm').on('submit', function(e) {
    e.preventDefault();

    const $btn = $('#saveFulfillPoBtn');

    $.ajax({
        url: 'ajax/fulfill_purchase_order.php',
        type: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        beforeSend: function() {
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        },
        success: function(res) {
            if (res.status === 'success') {
                toastr.success(res.message);
                $('#fulfillPoModal').modal('hide');
                purchaseOrderTable.ajax.reload(null, false);
                if (purchaseRequestTable) {
                    purchaseRequestTable.ajax.reload(null, false);
                }
                if (inventoryPurchaseRequestTable) {
                    inventoryPurchaseRequestTable.ajax.reload(null, false);
                }
                if (auditLogTable) {
                    auditLogTable.ajax.reload(null, false);
                }
            } else {
                toastr.error(res.message || 'Failed to fulfill PO.');
            }
        },
        error: function(xhr) {
            console.error(xhr.responseText);
            toastr.error('Failed to fulfill PO.');
        },
        complete: function() {
            $btn.prop('disabled', false).html('Save Fulfillment');
        }
    });
});

$(document).on('click', '.encode-pr-request', function(e) {
    e.preventDefault();

    function encodeModalEscape(value) {
        return $('<div>').text(value ?? '').html();
    }

    const prId = $(this).data('id');
    $('#encode_pr_id').val(prId);
    $('#encodePrRef').text('Loading...');
    $('#encodePoRef').text('');
    $('#encodeReceiptNo').text('');
    $('#encodeDateReceived').text('');
    $('#encodePrItemsBody').html('<tr><td colspan="6" class="text-center text-muted">Loading items...</td></tr>');
    $('#encodePrModal').modal('show');

    $.ajax({
        url: 'ajax/fetch_pr_encoding_items.php',
        type: 'GET',
        data: { pr_id: prId },
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                $('#encodePrRef').text(res.header.pr_ref_no || '');
                $('#encodePoRef').text(res.header.po_ref_no || '');
                $('#encodeReceiptNo').text(res.header.receipt_no || '');
                $('#encodeDateReceived').text(res.header.date_received || '');

                let html = '';
                encodePrItemsCache = {};
                (res.items || []).forEach(function(item) {
                    encodePrItemsCache[item.po_item_id] = item;
                    let encoded = item.encoded === true;
                    html += '<tr data-po-item-id="' + item.po_item_id + '">' +
                        '<td>' + encodeModalEscape(item.sku) + '</td>' +
                        '<td>' + encodeModalEscape(item.item_name) + '</td>' +
                        '<td class="text-right">' + formatPoQty(item.po_qty) + '</td>' +
                        '<td>' + encodeModalEscape(item.unit || '') + '</td>' +
                        '<td><input type="number" class="form-control form-control-sm encode-unit-price" step="0.01" min="0" value="' + Number(item.unit_price || 0).toFixed(2) + '" ' + (encoded ? 'disabled' : '') + '></td>' +
                        '<td>' + (encoded ? '<span class="badge badge-success">Encoded</span>' : '<a href="#" class="encode-pr-item"><span class="badge badge-warning">Encode</span></a>') + '</td>' +
                        '</tr>';
                });

                if (!html) {
                    html = '<tr><td colspan="6" class="text-center text-muted">No items found.</td></tr>';
                }

                $('#encodePrItemsBody').html(html);
            } else {
                toastr.error(res.message || 'Failed to encode PR request.');
                $('#encodePrModal').modal('hide');
            }
        },
        error: function(xhr) {
            console.error(xhr.responseText);
            toastr.error('Failed to load PR items.');
            $('#encodePrModal').modal('hide');
        }
    });
});

$(document).on('click', '.encode-pr-item', function(e) {
    e.preventDefault();

    const row = $(this).closest('tr');
    const btn = $(this);
    const poItemId = row.data('po-item-id');
    const prId = $('#encode_pr_id').val();
    const unitPrice = row.find('.encode-unit-price').val();

    const item = encodePrItemsCache[poItemId] || {};
    const itemSku = String(item.sku || '');

    if (itemSku.toUpperCase().indexOf('REQ') === 0 && item.item_exists !== true && !item.inventory_sku) {
        pendingEncodeAfterProductSave = { row: row, poItemId: poItemId };
        $('#productForm')[0].reset();
        $('#id').val('');
        $('#productModalLabel').text('Add Requested Item');
        $('#sku').val('');
        $('#material_name').val(item.item_name || '');
        $('#material_type').val(item.material_type || '');
        $('#category').val('fab');
        const requestedColor = item.color || ((String(item.description || '').match(/Color:\s*([^\n]+)/i) || [])[1]) || '';
        $('#color').val(requestedColor === 'N/A' ? '' : requestedColor);
        $('#description').val(item.description || '');
        $('#quantity').val(0);
        $('#unit').val(item.unit || '');
        $('#unit_price').val(unitPrice || item.unit_price || '');
        $('#reorder_level').val(10);
        $('#productModal').modal('show');
        $('#material_name, #color, #gsm').trigger('input');
        toastr.info('Add this requested item first. The SKU will be auto-generated.');
        return;
    }

    $.ajax({
        url: 'ajax/encode_pr_item.php',
        type: 'POST',
        data: {
            pr_id: prId,
            po_item_id: poItemId,
            unit_price: unitPrice,
            inventory_sku: item.inventory_sku || ''
        },
        dataType: 'json',
        beforeSend: function() {
            btn.replaceWith('<span class="badge badge-secondary">Encoding...</span>');
        },
        success: function(res) {
            if (res.status === 'success') {
                toastr.success(res.message);
                row.find('.encode-unit-price').prop('disabled', true);
                row.find('td:last').html('<span class="badge badge-success">Encoded</span>');
                stocktable.ajax.reload(null, false);
                inventorytable.ajax.reload(null, false);
                invInHistoryTable.ajax.reload(null, false);
                if (inventoryPurchaseRequestTable) {
                    inventoryPurchaseRequestTable.ajax.reload(null, false);
                }
                if (purchaseRequestTable) {
                    purchaseRequestTable.ajax.reload(null, false);
                }
                if (auditLogTable) {
                    auditLogTable.ajax.reload(null, false);
                }
            } else {
                toastr.error(res.message || 'Failed to encode item.');
                row.find('td:last').html('<a href="#" class="encode-pr-item"><span class="badge badge-warning">Encode</span></a>');
            }
        },
        error: function(xhr) {
            console.error(xhr.responseText);
            toastr.error('Failed to encode item.');
            row.find('td:last').html('<a href="#" class="encode-pr-item"><span class="badge badge-warning">Encode</span></a>');
        }
    });
});

$(document).on('click', '.view-po-request', function(e) {
    e.preventDefault();

    const poId = $(this).data('id');
    if (!poId) {
        toastr.error('Invalid PO reference.');
        return;
    }

    const previewWin = window.open('', '_blank', 'width=900,height=700');
    if (!previewWin) {
        toastr.warning('Print preview was blocked by the browser.');
        return;
    }
    previewWin.document.open();
    previewWin.document.write('<html><body style="font-family:Arial;padding:24px;">Loading PO preview...</body></html>');
    previewWin.document.close();

    $.ajax({
        url: 'ajax/fetch_purchase_order_details.php',
        method: 'GET',
        data: { po_id: poId },
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                printPurchaseOrder(res.purchase_order, previewWin);
            } else {
                previewWin.close();
                toastr.error(res.message || 'Failed to load PO details.');
            }
        },
        error: function(xhr) {
            previewWin.close();
            console.error(xhr.responseText);
            toastr.error('Failed to load PO details.');
        }
    });
});


});

</script>
<script>
function printQR() {
    let content = document.getElementById('printArea').innerHTML;

    let win = window.open('', '', 'width=600,height=600');

    win.document.write(`
        <html>
        <head>
            <title>Print QR</title>
            <style>
                body { text-align:center; font-family: Arial; }
            </style>
        </head>
        <body onload="window.print(); window.close();">
            ${content}
        </body>
        </html>
    `);

    win.document.close();
}
</script>

</body>
</html>
