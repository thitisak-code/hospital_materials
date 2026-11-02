<?php
/**
 * ห้องพัสดุประจำหน่วย (Unit Room Card View) - ฉบับสมบูรณ์
 * รองรับคลังห้องตนเอง, เบิกใช้ภายในห้อง, ตะกร้าขอเบิกคลังกลางหลายรายการ (Multi-item), 
 * สแกน QR Code ใบเบิก และการยืม-คืนข้ามแผนก
 * ระบบจัดการวัสดุ โรงพยาบาลมหาสารคาม
 */
require_once __DIR__ . '/../config.php';
checkAuth();

// ตรวจสอบ unit_id ที่กำลังเปิดดู
if ($_SESSION['role'] === 'super_admin' && isset($_GET['unit_id'])) {
    $current_unit_id = (int)$_GET['unit_id'];
} else {
    $current_unit_id = (int)($_SESSION['unit_id'] ?? 2);
}

if ($current_unit_id === 1 && $_SESSION['role'] === 'super_admin' && !isset($_GET['unit_id'])) {
    header("Location: " . BASE_URL . "/admin/dashboard.php");
    exit;
}

// ดึงข้อมูลหน่วยงานปัจจุบัน
$unit_stmt = $pdo->prepare("SELECT * FROM units WHERE unit_id = ?");
$unit_stmt->execute([$current_unit_id]);
$current_unit = $unit_stmt->fetch();

if (!$current_unit) {
    die("ไม่พบข้อมูลหน่วยงาน");
}

// ดึงหมวดหมู่ทั้งหมด
$categories_stmt = $pdo->query("SELECT * FROM categories ORDER BY category_id ASC");
$categories = $categories_stmt->fetchAll();

// ดึงรายการหน่วยงานอื่น สำหรับขอยืมพัสดุ
$other_units = $pdo->query("SELECT * FROM units WHERE unit_id != {$current_unit_id} AND unit_id != 1 ORDER BY unit_id ASC")->fetchAll();

// ดึงข้อมูลวัสดุทั้งหมด พร้อมสต็อกในห้องนี้ และสต็อกคลังกลาง
$stocks_stmt = $pdo->prepare("
    SELECT m.*, 
           COALESCE(us.quantity, 0) AS unit_stock,
           COALESCE(central_s.quantity, 0) AS central_stock
    FROM materials m
    LEFT JOIN unit_stocks us ON m.material_id = us.material_id AND us.unit_id = ?
    LEFT JOIN unit_stocks central_s ON m.material_id = central_s.material_id AND central_s.unit_id = 1
    ORDER BY m.material_id ASC
");
$stocks_stmt->execute([$current_unit_id]);
$materials = $stocks_stmt->fetchAll();

// สถิติห้องพัสดุ
$total_types = count($materials);
$total_items_in_stock = 0;
$low_stock_count = 0;
foreach ($materials as $m) {
    $total_items_in_stock += (int)$m['unit_stock'];
    if ((int)$m['unit_stock'] <= (int)$m['min_stock']) {
        $low_stock_count++;
    }
}

// ดึงประวัติใบขอเบิกพัสดุของหน่วยงานนี้ (Requisitions)
$my_reqs_stmt = $pdo->prepare("
    SELECT r.*, COUNT(ri.item_id) AS total_items, SUM(ri.quantity_requested) AS total_qty_req,
           usr.full_name AS requester_name, u_app.full_name AS approver_name
    FROM requisitions r
    JOIN users usr ON r.user_id = usr.user_id
    LEFT JOIN users u_app ON r.approved_by = u_app.user_id
    LEFT JOIN requisition_items ri ON r.requisition_id = ri.requisition_id
    WHERE r.unit_id = ?
    GROUP BY r.requisition_id
    ORDER BY r.requisition_id DESC
");
$my_reqs_stmt->execute([$current_unit_id]);
$my_requisitions = $my_reqs_stmt->fetchAll();

// แยกรายการใบขอเบิกของหน่วย: รอคลังกลางจ่าย, ได้รับของแล้ว, ไม่อนุมัติ (ไม่รวมกัน)
$my_pending_reqs   = [];
$my_completed_reqs = [];
$my_rejected_reqs  = [];
foreach ($my_requisitions as $mr) {
    if ($mr['status'] === 'pending') {
        $my_pending_reqs[] = $mr;
    } elseif ($mr['status'] === 'completed' || $mr['status'] === 'approved') {
        $my_completed_reqs[] = $mr;
    } else {
        $my_rejected_reqs[] = $mr;
    }
}

// ดึงประวัติการนำพัสดุออกจากห้องไปใช้งาน (Unit Usages)
$usages_stmt = $pdo->prepare("
    SELECT u.*, m.material_code, m.material_name, m.unit_unit
    FROM unit_usages u
    JOIN materials m ON u.material_id = m.material_id
    WHERE u.unit_id = ?
    ORDER BY u.usage_id DESC
    LIMIT 30
");
$usages_stmt->execute([$current_unit_id]);
$my_usages = $usages_stmt->fetchAll();

// รายการยืม-คืนของหน่วยนี้
$my_borrows_stmt = $pdo->prepare("
    SELECT b.*, m.material_code, m.material_name, m.unit_unit, u_l.unit_name AS lender_name
    FROM item_borrows b
    JOIN materials m ON b.material_id = m.material_id
    JOIN units u_l ON b.lender_unit_id = u_l.unit_id
    WHERE b.borrower_unit_id = ?
    ORDER BY b.borrow_id DESC
");
$my_borrows_stmt->execute([$current_unit_id]);
$my_borrows = $my_borrows_stmt->fetchAll();

$req_enabled = getSetting('requisition_enabled', '1') === '1';
$closed_reason = getSetting('requisition_closed_reason', 'ระบบปิดรับการเบิกพัสดุชั่วคราวเพื่อตรวจนับสต็อกประจำงวด');
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ห้องพัสดุ: <?= h($current_unit['unit_name']) ?> | โรงพยาบาลมหาสารคาม</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --mhk-primary: #0f766e;
            --mhk-primary-dark: #115e59;
            --mhk-primary-light: #ccfbf1;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
        }
        h1, h2, h3, h4, h5, .nav-link, .btn {
            font-family: 'Prompt', sans-serif;
        }
        .navbar-unit {
            background: linear-gradient(135deg, var(--mhk-primary) 0%, var(--mhk-primary-dark) 100%);
            box-shadow: 0 4px 15px rgba(15, 118, 110, 0.2);
        }
        .material-card {
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            transition: all 0.25s ease;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
        }
        .material-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 22px rgba(0, 0, 0, 0.07);
        }
        .material-card.is-low-stock {
            border-left: 5px solid #f59e0b;
        }
        .material-card.is-out-of-stock {
            border-left: 5px solid #ef4444;
        }
        .material-card.is-safe-stock {
            border-left: 5px solid #10b981;
        }
        .stock-number {
            font-size: 2.2rem;
            font-weight: 700;
            font-family: 'Prompt', sans-serif;
            line-height: 1;
        }
        .stat-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 18px;
        }
        .card-action-bar {
            background: #f8fafc;
            border-top: 1px solid #f1f5f9;
            padding: 10px 12px;
        }
        /* Floating Buttons (Desktop) */
        .floating-cart-btn {
            position: fixed;
            bottom: 25px;
            right: 25px;
            z-index: 1050;
            border-radius: 50px;
            padding: 14px 24px;
            box-shadow: 0 8px 24px rgba(15, 118, 110, 0.35);
            display: none;
            animation: bounceIn 0.3s ease;
        }
        .floating-use-btn {
            position: fixed;
            bottom: 25px;
            left: 25px;
            z-index: 1050;
            border-radius: 50px;
            padding: 14px 24px;
            box-shadow: 0 8px 24px rgba(220, 38, 38, 0.35);
            display: none;
            animation: bounceIn 0.3s ease;
        }
        @keyframes bounceIn {
            0% { transform: scale(0.8); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        .nav-tabs .nav-link.active {
            color: var(--mhk-primary);
            border-bottom: 3px solid var(--mhk-primary);
            font-weight: 600;
        }

        /* Mobile Bottom Navigation Bar (สำหรับโทรศัพท์มือถือ) */
        .mobile-bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 64px;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.08);
            z-index: 1040;
            display: none;
            justify-content: space-around;
            align-items: center;
            padding: 0 4px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .bottom-nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 500;
            padding: 6px 2px;
            position: relative;
            background: none;
            border: none;
            outline: none;
            transition: color 0.15s ease;
        }
        .bottom-nav-item:hover, .bottom-nav-item:active, .bottom-nav-item.active {
            color: var(--mhk-primary);
        }
        .bottom-nav-item i {
            font-size: 1.25rem;
            margin-bottom: 2px;
        }
        .bottom-nav-badge {
            position: absolute;
            top: 2px;
            right: 18%;
            background: #ef4444;
            color: #ffffff;
            font-size: 0.65rem;
            font-weight: 700;
            border-radius: 10px;
            padding: 1px 5px;
            min-width: 16px;
            text-align: center;
            line-height: 1.2;
            box-shadow: 0 2px 5px rgba(239, 68, 68, 0.35);
        }
        .bottom-nav-scan-btn {
            position: relative;
            top: -12px;
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #ffffff !important;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 18px rgba(245, 158, 11, 0.45);
            border: 3px solid #ffffff;
            transition: transform 0.15s ease;
            text-decoration: none;
        }
        .bottom-nav-scan-btn:active {
            transform: scale(0.92);
        }
        .bottom-nav-scan-btn i {
            font-size: 1.45rem;
            margin-bottom: 0;
        }

        /* Responsive Breakpoints สำหรับมือถือ */
        @media (max-width: 991px) {
            .mobile-bottom-nav {
                display: flex !important;
            }
            body {
                padding-bottom: 78px !important;
            }
            .floating-cart-btn, .floating-use-btn {
                display: none !important;
            }
            .navbar-unit .navbar-brand {
                font-size: 0.92rem;
            }
        }
    </style>
</head>
<body>

<?php renderLoadingScreen(); ?>

<!-- Navbar (Top Header) -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-unit sticky-top py-2">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php<?= isset($_GET['unit_id']) ? '?unit_id='.$current_unit_id : '' ?>">
            <span class="bg-white text-dark rounded-circle p-2 me-2 d-inline-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                <i class="fa-solid fa-hospital-user text-teal" style="color: var(--mhk-primary);"></i>
            </span>
            <div>
                <span class="fw-bold d-block lh-1 fs-6 fs-md-5">โรงพยาบาลมหาสารคาม</span>
                <span class="small opacity-75 fs-7">ห้องพัสดุ: <?= h($current_unit['unit_name']) ?></span>
            </div>
        </a>

        <!-- Mobile Top Right Menu Button -->
        <button class="btn btn-sm btn-outline-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#unitMobileMenu" aria-label="เมนู">
            <i class="fa-solid fa-bars"></i>
        </button>

        <!-- Desktop Navigation Links -->
        <div class="collapse navbar-collapse" id="unitNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3">
                <li class="nav-item">
                    <a class="nav-link active" href="dashboard.php<?= isset($_GET['unit_id']) ? '?unit_id='.$current_unit_id : '' ?>">
                        <i class="fa-solid fa-cubes me-1"></i> รายการพัสดุในห้อง (Card View)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#requisitions_tab">
                        <i class="fa-solid fa-file-invoice me-1"></i> ใบขอเบิกคลังกลาง
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#usages_tab">
                        <i class="fa-solid fa-clipboard-check me-1"></i> ประวัติการเบิกใช้
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-bold text-warning" href="<?= BASE_URL ?>/unit/scan.php<?= isset($_GET['unit_id']) ? '?unit_id='.$current_unit_id : '' ?>">
                        <i class="fa-solid fa-barcode me-1"></i> สแกนบาร์โค้ดจ่ายคนไข้
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <?php if ($_SESSION['role'] === 'super_admin'): ?>
                    <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-sm btn-outline-light">
                        <i class="fa-solid fa-warehouse me-1"></i> กลับคลังใหญ่
                    </a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/report_pdf.php?type=unit&unit_id=<?= $current_unit_id ?>" target="_blank" class="btn btn-sm btn-light text-danger fw-semibold">
                    <i class="fa-solid fa-file-pdf me-1"></i> พิมพ์รายงานห้อง
                </a>
                <a href="<?= BASE_URL ?>/logout.php" class="btn btn-sm btn-outline-light">
                    <i class="fa-solid fa-arrow-right-from-bracket me-1"></i> ออกจากระบบ
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- Mobile Bottom Navigation Bar (เมนูด้านล่างสำหรับโทรศัพท์มือถือ) -->
<nav class="mobile-bottom-nav d-lg-none">
    <!-- 1. พัสดุในห้อง -->
    <a href="#materialsSection" class="bottom-nav-item active">
        <i class="fa-solid fa-boxes-stacked"></i>
        <span>พัสดุในห้อง</span>
    </a>

    <!-- 2. เบิกใช้ในห้อง -->
    <button type="button" class="bottom-nav-item text-danger" onclick="openMultiUseModal()">
        <span id="mobileBottomUseBadge" class="bottom-nav-badge d-none">0</span>
        <i class="fa-solid fa-hand-holding-medical"></i>
        <span>เบิกใช้</span>
    </button>

    <!-- 3. สแกน QR ด่วน (ปุ่มลอยตรงกลาง) -->
    <a href="<?= BASE_URL ?>/unit/scan.php" class="bottom-nav-scan-btn shadow" title="สแกน QR Code">
        <i class="fa-solid fa-qrcode"></i>
    </a>

    <!-- 4. ใบขอเบิกคลังกลาง -->
    <button type="button" class="bottom-nav-item text-success" onclick="openCartModal()">
        <span id="mobileBottomReqBadge" class="bottom-nav-badge d-none">0</span>
        <i class="fa-solid fa-cart-shopping"></i>
        <span>ใบขอเบิก</span>
    </button>

    <!-- 5. เมนู / ประวัติ -->
    <button type="button" class="bottom-nav-item" data-bs-toggle="offcanvas" data-bs-target="#unitMobileMenu">
        <i class="fa-solid fa-bars"></i>
        <span>เมนู</span>
    </button>
</nav>

<div class="container-fluid px-lg-4 py-4">

    <!-- Flash Message -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> me-2"></i>
            <?= h($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ประกาศปิดรับเบิกพัสดุชั่วคราว (ถ้า Super Admin ตั้งค่าปิดไว้) -->
    <?php if (!$req_enabled): ?>
        <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center gap-3 p-3">
            <div class="p-3 bg-warning bg-opacity-25 text-dark rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                <i class="fa-solid fa-triangle-exclamation fa-xl"></i>
            </div>
            <div>
                <h5 class="fw-bold mb-1 text-dark">ประกาศ: คลังกลางปิดรับใบขอเบิกพัสดุชั่วคราว</h5>
                <p class="mb-0 text-dark small"><?= nl2br(h($closed_reason)) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Banner Room Info -->
    <div class="card p-4 rounded-4 border shadow-sm mb-4 bg-white">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center">
                <div class="p-3 rounded-4 me-3 text-teal" style="background-color: var(--mhk-primary-light); color: var(--mhk-primary);">
                    <i class="fa-solid fa-boxes-stacked fa-3x"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-dark font-monospace"><?= h($current_unit['unit_code']) ?></span>
                        <span class="badge bg-success bg-opacity-10 text-success border">คลังห้องประจำหน่วยพร้อมใช้งาน</span>
                    </div>
                    <h2 class="h3 fw-bold mb-1 text-dark"><?= h($current_unit['unit_name']) ?></h2>
                    <p class="text-muted small mb-0">
                        <i class="fa-solid fa-location-dot me-1 text-danger"></i> <?= h($current_unit['location'] ?? 'อาคารบริการ') ?> &nbsp;|&nbsp; 
                        <i class="fa-solid fa-phone me-1 text-primary"></i> <?= h($current_unit['phone'] ?? '-') ?>
                    </p>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-danger rounded-3 shadow-sm px-3 fw-semibold" onclick="openMultiUseModal()">
                    <i class="fa-solid fa-hand-holding-medical me-1"></i> เบิกใช้ในห้อง (หลายรายการ) (<span id="multiUseCountBadge">0</span>)
                </button>
                <button type="button" class="btn btn-success rounded-3 shadow-sm px-3 fw-semibold" onclick="openCartModal()">
                    <i class="fa-solid fa-cart-shopping me-1"></i> ใบขอเบิกคลังกลาง (<span id="cartCountBadge">0</span>)
                </button>
                <a href="<?= BASE_URL ?>/unit/scan.php" class="btn btn-warning rounded-3 shadow-sm px-3 fw-semibold">
                    <i class="fa-solid fa-camera me-1"></i> สแกน QR ด่วน
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-2 g-md-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100 p-3">
                <div class="text-muted small fs-7">ประเภทพัสดุในห้อง</div>
                <h3 class="fw-bold text-dark mb-0 fs-4 fs-md-3"><?= number_format($total_types) ?> <small class="fs-7 text-muted fw-normal">รายการ</small></h3>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100 p-3">
                <div class="text-muted small fs-7">ยอดคงคลังในห้องรวม</div>
                <h3 class="fw-bold text-success mb-0 fs-4 fs-md-3"><?= number_format($total_items_in_stock) ?> <small class="fs-7 text-muted fw-normal">ชิ้น</small></h3>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100 p-3">
                <div class="text-muted small fs-7">พัสดุใกล้หมด (ต่ำเกณฑ์)</div>
                <h3 class="fw-bold <?= $low_stock_count > 0 ? 'text-warning' : 'text-muted' ?> mb-0 fs-4 fs-md-3">
                    <?= number_format($low_stock_count) ?> <small class="fs-7 text-muted fw-normal">รายการ</small>
                </h3>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100 p-3">
                <div class="text-muted small fs-7">ใบขอเบิกค้างจ่าย</div>
                <h3 class="fw-bold text-primary mb-0 fs-4 fs-md-3">
                    <?= count(array_filter($my_requisitions, fn($r) => $r['status'] === 'pending')) ?> <small class="fs-7 text-muted fw-normal">ฉบับ</small>
                </h3>
            </div>
        </div>
    </div>

    <!-- Filter & Search Bar for Cards -->
    <div id="materialsSection" class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3 pt-2">
        <h4 class="fw-bold mb-0 text-dark fs-5 fs-md-4">
            <i class="fa-solid fa-table-cells me-2 text-primary"></i> คลังพัสดุประจำห้อง (Card View)
        </h4>
        <div class="d-flex flex-wrap gap-2">
            <div class="input-group" style="max-width: 300px;">
                <span class="input-group-text bg-white border-end-0 text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="text" id="cardSearchInput" class="form-control border-start-0" placeholder="พิมพ์ชื่อหรือรหัสพัสดุ...">
            </div>
            <select id="cardCategoryFilter" class="form-select" style="max-width: 200px;">
                <option value="">ทุกหมวดหมู่</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat['category_name']) ?>"><?= h($cat['category_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Cards Grid -->
    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 g-3 mb-5" id="materialsGrid">
        <?php foreach ($materials as $m): ?>
            <?php
                $qty = (int)$m['unit_stock'];
                $min = (int)$m['min_stock'];
                $central = (int)$m['central_stock'];

                if ($qty <= 0) {
                    $card_class = 'is-out-of-stock';
                    $stock_color = 'text-danger';
                    $badge = '<span class="badge bg-danger rounded-pill">หมดสต็อก</span>';
                } elseif ($qty <= $min) {
                    $card_class = 'is-low-stock';
                    $stock_color = 'text-warning';
                    $badge = '<span class="badge bg-warning text-dark rounded-pill">ต่ำเกณฑ์ (ต้องเติม)</span>';
                } else {
                    $card_class = 'is-safe-stock';
                    $stock_color = 'text-success';
                    $badge = '<span class="badge bg-success rounded-pill">พร้อมใช้</span>';
                }
            ?>
            <div class="col material-card-item" 
                 data-name="<?= strtolower(h($m['material_name'])) ?>" 
                 data-code="<?= strtolower(h($m['material_code'])) ?>" 
                 data-category="<?= h($m['category']) ?>">
                
                <div class="card material-card h-100 <?= $card_class ?>">
                    <div class="p-3 pb-2 flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="badge bg-light text-secondary border font-monospace"><?= h($m['material_code']) ?></span>
                            <?= $badge ?>
                        </div>

                        <h5 class="fw-bold text-dark mb-1" style="font-size: 1.05rem; min-height: 2.6rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                            <?= h($m['material_name']) ?>
                        </h5>
                        <div class="small text-muted mb-2">
                            <span class="badge bg-secondary bg-opacity-10 text-secondary"><?= h($m['category']) ?></span>
                        </div>

                        <div class="bg-light p-3 rounded-3 text-center mb-2">
                            <div class="text-muted small mb-1">ยอดคงเหลือในห้องเรา</div>
                            <div class="d-flex align-items-baseline justify-content-center gap-1">
                                <span class="stock-number <?= $stock_color ?>"><?= number_format($qty) ?></span>
                                <span class="text-muted fs-6"><?= h($m['unit_unit']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between text-muted mt-2" style="font-size: 0.75rem;">
                                <span>เกณฑ์ขั้นต่ำ: <strong><?= $min ?></strong></span>
                                <span>คลังกลางมี: <strong class="text-success"><?= number_format($central) ?></strong></span>
                            </div>
                        </div>
                    </div>

                    <!-- Card Actions -->
                    <div class="card-action-bar">
                        <div class="d-flex gap-1 mb-1">
                            <!-- 1. เบิกใช้ในห้องตนเอง (เดี่ยว) -->
                            <button type="button" class="btn btn-sm btn-outline-danger flex-fill" 
                                    onclick="openUseModal(<?= $m['material_id'] ?>, '<?= addslashes(h($m['material_name'])) ?>', <?= $qty ?>, '<?= h($m['unit_unit']) ?>')"
                                    title="เบิกใช้วัสดุนี้ทันที" <?= $qty <= 0 ? 'disabled' : '' ?>>
                                <i class="fa-solid fa-hand-holding-medical me-1"></i> เบิกใช้
                            </button>
                            <!-- เพิ่มเข้าชุดเบิกใช้หลายรายการในห้อง -->
                            <button type="button" class="btn btn-sm btn-danger px-2" 
                                    onclick="addToMultiUse(<?= $m['material_id'] ?>, '<?= addslashes(h($m['material_name'])) ?>', '<?= h($m['material_code']) ?>', '<?= h($m['unit_unit']) ?>', <?= $qty ?>)"
                                    title="เลือกใส่ชุดเบิกใช้หลายรายการพร้อมกัน" <?= $qty <= 0 ? 'disabled' : '' ?>>
                                <i class="fa-solid fa-cart-plus me-1"></i> รวมใช้
                            </button>
                        </div>
                        <div class="d-flex gap-1 mb-1">
                            <!-- 2. ใส่ตะกร้าขอเบิกคลังกลาง -->
                            <?php if ($req_enabled): ?>
                                <button type="button" class="btn btn-sm btn-outline-success w-100" 
                                        onclick="addToRequisitionCart(<?= $m['material_id'] ?>, '<?= addslashes(h($m['material_name'])) ?>', '<?= h($m['material_code']) ?>', '<?= h($m['unit_unit']) ?>', <?= $central ?>)"
                                        title="เพิ่มพัสดุนี้ลงใบขอเบิกคลังกลาง">
                                    <i class="fa-solid fa-plus me-1"></i> ขอเบิกคลังกลาง
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-light border text-muted w-100" 
                                        onclick="Swal.fire({icon: 'warning', title: 'คลังกลางปิดรับเบิกชั่วคราว', text: '<?= addslashes(h($closed_reason)) ?>'})"
                                        title="คลังกลางปิดรับเบิกชั่วคราว">
                                    <i class="fa-solid fa-lock me-1 text-warning"></i> คลังปิดรับเบิก
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-1 mb-1">
                            <!-- ขอยืมจากหน่วยอื่น -->
                            <button type="button" class="btn btn-sm btn-light border text-muted w-100" 
                                    onclick="openBorrowModal(<?= $m['material_id'] ?>, '<?= addslashes(h($m['material_name'])) ?>', '<?= h($m['material_code']) ?>')">
                                <i class="fa-solid fa-handshake me-1 text-primary"></i> ขอยืมจากหน่วยอื่น
                            </button>
                        </div>
                        <!-- ประวัติ เข้า-ออก วันที่และเวลา -->
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-info w-100" 
                                    onclick="openMovementsModal(<?= $m['material_id'] ?>, '<?= h($m['material_code']) ?>', '<?= addslashes(h($m['material_name'])) ?>', '<?= addslashes(h($m['category'])) ?>', <?= $qty ?>, '<?= h($m['unit_unit']) ?>')" 
                                    title="ดูประวัติวันและเวลาการนำเข้าและเบิกออกของห้องนี้">
                                <i class="fa-solid fa-clock-rotate-left me-1"></i> ประวัติ เข้า-ออก
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Section: Tabs for Requisitions History, Usages Log, and Inter-unit Borrows -->
    <div class="card p-4 rounded-4 border shadow-sm bg-white" id="history_tabs">
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#requisitions_tab" type="button">
                    <i class="fa-solid fa-file-invoice me-1 text-primary"></i> ใบขอเบิกคลังกลางของหน่วยเรา (<?= count($my_requisitions) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#usages_tab" type="button">
                    <i class="fa-solid fa-clipboard-check me-1 text-success"></i> ประวัติการเบิกใช้ภายในห้อง (<?= count($my_usages) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#borrow_tabs" type="button">
                    <i class="fa-solid fa-handshake me-1 text-warning"></i> ประวัติยืมจากหน่วยอื่น (<?= count($my_borrows) ?>)
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- Tab 1: Requisitions History (แยกสถานะ จ่ายแล้ว กับ ยังไม่จ่าย ออกจากกัน) -->
            <div class="tab-pane fade show active" id="requisitions_tab">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <div>
                        <h5 class="fw-bold mb-1 text-dark">ใบขอเบิกพัสดุจากคลังกลางของหน่วยเรา</h5>
                        <p class="text-muted small mb-0">แยกการแสดงผลอย่างชัดเจน ระหว่างรายการที่รอคลังกลางจ่ายของ และรายการที่ได้รับของเข้าห้องแล้ว</p>
                    </div>
                    <button class="btn btn-sm btn-success rounded-3 shadow-sm" onclick="openCartModal()">
                        <i class="fa-solid fa-plus me-1"></i> ทำใบขอเบิกใหม่
                    </button>
                </div>

                <!-- Sub-nav Tabs แยกสถานะ -->
                <ul class="nav nav-pills mb-3 border-bottom pb-2 gap-2" id="unitReqSubTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active rounded-pill px-3 py-1 fw-semibold small" id="unit-pending-btn" data-bs-toggle="pill" data-bs-target="#unit-sub-pending" type="button">
                            <i class="fa-solid fa-clock text-warning me-1"></i> รอคลังกลางจ่ายของ
                            <span class="badge bg-warning text-dark rounded-pill ms-1"><?= count($my_pending_reqs) ?></span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link rounded-pill px-3 py-1 fw-semibold small" id="unit-completed-btn" data-bs-toggle="pill" data-bs-target="#unit-sub-completed" type="button">
                            <i class="fa-solid fa-circle-check text-success me-1"></i> ได้รับของเข้าห้องแล้ว
                            <span class="badge bg-success rounded-pill ms-1"><?= count($my_completed_reqs) ?></span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link rounded-pill px-3 py-1 fw-semibold small" id="unit-rejected-btn" data-bs-toggle="pill" data-bs-target="#unit-sub-rejected" type="button">
                            <i class="fa-solid fa-ban text-danger me-1"></i> ไม่อนุมัติ
                            <span class="badge bg-danger rounded-pill ms-1"><?= count($my_rejected_reqs) ?></span>
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="unitReqSubTabsContent">
                    <!-- Sub 1: รอคลังกลางจ่ายของ -->
                    <div class="tab-pane fade show active" id="unit-sub-pending">
                        <?php if (empty($my_pending_reqs)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fa-solid fa-circle-check fa-2x text-success opacity-50 mb-2"></i>
                                <p class="mb-0">ไม่มีใบขอเบิกที่ค้างรอคลังกลางจ่าย</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 140px;">เลขที่ใบเบิก</th>
                                            <th>วันที่ส่งขอ</th>
                                            <th>ผู้ทำรายการ</th>
                                            <th class="text-center">จำนวนรายการ</th>
                                            <th class="text-center">จำนวนรวม</th>
                                            <th class="text-center">สถานะ</th>
                                            <th class="text-center" style="width: 200px;">การดำเนินการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($my_pending_reqs as $mr): ?>
                                            <tr>
                                                <td><span class="badge bg-dark font-monospace fs-6"><?= h($mr['requisition_no']) ?></span></td>
                                                <td><small><?= thaiDate($mr['request_date']) ?></small></td>
                                                <td><small class="text-muted"><?= h($mr['requester_name']) ?></small></td>
                                                <td class="text-center fw-bold"><?= number_format($mr['total_items']) ?> รายการ</td>
                                                <td class="text-center fw-bold text-teal" style="color: var(--mhk-primary);"><?= number_format($mr['total_qty_req']) ?></td>
                                                <td class="text-center"><span class="badge bg-warning text-dark"><i class="fa-solid fa-clock me-1"></i> รอคลังกลางจ่าย</span></td>
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-dark" onclick="openReqQr('<?= h($mr['requisition_no']) ?>')" title="แสดง QR Code นำไปให้คลังสแกนจ่าย">
                                                            <i class="fa-solid fa-qrcode me-1"></i> QR
                                                        </button>
                                                        <a href="<?= BASE_URL ?>/report_pdf.php?type=requisition&req_id=<?= $mr['requisition_id'] ?>" target="_blank" class="btn btn-outline-danger" title="พิมพ์ใบเบิก (PDF)">
                                                            <i class="fa-solid fa-file-pdf me-1"></i> พิมพ์
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Sub 2: ได้รับของเข้าห้องแล้ว -->
                    <div class="tab-pane fade" id="unit-sub-completed">
                        <?php if (empty($my_completed_reqs)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fa-regular fa-folder-open fa-2x text-secondary opacity-50 mb-2"></i>
                                <p class="mb-0">ยังไม่มีรายการที่คลังกลางจ่ายของให้</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 140px;">เลขที่ใบเบิก</th>
                                            <th>วันที่เบิกขอ</th>
                                            <th>ผู้อนุมัติจ่าย / วันที่ได้รับ</th>
                                            <th class="text-center">จำนวนรายการ</th>
                                            <th class="text-center">จำนวนรวม</th>
                                            <th class="text-center">สถานะ</th>
                                            <th class="text-center" style="width: 160px;">การดำเนินการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($my_completed_reqs as $mr): ?>
                                            <tr>
                                                <td><span class="badge bg-secondary font-monospace fs-6"><?= h($mr['requisition_no']) ?></span></td>
                                                <td><small><?= thaiDate($mr['request_date']) ?></small></td>
                                                <td>
                                                    <div class="fw-semibold text-dark"><i class="fa-solid fa-user-check me-1 text-success"></i> <?= h($mr['approver_name'] ?? 'คลังกลาง') ?></div>
                                                    <small class="text-muted"><?= thaiDate($mr['approved_date'] ?? $mr['request_date']) ?></small>
                                                </td>
                                                <td class="text-center fw-bold"><?= number_format($mr['total_items']) ?> รายการ</td>
                                                <td class="text-center fw-bold text-success"><?= number_format($mr['total_qty_req']) ?></td>
                                                <td class="text-center"><span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i> ได้รับของแล้ว</span></td>
                                                <td class="text-center">
                                                    <a href="<?= BASE_URL ?>/report_pdf.php?type=requisition&req_id=<?= $mr['requisition_id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="พิมพ์ใบเบิก (PDF)">
                                                        <i class="fa-solid fa-file-pdf me-1"></i> พิมพ์ใบเบิก
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Sub 3: ไม่อนุมัติ -->
                    <div class="tab-pane fade" id="unit-sub-rejected">
                        <?php if (empty($my_rejected_reqs)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fa-regular fa-circle-check fa-2x text-secondary opacity-50 mb-2"></i>
                                <p class="mb-0">ไม่มีรายการใบเบิกที่ถูกไม่อนุมัติ</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 140px;">เลขที่ใบเบิก</th>
                                            <th>วันที่ส่งขอ</th>
                                            <th>ผู้ทำรายการ</th>
                                            <th class="text-center">จำนวนรายการ</th>
                                            <th class="text-center">สถานะ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($my_rejected_reqs as $mr): ?>
                                            <tr>
                                                <td><span class="badge bg-dark font-monospace fs-6"><?= h($mr['requisition_no']) ?></span></td>
                                                <td><small><?= thaiDate($mr['request_date']) ?></small></td>
                                                <td><small class="text-muted"><?= h($mr['requester_name']) ?></small></td>
                                                <td class="text-center fw-bold"><?= number_format($mr['total_items']) ?> รายการ</td>
                                                <td class="text-center"><span class="badge bg-danger"><i class="fa-solid fa-ban me-1"></i> ไม่อนุมัติ</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Usages Log -->
            <div class="tab-pane fade" id="usages_tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark">บันทึกประวัติการนำพัสดุในห้องออกไปใช้งานกับผู้ป่วย/หัตถการ</h5>
                </div>

                <?php if (empty($my_usages)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fa-solid fa-notes-medical fa-3x mb-2 text-secondary opacity-50"></i>
                        <p>ยังไม่มีบันทึกการนำพัสดุออกไปใช้งาน</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 130px;">รหัสพัสดุ</th>
                                    <th>รายการพัสดุที่ใช้</th>
                                    <th class="text-center" style="width: 120px;">จำนวนที่ใช้</th>
                                    <th>ผู้เบิกใช้งาน</th>
                                    <th>ผู้ป่วย / เตียง / วัตถุประสงค์</th>
                                    <th style="width: 160px;">เวลาที่เบิกใช้</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($my_usages as $u): ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark border font-monospace"><?= h($u['material_code']) ?></span></td>
                                        <td><span class="fw-semibold text-dark"><?= h($u['material_name']) ?></span></td>
                                        <td class="text-center fw-bold text-danger"><?= number_format($u['quantity']) ?> <?= h($u['unit_unit']) ?></td>
                                        <td><span class="small text-muted"><?= h($u['used_by']) ?></span></td>
                                        <td><span class="small text-secondary"><?= h($u['patient_or_purpose'] ?? '-') ?></span></td>
                                        <td><small class="text-muted"><?= thaiDate($u['used_at']) ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tab 3: Inter-unit Borrows -->
            <div class="tab-pane fade" id="borrow_tabs">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>รหัสยืม</th>
                                <th>พัสดุ</th>
                                <th>หน่วยงานผู้ให้ยืม</th>
                                <th class="text-center">จำนวน</th>
                                <th>วันที่ยืม</th>
                                <th class="text-center">สถานะ</th>
                                <th class="text-center">การดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($my_borrows as $b): ?>
                                <tr>
                                    <td class="font-monospace fw-bold">#BR-<?= str_pad($b['borrow_id'], 4, '0', STR_PAD_LEFT) ?></td>
                                    <td><strong class="text-dark"><?= h($b['material_name']) ?></strong></td>
                                    <td><span class="badge bg-light text-dark border"><?= h($b['lender_name']) ?></span></td>
                                    <td class="text-center fw-bold"><?= number_format($b['quantity']) ?> <?= h($b['unit_unit']) ?></td>
                                    <td><small><?= thaiDate($b['borrow_date']) ?></small></td>
                                    <td class="text-center">
                                        <span class="badge bg-info text-dark"><?= h($b['status']) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($b['status'] === 'approved'): ?>
                                            <form method="POST" action="borrow_action.php" onsubmit="return confirm('ยืนยันส่งคืนพัสดุ?');">
                                                <input type="hidden" name="action" value="return_item">
                                                <input type="hidden" name="borrow_id" value="<?= $b['borrow_id'] ?>">
                                                <input type="hidden" name="current_unit_id" value="<?= $current_unit_id ?>">
                                                <button type="submit" class="btn btn-sm btn-success rounded-3"><i class="fa-solid fa-rotate-left me-1"></i> ส่งคืน</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Floating Cart Button -->
<button class="btn btn-success btn-lg floating-cart-btn" id="floatingCartBtn" onclick="openCartModal()">
    <i class="fa-solid fa-cart-shopping me-2"></i> ใบขอเบิกคลังกลาง (<span id="floatingCartCount">0</span> รายการ)
</button>

<!-- Floating Multi-Use Cart Button -->
<button class="btn btn-danger btn-lg floating-use-btn" id="floatingUseBtn" onclick="openMultiUseModal()">
    <i class="fa-solid fa-hand-holding-medical me-2"></i> เบิกใช้ในห้อง (<span id="floatingUseCount">0</span> รายการ)
</button>

<!-- ==================== MODALS ==================== -->

<!-- Modal: ตะกร้าขอเบิกพัสดุหลายรายการ (Multi-item Requisition Cart Modal) -->
<div class="modal fade" id="requisitionCartModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-teal text-white" style="background-color: var(--mhk-primary);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-file-circle-plus me-2"></i> เอกสารขอเบิกพัสดุจากคลังกลาง (Multi-item Order)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="borrow_action.php">
                <input type="hidden" name="action" value="create_requisition">
                <input type="hidden" name="unit_id" value="<?= $current_unit_id ?>">

                <div class="modal-body p-4">
                    <?php if (!$req_enabled): ?>
                        <div class="alert alert-warning border-0 shadow-sm rounded-3 mb-3 d-flex align-items-center gap-2">
                            <i class="fa-solid fa-lock fa-lg text-warning"></i>
                            <div>
                                <strong>คลังกลางปิดรับใบเบิกชั่วคราว:</strong> <?= h($closed_reason) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted small mb-3">
                        หน่วยงาน <strong><?= h($current_unit['unit_name']) ?></strong> กำลังทำเรื่องขอเบิกวัสดุจากคลังกลาง สามารถระบุจำนวนที่ต้องการได้ในแต่ละรายการ
                    </p>

                    <div class="table-responsive mb-3">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>พัสดุ / เวชภัณฑ์</th>
                                    <th class="text-center" style="width: 130px;">คลังกลางมี</th>
                                    <th class="text-center" style="width: 140px;">จำนวนที่ขอเบิก</th>
                                    <th class="text-center" style="width: 60px;">ลบ</th>
                                </tr>
                            </thead>
                            <tbody id="cartItemsTableBody">
                                <!-- Populated dynamically by JS -->
                            </tbody>
                        </table>
                    </div>

                    <div id="emptyCartNotice" class="text-center py-4 text-muted" style="display: none;">
                        <i class="fa-solid fa-cart-arrow-down fa-3x mb-2 text-secondary opacity-50"></i>
                        <p>ยังไม่มีรายการพัสดุในใบเบิก กรุณากดปุ่ม <strong>"+ ขอเบิกคลังกลาง"</strong> บนการ์ดพัสดุที่ต้องการ</p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">หมายเหตุ / วัตถุประสงค์ความต้องการเบิก</label>
                        <textarea name="note" class="form-control rounded-3" rows="2" placeholder="เช่น ขอเบิกสำรองใช้ประจำสัปดาห์ หรือเตรียมรับเคสฉุกเฉิน"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ปิด</button>
                    <button type="submit" class="btn btn-success rounded-3 px-4 shadow-sm" id="btnSubmitRequisitionOrder" <?= !$req_enabled ? 'disabled' : '' ?>>
                        <i class="fa-solid fa-paper-plane me-1"></i> ยืนยันส่งใบขอเบิกไปยังคลังกลาง
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: เบิกใช้พัสดุในห้องหลายรายการพร้อมกัน (Multi-item Unit Internal Dispense) -->
<div class="modal fade" id="multiUseModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-hand-holding-medical me-2"></i> บันทึกเบิกใช้พัสดุในห้อง (หลายรายการพร้อมกัน)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="borrow_action.php">
                <input type="hidden" name="action" value="batch_internal_usage">
                <input type="hidden" name="current_unit_id" value="<?= $current_unit_id ?>">

                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">
                        ตัดสต็อกพัสดุออกจากห้องประจำหน่วย <strong><?= h($current_unit['unit_name']) ?></strong> พร้อมกันหลายรายการ สำหรับผู้ป่วยหรือหัตถการเดียวกัน
                    </p>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">ชื่อเจ้าหน้าที่ผู้เบิกใช้ <span class="text-danger">*</span></label>
                            <input type="text" name="used_by" class="form-control rounded-3" value="<?= h($_SESSION['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">ผู้ป่วย / เตียง / หัตถการที่นำไปใช้</label>
                            <input type="text" name="patient_or_purpose" class="form-control rounded-3" placeholder="เช่น ผู้ป่วยเตียง 4, หัตถการล้างแผลฉุกเฉิน, HN: 6500123">
                        </div>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>พัสดุ / เวชภัณฑ์</th>
                                    <th class="text-center" style="width: 130px;">คงเหลือในห้อง</th>
                                    <th class="text-center" style="width: 140px;">จำนวนที่เบิกใช้</th>
                                    <th class="text-center" style="width: 60px;">ลบ</th>
                                </tr>
                            </thead>
                            <tbody id="multiUseTableBody">
                                <!-- Populated dynamically by JS -->
                            </tbody>
                        </table>
                    </div>

                    <div id="emptyMultiUseNotice" class="text-center py-4 text-muted" style="display: none;">
                        <i class="fa-solid fa-box-open fa-3x mb-2 text-danger opacity-50"></i>
                        <p>ยังไม่มีรายการพัสดุที่เลือกเบิกใช้ กรุณากดปุ่ม <strong>"+ รวมใช้"</strong> บนการ์ดพัสดุที่ต้องการ</p>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ปิด</button>
                    <button type="submit" class="btn btn-danger rounded-3 px-4 shadow-sm" id="btnSubmitMultiUse">
                        <i class="fa-solid fa-check me-1"></i> ยืนยันตัดยอดการใช้ทั้งหมด
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: เบิกใช้ในห้องตนเอง (Unit Internal Dispense) -->
<div class="modal fade" id="useInternalModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-hand-holding-medical me-2"></i> บันทึกนำพัสดุออกจากห้องไปใช้งาน</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="borrow_action.php">
                <input type="hidden" name="action" value="use_internal_material">
                <input type="hidden" name="material_id" id="internal_mat_id">
                <input type="hidden" name="current_unit_id" value="<?= $current_unit_id ?>">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="text-muted small">รายการพัสดุที่นำไปใช้</label>
                        <h5 class="fw-bold text-dark mb-1" id="internal_mat_name"></h5>
                        <p class="small text-muted mb-0">คงเหลือในห้องปัจจุบัน: <span class="badge bg-secondary fs-6" id="internal_current_stock"></span></p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">จำนวนที่นำไปใช้ <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" id="internal_quantity" class="form-control form-control-lg rounded-3" value="1" min="1" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">ชื่อเจ้าหน้าที่ผู้เบิกใช้</label>
                        <input type="text" name="used_by" class="form-control rounded-3" value="<?= h($_SESSION['full_name']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">ผู้ป่วย / เตียง / หัตถการที่นำไปใช้</label>
                        <input type="text" name="patient_or_purpose" class="form-control rounded-3" placeholder="เช่น ผู้ป่วยเตียง 4, หัตถการล้างแผลฉุกเฉิน">
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-danger rounded-3 px-4">บันทึกตัดยอดการใช้</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: QR Code ใบเบิกพัสดุ -->
<div class="modal fade" id="unitReqQrModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4 text-center">
            <div class="modal-header border-0 pb-0">
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-0 pb-4">
                <div class="border p-4 rounded-4 bg-light d-inline-block w-100">
                    <h6 class="fw-bold mb-1 text-teal" style="color: var(--mhk-primary);">QR Code ใบขอเบิกพัสดุ</h6>
                    <small class="text-muted mb-3 d-block">ยื่นให้เจ้าหน้าที่คลังกลางสแกนเพื่อตรวจจ่ายของ</small>
                    <div id="unitReqQrCanvas" class="qr-preview-box mb-3 mx-auto p-2 bg-white rounded-3 shadow-sm d-inline-block"></div>
                    <h4 class="fw-bold text-dark font-monospace" id="unitReqQrNo"></h4>
                </div>
            </div>
            <div class="modal-footer border-0 justify-content-center bg-light rounded-bottom-4">
                <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: ขอยืมจากหน่วยอื่น -->
<div class="modal fade" id="borrowModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-handshake me-2"></i> ขอยืมพัสดุจากหน่วยงานอื่น</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="borrow_action.php">
                <input type="hidden" name="action" value="request_borrow">
                <input type="hidden" name="material_id" id="borrow_material_id">
                <input type="hidden" name="current_unit_id" value="<?= $current_unit_id ?>">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="text-muted small">ชื่อพัสดุที่ต้องการยืม</label>
                        <h5 class="fw-bold text-dark mb-1" id="borrow_material_name"></h5>
                        <div class="badge bg-light text-secondary border font-monospace" id="borrow_material_code"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">เลือกหน่วยงานที่ต้องการขอยืม <span class="text-danger">*</span></label>
                        <select name="lender_unit_id" class="form-select rounded-3" required>
                            <option value="">-- กรุณาเลือกหน่วยงาน --</option>
                            <?php foreach ($other_units as $ou): ?>
                                <option value="<?= $ou['unit_id'] ?>"><?= h($ou['unit_name']) ?> (<?= h($ou['unit_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">จำนวนที่ขอยืม <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" class="form-control rounded-3" value="1" min="1" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">กำหนดส่งคืน</label>
                            <input type="date" name="due_date" class="form-control rounded-3" value="<?= date('Y-m-d', strtotime('+3 days')) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">หมายเหตุ / ความจำเป็นเร่งด่วน</label>
                        <input type="text" name="note" class="form-control rounded-3" placeholder="เช่น ขอสำรองเคสด่วน">
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4">ส่งคำขอยืม</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: ดูประวัติวันและเวลาการนำเข้า-จ่ายออกของห้องนี้ (Unit Material In-Out Movements) -->
<div class="modal fade" id="movementsModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">
                    <i class="fa-solid fa-clock-rotate-left me-2 text-info"></i> ประวัติวันและเวลา เข้า-ออก ประจำห้อง: <?= h($current_unit['unit_name']) ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Info Header Box -->
                <div class="p-3 bg-light rounded-4 border mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <span class="badge bg-secondary font-monospace fs-6" id="mov_mat_code"></span>
                            <h4 class="fw-bold text-dark mt-1 mb-1" id="mov_mat_name"></h4>
                            <span class="badge bg-info bg-opacity-10 text-info border" id="mov_mat_cat"></span>
                        </div>
                        <div class="text-end">
                            <span class="text-muted small d-block">ยอดคงเหลือในห้องเรา</span>
                            <h3 class="fw-bold text-teal mb-0" id="mov_current_stock" style="color: var(--mhk-primary);"></h3>
                        </div>
                    </div>
                </div>

                <!-- Movements Table -->
                <div class="table-responsive">
                    <table class="table table-hover align-middle border mb-0" id="modalMovementsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 170px;">วัน-เวลา</th>
                                <th class="text-center" style="width: 130px;">ประเภท</th>
                                <th class="text-center" style="width: 120px;">จำนวน</th>
                                <th class="text-center" style="width: 130px;">คงเหลือในห้อง</th>
                                <th>อ้างอิง / เอกสาร</th>
                                <th>ผู้ทำรายการ / บันทึก (เช่น HN คนไข้)</th>
                            </tr>
                        </thead>
                        <tbody id="movementsTableBody">
                            <!-- Populated via AJAX -->
                        </tbody>
                    </table>
                </div>

                <div id="movementsLoading" class="text-center py-5">
                    <div class="spinner-border text-teal" style="color: var(--mhk-primary);" role="status"></div>
                    <p class="text-muted mt-2 mb-0">กำลังโหลดข้อมูลประวัติการเข้า-ออก...</p>
                </div>

                <div id="movementsEmpty" class="text-center py-5 d-none">
                    <i class="fa-solid fa-box-open fa-3x text-muted opacity-50 mb-2"></i>
                    <h6 class="fw-bold text-muted">ยังไม่มีประวัติการนำเข้าหรือเบิกใช้ของพัสดุนี้ในห้องเรา</h6>
                    <small class="text-muted">เมื่อมีการรับของเข้าห้องจากการขอเบิกคลัง หรือมีการเบิกใช้กับผู้ป่วย ระบบจะบันทึกวันและเวลาที่นี่โดยอัตโนมัติ</small>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-secondary rounded-3 px-4" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // ==================== REQUISITION CART LOGIC ====================
    let requisitionCart = {};

    function addToRequisitionCart(id, name, code, unit, centralStock) {
        if (!requisitionCart[id]) {
            requisitionCart[id] = { id, name, code, unit, centralStock, qty: 10 };
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: `เพิ่ม [${name}] ลงใบเบิกแล้ว`,
                showConfirmButton: false,
                timer: 1800
            });
        } else {
            requisitionCart[id].qty += 5;
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'info',
                title: `เพิ่มจำนวน [${name}] เป็น ${requisitionCart[id].qty} ${unit}`,
                showConfirmButton: false,
                timer: 1800
            });
        }
        updateCartUi();
    }

    function removeFromCart(id) {
        delete requisitionCart[id];
        updateCartUi();
        renderCartItems();
    }

    function updateCartUi() {
        const count = Object.keys(requisitionCart).length;
        document.getElementById('cartCountBadge').innerText = count;
        document.getElementById('floatingCartCount').innerText = count;

        const floatingBtn = document.getElementById('floatingCartBtn');
        if (count > 0) {
            floatingBtn.style.display = 'block';
        } else {
            floatingBtn.style.display = 'none';
        }

        const mobileBadge = document.getElementById('mobileBottomReqBadge');
        if (mobileBadge) {
            mobileBadge.innerText = count;
            if (count > 0) {
                mobileBadge.classList.remove('d-none');
            } else {
                mobileBadge.classList.add('d-none');
            }
        }
    }

    function renderCartItems() {
        const tbody = document.getElementById('cartItemsTableBody');
        const emptyNotice = document.getElementById('emptyCartNotice');
        const submitBtn = document.getElementById('btnSubmitRequisitionOrder');

        tbody.innerHTML = '';
        const keys = Object.keys(requisitionCart);

        if (keys.length === 0) {
            emptyNotice.style.display = 'block';
            submitBtn.disabled = true;
            return;
        }

        emptyNotice.style.display = 'none';
        submitBtn.disabled = false;

        keys.forEach(id => {
            const item = requisitionCart[id];
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <div class="fw-bold text-dark">${item.name}</div>
                    <span class="badge bg-light text-secondary border font-monospace">${item.code}</span>
                </td>
                <td class="text-center fw-bold text-success">${item.centralStock} ${item.unit}</td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="number" name="items[${item.id}]" class="form-control text-center fw-bold fs-6" 
                               value="${item.qty}" min="1" onchange="requisitionCart[${item.id}].qty = parseInt(this.value) || 1" required>
                        <span class="input-group-text">${item.unit}</span>
                    </div>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="removeFromCart(${item.id})">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    function openCartModal() {
        renderCartItems();
        new bootstrap.Modal(document.getElementById('requisitionCartModal')).show();
    }

    // ==================== MULTI-ITEM ROOM USAGE LOGIC ====================
    let multiUseCart = {};

    function addToMultiUse(id, name, code, unit, roomStock) {
        if (roomStock <= 0) {
            Swal.fire({
                icon: 'warning',
                title: 'ไม่สามารถเบิกได้',
                text: `พัสดุ [${name}] ในห้องหมดสต็อกแล้ว`,
                timer: 2000
            });
            return;
        }

        if (!multiUseCart[id]) {
            multiUseCart[id] = { id, name, code, unit, roomStock, qty: 1 };
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: `เพิ่ม [${name}] ใส่ชุดเบิกใช้ในห้องแล้ว`,
                showConfirmButton: false,
                timer: 1800
            });
        } else {
            if (multiUseCart[id].qty < roomStock) {
                multiUseCart[id].qty += 1;
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'info',
                    title: `เพิ่มจำนวน [${name}] เป็น ${multiUseCart[id].qty} ${unit}`,
                    showConfirmButton: false,
                    timer: 1800
                });
            } else {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'warning',
                    title: `จำนวนถึงขีดจำกัดคงเหลือในห้องแล้ว (${roomStock} ${unit})`,
                    showConfirmButton: false,
                    timer: 2000
                });
            }
        }
        updateMultiUseUi();
    }

    function removeFromMultiUse(id) {
        delete multiUseCart[id];
        updateMultiUseUi();
        renderMultiUseItems();
    }

    function updateMultiUseUi() {
        const count = Object.keys(multiUseCart).length;
        const countBadge = document.getElementById('multiUseCountBadge');
        if (countBadge) countBadge.innerText = count;

        const floatingCount = document.getElementById('floatingUseCount');
        if (floatingCount) floatingCount.innerText = count;

        const floatingBtn = document.getElementById('floatingUseBtn');
        if (floatingBtn) {
            floatingBtn.style.display = count > 0 ? 'block' : 'none';
        }

        const mobileUseBadge = document.getElementById('mobileBottomUseBadge');
        if (mobileUseBadge) {
            mobileUseBadge.innerText = count;
            if (count > 0) {
                mobileUseBadge.classList.remove('d-none');
            } else {
                mobileUseBadge.classList.add('d-none');
            }
        }
    }

    function renderMultiUseItems() {
        const tbody = document.getElementById('multiUseTableBody');
        const emptyNotice = document.getElementById('emptyMultiUseNotice');
        const submitBtn = document.getElementById('btnSubmitMultiUse');

        tbody.innerHTML = '';
        const keys = Object.keys(multiUseCart);

        if (keys.length === 0) {
            emptyNotice.style.display = 'block';
            submitBtn.disabled = true;
            return;
        }

        emptyNotice.style.display = 'none';
        submitBtn.disabled = false;

        keys.forEach(id => {
            const item = multiUseCart[id];
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <div class="fw-bold text-dark">${item.name}</div>
                    <span class="badge bg-light text-secondary border font-monospace">${item.code}</span>
                </td>
                <td class="text-center fw-bold text-primary">${item.roomStock} ${item.unit}</td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="number" name="items[${item.id}]" class="form-control text-center fw-bold fs-6" 
                               value="${item.qty}" min="1" max="${item.roomStock}" 
                               onchange="let val = parseInt(this.value) || 1; if(val > ${item.roomStock}) val = ${item.roomStock}; if(val < 1) val = 1; this.value = val; multiUseCart[${item.id}].qty = val;" required>
                        <span class="input-group-text">${item.unit}</span>
                    </div>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="removeFromMultiUse(${item.id})">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    function openMultiUseModal() {
        renderMultiUseItems();
        new bootstrap.Modal(document.getElementById('multiUseModal')).show();
    }

    // Modal เบิกใช้ในห้อง
    function openUseModal(id, name, currentStock, unit) {
        document.getElementById('internal_mat_id').value = id;
        document.getElementById('internal_mat_name').innerText = name;
        document.getElementById('internal_current_stock').innerText = currentStock + ' ' + unit;
        document.getElementById('internal_quantity').max = currentStock;
        new bootstrap.Modal(document.getElementById('useInternalModal')).show();
    }

    // Modal ขอยืม
    function openBorrowModal(id, name, code) {
        document.getElementById('borrow_material_id').value = id;
        document.getElementById('borrow_material_name').innerText = name;
        document.getElementById('borrow_material_code').innerText = code;
        new bootstrap.Modal(document.getElementById('borrowModal')).show();
    }

    // QR Code ใบเบิก
    function openReqQr(reqNo) {
        document.getElementById('unitReqQrNo').innerText = reqNo;
        const container = document.getElementById('unitReqQrCanvas');
        container.innerHTML = '';
        new QRCode(container, { text: reqNo, width: 180, height: 180, colorDark: "#0f766e", colorLight: "#ffffff" });
        new bootstrap.Modal(document.getElementById('unitReqQrModal')).show();
    }

    // ค้นหาการ์ด Real-time
    const searchInput = document.getElementById('cardSearchInput');
    const catFilter = document.getElementById('cardCategoryFilter');

    function filterCards() {
        const q = searchInput.value.toLowerCase().trim();
        const cat = catFilter.value;
        document.querySelectorAll('.material-card-item').forEach(card => {
            const name = card.getAttribute('data-name');
            const code = card.getAttribute('data-code');
            const c = card.getAttribute('data-category');

            const matchQ = !q || name.includes(q) || code.includes(q);
            const matchCat = !cat || c === cat;
            card.style.display = (matchQ && matchCat) ? '' : 'none';
        });
    }

    searchInput.addEventListener('input', filterCards);
    catFilter.addEventListener('change', filterCards);

    // Modal ประวัติการเข้า-ออกของห้องนี้
    function openMovementsModal(id, code, name, category, stock, unit) {
        document.getElementById('mov_mat_code').innerText = code;
        document.getElementById('mov_mat_name').innerText = name;
        document.getElementById('mov_mat_cat').innerText = category;
        document.getElementById('mov_current_stock').innerText = `${stock} ${unit}`;
        
        const tbody = document.getElementById('movementsTableBody');
        tbody.innerHTML = '';
        document.getElementById('movementsLoading').classList.remove('d-none');
        document.getElementById('movementsEmpty').classList.add('d-none');
        document.getElementById('modalMovementsTable').classList.remove('d-none');

        const modal = new bootstrap.Modal(document.getElementById('movementsModal'));
        modal.show();

        fetch(`<?= BASE_URL ?>/api_movements.php?material_id=${id}&unit_id=<?= $current_unit_id ?>`)
            .then(res => res.json())
            .then(data => {
                document.getElementById('movementsLoading').classList.add('d-none');
                if (!data.success || !data.movements || data.movements.length === 0) {
                    document.getElementById('movementsEmpty').classList.remove('d-none');
                    document.getElementById('modalMovementsTable').classList.add('d-none');
                    return;
                }

                data.movements.forEach(m => {
                    const tr = document.createElement('tr');
                    const isIn = m.movement_type === 'in';
                    const badgeType = isIn 
                        ? '<span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fa-solid fa-arrow-down-left me-1"></i> รับเข้าห้อง (+)</span>'
                        : '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="fa-solid fa-arrow-up-right me-1"></i> เบิกจ่ายออก (-)</span>';
                    const qtyText = isIn 
                        ? `<span class="fw-bold text-success">+${m.quantity} ${unit}</span>` 
                        : `<span class="fw-bold text-danger">-${m.quantity} ${unit}</span>`;
                    const balText = m.balance_after !== null ? `${m.balance_after} ${unit}` : '-';

                    tr.innerHTML = `
                        <td><small class="fw-semibold text-dark">${m.created_at}</small></td>
                        <td class="text-center">${badgeType}</td>
                        <td class="text-center">${qtyText}</td>
                        <td class="text-center text-muted fw-semibold">${balText}</td>
                        <td><span class="badge bg-dark font-monospace">${m.reference_id || m.reference_type}</span></td>
                        <td>
                            <div class="small text-dark">${m.description}</div>
                            <small class="text-muted"><i class="fa-solid fa-user me-1"></i> ${m.performed_by}</small>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            })
            .catch(err => {
                document.getElementById('movementsLoading').classList.add('d-none');
                document.getElementById('movementsEmpty').classList.remove('d-none');
                document.getElementById('modalMovementsTable').classList.add('d-none');
            });
    }
</script>

<!-- Mobile Drawer Menu (Offcanvas) สำหรับโทรศัพท์ -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="unitMobileMenu" aria-labelledby="unitMobileMenuLabel">
    <div class="offcanvas-header bg-light border-bottom">
        <div class="d-flex align-items-center">
            <span class="rounded-circle p-2 me-2 d-inline-flex align-items-center justify-content-center text-white" style="background-color: var(--mhk-primary); width: 38px; height: 38px;">
                <i class="fa-solid fa-hospital-user"></i>
            </span>
            <div>
                <h6 class="offcanvas-title fw-bold mb-0 text-dark" id="unitMobileMenuLabel"><?= h($current_unit['unit_name']) ?></h6>
                <small class="text-muted"><i class="fa-solid fa-user me-1"></i><?= h($_SESSION['fullname']) ?> (<?= h($_SESSION['role']) ?>)</small>
            </div>
        </div>
        <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0">
        <div class="list-group list-group-flush">
            <a href="dashboard.php<?= isset($_GET['unit_id']) ? '?unit_id='.$current_unit_id : '' ?>" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center" data-bs-dismiss="offcanvas">
                <i class="fa-solid fa-boxes-stacked me-3 fa-lg" style="color: var(--mhk-primary); width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">พัสดุในห้อง (Card View)</div>
                    <small class="text-muted">ดูรายการวัสดุและสต็อกคงเหลือในห้อง</small>
                </div>
            </a>
            <a href="<?= BASE_URL ?>/unit/scan.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center">
                <i class="fa-solid fa-qrcode me-3 text-warning fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">สแกน QR เบิก / ยืมด่วน</div>
                    <small class="text-muted">ใช้กล้องมือถือสแกนคิวอาร์โค้ดพัสดุ</small>
                </div>
            </a>
            <a href="#requisitions_tab" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center" data-bs-dismiss="offcanvas">
                <i class="fa-solid fa-file-invoice me-3 text-success fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">ใบขอเบิกคลังกลาง</div>
                    <small class="text-muted">ติดตามสถานะใบขอเบิกพัสดุ</small>
                </div>
            </a>
            <a href="#usages_tab" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center" data-bs-dismiss="offcanvas">
                <i class="fa-solid fa-clipboard-check me-3 text-danger fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">ประวัติการเบิกใช้ในห้อง</div>
                    <small class="text-muted">บันทึกการนำวัสดุไปใช้งานของหน่วย</small>
                </div>
            </a>
            <a href="#borrows_tab" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center" data-bs-dismiss="offcanvas">
                <i class="fa-solid fa-handshake me-3 text-info fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">การยืม-คืนระหว่างหน่วย</div>
                    <small class="text-muted">ตรวจสอบสถานะการยืมพัสดุ</small>
                </div>
            </a>
            <a href="<?= BASE_URL ?>/report_pdf.php?type=unit&unit_id=<?= $current_unit_id ?>" target="_blank" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center">
                <i class="fa-solid fa-file-pdf me-3 text-danger fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">พิมพ์รายงานประจำห้อง (PDF)</div>
                    <small class="text-muted">รายงานสรุปคลังวัสดุและยอดเบิกจ่าย</small>
                </div>
            </a>
            <?php if ($_SESSION['role'] === 'super_admin'): ?>
                <a href="<?= BASE_URL ?>/admin/dashboard.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center bg-light">
                    <i class="fa-solid fa-warehouse me-3 text-primary fa-lg" style="width: 24px;"></i>
                    <div>
                        <div class="fw-semibold text-primary">กลับคลังใหญ่ Super Admin</div>
                        <small class="text-muted">ไปที่แดชบอร์ดหลักของคลังกลาง</small>
                    </div>
                </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/logout.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center text-danger">
                <i class="fa-solid fa-arrow-right-from-bracket me-3 text-danger fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-danger">ออกจากระบบ</div>
                    <small class="text-muted">สิ้นสุดการทำงานและออกจากระบบ</small>
                </div>
            </a>
        </div>
    </div>
</div>

</body>
</html>
