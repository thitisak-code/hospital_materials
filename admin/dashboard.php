<?php
/**
 * แดชบอร์ดคลังใหญ่สำหรับ Super Admin (ฉบับอัปเกรด)
 * จัดการพัสดุ, ใบขอเบิกหลายรายการ (Multi-item), ตรวจรับผ่านแอป/สแกน QR, จัดการหน่วยงาน และหมวดหมู่
 * ระบบจัดการวัสดุ โรงพยาบาลมหาสารคาม
 */
require_once __DIR__ . '/../config.php';
checkAuth('super_admin');

$central_unit_id = 1; // คลังพัสดุและเวชภัณฑ์กลาง

// จัดการการส่งฟอร์ม (POST Request Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. เพิ่มรายการวัสดุใหม่เข้าระบบ
    if ($action === 'add_material') {
        $material_code = trim($_POST['material_code'] ?? '');
        $barcode       = trim($_POST['barcode'] ?? '') ?: $material_code;
        $material_name = trim($_POST['material_name'] ?? '');
        $category      = trim($_POST['category'] ?? 'เวชภัณฑ์สิ้นเปลือง');
        $unit_unit     = trim($_POST['unit_unit'] ?? 'ชิ้น');
        $unit_price    = max(0, (float)($_POST['unit_price'] ?? 0));
        $supplier      = trim($_POST['supplier'] ?? 'องค์การเภสัชกรรม (GPO)');
        $lot_no        = trim($_POST['lot_no'] ?? '');
        $exp_date      = !empty($_POST['exp_date']) ? $_POST['exp_date'] : null;
        $min_stock     = max(0, (int)($_POST['min_stock'] ?? 10));
        $description   = trim($_POST['description'] ?? '');
        $initial_stock = max(0, (int)($_POST['initial_stock'] ?? 0));

        if (!empty($material_code) && !empty($material_name)) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO materials (material_code, barcode, material_name, category, unit_unit, unit_price, supplier, lot_no, exp_date, min_stock, description)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$material_code, $barcode, $material_name, $category, $unit_unit, $unit_price, $supplier, $lot_no, $exp_date, $min_stock, $description]);
                $new_material_id = $pdo->lastInsertId();

                // บันทึกสต็อกเริ่มต้นลงในคลังกลาง (Unit ID = 1)
                $stock_stmt = $pdo->prepare("
                    INSERT INTO unit_stocks (unit_id, material_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
                ");
                $stock_stmt->execute([$central_unit_id, $new_material_id, $initial_stock]);

                if ($initial_stock > 0) {
                    logStockMovement($pdo, $new_material_id, $central_unit_id, 'in', $initial_stock, 'initial', null, 'ยอดยกมาเริ่มต้นคลังกลาง', $_SESSION['user_id'], $unit_price, $supplier);
                }

                $pdo->commit();
                setFlash('success', "เพิ่มรายการพัสดุ [{$material_code}] {$material_name} เรียบร้อยแล้ว");
            } catch (\PDOException $e) {
                $pdo->rollBack();
                if ($e->getCode() == 23000) {
                    setFlash('danger', "รหัสพัสดุ '{$material_code}' มีอยู่ในระบบแล้ว กรุณาใช้รหัสอื่น");
                } else {
                    setFlash('danger', "เกิดข้อผิดพลาด: " . $e->getMessage());
                }
            }
        } else {
            setFlash('danger', 'กรุณากรอกรหัสพัสดุและชื่อพัสดุให้ครบถ้วน');
        }
        header("Location: dashboard.php");
        exit;
    }

    // 2. แก้ไขข้อมูลวัสดุ
    if ($action === 'edit_material') {
        $material_id   = (int)($_POST['material_id'] ?? 0);
        $barcode       = trim($_POST['barcode'] ?? '');
        $material_name = trim($_POST['material_name'] ?? '');
        $category      = trim($_POST['category'] ?? '');
        $unit_unit     = trim($_POST['unit_unit'] ?? '');
        $unit_price    = max(0, (float)($_POST['unit_price'] ?? 0));
        $supplier      = trim($_POST['supplier'] ?? '');
        $lot_no        = trim($_POST['lot_no'] ?? '');
        $exp_date      = !empty($_POST['exp_date']) ? $_POST['exp_date'] : null;
        $min_stock     = max(0, (int)($_POST['min_stock'] ?? 10));
        $description   = trim($_POST['description'] ?? '');

        if ($material_id > 0 && !empty($material_name)) {
            $stmt = $pdo->prepare("
                UPDATE materials 
                SET barcode = ?, material_name = ?, category = ?, unit_unit = ?, unit_price = ?, supplier = ?, lot_no = ?, exp_date = ?, min_stock = ?, description = ?
                WHERE material_id = ?
            ");
            $stmt->execute([$barcode, $material_name, $category, $unit_unit, $unit_price, $supplier, $lot_no, $exp_date, $min_stock, $description, $material_id]);
            setFlash('success', "อัปเดตข้อมูลพัสดุเรียบร้อยแล้ว");
        }
        header("Location: dashboard.php");
        exit;
    }

    // 3. ปรับยอดสต็อกคลังกลาง
    if ($action === 'adjust_stock') {
        $material_id   = (int)($_POST['material_id'] ?? 0);
        $adjust_type   = $_POST['adjust_type'] ?? 'add';
        $quantity      = (int)($_POST['quantity'] ?? 0);

        if ($material_id > 0) {
            if ($adjust_type === 'set') {
                $old_stk = $pdo->query("SELECT quantity FROM unit_stocks WHERE unit_id = {$central_unit_id} AND material_id = {$material_id}")->fetchColumn();
                $old_qty = $old_stk !== false ? (int)$old_stk : 0;
                $new_qty = max(0, $quantity);
                $diff = $new_qty - $old_qty;

                $stmt = $pdo->prepare("
                    INSERT INTO unit_stocks (unit_id, material_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = ?
                ");
                $stmt->execute([$central_unit_id, $material_id, $new_qty, $new_qty]);

                if ($diff !== 0) {
                    $mov_type = $diff > 0 ? 'in' : 'out';
                    logStockMovement($pdo, $material_id, $central_unit_id, $mov_type, abs($diff), 'adjustment', null, 'ปรับยอดคงเหลือคลังกลางเป็น ' . $new_qty, $_SESSION['user_id']);
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO unit_stocks (unit_id, material_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = GREATEST(0, quantity + ?)
                ");
                $stmt->execute([$central_unit_id, $material_id, $quantity, $quantity]);

                if ($quantity > 0) {
                    logStockMovement($pdo, $material_id, $central_unit_id, 'in', $quantity, 'adjustment', null, 'เติมสต็อกเข้าคลังกลาง +' . $quantity, $_SESSION['user_id']);
                }
            }
            setFlash('success', "ปรับยอดสต็อกคลังกลางเรียบร้อยแล้ว");
        }
        header("Location: dashboard.php");
        exit;
    }

    // 4. ลบรายการวัสดุ
    if ($action === 'delete_material') {
        $material_id = (int)($_POST['material_id'] ?? 0);
        if ($material_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM materials WHERE material_id = ?");
                $stmt->execute([$material_id]);
                setFlash('success', "ลบรายการพัสดุเรียบร้อยแล้ว");
            } catch (\PDOException $e) {
                setFlash('danger', "ไม่สามารถลบรายการนี้ได้ เนื่องจากมีข้อมูลประวัติการยืม-คืนหรือสต็อกผูกอยู่");
            }
        }
        header("Location: dashboard.php");
        exit;
    }
}

// ดึงหมวดหมู่ทั้งหมดจากตาราง categories
$categories_stmt = $pdo->query("SELECT * FROM categories ORDER BY category_id ASC");
$categories = $categories_stmt->fetchAll();

// ดึงรายชื่อบริษัทผู้จัดจำหน่าย/จัดซื้อ
$suppliers_stmt = $pdo->query("SELECT * FROM suppliers ORDER BY supplier_name ASC");
$suppliers = $suppliers_stmt->fetchAll();

// ดึงข้อมูลสถิติภาพรวมคลังกลาง
$stats = [
    'total_materials' => $pdo->query("SELECT COUNT(*) FROM materials")->fetchColumn(),
    'total_units'     => $pdo->query("SELECT COUNT(*) FROM units")->fetchColumn(),
    'pending_reqs'    => $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'pending'")->fetchColumn(),
    'low_stock_items' => $pdo->query("
        SELECT COUNT(*) FROM materials m 
        LEFT JOIN unit_stocks us ON m.material_id = us.material_id AND us.unit_id = {$central_unit_id}
        WHERE COALESCE(us.quantity, 0) <= m.min_stock
    ")->fetchColumn(),
    'pending_borrows' => $pdo->query("SELECT COUNT(*) FROM item_borrows WHERE status = 'pending'")->fetchColumn(),
];

// ดึงรายการวัสดุและยอดสต็อกในคลังกลาง
$materials_stmt = $pdo->query("
    SELECT m.*, COALESCE(us.quantity, 0) AS central_stock
    FROM materials m
    LEFT JOIN unit_stocks us ON m.material_id = us.material_id AND us.unit_id = {$central_unit_id}
    ORDER BY m.material_id DESC
");
$materials = $materials_stmt->fetchAll();

// ดึงรายการใบขอเบิกพัสดุจากทุกหน่วยงาน (Requisitions)
$reqs_stmt = $pdo->query("
    SELECT r.*, u.unit_name, u.unit_code, usr.full_name AS requester_name,
           u_app.full_name AS approver_name,
           COUNT(ri.item_id) AS total_items,
           SUM(ri.quantity_requested) AS total_quantity_req
    FROM requisitions r
    JOIN units u ON r.unit_id = u.unit_id
    JOIN users usr ON r.user_id = usr.user_id
    LEFT JOIN users u_app ON r.approved_by = u_app.user_id
    LEFT JOIN requisition_items ri ON r.requisition_id = ri.requisition_id
    GROUP BY r.requisition_id
    ORDER BY r.requisition_id DESC
");
$requisitions = $reqs_stmt->fetchAll();

// แยกรายการใบขอเบิก: รอจ่ายของ, จ่ายแล้ว, และไม่อนุมัติ (ไม่เอามารวมกันตามข้อกำหนด UX)
$pending_requisitions   = [];
$completed_requisitions = [];
$rejected_requisitions  = [];
foreach ($requisitions as $rq) {
    if ($rq['status'] === 'pending') {
        $pending_requisitions[] = $rq;
    } elseif ($rq['status'] === 'completed' || $rq['status'] === 'approved') {
        $completed_requisitions[] = $rq;
    } else {
        $rejected_requisitions[] = $rq;
    }
}

// ดึงรายการยืม-คืนระหว่างหน่วยงานทั้งหมดในโรงพยาบาล
$borrows_stmt = $pdo->query("
    SELECT b.*, 
           m.material_code, m.material_name, m.unit_unit,
           u_borrower.unit_name AS borrower_name, u_borrower.unit_code AS borrower_code,
           u_lender.unit_name AS lender_name, u_lender.unit_code AS lender_code
    FROM item_borrows b
    JOIN materials m ON b.material_id = m.material_id
    JOIN units u_borrower ON b.borrower_unit_id = u_borrower.unit_id
    JOIN units u_lender ON b.lender_unit_id = u_lender.unit_id
    ORDER BY b.borrow_id DESC
");
$borrows = $borrows_stmt->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>คลังใหญ่ (Super Admin) | ระบบจัดการวัสดุ โรงพยาบาลมหาสารคาม</title>
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
            --mhk-primary-light: #e0f2f1;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f4f7f6;
            color: #333333;
        }
        h1, h2, h3, h4, h5, .nav-link, .btn {
            font-family: 'Prompt', sans-serif;
        }
        .navbar-mhk {
            background: linear-gradient(135deg, var(--mhk-primary-dark) 0%, var(--mhk-primary) 100%);
            box-shadow: 0 4px 15px rgba(0, 77, 64, 0.15);
        }
        .stat-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .content-card {
            background: #ffffff;
            border-radius: 16px;
            border: none;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.04);
            margin-bottom: 25px;
        }
        .table thead th {
            background-color: #f8fafc;
            font-family: 'Prompt', sans-serif;
            font-size: 0.88rem;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
        }
        .qr-preview-box {
            background: #ffffff;
            padding: 15px;
            border-radius: 12px;
            display: inline-block;
            border: 1px solid #e2e8f0;
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
            .navbar-mhk .navbar-brand {
                font-size: 0.92rem;
            }
        }
    </style>
</head>
<body>

<?php renderLoadingScreen(); ?>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-mhk sticky-top py-2">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <span class="bg-white text-dark rounded-circle p-2 me-2 d-inline-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                <i class="fa-solid fa-hospital text-teal fa-lg" style="color: var(--mhk-primary);"></i>
            </span>
            <div>
                <span class="fw-bold d-block lh-1 fs-6 fs-md-5">โรงพยาบาลมหาสารคาม</span>
                <span class="small opacity-75 fs-7">ระบบคลังใหญ่ & พัสดุกลาง (Super Admin)</span>
            </div>
        </a>

        <!-- Mobile Top Right Menu Button -->
        <button class="btn btn-sm btn-outline-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminMobileMenu" aria-label="เมนู">
            <i class="fa-solid fa-bars"></i>
        </button>

        <div class="collapse navbar-collapse" id="adminNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3">
                <li class="nav-item">
                    <a class="nav-link active" href="dashboard.php">
                        <i class="fa-solid fa-warehouse me-1"></i> คลังกลาง
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-semibold text-warning" href="stock_in_out.php">
                        <i class="fa-solid fa-barcode me-1"></i> รับเข้า-จ่ายออกบาร์โค้ด
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="subunits_stock.php">
                        <i class="fa-solid fa-hospital-user me-1"></i> สต็อกคลังย่อย
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="refill.php">
                        <i class="fa-solid fa-dolly me-1"></i> ระบบ Refill
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="reports.php">
                        <i class="fa-solid fa-chart-pie me-1"></i> รายงานสถิติ
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#requisitions_section">
                        <i class="fa-solid fa-file-invoice me-1"></i> ใบขอเบิก
                        <?php if ($stats['pending_reqs'] > 0): ?>
                            <span class="badge bg-danger rounded-pill ms-1"><?= $stats['pending_reqs'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="scan_approve.php">
                        <i class="fa-solid fa-qrcode me-1 text-light"></i> สแกน QR อนุมัติ
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fa-solid fa-gear me-1"></i> จัดการระบบ
                    </a>
                    <ul class="dropdown-menu border-0 shadow">
                        <li><a class="dropdown-item" href="users.php"><i class="fa-solid fa-users-gear me-2 text-primary"></i>ผู้ใช้งาน</a></li>
                        <li><a class="dropdown-item" href="units.php"><i class="fa-solid fa-hospital me-2 text-success"></i>หน่วยงาน/แผนก</a></li>
                        <li><a class="dropdown-item" href="categories.php"><i class="fa-solid fa-tags me-2 text-warning"></i>หมวดหมู่</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="fa-solid fa-sliders me-2 text-secondary"></i>ตั้งค่าระบบ</a></li>
                    </ul>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <!-- รายงาน PDF -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-light dropdown-toggle text-dark fw-medium shadow-sm" type="button" data-bs-toggle="dropdown">
                        <i class="fa-solid fa-file-pdf text-danger me-1"></i> ออกรายงาน PDF
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                        <li>
                            <a class="dropdown-item py-2" href="<?= BASE_URL ?>/report_pdf.php?type=inventory" target="_blank">
                                <i class="fa-solid fa-boxes-stacked me-2 text-primary"></i> รายงานสต็อกคลังกลาง
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item py-2" href="<?= BASE_URL ?>/report_pdf.php?type=borrows" target="_blank">
                                <i class="fa-solid fa-handshake-angle me-2 text-success"></i> รายงานการยืม-คืนทั้งหมด
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- User profile & Logout -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fa-solid fa-user-shield me-1"></i> <?= h($_SESSION['full_name']) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                        <li class="px-3 py-2 border-bottom">
                            <span class="d-block small text-muted">บทบาท</span>
                            <span class="badge bg-warning text-dark"><i class="fa-solid fa-crown me-1"></i> Super Admin</span>
                        </li>
                        <li><a class="dropdown-item py-2 text-danger" href="<?= BASE_URL ?>/logout.php"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i> ออกจากระบบ</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Mobile Bottom Navigation Bar (เมนูด้านล่างสำหรับโทรศัพท์มือถือ Super Admin) -->
<nav class="mobile-bottom-nav d-lg-none">
    <!-- 1. คลังกลาง -->
    <a href="#materialsTable" class="bottom-nav-item active">
        <i class="fa-solid fa-warehouse"></i>
        <span>คลังกลาง</span>
    </a>

    <!-- 2. ใบขอเบิกจากหน่วยงาน -->
    <a href="#requisitions_section" class="bottom-nav-item text-primary">
        <?php if ($stats['pending_reqs'] > 0): ?>
            <span class="bottom-nav-badge"><?= $stats['pending_reqs'] ?></span>
        <?php endif; ?>
        <i class="fa-solid fa-file-invoice"></i>
        <span>ใบขอเบิก</span>
    </a>

    <!-- 3. สแกน QR ตรวจรับด่วน (ปุ่มลอยตรงกลาง) -->
    <a href="scan_approve.php" class="bottom-nav-scan-btn shadow" title="สแกน QR Code ตรวจรับ">
        <i class="fa-solid fa-qrcode"></i>
    </a>

    <!-- 4. พิมพ์ QR พัสดุ -->
    <button type="button" class="bottom-nav-item text-dark" onclick="openBatchQrModal()">
        <span id="mobileAdminQrBadge" class="bottom-nav-badge d-none">0</span>
        <i class="fa-solid fa-print"></i>
        <span>พิมพ์ QR</span>
    </button>

    <!-- 5. เมนูรวม / จัดการระบบ -->
    <button type="button" class="bottom-nav-item" data-bs-toggle="offcanvas" data-bs-target="#adminMobileMenu">
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

    <!-- Header Actions Banner -->
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3">
        <div>
            <h2 class="h3 fw-bold mb-1 text-dark">
                <i class="fa-solid fa-cubes-stacked me-2 text-success"></i> ภาพรวมคลังพัสดุและเวชภัณฑ์กลาง
            </h2>
            <p class="text-muted small mb-0">
                ควบคุมบัญชีพัสดุกลาง, อนุมัติใบเบิกหลายรายการ (Multi-item) ผ่านเว็บหรือสแกน QR, จัดการหน่วยงาน และหมวดหมู่
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="scan_approve.php" class="btn btn-warning rounded-3 shadow-sm px-3 fw-semibold">
                <i class="fa-solid fa-qrcode me-1"></i> สแกน QR อนุมัติใบเบิก
            </a>
            <button class="btn btn-success rounded-3 shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#addMaterialModal">
                <i class="fa-solid fa-plus-circle me-1"></i> เพิ่มวัสดุใหม่
            </button>
        </div>
    </div>

    <!-- Stats Cards Row -->
    <div class="row g-2 g-md-3 mb-4">
        <!-- ใบขอเบิกค้างอนุมัติ -->
        <div class="col-6 col-lg-3">
            <div class="card stat-card p-3 h-100 bg-white border-start border-4 border-warning">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning me-2 me-md-3 d-none d-sm-inline-flex">
                        <i class="fa-solid fa-file-invoice fa-xl"></i>
                    </div>
                    <div>
                        <span class="text-muted small d-block fs-7">ใบขอเบิกค้างอนุมัติ</span>
                        <h3 class="fw-bold mb-0 text-dark fs-4 fs-md-3"><?= number_format($stats['pending_reqs']) ?> <small class="fs-7 text-muted fw-normal">ฉบับ</small></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- รายการวัสดุทั้งหมด -->
        <div class="col-6 col-lg-3">
            <div class="card stat-card p-3 h-100 bg-white">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary me-2 me-md-3 d-none d-sm-inline-flex">
                        <i class="fa-solid fa-box-open fa-xl"></i>
                    </div>
                    <div>
                        <span class="text-muted small d-block fs-7">รายการวัสดุในระบบ</span>
                        <h3 class="fw-bold mb-0 text-dark fs-4 fs-md-3"><?= number_format($stats['total_materials']) ?> <small class="fs-7 text-muted fw-normal">รายการ</small></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- หน่วยงานทั้งหมด -->
        <div class="col-6 col-lg-3">
            <div class="card stat-card p-3 h-100 bg-white">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info bg-opacity-10 text-info me-2 me-md-3 d-none d-sm-inline-flex">
                        <i class="fa-solid fa-hospital fa-xl"></i>
                    </div>
                    <div>
                        <span class="text-muted small d-block fs-7">หน่วยงาน / แผนก</span>
                        <h3 class="fw-bold mb-0 text-dark fs-4 fs-md-3"><?= number_format($stats['total_units']) ?> <small class="fs-7 text-muted fw-normal">หน่วย</small></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- คลังกลางใกล้หมด -->
        <div class="col-6 col-lg-3">
            <div class="card stat-card p-3 h-100 bg-white border-start border-4 border-danger">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger me-2 me-md-3 d-none d-sm-inline-flex">
                        <i class="fa-solid fa-triangle-exclamation fa-xl"></i>
                    </div>
                    <div>
                        <span class="text-muted small d-block fs-7">ต่ำกว่าเกณฑ์ (Min)</span>
                        <h3 class="fw-bold mb-0 text-danger fs-4 fs-md-3"><?= number_format($stats['low_stock_items']) ?> <small class="fs-7 text-muted fw-normal">รายการ</small></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section: ใบขอเบิกพัสดุจากหน่วยงาน (Requisitions Queue) - แยกสถานะ จ่ายแล้ว กับ ยังไม่จ่าย ออกจากกันตามความต้องการ -->
    <div class="card content-card p-4" id="requisitions_section">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div>
                <h4 class="fw-bold mb-1"><i class="fa-solid fa-file-invoice text-teal me-2" style="color: var(--mhk-primary);"></i> รายการใบขอเบิกพัสดุจากหน่วยงาน (Requisitions Queue)</h4>
                <p class="text-muted small mb-0">แยกการแสดงผลตามสถานะอย่างชัดเจน ไม่นำรายการที่จ่ายของแล้วและยังไม่จ่ายมารวมกัน</p>
            </div>
            <a href="scan_approve.php" class="btn btn-warning btn-sm rounded-3 fw-semibold">
                <i class="fa-solid fa-camera me-1"></i> เปิดหน้าสแกน QR ตรวจรับ
            </a>
        </div>

        <!-- Nav Tabs แยกประเภทสถานะ -->
        <ul class="nav nav-pills mb-3 border-bottom pb-3 gap-2" id="requisitionTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active rounded-pill px-3 py-2 fw-semibold" id="tab-pending-btn" data-bs-toggle="pill" data-bs-target="#tab-pending" type="button" role="tab">
                    <i class="fa-solid fa-clock me-1 text-warning"></i> รอจ่ายของ (Pending)
                    <span class="badge bg-warning text-dark rounded-pill ms-1"><?= count($pending_requisitions) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-pill px-3 py-2 fw-semibold" id="tab-completed-btn" data-bs-toggle="pill" data-bs-target="#tab-completed" type="button" role="tab">
                    <i class="fa-solid fa-circle-check me-1 text-success"></i> จ่ายของแล้ว (Completed)
                    <span class="badge bg-success rounded-pill ms-1"><?= count($completed_requisitions) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-pill px-3 py-2 fw-semibold" id="tab-rejected-btn" data-bs-toggle="pill" data-bs-target="#tab-rejected" type="button" role="tab">
                    <i class="fa-solid fa-circle-xmark me-1 text-danger"></i> ไม่อนุมัติ / ปฏิเสธ (Rejected)
                    <span class="badge bg-danger rounded-pill ms-1"><?= count($rejected_requisitions) ?></span>
                </button>
            </li>
        </ul>

        <div class="tab-content" id="requisitionTabsContent">
            <!-- 1. แท็บ: รอจ่ายของ (Pending) -->
            <div class="tab-pane fade show active" id="tab-pending" role="tabpanel">
                <?php if (empty($pending_requisitions)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fa-solid fa-circle-check fa-3x text-success opacity-50 mb-2"></i>
                        <h6 class="fw-bold">ไม่มีรายการใบเบิกค้างจ่าย</h6>
                        <small>หน่วยงานยังไม่มีการส่งใบขอเบิกใหม่ที่รอการตรวจรับ/อนุมัติจ่าย</small>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="pendingReqTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 140px;">เลขที่ใบเบิก</th>
                                    <th>หน่วยงานที่ขอเบิก</th>
                                    <th>ผู้ทำรายการ</th>
                                    <th>วันที่ขอเบิก</th>
                                    <th class="text-center">จำนวนรายการ</th>
                                    <th class="text-center">รวมจำนวนชิ้น</th>
                                    <th class="text-center">สถานะ</th>
                                    <th class="text-center" style="width: 220px;">การดำเนินการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_requisitions as $rq): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-dark font-monospace fs-6"><?= h($rq['requisition_no']) ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= h($rq['unit_name']) ?></div>
                                            <span class="badge bg-light text-secondary border font-monospace"><?= h($rq['unit_code']) ?></span>
                                        </td>
                                        <td><span class="small text-muted"><?= h($rq['requester_name']) ?></span></td>
                                        <td><small><?= thaiDate($rq['request_date']) ?></small></td>
                                        <td class="text-center fw-bold"><?= number_format($rq['total_items']) ?> รายการ</td>
                                        <td class="text-center fw-bold text-teal" style="color: var(--mhk-primary);"><?= number_format($rq['total_quantity_req']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-warning text-dark"><i class="fa-solid fa-clock me-1"></i> รออนุมัติจ่าย</span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <!-- ตรวจจ่ายในแอป -->
                                                <button type="button" class="btn btn-outline-primary" 
                                                        onclick="openApproveModal('<?= h($rq['requisition_no']) ?>')" 
                                                        title="ตรวจรับ/จ่ายพัสดุในแอป">
                                                    <i class="fa-solid fa-list-check me-1"></i> ตรวจจ่าย
                                                </button>

                                                <!-- QR Code ใบเบิก -->
                                                <button type="button" class="btn btn-outline-dark" 
                                                        onclick="openReqQrModal('<?= h($rq['requisition_no']) ?>', '<?= addslashes(h($rq['unit_name'])) ?>')" 
                                                        title="QR Code ใบเบิก">
                                                    <i class="fa-solid fa-qrcode"></i>
                                                </button>

                                                <!-- พิมพ์ PDF -->
                                                <a href="<?= BASE_URL ?>/report_pdf.php?type=requisition&req_id=<?= $rq['requisition_id'] ?>" 
                                                   target="_blank" class="btn btn-outline-danger" title="พิมพ์ใบเบิกราชการ (PDF)">
                                                    <i class="fa-solid fa-print"></i>
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

            <!-- 2. แท็บ: จ่ายของแล้ว (Completed) -->
            <div class="tab-pane fade" id="tab-completed" role="tabpanel">
                <?php if (empty($completed_requisitions)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fa-regular fa-folder-open fa-3x text-secondary opacity-50 mb-2"></i>
                        <h6 class="fw-bold">ยังไม่มีประวัติการจ่ายของ</h6>
                        <small>รายการที่อนุมัติจ่ายแล้วจะถูกแยกมาแสดงที่นี่</small>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="completedReqTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 140px;">เลขที่ใบเบิก</th>
                                    <th>หน่วยงานที่ขอเบิก</th>
                                    <th>ผู้ขอเบิก</th>
                                    <th>ผู้อนุมัติจ่าย / วันที่จ่าย</th>
                                    <th class="text-center">จำนวนรายการ</th>
                                    <th class="text-center">รวมจำนวนชิ้น</th>
                                    <th class="text-center">สถานะ</th>
                                    <th class="text-center" style="width: 200px;">การดำเนินการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($completed_requisitions as $rq): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-secondary font-monospace fs-6"><?= h($rq['requisition_no']) ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= h($rq['unit_name']) ?></div>
                                            <span class="badge bg-light text-secondary border font-monospace"><?= h($rq['unit_code']) ?></span>
                                        </td>
                                        <td><span class="small text-muted"><?= h($rq['requester_name']) ?></span></td>
                                        <td>
                                            <div class="fw-semibold text-dark"><i class="fa-solid fa-user-check me-1 text-success"></i> <?= h($rq['approver_name'] ?? 'คลังกลาง') ?></div>
                                            <small class="text-muted"><?= thaiDate($rq['approved_date'] ?? $rq['request_date']) ?></small>
                                        </td>
                                        <td class="text-center fw-bold"><?= number_format($rq['total_items']) ?> รายการ</td>
                                        <td class="text-center fw-bold text-success"><?= number_format($rq['total_quantity_req']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i> จ่ายของแล้ว</span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <!-- ดูรายการที่จ่าย -->
                                                <button type="button" class="btn btn-outline-primary" 
                                                        onclick="openApproveModal('<?= h($rq['requisition_no']) ?>')" 
                                                        title="ดูรายการพัสดุในใบเบิกนี้">
                                                    <i class="fa-solid fa-eye me-1"></i> ดูรายการ
                                                </button>

                                                <!-- พิมพ์ PDF -->
                                                <a href="<?= BASE_URL ?>/report_pdf.php?type=requisition&req_id=<?= $rq['requisition_id'] ?>" 
                                                   target="_blank" class="btn btn-outline-danger" title="พิมพ์ใบเบิกราชการ (PDF)">
                                                    <i class="fa-solid fa-print me-1"></i> พิมพ์ PDF
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

            <!-- 3. แท็บ: ไม่อนุมัติ / ปฏิเสธ (Rejected) -->
            <div class="tab-pane fade" id="tab-rejected" role="tabpanel">
                <?php if (empty($rejected_requisitions)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fa-regular fa-circle-check fa-3x text-secondary opacity-50 mb-2"></i>
                        <p class="mb-0">ไม่มีรายการที่ถูกปฏิเสธ</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="rejectedReqTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 140px;">เลขที่ใบเบิก</th>
                                    <th>หน่วยงานที่ขอเบิก</th>
                                    <th>ผู้ขอเบิก</th>
                                    <th>วันที่ทำรายการ</th>
                                    <th class="text-center">จำนวนรายการ</th>
                                    <th class="text-center">สถานะ</th>
                                    <th class="text-center" style="width: 150px;">การดำเนินการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rejected_requisitions as $rq): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-dark font-monospace fs-6"><?= h($rq['requisition_no']) ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= h($rq['unit_name']) ?></div>
                                            <span class="badge bg-light text-secondary border font-monospace"><?= h($rq['unit_code']) ?></span>
                                        </td>
                                        <td><span class="small text-muted"><?= h($rq['requester_name']) ?></span></td>
                                        <td><small><?= thaiDate($rq['request_date']) ?></small></td>
                                        <td class="text-center fw-bold"><?= number_format($rq['total_items']) ?> รายการ</td>
                                        <td class="text-center">
                                            <span class="badge bg-danger"><i class="fa-solid fa-circle-xmark me-1"></i> ไม่อนุมัติ</span>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                    onclick="openApproveModal('<?= h($rq['requisition_no']) ?>')">
                                                <i class="fa-solid fa-eye me-1"></i> ดูรายละเอียด
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Main Inventory Section -->
    <div class="card content-card p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div>
                <h4 class="fw-bold mb-1"><i class="fa-solid fa-list-check me-2 text-primary"></i> บัญชีพัสดุและเวชภัณฑ์คลังกลาง</h4>
                <p class="text-muted small mb-0">รายการวัสดุอุปกรณ์ทั้งหมดพร้อมยอดสต็อกคงคลังและการสร้าง QR Code</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-dark rounded-3 shadow-sm px-3 fw-semibold" id="btnBatchPrintQr" onclick="openBatchQrModal()">
                    <i class="fa-solid fa-print me-1 text-primary"></i> พิมพ์ QR สติกเกอร์ (<span id="selectedCountBadge">0</span>)
                </button>
                <button class="btn btn-success rounded-3 shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#addMaterialModal">
                    <i class="fa-solid fa-plus-circle me-1"></i> เพิ่มวัสดุใหม่
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="materialsTable">
                <thead>
                    <tr>
                        <th style="width: 40px;" class="text-center">
                            <input type="checkbox" id="selectAllMaterials" class="form-check-input" title="เลือกทั้งหมด">
                        </th>
                        <th style="width: 110px;">รหัสพัสดุ</th>
                        <th>ชื่อพัสดุ / เวชภัณฑ์</th>
                        <th>หมวดหมู่</th>
                        <th>หน่วยนับ</th>
                        <th class="text-end">ราคา/หน่วย</th>
                        <th>บริษัทคู่ค้า (Supplier)</th>
                        <th class="text-center">สต็อกคลังกลาง</th>
                        <th class="text-center">เกณฑ์ขั้นต่ำ (Min)</th>
                        <th class="text-center">สถานะ</th>
                        <th class="text-center" style="width: 220px;">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($materials as $item): ?>
                        <?php
                            $stock = (int)$item['central_stock'];
                            $min   = (int)$item['min_stock'];
                            if ($stock <= 0) {
                                $status_badge = '<span class="badge bg-danger">หมดสต็อก</span>';
                            } elseif ($stock <= $min) {
                                $status_badge = '<span class="badge bg-warning text-dark">ใกล้หมด</span>';
                            } else {
                                $status_badge = '<span class="badge bg-success">ปกติ</span>';
                            }
                        ?>
                        <tr>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input mat-select-checkbox" 
                                       value="<?= $item['material_id'] ?>" 
                                       data-code="<?= h($item['material_code']) ?>" 
                                       data-name="<?= addslashes(h($item['material_name'])) ?>" 
                                       data-category="<?= addslashes(h($item['category'])) ?>"
                                       data-unit="<?= h($item['unit_unit']) ?>"
                                       onchange="updateSelectedBatchCount()">
                            </td>
                            <td><span class="badge bg-light text-dark border font-monospace"><?= h($item['material_code']) ?></span></td>
                            <td>
                                <div class="fw-semibold text-dark"><?= h($item['material_name']) ?></div>
                                <?php if (!empty($item['description'])): ?>
                                    <small class="text-muted d-block text-truncate" style="max-width: 280px;"><?= h($item['description']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-secondary bg-opacity-10 text-secondary border"><?= h($item['category']) ?></span></td>
                            <td><?= h($item['unit_unit']) ?></td>
                            <td class="text-end fw-semibold text-dark"><?= number_format($item['unit_price'] ?? 0, 2) ?> ฿</td>
                            <td class="small text-secondary"><?= h($item['supplier'] ?: 'องค์การเภสัชกรรม (GPO)') ?></td>
                            <td class="text-center">
                                <span class="fs-6 fw-bold <?= $stock <= $min ? 'text-danger' : 'text-success' ?>">
                                    <?= number_format($stock) ?>
                                </span>
                            </td>
                            <td class="text-center text-muted"><?= number_format($min) ?></td>
                            <td class="text-center"><?= $status_badge ?></td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-info" 
                                            onclick="openMovementsModal(<?= $item['material_id'] ?>, '<?= h($item['material_code']) ?>', '<?= addslashes(h($item['material_name'])) ?>', '<?= addslashes(h($item['category'])) ?>', <?= $stock ?>, '<?= h($item['unit_unit']) ?>')" 
                                            title="ดูประวัติวันและเวลาการนำเข้า-จ่ายออก">
                                        <i class="fa-solid fa-clock-rotate-left"></i> เข้า-ออก
                                    </button>
                                    <button type="button" class="btn btn-outline-dark" 
                                            onclick="openQrModal('<?= h($item['material_code']) ?>', '<?= addslashes(h($item['material_name'])) ?>', '<?= addslashes(h($item['category'])) ?>', <?= $item['material_id'] ?>)" 
                                            title="สร้าง / พิมพ์ QR Code">
                                        <i class="fa-solid fa-qrcode"></i> QR
                                    </button>
                                    <button type="button" class="btn btn-outline-success" 
                                            onclick="openAdjustModal(<?= $item['material_id'] ?>, '<?= addslashes(h($item['material_name'])) ?>', <?= $stock ?>)" 
                                            title="ปรับยอดสต็อก">
                                        <i class="fa-solid fa-boxes-packing"></i> เติม
                                    </button>
                                    <button type="button" class="btn btn-outline-primary" 
                                            onclick="openEditModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') ?>)" 
                                            title="แก้ไขข้อมูล">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" 
                                            onclick="confirmDelete(<?= $item['material_id'] ?>, '<?= addslashes(h($item['material_name'])) ?>')" 
                                            title="ลบรายการ">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==================== MODALS ==================== -->

<!-- Modal: ตรวจจ่ายใบขอเบิกในแอป (Approve Requisition in App) -->
<div class="modal fade" id="approveReqModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-teal text-white" style="background-color: var(--mhk-primary);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-list-check me-2"></i> ตรวจรับและอนุมัติจ่ายพัสดุ (Approve in App)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?= BASE_URL ?>/unit/borrow_action.php">
                <input type="hidden" name="action" value="approve_requisition">
                <input type="hidden" name="requisition_id" id="modalReqId">
                <input type="hidden" name="redirect_to" value="admin/dashboard.php#requisitions_section">

                <div class="modal-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                        <div>
                            <span class="badge bg-dark font-monospace fs-6" id="modalReqNo"></span>
                            <h5 class="fw-bold text-dark mt-1 mb-0" id="modalReqUnit"></h5>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-warning text-dark" id="modalReqStatus"></span>
                            <small class="d-block text-muted" id="modalReqDate"></small>
                        </div>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>พัสดุ</th>
                                    <th class="text-center" style="width: 130px;">สต็อกคลังกลาง</th>
                                    <th class="text-center" style="width: 130px;">ขอเบิก</th>
                                    <th class="text-center" style="width: 140px;">อนุมัติจ่าย</th>
                                </tr>
                            </thead>
                            <tbody id="modalReqItemsBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ปิด</button>
                    <button type="submit" class="btn btn-success rounded-3 px-4 shadow-sm" id="modalBtnSubmitApprove">
                        <i class="fa-solid fa-circle-check me-1"></i> ยืนยันอนุมัติจ่ายพัสดุ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: เพิ่มรายการวัสดุใหม่ -->
<div class="modal fade" id="addMaterialModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus-circle me-2"></i> เพิ่มรายการวัสดุ/เวชภัณฑ์ใหม่</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_material">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">รหัสพัสดุ (Material Code) <span class="text-danger">*</span></label>
                            <input type="text" name="material_code" class="form-control rounded-3 font-monospace" placeholder="เช่น MAT-1011" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">รหัสบาร์โค้ด (Barcode)</label>
                            <input type="text" name="barcode" class="form-control rounded-3 font-monospace" placeholder="ยิงบาร์โค้ด หรือเว้นว่างเพื่อใช้รหัสพัสดุ">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">หมวดหมู่ <span class="text-danger">*</span></label>
                            <select name="category" class="form-select rounded-3" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= h($cat['category_name']) ?>"><?= h($cat['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">บริษัทที่จัดซื้อ (Supplier)</label>
                            <select name="supplier" class="form-select rounded-3">
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= h($sup['supplier_name']) ?>"><?= h($sup['supplier_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">ชื่อพัสดุ / เวชภัณฑ์ <span class="text-danger">*</span></label>
                            <input type="text" name="material_name" class="form-control rounded-3" placeholder="เช่น กระบอกฉีดยา ขนาด 20 มล." required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">หน่วยนับ <span class="text-danger">*</span></label>
                            <input type="text" name="unit_unit" class="form-control rounded-3" placeholder="เช่น กล่อง, ขวด, เครื่อง" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">ราคาต่อหน่วย (บาท)</label>
                            <input type="number" step="0.01" name="unit_price" class="form-control rounded-3" value="0.00" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">เกณฑ์ขั้นต่ำ (Min Stock)</label>
                            <input type="number" name="min_stock" class="form-control rounded-3" value="10" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">ยอดสต็อกเริ่มต้นคลังกลาง</label>
                            <input type="number" name="initial_stock" class="form-control rounded-3" value="50" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">เลขที่ล็อต (Lot No.)</label>
                            <input type="text" name="lot_no" class="form-control rounded-3" placeholder="เช่น LOT-2026-001">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">วันหมดอายุ (Exp Date)</label>
                            <input type="date" name="exp_date" class="form-control rounded-3">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">รายละเอียดเพิ่มเติม / สเปก</label>
                            <textarea name="description" class="form-control rounded-3" rows="2" placeholder="ระบุขนาด, มาตรฐาน หรือวิธีเก็บรักษา"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success rounded-3 px-4">บันทึกรายการ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: แก้ไขข้อมูลวัสดุ -->
<div class="modal fade" id="editMaterialModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i> แก้ไขข้อมูลวัสดุ/เวชภัณฑ์</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_material">
                <input type="hidden" name="material_id" id="edit_material_id">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">รหัสพัสดุ</label>
                            <input type="text" id="edit_material_code" class="form-control rounded-3 bg-light font-monospace" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">รหัสบาร์โค้ด (Barcode)</label>
                            <input type="text" name="barcode" id="edit_barcode" class="form-control rounded-3 font-monospace">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">หมวดหมู่</label>
                            <select name="category" id="edit_category" class="form-select rounded-3">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= h($cat['category_name']) ?>"><?= h($cat['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">บริษัทที่จัดซื้อ (Supplier)</label>
                            <select name="supplier" id="edit_supplier" class="form-select rounded-3">
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= h($sup['supplier_name']) ?>"><?= h($sup['supplier_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">ชื่อพัสดุ <span class="text-danger">*</span></label>
                            <input type="text" name="material_name" id="edit_material_name" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">หน่วยนับ</label>
                            <input type="text" name="unit_unit" id="edit_unit_unit" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">ราคาต่อหน่วย (บาท)</label>
                            <input type="number" step="0.01" name="unit_price" id="edit_unit_price" class="form-control rounded-3" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">เกณฑ์ขั้นต่ำ (Min Stock)</label>
                            <input type="number" name="min_stock" id="edit_min_stock" class="form-control rounded-3" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">เลขที่ล็อต (Lot No.)</label>
                            <input type="text" name="lot_no" id="edit_lot_no" class="form-control rounded-3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">วันหมดอายุ (Exp Date)</label>
                            <input type="date" name="exp_date" id="edit_exp_date" class="form-control rounded-3">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">รายละเอียดเพิ่มเติม</label>
                            <textarea name="description" id="edit_description" class="form-control rounded-3" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4">บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: เติม/ปรับยอดสต็อกคลังกลาง -->
<div class="modal fade" id="adjustStockModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-teal text-white" style="background-color: var(--mhk-primary);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-boxes-packing me-2"></i> ปรับยอดสต็อกคลังกลาง</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="material_id" id="adjust_material_id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label text-muted small">ชื่อพัสดุ</label>
                        <h5 class="fw-bold text-dark" id="adjust_material_name"></h5>
                        <p class="small text-muted mb-0">คงเหลือคลังกลาง: <span class="badge bg-secondary fs-6" id="adjust_current_stock"></span></p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">รูปแบบการปรับยอด</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="adjust_type" id="type_add" value="add" checked>
                                <label class="form-check-label" for="type_add"><i class="fa-solid fa-plus text-success"></i> บวกเพิ่ม</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="adjust_type" id="type_set" value="set">
                                <label class="form-check-label" for="type_set"><i class="fa-solid fa-pen text-primary"></i> ตั้งยอดใหม่</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">จำนวน</label>
                        <input type="number" name="quantity" class="form-control rounded-3 fs-5" value="10" min="1" required>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success rounded-3 px-4">ยืนยัน</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: แสดง QR Code พัสดุ -->
<div class="modal fade" id="qrModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4 text-center">
            <div class="modal-header border-0 pb-0">
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-0 pb-4">
                <div class="border p-4 rounded-4 bg-light d-inline-block w-100">
                    <div class="d-flex align-items-center justify-content-center mb-2">
                        <img src="<?= BASE_URL . '/' . ltrim(h(getSetting('hospital_logo', 'assets/img/logo.svg')), '/') ?>" alt="Logo" style="width: 28px; height: 28px; object-fit: contain;" class="me-2">
                        <h6 class="fw-bold mb-0 text-dark"><?= h(getSetting('hospital_name', 'โรงพยาบาลมหาสารคาม')) ?></h6>
                    </div>
                    <div class="small text-muted mb-3">รหัสติดตามและเบิกพัสดุ (สแกนผ่านมือถือได้ทันที)</div>
                    <div id="qrcodeCanvas" class="qr-preview-box mb-3 mx-auto"></div>
                    <h5 class="fw-bold text-dark font-monospace mb-1" id="qrCodeLabel"></h5>
                    <div class="fw-semibold text-secondary" id="qrNameLabel"></div>
                    <small class="text-muted d-block mt-2 font-monospace" id="qrUrlLabel" style="font-size: 0.72rem; word-break: break-all;"></small>
                </div>
            </div>
            <div class="modal-footer border-0 justify-content-center bg-light rounded-bottom-4 gap-2">
                <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ปิด</button>
                <a id="qrBatchLinkBtn" href="#" target="_blank" class="btn btn-primary rounded-3">
                    <i class="fa-solid fa-print me-1"></i> พิมพ์สติกเกอร์ (เลือกจำนวนได้)
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal: แสดง QR Code ใบเบิก -->
<div class="modal fade" id="reqQrModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4 text-center">
            <div class="modal-header border-0 pb-0">
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-0 pb-4">
                <div class="border p-4 rounded-4 bg-light d-inline-block w-100">
                    <h6 class="fw-bold mb-1 text-primary">QR Code ใบขอเบิกพัสดุ</h6>
                    <div class="small text-muted mb-3" id="reqQrUnit"></div>
                    <div id="reqQrcodeCanvas" class="qr-preview-box mb-3 mx-auto"></div>
                    <h5 class="fw-bold text-dark font-monospace" id="reqQrNo"></h5>
                    <small class="text-muted">ใช้สำหรับให้คลังกลางสแกนเพื่อจ่ายพัสดุ</small>
                </div>
            </div>
            <div class="modal-footer border-0 justify-content-center bg-light rounded-bottom-4">
                <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: ดูประวัติวันและเวลาการนำเข้า-จ่ายออกของวัสดุ (Material In-Out History) -->
<div class="modal fade" id="movementsModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">
                    <i class="fa-solid fa-clock-rotate-left me-2 text-info"></i> ประวัติวันและเวลาการนำเข้า-จ่ายออกของวัสดุ
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
                            <span class="text-muted small d-block">ยอดสต็อกรวมในระบบ</span>
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
                                <th class="text-center" style="width: 130px;">คงเหลือหลังรายการ</th>
                                <th>หน่วยงาน / แผนก</th>
                                <th>อ้างอิง / เอกสาร</th>
                                <th>ผู้ทำรายการ / บันทึก</th>
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
                    <h6 class="fw-bold text-muted">ยังไม่มีประวัติการนำเข้าหรือจ่ายออกของพัสดุนี้</h6>
                    <small class="text-muted">เมื่อมีการเบิกจ่าย โอนย้าย หรือปรับสต็อก ระบบจะบันทึกวันและเวลาที่นี่โดยอัตโนมัติ</small>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-secondary rounded-3 px-4" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: พิมพ์สติกเกอร์ QR Code แบบเลือกหลายรายการและเลือกจำนวนได้ -->
<div class="modal fade" id="batchQrPrintModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">
                    <i class="fa-solid fa-print me-2 text-primary"></i> พิมพ์ QR Code สติกเกอร์ (เลือกหลายรายการ & เลือกจำนวนได้)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="print_qr_batch.php" target="_blank">
                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">
                        กำหนดจำนวนสติกเกอร์ที่ต้องการพิมพ์สำหรับแต่ละรายการ และเลือกรูปแบบเลย์เอาต์แผ่นพิมพ์เพื่อพิมพ์ออกสติกเกอร์หรือกระดาษ A4
                    </p>

                    <!-- Quick Tools Bar -->
                    <div class="bg-light p-3 rounded-4 border mb-3">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-muted mb-1">รูปแบบเลย์เอาต์กระดาษพิมพ์</label>
                                <select name="layout_type" id="batchLayoutType" class="form-select form-select-sm rounded-3">
                                    <option value="a4_3col" selected>สติกเกอร์ A4 (3 คอลัมน์ x 7 แถว = 21 ดวง/แผ่น) [มาตรฐาน]</option>
                                    <option value="a4_2col">สติกเกอร์ A4 ป้ายใหญ่ (2 คอลัมน์ x 5 แถว = 10 ดวง/แผ่น)</option>
                                    <option value="thermal_single">สติกเกอร์ความร้อน / ม้วนบาร์โค้ด (Thermal Label 5x3 cm)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-muted mb-1">ตั้งค่าจำนวนพิมพ์เท่ากันทุกรายการ</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" id="quickSetAllCopies" class="form-control text-center" value="1" min="1" max="100">
                                    <button type="button" class="btn btn-outline-secondary" onclick="applyCopiesToAll()">
                                        <i class="fa-solid fa-clone me-1"></i> ปรับใช้ทั้งหมด
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Selected Items Table -->
                    <div class="table-responsive mb-3" style="max-height: 380px; overflow-y: auto;">
                        <table class="table table-bordered align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>รหัส / ชื่อพัสดุ</th>
                                    <th>หมวดหมู่</th>
                                    <th class="text-center" style="width: 140px;">จำนวนดวงที่พิมพ์</th>
                                    <th class="text-center" style="width: 60px;">ลบ</th>
                                </tr>
                            </thead>
                            <tbody id="batchQrTableBody">
                                <!-- Populated dynamically by JS -->
                            </tbody>
                        </table>
                    </div>

                    <div id="emptyBatchNotice" class="text-center py-4 text-muted d-none">
                        <i class="fa-solid fa-qrcode fa-3x mb-2 text-secondary opacity-50"></i>
                        <p class="mb-0">ยังไม่ได้เลือกรายการพัสดุ กรุณาทำเครื่องหมายถูก [✓] หน้ารายการพัสดุที่ต้องการพิมพ์</p>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4 shadow-sm" id="btnSubmitBatchPrint">
                        <i class="fa-solid fa-print me-1"></i> เปิดหน้าต่างพิมพ์สติกเกอร์
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Delete Form -->
<form id="deleteForm" method="POST" action="" style="display: none;">
    <input type="hidden" name="action" value="delete_material">
    <input type="hidden" name="material_id" id="delete_material_id">
</form>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    $(document).ready(function() {
        $('#materialsTable').DataTable({
            language: { search: "ค้นหา:", lengthMenu: "แสดง _MENU_ รายการ", paginate: { previous: "ก่อน", next: "ถัดไป" } },
            pageLength: 10,
            columnDefs: [{ orderable: false, targets: [0, 8] }]
        });

        // Select all materials checkbox
        $('#selectAllMaterials').on('change', function() {
            const checked = this.checked;
            $('.mat-select-checkbox').each(function() {
                this.checked = checked;
            });
            updateSelectedBatchCount();
        });

        if ($('#pendingReqTable').length) {
            $('#pendingReqTable').DataTable({
                language: { search: "ค้นหาใบเบิก:", lengthMenu: "แสดง _MENU_ รายการ", paginate: { previous: "ก่อน", next: "ถัดไป" } },
                order: [[0, 'desc']],
                pageLength: 10
            });
        }

        if ($('#completedReqTable').length) {
            $('#completedReqTable').DataTable({
                language: { search: "ค้นหาประวัติ:", lengthMenu: "แสดง _MENU_ รายการ", paginate: { previous: "ก่อน", next: "ถัดไป" } },
                order: [[0, 'desc']],
                pageLength: 10
            });
        }

        if ($('#rejectedReqTable').length) {
            $('#rejectedReqTable').DataTable({
                language: { search: "ค้นหา:", lengthMenu: "แสดง _MENU_ รายการ", paginate: { previous: "ก่อน", next: "ถัดไป" } },
                order: [[0, 'desc']],
                pageLength: 10
            });
        }
    });

    // Modal ประวัติการเข้า-ออกของวัสดุ
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

        fetch(`<?= BASE_URL ?>/api_movements.php?material_id=${id}`)
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
                        ? '<span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fa-solid fa-arrow-down-left me-1"></i> นำเข้า (+)</span>'
                        : '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="fa-solid fa-arrow-up-right me-1"></i> จ่ายออก (-)</span>';
                    const qtyText = isIn 
                        ? `<span class="fw-bold text-success">+${m.quantity} ${unit}</span>` 
                        : `<span class="fw-bold text-danger">-${m.quantity} ${unit}</span>`;
                    const balText = m.balance_after !== null ? `${m.balance_after} ${unit}` : '-';

                    tr.innerHTML = `
                        <td><small class="fw-semibold text-dark">${m.created_at}</small></td>
                        <td class="text-center">${badgeType}</td>
                        <td class="text-center">${qtyText}</td>
                        <td class="text-center text-muted fw-semibold">${balText}</td>
                        <td>
                            <div class="fw-bold text-dark small">${m.unit_name}</div>
                            <span class="badge bg-light text-secondary border font-monospace">${m.unit_code}</span>
                        </td>
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

    // เปิด Modal ตรวจจ่ายใบเบิกในแอป
    function openApproveModal(reqNo) {
        Swal.showLoading();

        fetch(`scan_approve.php?ajax=get_requisition&req_no=${encodeURIComponent(reqNo)}`)
            .then(res => res.json())
            .then(data => {
                Swal.close();
                if (!data.success) {
                    Swal.fire('ไม่พบใบขอเบิก', data.message, 'error');
                    return;
                }

                const r = data.requisition;
                const items = data.items;

                document.getElementById('modalReqId').value = r.requisition_id;
                document.getElementById('modalReqNo').innerText = r.requisition_no;
                document.getElementById('modalReqUnit').innerText = `${r.unit_name} (${r.unit_code})`;
                document.getElementById('modalReqStatus').innerText = r.status === 'pending' ? 'รออนุมัติ' : 'จ่ายแล้ว';
                document.getElementById('modalReqDate').innerText = `ขอเมื่อ: ${r.request_date}`;

                const tbody = document.getElementById('modalReqItemsBody');
                tbody.innerHTML = '';

                items.forEach(item => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>
                            <strong class="text-dark">${item.material_name}</strong>
                            <div class="small text-muted font-monospace">${item.material_code}</div>
                        </td>
                        <td class="text-center fw-bold ${item.central_stock > 0 ? 'text-success' : 'text-danger'}">
                            ${item.central_stock} ${item.unit_unit}
                        </td>
                        <td class="text-center fw-bold text-dark">${item.quantity_requested} ${item.unit_unit}</td>
                        <td>
                            <input type="number" name="items[${item.item_id}]" class="form-control text-center fw-bold" 
                                   value="${item.quantity_requested}" min="0" max="${item.central_stock}" 
                                   ${r.status !== 'pending' ? 'readonly' : ''} required>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });

                if (r.status !== 'pending') {
                    document.getElementById('modalBtnSubmitApprove').style.display = 'none';
                } else {
                    document.getElementById('modalBtnSubmitApprove').style.display = 'inline-block';
                }

                new bootstrap.Modal(document.getElementById('approveReqModal')).show();
            })
            .catch(err => {
                Swal.close();
                Swal.fire('ข้อผิดพลาด', 'ไม่สามารถดึงข้อมูลได้', 'error');
            });
    }

    // Modal QR Code พัสดุ
    const qrBaseUrl = "<?= getQrBaseUrl() ?>";
    function openQrModal(code, name, category, id) {
        document.getElementById('qrCodeLabel').innerText = code;
        document.getElementById('qrNameLabel').innerText = name;
        const targetUrl = `${qrBaseUrl}/unit/scan.php?code=${encodeURIComponent(code)}`;
        const urlLabel = document.getElementById('qrUrlLabel');
        if (urlLabel) urlLabel.innerText = targetUrl;

        const printBtn = document.getElementById('qrBatchLinkBtn');
        if (printBtn && id) {
            printBtn.href = `print_qr_batch.php?copies[${id}]=1&layout_type=a4_3col`;
        }

        const container = document.getElementById('qrcodeCanvas');
        container.innerHTML = '';
        new QRCode(container, { text: targetUrl, width: 170, height: 170, colorDark: "#004d40", colorLight: "#ffffff" });
        new bootstrap.Modal(document.getElementById('qrModal')).show();
    }

    // Modal QR Code ใบเบิก
    function openReqQrModal(reqNo, unitName) {
        document.getElementById('reqQrNo').innerText = reqNo;
        document.getElementById('reqQrUnit').innerText = `หน่วยงาน: ${unitName}`;
        const container = document.getElementById('reqQrcodeCanvas');
        container.innerHTML = '';
        new QRCode(container, { text: reqNo, width: 180, height: 180, colorDark: "#0f766e", colorLight: "#ffffff" });
        new bootstrap.Modal(document.getElementById('reqQrModal')).show();
    }

    function openAdjustModal(id, name, currentStock) {
        document.getElementById('adjust_material_id').value = id;
        document.getElementById('adjust_material_name').innerText = name;
        document.getElementById('adjust_current_stock').innerText = currentStock + ' รายการ';
        new bootstrap.Modal(document.getElementById('adjustStockModal')).show();
    }

    function openEditModal(item) {
        document.getElementById('edit_material_id').value = item.material_id;
        document.getElementById('edit_material_code').value = item.material_code;
        document.getElementById('edit_barcode').value = item.barcode || item.material_code;
        document.getElementById('edit_material_name').value = item.material_name;
        document.getElementById('edit_category').value = item.category || '';
        document.getElementById('edit_supplier').value = item.supplier || '';
        document.getElementById('edit_unit_unit').value = item.unit_unit;
        document.getElementById('edit_unit_price').value = item.unit_price || 0;
        document.getElementById('edit_min_stock').value = item.min_stock;
        document.getElementById('edit_lot_no').value = item.lot_no || '';
        document.getElementById('edit_exp_date').value = item.exp_date || '';
        document.getElementById('edit_description').value = item.description || '';
        new bootstrap.Modal(document.getElementById('editMaterialModal')).show();
    }

    function confirmDelete(id, name) {
        Swal.fire({
            title: 'ยืนยันการลบ?',
            text: `ต้องการลบรายการ "${name}" หรือไม่?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete_material_id').value = id;
                document.getElementById('deleteForm').submit();
            }
        });
    }

    // ==================== BATCH QR PRINTING LOGIC ====================
    let selectedMaterialsForQr = {};

    function updateSelectedBatchCount() {
        // Collect checked items
        $('.mat-select-checkbox:checked').each(function() {
            const id = $(this).val();
            if (!selectedMaterialsForQr[id]) {
                selectedMaterialsForQr[id] = {
                    id: id,
                    code: $(this).data('code'),
                    name: $(this).data('name'),
                    category: $(this).data('category'),
                    unit: $(this).data('unit'),
                    copies: 1
                };
            }
        });

        // Remove unchecked items
        $('.mat-select-checkbox:not(:checked)').each(function() {
            const id = $(this).val();
            delete selectedMaterialsForQr[id];
        });

        const count = Object.keys(selectedMaterialsForQr).length;
        $('#selectedCountBadge').text(count);
        const mobileBadge = document.getElementById('mobileAdminQrBadge');
        if (mobileBadge) {
            mobileBadge.innerText = count;
            if (count > 0) mobileBadge.classList.remove('d-none');
            else mobileBadge.classList.add('d-none');
        }
    }

    function openBatchQrModal() {
        updateSelectedBatchCount();
        renderBatchQrTable();
        new bootstrap.Modal(document.getElementById('batchQrPrintModal')).show();
    }

    function renderBatchQrTable() {
        const tbody = document.getElementById('batchQrTableBody');
        const emptyNotice = document.getElementById('emptyBatchNotice');
        const submitBtn = document.getElementById('btnSubmitBatchPrint');
        tbody.innerHTML = '';

        const keys = Object.keys(selectedMaterialsForQr);
        if (keys.length === 0) {
            emptyNotice.classList.remove('d-none');
            submitBtn.disabled = true;
            return;
        }

        emptyNotice.classList.add('d-none');
        submitBtn.disabled = false;

        keys.forEach(id => {
            const item = selectedMaterialsForQr[id];
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <span class="badge bg-dark font-monospace">${item.code}</span>
                    <div class="fw-semibold text-dark mt-1">${item.name}</div>
                </td>
                <td><span class="badge bg-secondary bg-opacity-10 text-secondary border">${item.category}</span></td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="number" name="copies[${item.id}]" class="form-control text-center fw-bold batch-copy-input" 
                               value="${item.copies || 1}" min="1" max="100" 
                               onchange="selectedMaterialsForQr[${item.id}].copies = parseInt(this.value) || 1" required>
                        <span class="input-group-text">ดวง</span>
                    </div>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="removeBatchQrItem(${item.id})">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    function removeBatchQrItem(id) {
        delete selectedMaterialsForQr[id];
        $(`.mat-select-checkbox[value="${id}"]`).prop('checked', false);
        const newCount = Object.keys(selectedMaterialsForQr).length;
        $('#selectedCountBadge').text(newCount);
        const mobileBadge = document.getElementById('mobileAdminQrBadge');
        if (mobileBadge) {
            mobileBadge.innerText = newCount;
            if (newCount > 0) mobileBadge.classList.remove('d-none');
            else mobileBadge.classList.add('d-none');
        }
        renderBatchQrTable();
    }

    function applyCopiesToAll() {
        const count = parseInt(document.getElementById('quickSetAllCopies').value) || 1;
        Object.keys(selectedMaterialsForQr).forEach(id => {
            selectedMaterialsForQr[id].copies = count;
        });
        $('.batch-copy-input').val(count);
    }
</script>

<!-- Mobile Drawer Menu (Offcanvas) สำหรับโทรศัพท์ Super Admin -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="adminMobileMenu" aria-labelledby="adminMobileMenuLabel">
    <div class="offcanvas-header bg-light border-bottom">
        <div class="d-flex align-items-center">
            <span class="rounded-circle p-2 me-2 d-inline-flex align-items-center justify-content-center text-white" style="background-color: var(--mhk-primary); width: 38px; height: 38px;">
                <i class="fa-solid fa-hospital"></i>
            </span>
            <div>
                <h6 class="offcanvas-title fw-bold mb-0 text-dark" id="adminMobileMenuLabel">คลังใหญ่ Super Admin</h6>
                <small class="text-muted"><i class="fa-solid fa-user-shield me-1"></i><?= h($_SESSION['full_name']) ?></small>
            </div>
        </div>
        <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0">
        <div class="list-group list-group-flush">
            <a href="dashboard.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center" data-bs-dismiss="offcanvas">
                <i class="fa-solid fa-warehouse me-3 fa-lg" style="color: var(--mhk-primary); width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">คลังกลาง & บัญชีพัสดุ</div>
                    <small class="text-muted">ดูรายการวัสดุและสต็อกคงคลังกลาง</small>
                </div>
            </a>
            <a href="stock_in_out.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center">
                <i class="fa-solid fa-barcode me-3 text-warning fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">รับเข้า – จ่ายออกบาร์โค้ด</div>
                    <small class="text-muted">ยิงปืนบาร์โค้ด/กล้อง รับของจากบริษัท และจ่ายออก</small>
                </div>
            </a>
            <a href="subunits_stock.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center">
                <i class="fa-solid fa-hospital-user me-3 text-info fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">สต็อกคลังย่อย (Monitor & Control)</div>
                    <small class="text-muted">ส่องดูยอดคงเหลือคลังย่อยและควบคุมการจ่าย</small>
                </div>
            </a>
            <a href="refill.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center">
                <i class="fa-solid fa-dolly me-3 text-success fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">ระบบ Refill เวชภัณฑ์</div>
                    <small class="text-muted">แนะนำเติมสต็อกอัตโนมัติ (Smart Refill)</small>
                </div>
            </a>
            <a href="reports.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center">
                <i class="fa-solid fa-chart-pie me-3 text-danger fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">ระบบประมวลผลรายงานสถิติ</div>
                    <small class="text-muted">รายงานรับ-จ่าย, Stock Card, บริษัท, มูลค่าคลัง</small>
                </div>
            </a>
            <a href="#requisitions_section" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center justify-content-between" data-bs-dismiss="offcanvas">
                <div class="d-flex align-items-center">
                    <i class="fa-solid fa-file-invoice me-3 text-primary fa-lg" style="width: 24px;"></i>
                    <div>
                        <div class="fw-semibold text-dark">ใบขอเบิกจากหน่วยงาน</div>
                        <small class="text-muted">อนุมัติและตรวจจ่ายพัสดุ</small>
                    </div>
                </div>
                <?php if ($stats['pending_reqs'] > 0): ?>
                    <span class="badge bg-danger rounded-pill"><?= $stats['pending_reqs'] ?></span>
                <?php endif; ?>
            </a>
            <a href="scan_approve.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center">
                <i class="fa-solid fa-qrcode me-3 text-warning fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">สแกน QR ตรวจรับพัสดุ</div>
                    <small class="text-muted">ตรวจจ่ายใบเบิกผ่านกล้องโทรศัพท์</small>
                </div>
            </a>
            <a href="users.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center">
                <i class="fa-solid fa-users-gear me-3 text-info fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">จัดการผู้ใช้งาน (Users)</div>
                    <small class="text-muted">เพิ่ม/แก้ไขผู้ใช้ และกำหนดหน่วยงาน</small>
                </div>
            </a>
            <a href="units.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center">
                <i class="fa-solid fa-hospital-user me-3 text-success fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">จัดการหน่วยงาน / ห้องพัสดุ</div>
                    <small class="text-muted">เพิ่ม/แก้ไข/ลบ หน่วยงานในโรงพยาบาล</small>
                </div>
            </a>
            <a href="categories.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center">
                <i class="fa-solid fa-tags me-3 text-secondary fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">จัดการหมวดหมู่พัสดุ</div>
                    <small class="text-muted">เพิ่ม/แก้ไขหมวดหมู่วัสดุเวชภัณฑ์</small>
                </div>
            </a>
            <a href="settings.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center">
                <i class="fa-solid fa-sliders me-3 text-dark fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">ตั้งค่าระบบ & QR URL & โลโก้</div>
                    <small class="text-muted">เปิด/ปิดรับเบิก, โลโก้, ที่อยู่ QR</small>
                </div>
            </a>
            <a href="<?= BASE_URL ?>/report_pdf.php?type=inventory" target="_blank" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center">
                <i class="fa-solid fa-file-pdf me-3 text-danger fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">ออกรายงานสต็อกคลังกลาง (PDF)</div>
                    <small class="text-muted">พิมพ์สรุปพัสดุและเวชภัณฑ์คงเหลือ</small>
                </div>
            </a>
            <a href="<?= BASE_URL ?>/report_pdf.php?type=borrows" target="_blank" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center">
                <i class="fa-solid fa-handshake-angle me-3 text-primary fa-lg" style="width: 24px;"></i>
                <div>
                    <div class="fw-semibold text-dark">รายงานการยืม-คืนทั้งหมด (PDF)</div>
                    <small class="text-muted">ประวัติการยืมพัสดุระหว่างหน่วยงาน</small>
                </div>
            </a>
            <a href="<?= BASE_URL ?>/logout.php" class="list-group-item list-group-item-action py-3 px-3 d-flex align-items-center text-danger bg-light">
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
