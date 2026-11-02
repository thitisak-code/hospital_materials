<?php
/**
 * ตรวจติดตามและควบคุมสต็อกคลังย่อย (Sub-unit Stocks Monitor & Control)
 * สำหรับ Super Admin / หัวหน้าคลังใหญ่ โรงพยาบาลมหาสารคาม
 * มองเห็นยอดคงเหลือของทุกคลังย่อย และควบคุมการจ่าย/ปรับยอด/เติมสต็อก
 */
require_once __DIR__ . '/../config.php';
checkAuth('super_admin');

$user_id = $_SESSION['user_id'];
$selected_unit_id = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0; // 0 = ทุกคลังย่อย
$filter_status = $_GET['status'] ?? 'all'; // 'all', 'low', 'empty'

// จัดการการส่งฟอร์ม (POST Request Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ควบคุมปรับยอดสต็อกคลังย่อยโดยตรง (Admin Adjust Sub-unit Stock)
    if ($action === 'adjust_subunit_stock') {
        $unit_id     = (int)($_POST['unit_id'] ?? 0);
        $material_id = (int)($_POST['material_id'] ?? 0);
        $new_qty     = max(0, (int)($_POST['quantity'] ?? 0));
        $reason      = trim($_POST['reason'] ?? 'คลังใหญ่ตรวจนับและปรับปรุงยอด');

        if ($unit_id > 1 && $material_id > 0) {
            $old_qty = (int)$pdo->query("SELECT quantity FROM unit_stocks WHERE unit_id = {$unit_id} AND material_id = {$material_id}")->fetchColumn();
            $diff = $new_qty - $old_qty;

            $stmt = $pdo->prepare("
                INSERT INTO unit_stocks (unit_id, material_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = ?
            ");
            $stmt->execute([$unit_id, $material_id, $new_qty, $new_qty]);

            if ($diff !== 0) {
                $type = $diff > 0 ? 'in' : 'out';
                logStockMovement($pdo, $material_id, $unit_id, $type, abs($diff), 'adjust', null, "คลังใหญ่ปรับปรุงยอด: {$reason} (จาก {$old_qty} เป็น {$new_qty})", $user_id);
            }

            setFlash('success', "ปรับยอดสต็อกคลังย่อยเรียบร้อยแล้ว (ยอดใหม่: {$new_qty})");
        }
        header("Location: subunits_stock.php" . ($unit_id > 0 ? "?unit_id={$unit_id}" : ""));
        exit;
    }

    // 2. เติมสต็อกด่วนจากคลังใหญ่ไปคลังย่อย (Direct Push Refill)
    if ($action === 'quick_refill_subunit') {
        $to_unit_id  = (int)($_POST['to_unit_id'] ?? 0);
        $material_id = (int)($_POST['material_id'] ?? 0);
        $refill_qty  = (int)($_POST['quantity'] ?? 0);
        $note        = trim($_POST['note'] ?? 'คลังใหญ่สั่งเติมสต็อกด่วน');

        if ($to_unit_id > 1 && $material_id > 0 && $refill_qty > 0) {
            try {
                $pdo->beginTransaction();

                // ตรวจสต็อกคลังใหญ่
                $c_stock_stmt = $pdo->prepare("SELECT quantity FROM unit_stocks WHERE unit_id = 1 AND material_id = ? FOR UPDATE");
                $c_stock_stmt->execute([$material_id]);
                $c_stock = (int)$c_stock_stmt->fetchColumn();

                if ($c_stock < $refill_qty) {
                    $pdo->rollBack();
                    setFlash('danger', "สต็อกคลังใหญ่มีไม่เพียงพอ (มีคงเหลือ {$c_stock} แต่ต้องการเติม {$refill_qty})");
                    header("Location: subunits_stock.php" . ($to_unit_id > 0 ? "?unit_id={$to_unit_id}" : ""));
                    exit;
                }

                $mat_stmt = $pdo->prepare("SELECT * FROM materials WHERE material_id = ?");
                $mat_stmt->execute([$material_id]);
                $mat = $mat_stmt->fetch();

                // ตัดสต็อกคลังใหญ่
                $deduct_stmt = $pdo->prepare("UPDATE unit_stocks SET quantity = quantity - ? WHERE unit_id = 1 AND material_id = ?");
                $deduct_stmt->execute([$refill_qty, $material_id]);

                // เพิ่มสต็อกคลังย่อย
                $add_stmt = $pdo->prepare("
                    INSERT INTO unit_stocks (unit_id, material_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
                ");
                $add_stmt->execute([$to_unit_id, $material_id, $refill_qty]);

                // ดึงชื่อหน่วยงาน
                $u_name = $pdo->query("SELECT unit_name FROM units WHERE unit_id = {$to_unit_id}")->fetchColumn();
                $ref_no = "REF-" . date('Ym') . "-" . strtoupper(substr(uniqid(), -4));

                // บันทึกลง refill_orders
                $ins_ref = $pdo->prepare("
                    INSERT INTO refill_orders (refill_no, from_unit_id, to_unit_id, material_id, quantity, status, note, created_by)
                    VALUES (?, 1, ?, ?, ?, 'completed', ?, ?)
                ");
                $ins_ref->execute([$ref_no, $to_unit_id, $material_id, $refill_qty, $note, $user_id]);

                // log movements
                logStockMovement($pdo, $material_id, 1, 'out', $refill_qty, 'refill', $ref_no, "Refill เติมสต็อกให้ {$u_name} ({$note})", $user_id, $mat['unit_price'], $mat['supplier']);
                logStockMovement($pdo, $material_id, $to_unit_id, 'in', $refill_qty, 'refill', $ref_no, "รับ Refill เติมสต็อกด่วนจากคลังใหญ่ ({$ref_no})", $user_id, $mat['unit_price'], $mat['supplier']);

                $pdo->commit();
                setFlash('success', "เติมเวชภัณฑ์ [{$mat['material_code']}] {$mat['material_name']} จำนวน {$refill_qty} {$mat['unit_unit']} ให้แก่ '{$u_name}' เรียบร้อยแล้ว (เลขที่: {$ref_no})");
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', "เกิดข้อผิดพลาดในการเติมสต็อก: " . $e->getMessage());
            }
        }
        header("Location: subunits_stock.php" . ($to_unit_id > 0 ? "?unit_id={$to_unit_id}" : ""));
        exit;
    }
}

// รายชื่อคลังย่อยทั้งหมด (ยกเว้นคลังใหญ่ id = 1)
$sub_units = $pdo->query("SELECT * FROM units WHERE unit_id != 1 ORDER BY unit_id ASC")->fetchAll();

// ดึงรายการสต็อกคลังย่อย
$query = "
    SELECT u.unit_id, u.unit_code, u.unit_name, u.location,
           m.material_id, m.material_code, m.barcode, m.material_name, m.category, m.unit_unit, m.min_stock, m.unit_price,
           COALESCE(us.quantity, 0) AS unit_stock,
           COALESCE(c_us.quantity, 0) AS central_stock
    FROM units u
    CROSS JOIN materials m
    LEFT JOIN unit_stocks us ON u.unit_id = us.unit_id AND m.material_id = us.material_id
    LEFT JOIN unit_stocks c_us ON c_us.unit_id = 1 AND m.material_id = c_us.material_id
    WHERE u.unit_id != 1
";

$params = [];
if ($selected_unit_id > 0) {
    $query .= " AND u.unit_id = ?";
    $params[] = $selected_unit_id;
}

$query .= " ORDER BY u.unit_id ASC, m.material_name ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$raw_stocks = $stmt->fetchAll();

// กรองตามสถานะ (ปกติ / ใกล้หมด / หมดสต็อก)
$stock_rows = [];
$low_stock_count = 0;
$empty_stock_count = 0;
$total_subunit_value = 0;

foreach ($raw_stocks as $row) {
    $qty = (int)$row['unit_stock'];
    $min = (int)$row['min_stock'];
    $val = $qty * (float)$row['unit_price'];
    $total_subunit_value += $val;

    if ($qty === 0) {
        $empty_stock_count++;
    } elseif ($qty <= $min) {
        $low_stock_count++;
    }

    if ($filter_status === 'low' && $qty > $min) {
        continue;
    }
    if ($filter_status === 'empty' && $qty > 0) {
        continue;
    }

    $stock_rows[] = $row;
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ควบคุมและตรวจติดตามสต็อกคลังย่อย | คลังใหญ่ รพ.มหาสารคาม</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        :root {
            --mhk-primary: #00796b;
            --mhk-primary-dark: #004d40;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
        }
        h1, h2, h3, h4, h5, .nav-link, .btn {
            font-family: 'Prompt', sans-serif;
        }
        .navbar-mhk {
            background: linear-gradient(135deg, var(--mhk-primary-dark) 0%, var(--mhk-primary) 100%);
            box-shadow: 0 4px 15px rgba(0, 77, 64, 0.2);
        }
        .stat-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
        }
        .badge-low-stock {
            background-color: #fef2f2;
            color: #ef4444;
            border: 1px solid #fecaca;
        }
        .badge-empty-stock {
            background-color: #7f1d1d;
            color: #ffffff;
        }
        .badge-ok-stock {
            background-color: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }
    </style>
</head>
<body>

<?php renderLoadingScreen(); ?>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-mhk sticky-top py-2">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <span class="bg-white text-success rounded-circle p-2 me-2 d-inline-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                <i class="fa-solid fa-hospital-user fa-lg" style="color: #00796b;"></i>
            </span>
            <div>
                <span class="fw-bold d-block lh-1 fs-6 fs-md-5">โรงพยาบาลมหาสารคาม</span>
                <span class="small opacity-75 fs-7">ตรวจติดตามและควบคุมสต็อกคลังย่อย</span>
            </div>
        </a>

        <div class="d-flex align-items-center gap-2">
            <a href="dashboard.php" class="btn btn-sm btn-light text-teal fw-medium rounded-3" style="color: #00796b;">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับคลังใหญ่
            </a>
            <a href="refill.php" class="btn btn-sm btn-outline-light rounded-3">
                <i class="fa-solid fa-dolly me-1"></i> ระบบ Refill
            </a>
            <a href="reports.php" class="btn btn-sm btn-outline-light rounded-3">
                <i class="fa-solid fa-chart-pie me-1"></i> รายงาน
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid py-4 px-lg-4">

    <!-- Flash Alert -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show rounded-4 shadow-sm mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger' ?> fs-4 me-2"></i>
                <div><?= h($flash['message']) ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Header & Statistics -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-network-wired text-teal me-2" style="color: #00796b;"></i>ศูนย์ตรวจติดตามและควบคุมสต็อกคลังย่อย
            </h3>
            <p class="text-muted small mb-0">คลังใหญ่มองเห็นยอดคงเหลือของทุกหน่วยงาน และสามารถควบคุมการจ่าย หรือสั่งเติม Refill ได้ทันที</p>
        </div>

        <div class="d-flex gap-2">
            <a href="refill.php" class="btn btn-success rounded-3 shadow-sm px-3">
                <i class="fa-solid fa-dolly me-1"></i> หน้าจัดการ Refill อัจฉริยะ
            </a>
            <button class="btn btn-outline-secondary bg-white rounded-3 shadow-sm px-3" onclick="window.print();">
                <i class="fa-solid fa-print me-1"></i> พิมพ์รายงานสต็อก
            </button>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small d-block">จำนวนคลังย่อยทั้งหมด</span>
                    <h3 class="fw-bold text-dark mb-0"><?= count($sub_units) ?> แผนก</h3>
                </div>
                <div class="bg-primary-subtle text-primary p-3 rounded-circle">
                    <i class="fa-solid fa-sitemap fs-4"></i>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small d-block">รายการที่ใกล้หมดเกณฑ์ (Low Stock)</span>
                    <h3 class="fw-bold text-warning mb-0"><?= number_format($low_stock_count) ?> รายการ</h3>
                </div>
                <div class="bg-warning-subtle text-warning p-3 rounded-circle">
                    <i class="fa-solid fa-triangle-exclamation fs-4"></i>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small d-block">รายการที่หมดสต็อก (Out of Stock)</span>
                    <h3 class="fw-bold text-danger mb-0"><?= number_format($empty_stock_count) ?> รายการ</h3>
                </div>
                <div class="bg-danger-subtle text-danger p-3 rounded-circle">
                    <i class="fa-solid fa-box-open fs-4"></i>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small d-block">มูลค่าเวชภัณฑ์ในคลังย่อยรวม</span>
                    <h3 class="fw-bold text-success mb-0"><?= number_format($total_subunit_value, 2) ?> ฿</h3>
                </div>
                <div class="bg-success-subtle text-success p-3 rounded-circle">
                    <i class="fa-solid fa-sack-dollar fs-4"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Bar -->
    <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
        <form method="GET" class="row g-2 align-items-center">
            <!-- คลังย่อย -->
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-hospital"></i></span>
                    <select name="unit_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">-- แสดงทุกคลังย่อย (ทั้งหมด <?= count($sub_units) ?> แผนก) --</option>
                        <?php foreach ($sub_units as $su): ?>
                            <option value="<?= $su['unit_id'] ?>" <?= $selected_unit_id === (int)$su['unit_id'] ? 'selected' : '' ?>>
                                [<?= h($su['unit_code']) ?>] <?= h($su['unit_name']) ?> (<?= h($su['location']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- สถานะสต็อก -->
            <div class="col-md-4">
                <div class="btn-group w-100" role="group">
                    <a href="?unit_id=<?= $selected_unit_id ?>&status=all" class="btn btn-sm <?= $filter_status === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                        ทั้งหมด
                    </a>
                    <a href="?unit_id=<?= $selected_unit_id ?>&status=low" class="btn btn-sm <?= $filter_status === 'low' ? 'btn-warning text-dark' : 'btn-outline-warning text-dark' ?>">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>ใกล้หมด (<?= $low_stock_count ?>)
                    </a>
                    <a href="?unit_id=<?= $selected_unit_id ?>&status=empty" class="btn btn-sm <?= $filter_status === 'empty' ? 'btn-danger' : 'btn-outline-danger' ?>">
                        <i class="fa-solid fa-circle-xmark me-1"></i>หมดสต็อก (<?= $empty_stock_count ?>)
                    </a>
                </div>
            </div>

            <div class="col-md-3 text-md-end">
                <span class="badge bg-light text-dark border px-3 py-2">
                    แสดง <?= count($stock_rows) ?> รายการ
                </span>
            </div>
        </form>
    </div>

    <!-- Main Table -->
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="table-responsive p-3">
            <table class="table table-hover align-middle mb-0" id="subunitTable">
                <thead class="table-light">
                    <tr>
                        <th>คลังย่อย (แผนก)</th>
                        <th>รหัส / บาร์โค้ด</th>
                        <th>รายการเวชภัณฑ์</th>
                        <th>หมวดหมู่</th>
                        <th class="text-center">เกณฑ์ขั้นต่ำ (Min)</th>
                        <th class="text-end">คงเหลือในคลังย่อย</th>
                        <th class="text-end">คลังใหญ่มีให้เติม</th>
                        <th>สถานะสต็อก</th>
                        <th class="text-end">มูลค่าคงคลัง</th>
                        <th class="text-center">ควบคุม / จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stock_rows)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-box-open fs-2 d-block mb-2 text-secondary opacity-50"></i>
                                ไม่พบรายการเวชภัณฑ์ตามเงื่อนไขที่เลือก
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($stock_rows as $sr): 
                            $u_qty = (int)$sr['unit_stock'];
                            $c_qty = (int)$sr['central_stock'];
                            $min   = (int)$sr['min_stock'];
                            $val   = $u_qty * (float)$sr['unit_price'];
                        ?>
                            <tr>
                                <td>
                                    <span class="badge bg-secondary-subtle text-dark border me-1"><?= h($sr['unit_code']) ?></span>
                                    <span class="fw-semibold text-dark"><?= h($sr['unit_name']) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= h($sr['material_code']) ?></span>
                                    <?php if (!empty($sr['barcode']) && $sr['barcode'] !== $sr['material_code']): ?>
                                        <small class="text-muted d-block"><?= h($sr['barcode']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-semibold text-dark"><?= h($sr['material_name']) ?></td>
                                <td><span class="badge bg-light text-secondary border"><?= h($sr['category']) ?></span></td>
                                <td class="text-center text-muted"><?= number_format($min) ?> <?= h($sr['unit_unit']) ?></td>
                                
                                <!-- คงเหลือคลังย่อย -->
                                <td class="text-end">
                                    <span class="fw-bold fs-6 <?= $u_qty === 0 ? 'text-danger' : ($u_qty <= $min ? 'text-warning text-dark' : 'text-success') ?>">
                                        <?= number_format($u_qty) ?>
                                    </span>
                                    <small class="text-muted"><?= h($sr['unit_unit']) ?></small>
                                </td>

                                <!-- คลังใหญ่มีให้เติม -->
                                <td class="text-end">
                                    <span class="badge <?= $c_qty > 0 ? 'bg-info-subtle text-info-emphasis border' : 'bg-light text-muted' ?>">
                                        <?= number_format($c_qty) ?> <?= h($sr['unit_unit']) ?>
                                    </span>
                                </td>

                                <!-- สถานะสต็อก -->
                                <td>
                                    <?php if ($u_qty === 0): ?>
                                        <span class="badge badge-empty-stock">
                                            <i class="fa-solid fa-circle-xmark me-1"></i>หมดสต็อก
                                        </span>
                                    <?php elseif ($u_qty <= $min): ?>
                                        <span class="badge badge-low-stock">
                                            <i class="fa-solid fa-triangle-exclamation me-1"></i>ต่ำกว่าเกณฑ์
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-ok-stock">
                                            <i class="fa-solid fa-check me-1"></i>สต็อกปกติ
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-end fw-semibold text-secondary">
                                    <?= number_format($val, 2) ?> ฿
                                </td>

                                <!-- ควบคุม / จัดการ -->
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <!-- ปุ่ม Refill ทันที -->
                                        <button type="button" class="btn btn-outline-success" 
                                                title="สั่ง Refill เติมเวชภัณฑ์ให้คลังย่อยนี้"
                                                onclick="openRefillModal(<?= $sr['unit_id'] ?>, '<?= h(addslashes($sr['unit_name'])) ?>', <?= $sr['material_id'] ?>, '<?= h(addslashes($sr['material_name'])) ?>', <?= $c_qty ?>, <?= $min ?>, <?= $u_qty ?>, '<?= h($sr['unit_unit']) ?>')">
                                            <i class="fa-solid fa-dolly me-1"></i>เติม Refill
                                        </button>

                                        <!-- ปุ่มปรับยอด -->
                                        <button type="button" class="btn btn-outline-secondary" 
                                                title="ปรับยอดสต็อกคลังย่อย"
                                                onclick="openAdjustModal(<?= $sr['unit_id'] ?>, '<?= h(addslashes($sr['unit_name'])) ?>', <?= $sr['material_id'] ?>, '<?= h(addslashes($sr['material_name'])) ?>', <?= $u_qty ?>, '<?= h($sr['unit_unit']) ?>')">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal สั่ง Refill เติมเวชภัณฑ์ทันที -->
<div class="modal fade" id="modalRefill" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-dolly me-2"></i>สั่งเติม Refill เวชภัณฑ์ให้คลังย่อย</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="quick_refill_subunit">
                <input type="hidden" name="to_unit_id" id="refill_to_unit_id">
                <input type="hidden" name="material_id" id="refill_material_id">

                <div class="modal-body p-4">
                    <div class="mb-3 p-3 bg-light rounded-3">
                        <div class="small text-muted">หน่วยงานปลายทาง:</div>
                        <div class="fw-bold fs-6 text-dark" id="refill_unit_name">-</div>
                        <div class="small text-muted mt-2">รายการเวชภัณฑ์:</div>
                        <div class="fw-bold text-success fs-6" id="refill_material_name">-</div>
                        <div class="d-flex justify-content-between mt-2 pt-2 border-top small">
                            <span>คลังย่อยปัจจุบันมี: <strong id="refill_unit_current">0</strong></span>
                            <span>คลังใหญ่มีให้เติม: <strong class="text-primary" id="refill_central_current">0</strong></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">จำนวนที่ต้องการเติม (Refill Quantity) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="quantity" id="refill_qty_input" class="form-control form-control-lg fw-bold text-success" min="1" required>
                            <span class="input-group-text bg-light" id="refill_unit_addon">ชิ้น</span>
                        </div>
                        <small class="text-muted" id="refill_suggest_text"></small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary">หมายเหตุการ Refill</label>
                        <input type="text" name="note" class="form-control" value="คลังใหญ่สั่ง Refill เติมสต็อก">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success rounded-3 px-4 fw-bold">
                        <i class="fa-solid fa-check me-1"></i> ยืนยันการ Refill
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal ปรับยอดสต็อกคลังย่อย (Admin Adjust) -->
<div class="modal fade" id="modalAdjust" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>ปรับปรุงยอดสต็อกคลังย่อย</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="adjust_subunit_stock">
                <input type="hidden" name="unit_id" id="adj_unit_id">
                <input type="hidden" name="material_id" id="adj_material_id">

                <div class="modal-body p-4">
                    <div class="mb-3 p-3 bg-light rounded-3">
                        <div class="small text-muted">หน่วยงาน:</div>
                        <div class="fw-bold fs-6 text-dark" id="adj_unit_name">-</div>
                        <div class="small text-muted mt-2">เวชภัณฑ์:</div>
                        <div class="fw-bold text-dark fs-6" id="adj_material_name">-</div>
                        <div class="small text-muted mt-2">ยอดปัจจุบันในระบบ: <strong id="adj_current_qty">0</strong></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">ยอดคงเหลือใหม่หลังตรวจนับ <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="quantity" id="adj_new_qty" class="form-control form-control-lg fw-bold" min="0" required>
                            <span class="input-group-text bg-light" id="adj_unit_addon">ชิ้น</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary">เหตุผลการปรับยอด</label>
                        <input type="text" name="reason" class="form-control" value="ตรวจนับพัสดุจริงประจำงวด" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4 fw-bold">
                        <i class="fa-solid fa-save me-1"></i> บันทึกยอดใหม่
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#subunitTable').DataTable({
            language: {
                search: "ค้นหาในตาราง:",
                lengthMenu: "แสดง _MENU_ รายการ",
                info: "แสดง _START_ ถึง _END_ จากทั้งหมด _TOTAL_ รายการ",
                paginate: { first: "แรก", last: "ท้าย", next: "ถัดไป", previous: "ก่อนหน้า" },
                emptyTable: "ไม่พบข้อมูล"
            },
            pageLength: 25,
            order: []
        });
    });

    const modalRefill = new bootstrap.Modal(document.getElementById('modalRefill'));
    const modalAdjust = new bootstrap.Modal(document.getElementById('modalAdjust'));

    function openRefillModal(unitId, unitName, matId, matName, cStock, minStock, uStock, unitUnit) {
        document.getElementById('refill_to_unit_id').value = unitId;
        document.getElementById('refill_material_id').value = matId;
        document.getElementById('refill_unit_name').textContent = unitName;
        document.getElementById('refill_material_name').textContent = matName;
        document.getElementById('refill_unit_current').textContent = uStock + ' ' + unitUnit;
        document.getElementById('refill_central_current').textContent = cStock + ' ' + unitUnit;
        document.getElementById('refill_unit_addon').textContent = unitUnit;

        const needed = Math.max(1, (minStock * 2) - uStock);
        const suggested = Math.min(needed, cStock > 0 ? cStock : 1);
        const qtyInput = document.getElementById('refill_qty_input');
        qtyInput.value = suggested;
        qtyInput.max = cStock;

        document.getElementById('refill_suggest_text').textContent = 'แนะนำเติม ' + suggested + ' ' + unitUnit + ' (เพื่อให้มีพอกว่าเกณฑ์ขั้นต่ำ ' + minStock + ')';

        modalRefill.show();
    }

    function openAdjustModal(unitId, unitName, matId, matName, uStock, unitUnit) {
        document.getElementById('adj_unit_id').value = unitId;
        document.getElementById('adj_material_id').value = matId;
        document.getElementById('adj_unit_name').textContent = unitName;
        document.getElementById('adj_material_name').textContent = matName;
        document.getElementById('adj_current_qty').textContent = uStock + ' ' + unitUnit;
        document.getElementById('adj_new_qty').value = uStock;
        document.getElementById('adj_unit_addon').textContent = unitUnit;

        modalAdjust.show();
    }
</script>
</body>
</html>

