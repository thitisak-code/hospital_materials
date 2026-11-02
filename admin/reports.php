<?php
/**
 * ระบบประมวลผลรายงานสถิติ คลังพัสดุและเวชภัณฑ์ (Warehouse Management & Analytics Reports)
 * โรงพยาบาลมหาสารคาม
 * 
 * ตอบสนองความต้องการของ หน.คลัง ครบทั้ง 4 ด้าน:
 * 1. รายงานการรับ – จ่าย หน่วยย่อย และ ภาพรวม (รายวัน, รายเดือน, รายไตรมาส, รายปี)
 * 2. Stock Card (บัตรคุมคลัง) พร้อมยอดยกมา-ยอดคงเหลือยกไป
 * 3. รายงานการจัดซื้อแยกตามบริษัทคู่ค้า ในช่วงเวลาที่ต้องการ
 * 4. รายงานมูลค่าคงคลัง, มูลค่ารวมรับ, และมูลค่ารวมจ่าย ของหน่วยย่อยและคลังใหญ่
 */
require_once __DIR__ . '/../config.php';
checkAuth('super_admin');

// 1. รับค่าและประมวลผลช่วงเวลา (Time Period Filtering)
$period_type = $_GET['period_type'] ?? 'monthly'; // 'daily', 'monthly', 'quarterly', 'yearly', 'custom'
$selected_unit_id = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0; // 0 = ภาพรวมทั้งหมด
$active_tab = $_GET['tab'] ?? 'receive_dispense'; // 'receive_dispense', 'stock_card', 'suppliers', 'valuation'
$selected_material_id = isset($_GET['material_id']) ? (int)$_GET['material_id'] : 1;

$today = date('Y-m-d');
$current_year = (int)date('Y');
$current_month = (int)date('m');

$start_date = '';
$end_date   = '';
$period_label = '';

if ($period_type === 'daily') {
    $date_val = !empty($_GET['daily_date']) ? $_GET['daily_date'] : $today;
    $start_date = $date_val . ' 00:00:00';
    $end_date   = $date_val . ' 23:59:59';
    $period_label = "รายวัน: " . thaiDate($date_val, false);
} elseif ($period_type === 'monthly') {
    $m = !empty($_GET['month']) ? (int)$_GET['month'] : $current_month;
    $y = !empty($_GET['year']) ? (int)$_GET['year'] : $current_year;
    $start_date = sprintf('%04d-%02d-01 00:00:00', $y, $m);
    $last_day = date('t', strtotime(sprintf('%04d-%02d-01', $y, $m)));
    $end_date   = sprintf('%04d-%02d-%02d 23:59:59', $y, $m, $last_day);
    $thai_months = [1=>'มกราคม', 2=>'กุมภาพันธ์', 3=>'มีนาคม', 4=>'เมษายน', 5=>'พฤษภาคม', 6=>'มิถุนายน', 7=>'กรกฎาคม', 8=>'สิงหาคม', 9=>'กันยายน', 10=>'ตุลาคม', 11=>'พฤศจิกายน', 12=>'ธันวาคม'];
    $period_label = "รายเดือน: เดือน {$thai_months[$m]} พ.ศ. " . ($y + 543);
} elseif ($period_type === 'quarterly') {
    $q = !empty($_GET['quarter']) ? (int)$_GET['quarter'] : (int)ceil($current_month / 3);
    $y = !empty($_GET['year']) ? (int)$_GET['year'] : $current_year;
    
    // ไตรมาสปฏิทิน
    if ($q === 1) {
        $start_date = sprintf('%04d-01-01 00:00:00', $y);
        $end_date   = sprintf('%04d-03-31 23:59:59', $y);
        $period_label = "รายไตรมาส: ไตรมาส 1 (ม.ค. - มี.ค.) พ.ศ. " . ($y + 543);
    } elseif ($q === 2) {
        $start_date = sprintf('%04d-04-01 00:00:00', $y);
        $end_date   = sprintf('%04d-06-30 23:59:59', $y);
        $period_label = "รายไตรมาส: ไตรมาส 2 (เม.ย. - มิ.ย.) พ.ศ. " . ($y + 543);
    } elseif ($q === 3) {
        $start_date = sprintf('%04d-07-01 00:00:00', $y);
        $end_date   = sprintf('%04d-09-30 23:59:59', $y);
        $period_label = "รายไตรมาส: ไตรมาส 3 (ก.ค. - ก.ย.) พ.ศ. " . ($y + 543);
    } else {
        $start_date = sprintf('%04d-10-01 00:00:00', $y);
        $end_date   = sprintf('%04d-12-31 23:59:59', $y);
        $period_label = "รายไตรมาส: ไตรมาส 4 (ต.ค. - ธ.ค.) พ.ศ. " . ($y + 543);
    }
} elseif ($period_type === 'yearly') {
    $y = !empty($_GET['year']) ? (int)$_GET['year'] : $current_year;
    $start_date = sprintf('%04d-01-01 00:00:00', $y);
    $end_date   = sprintf('%04d-12-31 23:59:59', $y);
    $period_label = "รายปี: ประจำปี พ.ศ. " . ($y + 543);
} else { // custom
    $c_start = !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $c_end   = !empty($_GET['end_date']) ? $_GET['end_date'] : $today;
    $start_date = $c_start . ' 00:00:00';
    $end_date   = $c_end . ' 23:59:59';
    $period_label = "ช่วงเวลา: " . thaiDate($c_start, false) . " ถึง " . thaiDate($c_end, false);
}

// รายชื่อหน่วยงานทั้งหมด
$all_units = $pdo->query("SELECT * FROM units ORDER BY unit_id ASC")->fetchAll();
$all_materials = $pdo->query("SELECT * FROM materials ORDER BY material_name ASC")->fetchAll();

// -------------------------------------------------------------
// 1. DATA FOR: รายงานการรับ – จ่าย หน่วยย่อย และ ภาพรวม
// -------------------------------------------------------------
$unit_summary_sql = "
    SELECT u.unit_id, u.unit_code, u.unit_name,
           SUM(CASE WHEN sm.movement_type = 'in' THEN sm.quantity ELSE 0 END) AS total_in_qty,
           SUM(CASE WHEN sm.movement_type = 'in' THEN sm.total_price ELSE 0 END) AS total_in_val,
           SUM(CASE WHEN sm.movement_type = 'out' THEN sm.quantity ELSE 0 END) AS total_out_qty,
           SUM(CASE WHEN sm.movement_type = 'out' THEN sm.total_price ELSE 0 END) AS total_out_val
    FROM units u
    LEFT JOIN stock_movements sm ON u.unit_id = sm.unit_id AND sm.created_at >= ? AND sm.created_at <= ?
    GROUP BY u.unit_id
    ORDER BY u.unit_id ASC
";
$unit_summary_stmt = $pdo->prepare($unit_summary_sql);
$unit_summary_stmt->execute([$start_date, $end_date]);
$unit_summaries = $unit_summary_stmt->fetchAll();

// รายการเคลื่อนไหว รับ-จ่าย ในช่วงเวลา (Movements Log)
$movements_filter_sql = "
    SELECT sm.*, m.material_code, m.material_name, m.unit_unit, u.unit_name, u.unit_code, usr.full_name AS performed_by_name
    FROM stock_movements sm
    JOIN materials m ON sm.material_id = m.material_id
    JOIN units u ON sm.unit_id = u.unit_id
    LEFT JOIN users usr ON sm.performed_by = usr.user_id
    WHERE sm.created_at >= ? AND sm.created_at <= ?
";
$params_mov = [$start_date, $end_date];
if ($selected_unit_id > 0) {
    $movements_filter_sql .= " AND sm.unit_id = ?";
    $params_mov[] = $selected_unit_id;
}
$movements_filter_sql .= " ORDER BY sm.movement_id DESC LIMIT 100";
$mov_stmt = $pdo->prepare($movements_filter_sql);
$mov_stmt->execute($params_mov);
$filtered_movements = $mov_stmt->fetchAll();

// -------------------------------------------------------------
// 2. DATA FOR: Stock Card (บัตรคุมคลัง)
// -------------------------------------------------------------
// ข้อมูลเวชภัณฑ์ที่เลือก
$sc_mat_stmt = $pdo->prepare("SELECT * FROM materials WHERE material_id = ?");
$sc_mat_stmt->execute([$selected_material_id]);
$stock_card_material = $sc_mat_stmt->fetch();

// 2.1 คำนวณยอดยกมา (Opening Balance) ก่อนหน้า $start_date
$op_sql = "
    SELECT 
        SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE -quantity END) AS opening_qty
    FROM stock_movements
    WHERE material_id = ? AND created_at < ?
";
$op_params = [$selected_material_id, $start_date];
if ($selected_unit_id > 0) {
    $op_sql .= " AND unit_id = ?";
    $op_params[] = $selected_unit_id;
}
$op_stmt = $pdo->prepare($op_sql);
$op_stmt->execute($op_params);
$opening_balance = (int)$op_stmt->fetchColumn();

// 2.2 ดึงรายการเคลื่อนไหวในช่วงเวลาของ Stock Card (เรียงจากเก่าไปใหม่เพื่อคำนวณคงเหลือต่อเนื่อง)
$sc_move_sql = "
    SELECT sm.*, u.unit_name, u.unit_code, usr.full_name AS performed_by_name
    FROM stock_movements sm
    JOIN units u ON sm.unit_id = u.unit_id
    LEFT JOIN users usr ON sm.performed_by = usr.user_id
    WHERE sm.material_id = ? AND sm.created_at >= ? AND sm.created_at <= ?
";
$sc_params = [$selected_material_id, $start_date, $end_date];
if ($selected_unit_id > 0) {
    $sc_move_sql .= " AND sm.unit_id = ?";
    $sc_params[] = $selected_unit_id;
}
$sc_move_sql .= " ORDER BY sm.created_at ASC, sm.movement_id ASC";
$sc_stmt = $pdo->prepare($sc_move_sql);
$sc_stmt->execute($sc_params);
$raw_sc_rows = $sc_stmt->fetchAll();

$running_balance = $opening_balance;
$sc_rows = [];
$total_sc_in = 0;
$total_sc_out = 0;

foreach ($raw_sc_rows as $row) {
    $in_q  = $row['movement_type'] === 'in' ? (int)$row['quantity'] : 0;
    $out_q = $row['movement_type'] === 'out' ? (int)$row['quantity'] : 0;
    $running_balance += ($in_q - $out_q);
    $total_sc_in += $in_q;
    $total_sc_out += $out_q;

    $row['in_qty']  = $in_q;
    $row['out_qty'] = $out_q;
    $row['running_bal'] = $running_balance;
    $sc_rows[] = $row;
}
$closing_balance = $running_balance;

// -------------------------------------------------------------
// 3. DATA FOR: รายงานการจัดซื้อแยกตามบริษัท (Purchases by Supplier)
// -------------------------------------------------------------
$sup_report_sql = "
    SELECT 
        COALESCE(NULLIF(sm.supplier, ''), 'ไม่ระบุบริษัท') AS supplier_name,
        COUNT(DISTINCT sm.reference_id) AS total_orders,
        COUNT(sm.movement_id) AS total_items,
        SUM(sm.quantity) AS total_quantity,
        SUM(sm.total_price) AS total_spent
    FROM stock_movements sm
    WHERE sm.movement_type = 'in' 
      AND sm.reference_type = 'receive_purchase'
      AND sm.created_at >= ? AND sm.created_at <= ?
    GROUP BY COALESCE(NULLIF(sm.supplier, ''), 'ไม่ระบุบริษัท')
    ORDER BY total_spent DESC
";
$sup_stmt = $pdo->prepare($sup_report_sql);
$sup_stmt->execute([$start_date, $end_date]);
$supplier_reports = $sup_stmt->fetchAll();

$grand_total_spent = 0;
foreach ($supplier_reports as $sr) {
    $grand_total_spent += (float)$sr['total_spent'];
}

// -------------------------------------------------------------
// 4. DATA FOR: รายงานมูลค่าคงคลัง มูลค่ารวมรับ และมูลค่ารวมจ่าย
// -------------------------------------------------------------
$val_sql = "
    SELECT 
        u.unit_id, u.unit_code, u.unit_name,
        COALESCE(SUM(us.quantity * m.unit_price), 0) AS current_inventory_value,
        COALESCE(in_sum.total_in_val, 0) AS period_in_value,
        COALESCE(out_sum.total_out_val, 0) AS period_out_value
    FROM units u
    LEFT JOIN unit_stocks us ON u.unit_id = us.unit_id
    LEFT JOIN materials m ON us.material_id = m.material_id
    LEFT JOIN (
        SELECT unit_id, SUM(total_price) AS total_in_val
        FROM stock_movements
        WHERE movement_type = 'in' AND created_at >= ? AND created_at <= ?
        GROUP BY unit_id
    ) in_sum ON u.unit_id = in_sum.unit_id
    LEFT JOIN (
        SELECT unit_id, SUM(total_price) AS total_out_val
        FROM stock_movements
        WHERE movement_type = 'out' AND created_at >= ? AND created_at <= ?
        GROUP BY unit_id
    ) out_sum ON u.unit_id = out_sum.unit_id
    GROUP BY u.unit_id
    ORDER BY u.unit_id ASC
";
$val_stmt = $pdo->prepare($val_sql);
$val_stmt->execute([$start_date, $end_date, $start_date, $end_date]);
$valuation_reports = $val_stmt->fetchAll();

$total_inventory_all = 0;
$total_in_all = 0;
$total_out_all = 0;
foreach ($valuation_reports as $vr) {
    $total_inventory_all += (float)$vr['current_inventory_value'];
    $total_in_all += (float)$vr['period_in_value'];
    $total_out_all += (float)$vr['period_out_value'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบประมวลผลรายงานสถิติและมูลค่าเวชภัณฑ์ | คลังใหญ่ รพ.มหาสารคาม</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --mhk-primary: #00796b;
            --mhk-primary-dark: #004d40;
            --mhk-primary-light: #e0f2f1;
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
        .card-report {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            background: #ffffff;
        }
        .stat-banner {
            border-radius: 16px;
            padding: 20px;
            color: #ffffff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        @media print {
            .no-print { display: none !important; }
            body { background: #ffffff !important; }
            .card-report { box-shadow: none !important; border: 1px solid #ddd !important; }
        }
    </style>
</head>
<body>

<?php renderLoadingScreen(); ?>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-mhk sticky-top py-2 no-print">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <span class="bg-white text-success rounded-circle p-2 me-2 d-inline-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                <i class="fa-solid fa-chart-pie fa-lg" style="color: #00796b;"></i>
            </span>
            <div>
                <span class="fw-bold d-block lh-1 fs-6 fs-md-5">โรงพยาบาลมหาสารคาม</span>
                <span class="small opacity-75 fs-7">ระบบประมวลผลรายงานสถิติและมูลค่าคลัง</span>
            </div>
        </a>

        <div class="d-flex align-items-center gap-2">
            <a href="dashboard.php" class="btn btn-sm btn-light text-teal fw-medium rounded-3" style="color: #00796b;">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับคลังใหญ่
            </a>
            <a href="stock_in_out.php" class="btn btn-sm btn-outline-light rounded-3">
                <i class="fa-solid fa-barcode me-1"></i> รับเข้า-จ่ายออกบาร์โค้ด
            </a>
            <a href="subunits_stock.php" class="btn btn-sm btn-outline-light rounded-3">
                <i class="fa-solid fa-hospital-user me-1"></i> สต็อกคลังย่อย
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid py-4 px-lg-4">

    <!-- Header Section -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 no-print">
        <div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-chart-line text-teal me-2" style="color: #00796b;"></i>ระบบประมวลผลรายงานสถิติเวชภัณฑ์
            </h3>
            <p class="text-muted small mb-0">รายงานแยกตามวัน, เดือน, ไตรมาส, ปี • Stock Card • บริษัทจัดซื้อ • มูลค่าคงคลัง</p>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary bg-white rounded-3 shadow-sm px-3" onclick="window.print();">
                <i class="fa-solid fa-print me-1"></i> พิมพ์รายงาน
            </button>
            <a href="../report_pdf.php?type=inventory" target="_blank" class="btn btn-danger rounded-3 shadow-sm px-3">
                <i class="fa-solid fa-file-pdf me-1"></i> ส่งออก PDF
            </a>
        </div>
    </div>

    <!-- Filter Toolbar Form -->
    <div class="card card-report p-3 mb-4 no-print">
        <form method="GET" id="reportFilterForm" class="row g-3 align-items-end">
            <input type="hidden" name="tab" id="filterTabInput" value="<?= h($active_tab) ?>">

            <!-- 1. เลือกรูปแบบช่วงเวลา -->
            <div class="col-md-2">
                <label class="form-label small fw-bold text-dark">รูปแบบช่วงเวลา:</label>
                <select name="period_type" id="periodTypeSelect" class="form-select form-select-sm" onchange="togglePeriodInputs()">
                    <option value="daily" <?= $period_type === 'daily' ? 'selected' : '' ?>>รายวัน (Daily)</option>
                    <option value="monthly" <?= $period_type === 'monthly' ? 'selected' : '' ?>>รายเดือน (Monthly)</option>
                    <option value="quarterly" <?= $period_type === 'quarterly' ? 'selected' : '' ?>>รายไตรมาส (Quarterly)</option>
                    <option value="yearly" <?= $period_type === 'yearly' ? 'selected' : '' ?>>รายปี (Yearly)</option>
                    <option value="custom" <?= $period_type === 'custom' ? 'selected' : '' ?>>กำหนดช่วงเวลาเอง (Custom)</option>
                </select>
            </div>

            <!-- Dynamic Input: Daily -->
            <div class="col-md-2 period-input-group" id="grp_daily" style="display: none;">
                <label class="form-label small fw-bold text-dark">เลือกวันที่:</label>
                <input type="date" name="daily_date" class="form-control form-control-sm" value="<?= h($_GET['daily_date'] ?? $today) ?>">
            </div>

            <!-- Dynamic Input: Monthly -->
            <div class="col-md-2 period-input-group" id="grp_monthly" style="display: none;">
                <label class="form-label small fw-bold text-dark">เลือกเดือน:</label>
                <select name="month" class="form-select form-select-sm">
                    <?php 
                    $months_th = [1=>'ม.ค.', 2=>'ก.พ.', 3=>'มี.ค.', 4=>'เม.ย.', 5=>'พ.ค.', 6=>'มิ.ย.', 7=>'ก.ค.', 8=>'ส.ค.', 9=>'ก.ย.', 10=>'ต.ค.', 11=>'พ.ย.', 12=>'ธ.ค.'];
                    $cur_m = !empty($_GET['month']) ? (int)$_GET['month'] : $current_month;
                    foreach ($months_th as $num => $th_name): ?>
                        <option value="<?= $num ?>" <?= $cur_m === $num ? 'selected' : '' ?>><?= $th_name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Dynamic Input: Quarterly -->
            <div class="col-md-2 period-input-group" id="grp_quarterly" style="display: none;">
                <label class="form-label small fw-bold text-dark">เลือกไตรมาส:</label>
                <?php $cur_q = !empty($_GET['quarter']) ? (int)$_GET['quarter'] : (int)ceil($current_month / 3); ?>
                <select name="quarter" class="form-select form-select-sm">
                    <option value="1" <?= $cur_q === 1 ? 'selected' : '' ?>>ไตรมาส 1 (ม.ค. - มี.ค.)</option>
                    <option value="2" <?= $cur_q === 2 ? 'selected' : '' ?>>ไตรมาส 2 (เม.ย. - มิ.ย.)</option>
                    <option value="3" <?= $cur_q === 3 ? 'selected' : '' ?>>ไตรมาส 3 (ก.ค. - ก.ย.)</option>
                    <option value="4" <?= $cur_q === 4 ? 'selected' : '' ?>>ไตรมาส 4 (ต.ค. - ธ.ค.)</option>
                </select>
            </div>

            <!-- Dynamic Input: Year (for Monthly, Quarterly, Yearly) -->
            <div class="col-md-2 period-input-group" id="grp_year" style="display: none;">
                <label class="form-label small fw-bold text-dark">ปี พ.ศ.:</label>
                <?php $cur_y = !empty($_GET['year']) ? (int)$_GET['year'] : $current_year; ?>
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = $current_year; $y >= $current_year - 4; $y--): ?>
                        <option value="<?= $y ?>" <?= $cur_y === $y ? 'selected' : '' ?>><?= $y + 543 ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <!-- Dynamic Input: Custom Range -->
            <div class="col-md-4 period-input-group" id="grp_custom" style="display: none;">
                <div class="row g-1">
                    <div class="col-6">
                        <label class="form-label small fw-bold text-dark">จากวันที่:</label>
                        <input type="date" name="start_date" class="form-control form-control-sm" value="<?= h($_GET['start_date'] ?? date('Y-m-01')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold text-dark">ถึงวันที่:</label>
                        <input type="date" name="end_date" class="form-control form-control-sm" value="<?= h($_GET['end_date'] ?? $today) ?>">
                    </div>
                </div>
            </div>

            <!-- 2. เลือกหน่วยงาน (Unit Filter) -->
            <div class="col-md-2">
                <label class="form-label small fw-bold text-dark">เลือกหน่วยงาน:</label>
                <select name="unit_id" class="form-select form-select-sm">
                    <option value="0">-- ภาพรวมทั้งโรงพยาบาล --</option>
                    <?php foreach ($all_units as $u): ?>
                        <option value="<?= $u['unit_id'] ?>" <?= $selected_unit_id === (int)$u['unit_id'] ? 'selected' : '' ?>>
                            <?= h($u['unit_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- ปุ่มประมวลผล -->
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100 rounded-3 fw-semibold">
                    <i class="fa-solid fa-filter me-1"></i> ประมวลผลรายงาน
                </button>
            </div>
        </form>
    </div>

    <!-- Banner แสดงช่วงเวลาที่กำลังประมวลผล -->
    <div class="alert alert-info border-0 rounded-4 shadow-sm mb-4 d-flex justify-content-between align-items-center">
        <div>
            <i class="fa-solid fa-calendar-check fs-5 me-2 text-primary"></i>
            <strong class="fs-6"><?= h($period_label) ?></strong>
            <span class="text-muted ms-2">| ข้อมูลหน่วยงาน: <?= $selected_unit_id === 0 ? 'ภาพรวมทั้งโรงพยาบาล (คลังใหญ่ + ทุกคลังย่อย)' : h($pdo->query("SELECT unit_name FROM units WHERE unit_id = {$selected_unit_id}")->fetchColumn()) ?></span>
        </div>
        <span class="badge bg-primary px-3 py-2">อัปเดตแบบเรียลไทม์</span>
    </div>

    <!-- Navigation Tabs for the 4 Core Reports -->
    <ul class="nav nav-tabs nav-fill mb-4 bg-white p-2 rounded-4 shadow-sm border-0 no-print" id="reportTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link <?= $active_tab === 'receive_dispense' ? 'active fw-bold text-teal' : 'text-secondary' ?> py-3" 
                    id="tab-rd-btn" data-bs-toggle="tab" data-bs-target="#tab-rd" onclick="setTab('receive_dispense')">
                <i class="fa-solid fa-arrows-split-up-and-left me-2"></i>1. รับ – จ่าย (หน่วยย่อยและภาพรวม)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $active_tab === 'stock_card' ? 'active fw-bold text-teal' : 'text-secondary' ?> py-3" 
                    id="tab-sc-btn" data-bs-toggle="tab" data-bs-target="#tab-sc" onclick="setTab('stock_card')">
                <i class="fa-solid fa-id-card-clip me-2"></i>2. Stock Card (บัตรคุมคลัง)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $active_tab === 'suppliers' ? 'active fw-bold text-teal' : 'text-secondary' ?> py-3" 
                    id="tab-sup-btn" data-bs-toggle="tab" data-bs-target="#tab-sup" onclick="setTab('suppliers')">
                <i class="fa-solid fa-building-wheat me-2"></i>3. รายงานจัดซื้อแยกตามบริษัท
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $active_tab === 'valuation' ? 'active fw-bold text-teal' : 'text-secondary' ?> py-3" 
                    id="tab-val-btn" data-bs-toggle="tab" data-bs-target="#tab-val" onclick="setTab('valuation')">
                <i class="fa-solid fa-sack-dollar me-2"></i>4. รายงานมูลค่าคงคลัง (รับ-จ่าย)
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content">

        <!-- ========================================== -->
        <!-- REPORT 1: รับ – จ่าย หน่วยย่อย และ ภาพรวม -->
        <!-- ========================================== -->
        <div class="tab-pane fade <?= $active_tab === 'receive_dispense' ? 'show active' : '' ?>" id="tab-rd">
            
            <!-- Summary Table by Units -->
            <div class="card card-report p-4 mb-4">
                <h5 class="fw-bold text-dark mb-3">
                    <i class="fa-solid fa-table-list text-teal me-2" style="color: #00796b;"></i>ตารางสรุปการรับ – จ่าย แยกตามหน่วยงานย่อยและคลังใหญ่
                </h5>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle mb-0">
                        <thead class="table-light text-center">
                            <tr>
                                <th rowspan="2" class="align-middle">รหัสหน่วยงาน</th>
                                <th rowspan="2" class="align-middle">ชื่อหน่วยงาน / คลัง</th>
                                <th colspan="2" class="bg-success-subtle text-success">การรับเข้า (Inflow)</th>
                                <th colspan="2" class="bg-danger-subtle text-danger">การจ่ายออก (Outflow)</th>
                                <th rowspan="2" class="align-middle">ยอดคงเหลือสุทธิ (รับ - จ่าย)</th>
                            </tr>
                            <tr>
                                <th class="bg-success-subtle text-success text-end">จำนวน (ชิ้น)</th>
                                <th class="bg-success-subtle text-success text-end">มูลค่ารวม (บาท)</th>
                                <th class="bg-danger-subtle text-danger text-end">จำนวน (ชิ้น)</th>
                                <th class="bg-danger-subtle text-danger text-end">มูลค่ารวม (บาท)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $tot_in_q = 0; $tot_in_v = 0; $tot_out_q = 0; $tot_out_v = 0;
                            foreach ($unit_summaries as $us): 
                                $tot_in_q += (int)$us['total_in_qty'];
                                $tot_in_v += (float)$us['total_in_val'];
                                $tot_out_q += (int)$us['total_out_qty'];
                                $tot_out_v += (float)$us['total_out_val'];
                                $net_q = (int)$us['total_in_qty'] - (int)$us['total_out_qty'];
                            ?>
                                <tr>
                                    <td class="text-center"><span class="badge bg-light text-dark border"><?= h($us['unit_code']) ?></span></td>
                                    <td class="fw-semibold text-dark">
                                        <?= h($us['unit_name']) ?>
                                        <?= $us['unit_id'] == 1 ? '<span class="badge bg-primary-subtle text-primary ms-1">คลังใหญ่</span>' : '' ?>
                                    </td>
                                    <td class="text-end fw-bold text-success">+<?= number_format($us['total_in_qty']) ?></td>
                                    <td class="text-end text-success"><?= number_format($us['total_in_val'], 2) ?> ฿</td>
                                    <td class="text-end fw-bold text-danger">-<?= number_format($us['total_out_qty']) ?></td>
                                    <td class="text-end text-danger"><?= number_format($us['total_out_val'], 2) ?> ฿</td>
                                    <td class="text-end fw-bold <?= $net_q >= 0 ? 'text-primary' : 'text-secondary' ?>">
                                        <?= ($net_q >= 0 ? '+' : '') . number_format($net_q) ?> ชิ้น
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold text-end">
                            <tr>
                                <td colspan="2" class="text-center">รวมภาพรวมทั้งโรงพยาบาล</td>
                                <td class="text-success">+<?= number_format($tot_in_q) ?></td>
                                <td class="text-success"><?= number_format($tot_in_v, 2) ?> ฿</td>
                                <td class="text-danger">-<?= number_format($tot_out_q) ?></td>
                                <td class="text-danger"><?= number_format($tot_out_v, 2) ?> ฿</td>
                                <td class="text-primary"><?= number_format($tot_in_q - $tot_out_q) ?> ชิ้น</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Detailed Movements in period -->
            <div class="card card-report p-4">
                <h5 class="fw-bold text-dark mb-3">
                    <i class="fa-solid fa-list-check text-primary me-2"></i>รายการเคลื่อนไหวรับ – จ่าย ในช่วงเวลาที่เลือก
                </h5>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>วัน-เวลา</th>
                                <th>ประเภท</th>
                                <th>หน่วยงาน</th>
                                <th>รหัส / บาร์โค้ด</th>
                                <th>รายการเวชภัณฑ์</th>
                                <th class="text-end">จำนวน</th>
                                <th class="text-end">ราคา/หน่วย</th>
                                <th class="text-end">มูลค่ารวม</th>
                                <th>เอกสารอ้างอิง / บริษัท</th>
                                <th>ผู้ดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filtered_movements)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">ไม่พบข้อมูลการเคลื่อนไหวในช่วงเวลานี้</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filtered_movements as $fm): ?>
                                    <tr>
                                        <td class="small text-secondary"><?= thaiDate($fm['created_at'], true) ?></td>
                                        <td>
                                            <?php if ($fm['movement_type'] === 'in'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                    <i class="fa-solid fa-arrow-down me-1"></i>รับเข้า
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                                    <i class="fa-solid fa-arrow-up me-1"></i>จ่ายออก
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?= h($fm['unit_name']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= h($fm['material_code']) ?></span></td>
                                        <td class="fw-semibold text-dark"><?= h($fm['material_name']) ?></td>
                                        <td class="text-end fw-bold <?= $fm['movement_type'] === 'in' ? 'text-success' : 'text-danger' ?>">
                                            <?= $fm['movement_type'] === 'in' ? '+' : '-' ?><?= number_format($fm['quantity']) ?> <?= h($fm['unit_unit']) ?>
                                        </td>
                                        <td class="text-end small text-muted"><?= number_format($fm['unit_price'], 2) ?> ฿</td>
                                        <td class="text-end fw-bold"><?= number_format($fm['total_price'], 2) ?> ฿</td>
                                        <td class="small text-secondary">
                                            <?= h($fm['supplier'] ?: $fm['description']) ?>
                                            <?php if (!empty($fm['reference_id'])): ?>
                                                <small class="d-block text-muted">[<?= h($fm['reference_id']) ?>]</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?= h($fm['performed_by_name'] ?? 'ระบบ') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- ========================================== -->
        <!-- REPORT 2: Stock Card (บัตรคุมคลัง) -->
        <!-- ========================================== -->
        <div class="tab-pane fade <?= $active_tab === 'stock_card' ? 'show active' : '' ?>" id="tab-sc">
            
            <div class="card card-report p-4 mb-4">
                <!-- ตัวเลือกเวชภัณฑ์สำหรับ Stock Card -->
                <form method="GET" class="row g-3 align-items-center mb-4 no-print">
                    <input type="hidden" name="period_type" value="<?= h($period_type) ?>">
                    <input type="hidden" name="tab" value="stock_card">
                    <input type="hidden" name="unit_id" value="<?= $selected_unit_id ?>">
                    <?php if (!empty($_GET['daily_date'])) echo '<input type="hidden" name="daily_date" value="'.h($_GET['daily_date']).'">'; ?>
                    <?php if (!empty($_GET['month'])) echo '<input type="hidden" name="month" value="'.h($_GET['month']).'">'; ?>
                    <?php if (!empty($_GET['quarter'])) echo '<input type="hidden" name="quarter" value="'.h($_GET['quarter']).'">'; ?>
                    <?php if (!empty($_GET['year'])) echo '<input type="hidden" name="year" value="'.h($_GET['year']).'">'; ?>
                    <?php if (!empty($_GET['start_date'])) echo '<input type="hidden" name="start_date" value="'.h($_GET['start_date']).'">'; ?>
                    <?php if (!empty($_GET['end_date'])) echo '<input type="hidden" name="end_date" value="'.h($_GET['end_date']).'">'; ?>

                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark">เลือกรายการเวชภัณฑ์ที่ต้องการดู Stock Card:</label>
                        <select name="material_id" class="form-select form-select-lg" onchange="this.form.submit()">
                            <?php foreach ($all_materials as $am): ?>
                                <option value="<?= $am['material_id'] ?>" <?= $selected_material_id === (int)$am['material_id'] ? 'selected' : '' ?>>
                                    [<?= h($am['material_code']) ?>] <?= h($am['material_name']) ?> (<?= h($am['category']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6 text-md-end">
                        <button type="button" class="btn btn-outline-success rounded-3 mt-4" onclick="window.print();">
                            <i class="fa-solid fa-print me-1"></i> พิมพ์บัตรคุมคลัง (Stock Card)
                        </button>
                    </div>
                </form>

                <!-- Stock Card Sheet Header -->
                <div class="border p-4 rounded-4 mb-3 bg-light">
                    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
                        <div>
                            <span class="badge bg-primary mb-2">บัตรคุมคลัง (STOCK CARD)</span>
                            <h4 class="fw-bold text-dark mb-1">
                                [<?= h($stock_card_material['material_code']) ?>] <?= h($stock_card_material['material_name']) ?>
                            </h4>
                            <div class="text-secondary small">
                                หมวดหมู่: <strong><?= h($stock_card_material['category']) ?></strong> | 
                                หน่วยนับ: <strong><?= h($stock_card_material['unit_unit']) ?></strong> | 
                                ราคาทุน: <strong><?= number_format($stock_card_material['unit_price'], 2) ?> บาท</strong> | 
                                บริษัทผู้จัดจำหน่าย: <strong><?= h($stock_card_material['supplier']) ?></strong>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="small text-muted">ช่วงเวลาที่ประมวลผล:</div>
                            <div class="fw-bold text-dark"><?= h($period_label) ?></div>
                            <div class="small text-muted mt-1">คลัง/หน่วยงาน: <?= $selected_unit_id === 0 ? 'ภาพรวม' : h($pdo->query("SELECT unit_name FROM units WHERE unit_id = {$selected_unit_id}")->fetchColumn()) ?></div>
                        </div>
                    </div>

                    <div class="row g-3 text-center">
                        <div class="col-sm-4">
                            <div class="p-3 bg-white rounded-3 border">
                                <span class="small text-muted d-block">ยอดยกมา (Opening Balance)</span>
                                <h4 class="fw-bold text-secondary mb-0"><?= number_format($opening_balance) ?> <?= h($stock_card_material['unit_unit']) ?></h4>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-3 bg-white rounded-3 border">
                                <span class="small text-muted d-block">รับเข้า (+<?= number_format($total_sc_in) ?>) / จ่ายออก (-<?= number_format($total_sc_out) ?>)</span>
                                <h4 class="fw-bold text-primary mb-0"><?= number_format($total_sc_in - $total_sc_out) ?> <?= h($stock_card_material['unit_unit']) ?></h4>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-3 bg-white rounded-3 border">
                                <span class="small text-muted d-block">ยอดคงเหลือยกไป (Closing Balance)</span>
                                <h4 class="fw-bold text-success mb-0"><?= number_format($closing_balance) ?> <?= h($stock_card_material['unit_unit']) ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stock Card Ledger Table -->
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle mb-0">
                        <thead class="table-light text-center">
                            <tr>
                                <th style="width: 140px;">วัน-เวลา</th>
                                <th>เลขที่เอกสาร / อ้างอิง</th>
                                <th>รายการ / คำอธิบาย</th>
                                <th>หน่วยงาน</th>
                                <th style="width: 100px;">ราคา/หน่วย</th>
                                <th class="text-success" style="width: 110px;">รับเข้า (In)</th>
                                <th class="text-danger" style="width: 110px;">จ่ายออก (Out)</th>
                                <th class="text-primary bg-primary-subtle" style="width: 130px;">คงเหลือ (Balance)</th>
                                <th>ผู้บันทึก</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- แถวยอดยกมา -->
                            <tr class="table-secondary">
                                <td class="text-center font-monospace">-</td>
                                <td class="text-center font-monospace">B/F</td>
                                <td class="fw-bold text-dark">ยอดยกมาต้นงวด (Opening Balance)</td>
                                <td class="text-center">-</td>
                                <td class="text-center">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end fw-bold text-primary bg-primary-subtle"><?= number_format($opening_balance) ?></td>
                                <td class="text-center">-</td>
                            </tr>

                            <?php if (empty($sc_rows)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">ไม่มีรายการเคลื่อนไหวรับ-จ่ายของเวชภัณฑ์นี้ในช่วงเวลาที่เลือก</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sc_rows as $sr): ?>
                                    <tr>
                                        <td class="small text-secondary"><?= thaiDate($sr['created_at'], true) ?></td>
                                        <td class="font-monospace small">
                                            <span class="badge bg-light text-dark border"><?= h($sr['reference_id'] ?: '-') ?></span>
                                        </td>
                                        <td class="small text-dark">
                                            <?= h($sr['description'] ?: '-') ?>
                                            <span class="badge bg-secondary-subtle text-dark ms-1"><?= h($sr['reference_type']) ?></span>
                                        </td>
                                        <td class="small"><?= h($sr['unit_name']) ?></td>
                                        <td class="text-end small"><?= number_format($sr['unit_price'], 2) ?></td>
                                        <td class="text-end fw-bold text-success">
                                            <?= $sr['in_qty'] > 0 ? ('+' . number_format($sr['in_qty'])) : '-' ?>
                                        </td>
                                        <td class="text-end fw-bold text-danger">
                                            <?= $sr['out_qty'] > 0 ? ('-' . number_format($sr['out_qty'])) : '-' ?>
                                        </td>
                                        <td class="text-end fw-bold text-primary bg-primary-subtle fs-6">
                                            <?= number_format($sr['running_bal']) ?>
                                        </td>
                                        <td class="small text-muted"><?= h($sr['performed_by_name'] ?? 'ระบบ') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold text-end">
                            <tr>
                                <td colspan="5" class="text-center">ยอดรวมรับ-จ่ายทั้งสิ้น</td>
                                <td class="text-success">+<?= number_format($total_sc_in) ?></td>
                                <td class="text-danger">-<?= number_format($total_sc_out) ?></td>
                                <td class="text-primary bg-primary-subtle"><?= number_format($closing_balance) ?> <?= h($stock_card_material['unit_unit']) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        </div>

        <!-- ========================================== -->
        <!-- REPORT 3: รายงานการจัดซื้อแยกตามบริษัท -->
        <!-- ========================================== -->
        <div class="tab-pane fade <?= $active_tab === 'suppliers' ? 'show active' : '' ?>" id="tab-sup">
            
            <div class="row g-4 mb-4">
                <!-- สรุปตัวเลขยอดจัดซื้อ -->
                <div class="col-lg-4">
                    <div class="stat-banner bg-success">
                        <span class="opacity-75 small d-block">ยอดมูลค่าการจัดซื้อรวมทั้งสิ้น</span>
                        <h2 class="fw-bold mb-1"><?= number_format($grand_total_spent, 2) ?> ฿</h2>
                        <small class="opacity-75"><?= h($period_label) ?></small>
                    </div>

                    <div class="card card-report p-3 mt-3">
                        <h6 class="fw-bold text-dark mb-2">สัดส่วนมูลค่าจัดซื้อแยกตามบริษัท</h6>
                        <canvas id="supplierChart" height="220"></canvas>
                    </div>
                </div>

                <!-- ตารางแจกแจงตามบริษัทคู่ค้า -->
                <div class="col-lg-8">
                    <div class="card card-report p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-dark mb-0">
                                <i class="fa-solid fa-building-wheat text-success me-2"></i>รายงานสรุปยอดจัดซื้อเวชภัณฑ์ แยกตามบริษัทคู่ค้า
                            </h5>
                            <span class="badge bg-light text-dark border">
                                รวม <?= count($supplier_reports) ?> บริษัท
                            </span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ลำดับ</th>
                                        <th>บริษัทที่จัดซื้อ (Supplier)</th>
                                        <th class="text-center">จำนวนใบสั่งซื้อ (PO)</th>
                                        <th class="text-center">จำนวนรายการ</th>
                                        <th class="text-end">ปริมาณรวม (ชิ้น)</th>
                                        <th class="text-end">มูลค่ารวม (บาท)</th>
                                        <th class="text-end">สัดส่วน (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($supplier_reports)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-5 text-muted">
                                                ไม่มีข้อมูลการจัดซื้อในช่วงเวลาที่เลือก
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php 
                                        $rank = 1;
                                        foreach ($supplier_reports as $srep): 
                                            $percent = $grand_total_spent > 0 ? (($srep['total_spent'] / $grand_total_spent) * 100) : 0;
                                        ?>
                                            <tr>
                                                <td class="text-center fw-bold text-muted"><?= $rank++ ?></td>
                                                <td class="fw-bold text-dark">
                                                    <i class="fa-solid fa-building text-secondary me-1"></i>
                                                    <?= h($srep['supplier_name']) ?>
                                                </td>
                                                <td class="text-center"><span class="badge bg-light text-dark border"><?= number_format($srep['total_orders']) ?></span></td>
                                                <td class="text-center"><?= number_format($srep['total_items']) ?></td>
                                                <td class="text-end fw-semibold"><?= number_format($srep['total_quantity']) ?></td>
                                                <td class="text-end fw-bold text-success fs-6"><?= number_format($srep['total_spent'], 2) ?> ฿</td>
                                                <td class="text-end">
                                                    <div class="d-flex align-items-center justify-content-end gap-2">
                                                        <span class="small fw-semibold"><?= number_format($percent, 1) ?>%</span>
                                                        <div class="progress" style="width: 60px; height: 6px;">
                                                            <div class="progress-bar bg-success" style="width: <?= $percent ?>%"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-light fw-bold text-end">
                                    <tr>
                                        <td colspan="5" class="text-center">มูลค่ารวมทั้งสิ้น</td>
                                        <td class="text-success fs-5"><?= number_format($grand_total_spent, 2) ?> ฿</td>
                                        <td>100.0%</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ========================================== -->
        <!-- REPORT 4: รายงานมูลค่าคงคลัง มูลค่ารวมรับ-จ่าย -->
        <!-- ========================================== -->
        <div class="tab-pane fade <?= $active_tab === 'valuation' ? 'show active' : '' ?>" id="tab-val">
            
            <!-- Cards สรุปมูลค่า 3 มิติ -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stat-banner bg-primary">
                        <span class="opacity-75 small d-block">มูลค่าคงคลังรวมทั้งโรงพยาบาล</span>
                        <h2 class="fw-bold mb-1"><?= number_format($total_inventory_all, 2) ?> ฿</h2>
                        <small class="opacity-75">คำนวณจากยอดสต็อกคงเหลือปัจจุบัน × ราคาทุน</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-banner bg-success">
                        <span class="opacity-75 small d-block">มูลค่ารวมรับ (Total Inflow) ในช่วงเวลา</span>
                        <h2 class="fw-bold mb-1"><?= number_format($total_in_all, 2) ?> ฿</h2>
                        <small class="opacity-75"><?= h($period_label) ?></small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-banner bg-danger">
                        <span class="opacity-75 small d-block">มูลค่ารวมจ่าย (Total Outflow) ในช่วงเวลา</span>
                        <h2 class="fw-bold mb-1"><?= number_format($total_out_all, 2) ?> ฿</h2>
                        <small class="opacity-75"><?= h($period_label) ?></small>
                    </div>
                </div>
            </div>

            <!-- Valuation Matrix Breakdown Table -->
            <div class="card card-report p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="fa-solid fa-scale-balanced text-teal me-2" style="color: #00796b;"></i>ตารางแจกแจงมูลค่าคงคลัง, มูลค่ารวมรับ, และมูลค่ารวมจ่าย แยกตามคลังใหญ่และคลังย่อย
                    </h5>
                    <button class="btn btn-sm btn-outline-success rounded-pill no-print" onclick="window.print();">
                        <i class="fa-solid fa-print me-1"></i>พิมพ์รายงานมูลค่า
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle mb-0">
                        <thead class="table-light text-center">
                            <tr>
                                <th>รหัส</th>
                                <th>หน่วยงาน / คลัง</th>
                                <th class="bg-primary-subtle text-primary text-end">มูลค่าคงคลังปัจจุบัน (บาท)</th>
                                <th class="bg-success-subtle text-success text-end">มูลค่ารวมรับในช่วงเวลา (บาท)</th>
                                <th class="bg-danger-subtle text-danger text-end">มูลค่ารวมจ่ายในช่วงเวลา (บาท)</th>
                                <th class="text-end">สัดส่วนมูลค่าคงคลัง (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($valuation_reports as $vr): 
                                $inv_pct = $total_inventory_all > 0 ? (($vr['current_inventory_value'] / $total_inventory_all) * 100) : 0;
                            ?>
                                <tr>
                                    <td class="text-center"><span class="badge bg-light text-dark border"><?= h($vr['unit_code']) ?></span></td>
                                    <td class="fw-bold text-dark">
                                        <?= h($vr['unit_name']) ?>
                                        <?= $vr['unit_id'] == 1 ? '<span class="badge bg-primary-subtle text-primary ms-1">คลังใหญ่กลาง</span>' : '' ?>
                                    </td>
                                    <td class="text-end fw-bold text-primary fs-6">
                                        <?= number_format($vr['current_inventory_value'], 2) ?> ฿
                                    </td>
                                    <td class="text-end text-success fw-semibold">
                                        +<?= number_format($vr['period_in_value'], 2) ?> ฿
                                    </td>
                                    <td class="text-end text-danger fw-semibold">
                                        -<?= number_format($vr['period_out_value'], 2) ?> ฿
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex align-items-center justify-content-end gap-2">
                                            <span class="small"><?= number_format($inv_pct, 1) ?>%</span>
                                            <div class="progress" style="width: 60px; height: 6px;">
                                                <div class="progress-bar bg-primary" style="width: <?= $inv_pct ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold text-end">
                            <tr>
                                <td colspan="2" class="text-center">รวมภาพรวมทั้งโรงพยาบาล</td>
                                <td class="text-primary fs-5"><?= number_format($total_inventory_all, 2) ?> ฿</td>
                                <td class="text-success fs-6">+<?= number_format($total_in_all, 2) ?> ฿</td>
                                <td class="text-danger fs-6">-<?= number_format($total_out_all, 2) ?> ฿</td>
                                <td>100.0%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        </div>

    </div>

</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function setTab(tabName) {
        document.getElementById('filterTabInput').value = tabName;
    }

    function togglePeriodInputs() {
        const type = document.getElementById('periodTypeSelect').value;
        document.querySelectorAll('.period-input-group').forEach(el => el.style.display = 'none');

        if (type === 'daily') {
            document.getElementById('grp_daily').style.display = 'block';
        } else if (type === 'monthly') {
            document.getElementById('grp_monthly').style.display = 'block';
            document.getElementById('grp_year').style.display = 'block';
        } else if (type === 'quarterly') {
            document.getElementById('grp_quarterly').style.display = 'block';
            document.getElementById('grp_year').style.display = 'block';
        } else if (type === 'yearly') {
            document.getElementById('grp_year').style.display = 'block';
        } else if (type === 'custom') {
            document.getElementById('grp_custom').style.display = 'block';
        }
    }

    // Init on load
    togglePeriodInputs();

    // Supplier Doughnut Chart
    <?php if (!empty($supplier_reports)): ?>
    const supCtx = document.getElementById('supplierChart');
    if (supCtx) {
        const supLabels = <?= json_encode(array_column($supplier_reports, 'supplier_name')) ?>;
        const supData = <?= json_encode(array_map('floatval', array_column($supplier_reports, 'total_spent'))) ?>;

        new Chart(supCtx, {
            type: 'doughnut',
            data: {
                labels: supLabels,
                datasets: [{
                    data: supData,
                    backgroundColor: [
                        '#00796b', '#2b6cb0', '#d69e2e', '#e53e3e', '#805ad5', '#319795'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, font: { family: 'Sarabun' } }
                    }
                }
            }
        });
    }
    <?php endif; ?>
</script>
</body>
</html>

