<?php
/**
 * ระบบรับเข้า – จ่ายออกเวชภัณฑ์ด้วยบาร์โค้ด (Barcode Stock In & Stock Out)
 * สำหรับหัวหน้าคลัง และเจ้าหน้าที่คลังพัสดุและเวชภัณฑ์กลาง โรงพยาบาลมหาสารคาม
 * รองรับทั้งเครื่องยิงบาร์โค้ด (Hardware Barcode Scanner) และกล้องสแกนเนอร์
 */
require_once __DIR__ . '/../config.php';
checkAuth('super_admin');

$central_unit_id = 1;
$user_id = $_SESSION['user_id'];

// AJAX Endpoint: ค้นหาข้อมูลเวชภัณฑ์ด้วยรหัสบาร์โค้ด หรือรหัสพัสดุ
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_barcode') {
    header('Content-Type: application/json; charset=utf-8');
    $code = trim($_GET['code'] ?? '');

    if (empty($code)) {
        echo json_encode(['success' => false, 'message' => 'ไม่ระบุรหัสบาร์โค้ด']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT m.*, COALESCE(us.quantity, 0) AS central_stock 
        FROM materials m
        LEFT JOIN unit_stocks us ON m.material_id = us.material_id AND us.unit_id = ?
        WHERE m.barcode = ? OR m.material_code = ? OR m.material_id = ?
        LIMIT 1
    ");
    $stmt->execute([$central_unit_id, $code, $code, is_numeric($code) ? (int)$code : 0]);
    $item = $stmt->fetch();

    if ($item) {
        echo json_encode([
            'success' => true,
            'item'    => $item
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => "ไม่พบเวชภัณฑ์ที่มีรหัสบาร์โค้ด [{$code}]"
        ]);
    }
    exit;
}

// จัดการการส่งฟอร์ม (POST Request Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. รับเข้าเวชภัณฑ์ด้วยบาร์โค้ด (Stock In / Receiving from Supplier)
    if ($action === 'barcode_stock_in') {
        $material_id = (int)($_POST['material_id'] ?? 0);
        $quantity    = (int)($_POST['quantity'] ?? 0);
        $unit_price  = (float)($_POST['unit_price'] ?? 0);
        $supplier    = trim($_POST['supplier'] ?? 'องค์การเภสัชกรรม (GPO)');
        $invoice_no  = trim($_POST['invoice_no'] ?? '');
        $lot_no      = trim($_POST['lot_no'] ?? '');
        $exp_date    = !empty($_POST['exp_date']) ? $_POST['exp_date'] : null;
        $note        = trim($_POST['note'] ?? '');

        if ($material_id > 0 && $quantity > 0) {
            try {
                $pdo->beginTransaction();

                // ตรวจสอบข้อมูลพัสดุ
                $mat_stmt = $pdo->prepare("SELECT * FROM materials WHERE material_id = ? FOR UPDATE");
                $mat_stmt->execute([$material_id]);
                $mat = $mat_stmt->fetch();

                if (!$mat) {
                    throw new Exception("ไม่พบรายการเวชภัณฑ์ที่ระบุ");
                }

                if ($unit_price <= 0) {
                    $unit_price = (float)$mat['unit_price'];
                }

                // อัปเดตราคา, บริษัทคู่ค้า, ล็อต, วันหมดอายุล่าสุดในตาราง materials
                $up_mat = $pdo->prepare("
                    UPDATE materials 
                    SET unit_price = ?, supplier = ?, 
                        lot_no = COALESCE(NULLIF(?, ''), lot_no),
                        exp_date = COALESCE(NULLIF(?, ''), exp_date)
                    WHERE material_id = ?
                ");
                $up_mat->execute([$unit_price, $supplier, $lot_no, $exp_date, $material_id]);

                // เพิ่มสต็อกในคลังกลาง (unit_id = 1)
                $stock_stmt = $pdo->prepare("
                    INSERT INTO unit_stocks (unit_id, material_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
                ");
                $stock_stmt->execute([$central_unit_id, $material_id, $quantity]);

                // บันทึกลง stock_movements
                $ref_id = !empty($invoice_no) ? $invoice_no : ('IN-' . date('Ymd-His'));
                $desc = "รับเข้าจากบริษัท: {$supplier}";
                if (!empty($invoice_no)) $desc .= " (เลขที่: {$invoice_no})";
                if (!empty($lot_no)) $desc .= " Lot: {$lot_no}";
                if (!empty($note)) $desc .= " หมายเหตุ: {$note}";

                logStockMovement($pdo, $material_id, $central_unit_id, 'in', $quantity, 'receive_purchase', $ref_id, $desc, $user_id, $unit_price, $supplier);

                $pdo->commit();
                $total_val = number_format($unit_price * $quantity, 2);
                setFlash('success', "รับเข้าเวชภัณฑ์ [{$mat['material_code']}] {$mat['material_name']} จำนวน {$quantity} {$mat['unit_unit']} (มูลค่า {$total_val} บาท) เข้าคลังกลางเรียบร้อยแล้ว");
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', "เกิดข้อผิดพลาดในการรับเข้า: " . $e->getMessage());
            }
        } else {
            setFlash('danger', "กรุณาระบุรายการเวชภัณฑ์และจำนวนที่ถูกต้อง");
        }
        header("Location: stock_in_out.php?tab=in");
        exit;
    }

    // 2. จ่ายออกเวชภัณฑ์ด้วยบาร์โค้ด (Stock Out / Direct Dispense / Transfer)
    if ($action === 'barcode_stock_out') {
        $material_id    = (int)($_POST['material_id'] ?? 0);
        $quantity       = (int)($_POST['quantity'] ?? 0);
        $target_unit_id = (int)($_POST['target_unit_id'] ?? 0);
        $out_type       = $_POST['out_type'] ?? 'unit_transfer'; // 'unit_transfer' or 'disposal' (ชำรุด/หมดอายุ)
        $doc_no         = trim($_POST['doc_no'] ?? '');
        $note           = trim($_POST['note'] ?? '');

        if ($material_id > 0 && $quantity > 0) {
            try {
                $pdo->beginTransaction();

                // ตรวจสต็อกคลังกลาง
                $stk_stmt = $pdo->prepare("SELECT quantity FROM unit_stocks WHERE unit_id = ? AND material_id = ? FOR UPDATE");
                $stk_stmt->execute([$central_unit_id, $material_id]);
                $c_stock = (int)$stk_stmt->fetchColumn();

                if ($c_stock < $quantity) {
                    $pdo->rollBack();
                    setFlash('danger', "ยอดคงเหลือในคลังกลางไม่เพียงพอ (มีคงเหลือ {$c_stock} แต่ต้องการจ่าย {$quantity})");
                    header("Location: stock_in_out.php?tab=out");
                    exit;
                }

                $mat_stmt = $pdo->prepare("SELECT * FROM materials WHERE material_id = ?");
                $mat_stmt->execute([$material_id]);
                $mat = $mat_stmt->fetch();
                $unit_price = (float)$mat['unit_price'];

                // 1. ตัดยอดออกจากคลังกลาง
                $deduct_stmt = $pdo->prepare("UPDATE unit_stocks SET quantity = quantity - ? WHERE unit_id = ? AND material_id = ?");
                $deduct_stmt->execute([$quantity, $central_unit_id, $material_id]);

                $ref_id = !empty($doc_no) ? $doc_no : ('OUT-' . date('Ymd-His'));

                if ($out_type === 'unit_transfer' && $target_unit_id > 0) {
                    // ดึงชื่อหน่วยงานปลายทาง
                    $u_stmt = $pdo->prepare("SELECT unit_name FROM units WHERE unit_id = ?");
                    $u_stmt->execute([$target_unit_id]);
                    $target_unit_name = $u_stmt->fetchColumn() ?: 'หน่วยงาน';

                    // 2. เพิ่มยอดเข้าหน่วยงานปลายทาง
                    $add_stmt = $pdo->prepare("
                        INSERT INTO unit_stocks (unit_id, material_id, quantity)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
                    ");
                    $add_stmt->execute([$target_unit_id, $material_id, $quantity]);

                    // บันทึก log ออกจากคลังใหญ่ และ เข้าคลังย่อย
                    $desc_out = "จ่ายตรงด้วยบาร์โค้ดให้: {$target_unit_name}";
                    if (!empty($note)) $desc_out .= " ({$note})";
                    logStockMovement($pdo, $material_id, $central_unit_id, 'out', $quantity, 'direct_issue', $ref_id, $desc_out, $user_id, $unit_price, $mat['supplier']);

                    $desc_in = "รับเข้าจากการจ่ายตรงคลังกลาง";
                    if (!empty($note)) $desc_in .= " ({$note})";
                    logStockMovement($pdo, $material_id, $target_unit_id, 'in', $quantity, 'direct_issue', $ref_id, $desc_in, $user_id, $unit_price, $mat['supplier']);

                    $msg = "จ่ายออกเวชภัณฑ์ [{$mat['material_code']}] จำนวน {$quantity} {$mat['unit_unit']} ให้แก่ '{$target_unit_name}' เรียบร้อยแล้ว";
                } else {
                    // ตัดจำหน่าย ชำรุด/หมดอายุ
                    $desc = "ตัดจำหน่ายออกจากคลังกลาง (ชำรุด/เสื่อมสภาพ/หมดอายุ)";
                    if (!empty($note)) $desc .= " - {$note}";
                    logStockMovement($pdo, $material_id, $central_unit_id, 'out', $quantity, 'disposal', $ref_id, $desc, $user_id, $unit_price, $mat['supplier']);

                    $msg = "ตัดจำหน่ายเวชภัณฑ์ [{$mat['material_code']}] จำนวน {$quantity} {$mat['unit_unit']} เรียบร้อยแล้ว";
                }

                $pdo->commit();
                setFlash('success', $msg);
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', "เกิดข้อผิดพลาดในการจ่ายออก: " . $e->getMessage());
            }
        } else {
            setFlash('danger', "กรุณาระบุรายการเวชภัณฑ์และจำนวนที่ถูกต้อง");
        }
        header("Location: stock_in_out.php?tab=out");
        exit;
    }
}

// ดึงรายการบริษัทจัดซื้อ
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY supplier_name ASC")->fetchAll();

// ดึงรายการหน่วยงานย่อยทั้งหมด
$units = $pdo->query("SELECT * FROM units WHERE unit_id != 1 ORDER BY unit_id ASC")->fetchAll();

// ดึงรายการเวชภัณฑ์ทั้งหมดสำหรับเลือกกรณีไม่ได้ยิงบาร์โค้ด
$materials = $pdo->query("
    SELECT m.*, COALESCE(us.quantity, 0) AS central_stock 
    FROM materials m
    LEFT JOIN unit_stocks us ON m.material_id = us.material_id AND us.unit_id = 1
    ORDER BY m.material_name ASC
")->fetchAll();

// ดึงประวัติการเคลื่อนไหว รับเข้า-จ่ายออก 20 รายการล่าสุด
$recent_movements = $pdo->query("
    SELECT sm.*, m.material_code, m.barcode, m.material_name, m.unit_unit, u.unit_name, usr.full_name AS performed_by_name
    FROM stock_movements sm
    JOIN materials m ON sm.material_id = m.material_id
    JOIN units u ON sm.unit_id = u.unit_id
    LEFT JOIN users usr ON sm.performed_by = usr.user_id
    WHERE sm.unit_id = 1
    ORDER BY sm.movement_id DESC
    LIMIT 20
")->fetchAll();

$current_tab = $_GET['tab'] ?? 'in';
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รับเข้า – จ่ายออกเวชภัณฑ์ด้วยบาร์โค้ด | คลังใหญ่ รพ.มหาสารคาม</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <!-- HTML5 QR & Barcode Scanner Library -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        .card-barcode {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            transition: transform 0.2s ease;
        }
        .scanner-viewport {
            width: 100%;
            border-radius: 12px;
            overflow: hidden;
            border: 2px dashed #00796b;
            background: #000;
        }
        .barcode-input-lg {
            font-size: 1.25rem;
            font-weight: 600;
            letter-spacing: 1px;
            background-color: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 12px;
        }
        .barcode-input-lg:focus {
            background-color: #ffffff;
            border-color: #00796b;
            box-shadow: 0 0 0 0.25rem rgba(0, 121, 107, 0.2);
        }
        .badge-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.85rem;
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
                <i class="fa-solid fa-barcode fa-lg" style="color: #00796b;"></i>
            </span>
            <div>
                <span class="fw-bold d-block lh-1 fs-6 fs-md-5">โรงพยาบาลมหาสารคาม</span>
                <span class="small opacity-75 fs-7">ระบบรับเข้า – จ่ายออกเวชภัณฑ์ด้วยบาร์โค้ด</span>
            </div>
        </a>

        <div class="d-flex align-items-center gap-2">
            <a href="dashboard.php" class="btn btn-sm btn-light text-teal fw-medium rounded-3" style="color: #00796b;">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับคลังใหญ่
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

    <!-- Header Section -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-barcode text-teal me-2" style="color: #00796b;"></i>ระบบรับเข้า – จ่ายออกด้วยบาร์โค้ด
            </h3>
            <p class="text-muted small mb-0">รองรับทั้งเครื่องยิงบาร์โค้ด USB/Bluetooth (Hardware Gun) และสแกนผ่านกล้องสมาร์ตโฟน/แท็บเล็ต</p>
        </div>
        
        <!-- Navigation Pills -->
        <ul class="nav nav-pills bg-white p-1 rounded-4 shadow-sm border" id="inOutTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link <?= $current_tab === 'in' ? 'active' : '' ?> rounded-pill px-4 py-2 fw-semibold" id="tab-in-btn" data-bs-toggle="pill" data-bs-target="#tab-in">
                    <i class="fa-solid fa-arrow-down-to-bracket me-2 text-success"></i>1. รับเข้าเวชภัณฑ์ (Stock In)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link <?= $current_tab === 'out' ? 'active' : '' ?> rounded-pill px-4 py-2 fw-semibold" id="tab-out-btn" data-bs-toggle="pill" data-bs-target="#tab-out">
                    <i class="fa-solid fa-arrow-up-from-bracket me-2 text-danger"></i>2. จ่ายออกเวชภัณฑ์ (Stock Out)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link rounded-pill px-4 py-2 fw-semibold" id="tab-history-btn" data-bs-toggle="pill" data-bs-target="#tab-history">
                    <i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i>ประวัติล่าสุด
                </button>
            </li>
        </ul>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">
        
        <!-- ========================================== -->
        <!-- TAB 1: รับเข้าเวชภัณฑ์ (Stock In) -->
        <!-- ========================================== -->
        <div class="tab-pane fade <?= $current_tab === 'in' ? 'show active' : '' ?>" id="tab-in">
            <div class="row g-4">
                <!-- ฝั่งซ้าย: สแกนเนอร์บาร์โค้ด -->
                <div class="col-lg-5">
                    <div class="card card-barcode p-4 bg-white">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold mb-0 text-dark">
                                <i class="fa-solid fa-expand text-success me-2"></i>ยิงบาร์โค้ดรับเข้า
                            </h5>
                            <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3" id="btnToggleCamIn">
                                <i class="fa-solid fa-camera me-1"></i>เปิดกล้องสแกน
                            </button>
                        </div>

                        <!-- กล้องสแกนเนอร์ (ซ่อนเป็นค่าเริ่มต้น) -->
                        <div id="camSectionIn" class="mb-3" style="display: none;">
                            <div id="readerIn" class="scanner-viewport"></div>
                            <small class="text-muted text-center d-block mt-2">ส่องบาร์โค้ดหรือ QR Code ให้อยู่ในกรอบ</small>
                        </div>

                        <!-- กล่องยิงปืนบาร์โค้ด (Barcode Gun Input) -->
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">
                                <i class="fa-solid fa-barcode text-success me-1"></i>ช่องยิงบาร์โค้ด (Hardware Scanner):
                            </label>
                            <div class="input-group">
                                <input type="text" id="barcodeInputIn" class="form-control barcode-input-lg" placeholder="ยิงบาร์โค้ด หรือพิมพ์รหัส..." autofocus autocomplete="off">
                                <button class="btn btn-success px-4" type="button" id="btnSearchIn">
                                    <i class="fa-solid fa-magnifying-glass me-1"></i>ค้นหา
                                </button>
                            </div>
                            <small class="text-muted mt-1 d-block">
                                <i class="fa-solid fa-circle-info me-1"></i>ใช้เครื่องยิงบาร์โค้ดยิงใส่กล่องนี้ได้ทันที ระบบจะค้นหาให้อัตโนมัติ
                            </small>
                        </div>

                        <!-- ทางเลือก: เลือกจากรายการพัสดุที่มี -->
                        <div class="border-top pt-3">
                            <label class="form-label small fw-semibold text-secondary">หรือค้นหา/เลือกจากรายการในระบบ:</label>
                            <select id="selectMaterialIn" class="form-select rounded-3">
                                <option value="">-- เลือกรายการเวชภัณฑ์ --</option>
                                <?php foreach ($materials as $m): ?>
                                    <option value="<?= $m['material_id'] ?>" 
                                            data-code="<?= h($m['material_code']) ?>" 
                                            data-barcode="<?= h($m['barcode']) ?>"
                                            data-name="<?= h($m['material_name']) ?>"
                                            data-unit="<?= h($m['unit_unit']) ?>"
                                            data-price="<?= h($m['unit_price']) ?>"
                                            data-supplier="<?= h($m['supplier']) ?>"
                                            data-lot="<?= h($m['lot_no']) ?>"
                                            data-exp="<?= h($m['exp_date']) ?>"
                                            data-stock="<?= (int)$m['central_stock'] ?>">
                                        [<?= h($m['material_code']) ?>] <?= h($m['material_name']) ?> (คงเหลือ: <?= (int)$m['central_stock'] ?> <?= h($m['unit_unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ฝั่งขวา: รายละเอียดการรับเข้า และบันทึก -->
                <div class="col-lg-7">
                    <div class="card card-barcode p-4 bg-white">
                        <h5 class="fw-bold mb-3 text-dark border-bottom pb-2">
                            <i class="fa-solid fa-clipboard-check text-success me-2"></i>รายละเอียดการรับเข้าเวชภัณฑ์
                        </h5>

                        <form method="POST" id="formStockIn">
                            <input type="hidden" name="action" value="barcode_stock_in">
                            <input type="hidden" name="material_id" id="in_material_id" value="">

                            <!-- กล่องแสดงข้อมูลเวชภัณฑ์ที่พบ -->
                            <div id="itemDetailCardIn" class="p-3 rounded-4 mb-3" style="background-color: #f8fafc; border: 1px solid #e2e8f0; display: none;">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <span class="badge bg-success mb-1" id="in_badge_code">MAT-xxxx</span>
                                        <span class="badge bg-secondary mb-1" id="in_badge_barcode">Barcode: xxxx</span>
                                        <h5 class="fw-bold text-dark mb-1" id="in_display_name">ชื่อเวชภัณฑ์</h5>
                                        <div class="text-muted small">
                                            หมวดหมู่: <span id="in_display_category" class="fw-semibold">-</span> | 
                                            หน่วยนับ: <span id="in_display_unit" class="fw-semibold">-</span>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <small class="text-muted d-block">สต็อกคลังกลางปัจจุบัน</small>
                                        <span class="fs-4 fw-bold text-success" id="in_display_stock">0</span>
                                        <span class="small text-muted" id="in_display_unit_label">ชิ้น</span>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3">
                                <!-- จำนวนรับเข้า -->
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-dark">จำนวนที่รับเข้า <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" name="quantity" id="in_quantity" class="form-control form-control-lg fw-bold text-success" min="1" placeholder="ระบุจำนวน" required>
                                        <span class="input-group-text bg-light in-unit-addon">หน่วย</span>
                                    </div>
                                </div>

                                <!-- ราคาต่อหน่วย -->
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-dark">ราคาต่อหน่วย (บาท) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" step="0.01" name="unit_price" id="in_unit_price" class="form-control form-control-lg fw-bold" placeholder="0.00" required>
                                        <span class="input-group-text bg-light">บาท</span>
                                    </div>
                                </div>

                                <!-- บริษัทที่จัดซื้อ / ผู้จัดจำหน่าย -->
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-dark">บริษัทที่จัดซื้อ (Supplier) <span class="text-danger">*</span></label>
                                    <select name="supplier" id="in_supplier" class="form-select form-select-lg" required>
                                        <?php foreach ($suppliers as $s): ?>
                                            <option value="<?= h($s['supplier_name']) ?>"><?= h($s['supplier_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- เลขที่ใบส่งของ / PO -->
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-dark">เลขที่ใบสั่งซื้อ/ใบส่งของ (PO / Invoice)</label>
                                    <input type="text" name="invoice_no" id="in_invoice_no" class="form-control form-control-lg" placeholder="เช่น PO-6911-001 หรือ INV-1234">
                                </div>

                                <!-- เลขล็อต -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold text-secondary">เลขที่ล็อต (Lot No.)</label>
                                    <input type="text" name="lot_no" id="in_lot_no" class="form-control" placeholder="เช่น LOT-2026-001">
                                </div>

                                <!-- วันหมดอายุ -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold text-secondary">วันหมดอายุ (Exp Date)</label>
                                    <input type="date" name="exp_date" id="in_exp_date" class="form-control">
                                </div>

                                <!-- หมายเหตุ -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold text-secondary">หมายเหตุการรับเข้า</label>
                                    <input type="text" name="note" id="in_note" class="form-control" placeholder="ระบุรายละเอียดเพิ่มเติม (ถ้ามี)">
                                </div>

                                <!-- แสดงมูลค่ารวมคำนวณสด -->
                                <div class="col-12">
                                    <div class="p-3 bg-light rounded-3 d-flex justify-content-between align-items-center">
                                        <span class="fw-semibold text-secondary">มูลค่าการรับเข้ารวมโดยประมาณ:</span>
                                        <span class="fs-4 fw-bold text-success" id="in_calc_total">0.00 บาท</span>
                                    </div>
                                </div>

                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-success btn-lg w-100 rounded-3 shadow-sm py-3 fw-bold" id="btnSubmitIn" disabled>
                                        <i class="fa-solid fa-circle-check me-2"></i>ยืนยันการรับเข้าสต็อกคลังกลาง
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- TAB 2: จ่ายออกเวชภัณฑ์ (Stock Out) -->
        <!-- ========================================== -->
        <div class="tab-pane fade <?= $current_tab === 'out' ? 'show active' : '' ?>" id="tab-out">
            <div class="row g-4">
                <!-- ฝั่งซ้าย: สแกนเนอร์บาร์โค้ด จ่ายออก -->
                <div class="col-lg-5">
                    <div class="card card-barcode p-4 bg-white">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold mb-0 text-dark">
                                <i class="fa-solid fa-expand text-danger me-2"></i>ยิงบาร์โค้ดจ่ายออก
                            </h5>
                            <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3" id="btnToggleCamOut">
                                <i class="fa-solid fa-camera me-1"></i>เปิดกล้องสแกน
                            </button>
                        </div>

                        <!-- กล้องสแกนเนอร์ จ่ายออก -->
                        <div id="camSectionOut" class="mb-3" style="display: none;">
                            <div id="readerOut" class="scanner-viewport"></div>
                            <small class="text-muted text-center d-block mt-2">ส่องบาร์โค้ดหรือ QR Code ให้อยู่ในกรอบ</small>
                        </div>

                        <!-- กล่องยิงปืนบาร์โค้ด -->
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">
                                <i class="fa-solid fa-barcode text-danger me-1"></i>ช่องยิงบาร์โค้ดจ่ายออก:
                            </label>
                            <div class="input-group">
                                <input type="text" id="barcodeInputOut" class="form-control barcode-input-lg" placeholder="ยิงบาร์โค้ดจ่ายออก..." autocomplete="off">
                                <button class="btn btn-danger px-4" type="button" id="btnSearchOut">
                                    <i class="fa-solid fa-magnifying-glass me-1"></i>ค้นหา
                                </button>
                            </div>
                        </div>

                        <!-- เลือกจากรายการเวชภัณฑ์ -->
                        <div class="border-top pt-3">
                            <label class="form-label small fw-semibold text-secondary">หรือค้นหา/เลือกจากรายการในระบบ:</label>
                            <select id="selectMaterialOut" class="form-select rounded-3">
                                <option value="">-- เลือกรายการเวชภัณฑ์ --</option>
                                <?php foreach ($materials as $m): ?>
                                    <option value="<?= $m['material_id'] ?>" 
                                            data-code="<?= h($m['material_code']) ?>" 
                                            data-barcode="<?= h($m['barcode']) ?>"
                                            data-name="<?= h($m['material_name']) ?>"
                                            data-unit="<?= h($m['unit_unit']) ?>"
                                            data-price="<?= h($m['unit_price']) ?>"
                                            data-supplier="<?= h($m['supplier']) ?>"
                                            data-stock="<?= (int)$m['central_stock'] ?>">
                                        [<?= h($m['material_code']) ?>] <?= h($m['material_name']) ?> (คลังกลางมี: <?= (int)$m['central_stock'] ?> <?= h($m['unit_unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ฝั่งขวา: รายละเอียดการจ่ายออก -->
                <div class="col-lg-7">
                    <div class="card card-barcode p-4 bg-white">
                        <h5 class="fw-bold mb-3 text-dark border-bottom pb-2">
                            <i class="fa-solid fa-dolly text-danger me-2"></i>รายละเอียดการจ่ายออกเวชภัณฑ์
                        </h5>

                        <form method="POST" id="formStockOut">
                            <input type="hidden" name="action" value="barcode_stock_out">
                            <input type="hidden" name="material_id" id="out_material_id" value="">

                            <!-- กล่องแสดงข้อมูลเวชภัณฑ์ที่พบ -->
                            <div id="itemDetailCardOut" class="p-3 rounded-4 mb-3" style="background-color: #fff5f5; border: 1px solid #fed7d7; display: none;">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <span class="badge bg-danger mb-1" id="out_badge_code">MAT-xxxx</span>
                                        <span class="badge bg-secondary mb-1" id="out_badge_barcode">Barcode: xxxx</span>
                                        <h5 class="fw-bold text-dark mb-1" id="out_display_name">ชื่อเวชภัณฑ์</h5>
                                        <div class="text-muted small">
                                            หมวดหมู่: <span id="out_display_category" class="fw-semibold">-</span> | 
                                            ราคาต่อหน่วย: <span id="out_display_price" class="fw-semibold text-danger">0.00</span> บาท
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <small class="text-muted d-block">สต็อกคลังกลางที่มีให้จ่าย</small>
                                        <span class="fs-4 fw-bold text-danger" id="out_display_stock">0</span>
                                        <span class="small text-muted" id="out_display_unit_label">ชิ้น</span>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3">
                                <!-- ประเภทการจ่ายออก -->
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-dark">ประเภทการจ่ายออก <span class="text-danger">*</span></label>
                                    <select name="out_type" id="out_type" class="form-select form-select-lg fw-semibold" required>
                                        <option value="unit_transfer">โอน/จ่ายให้คลังย่อย (แผนก/วอร์ด)</option>
                                        <option value="disposal">ตัดจำหน่าย (ชำรุด/เสื่อมสภาพ/หมดอายุ)</option>
                                    </select>
                                </div>

                                <!-- คลังย่อยปลายทาง -->
                                <div class="col-md-6" id="wrapperTargetUnit">
                                    <label class="form-label fw-bold text-dark">หน่วยงานที่รับของ (คลังย่อย) <span class="text-danger">*</span></label>
                                    <select name="target_unit_id" id="out_target_unit_id" class="form-select form-select-lg">
                                        <option value="">-- เลือกหน่วยงานที่รับเวชภัณฑ์ --</option>
                                        <?php foreach ($units as $u): ?>
                                            <option value="<?= $u['unit_id'] ?>">[<?= h($u['unit_code']) ?>] <?= h($u['unit_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- จำนวนที่จ่ายออก -->
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-dark">จำนวนที่จ่ายออก <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" name="quantity" id="out_quantity" class="form-control form-control-lg fw-bold text-danger" min="1" placeholder="ระบุจำนวน" required>
                                        <span class="input-group-text bg-light out-unit-addon">หน่วย</span>
                                    </div>
                                </div>

                                <!-- เลขที่เอกสารอ้างอิง -->
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-dark">เลขที่ใบจ่าย/เอกสารอ้างอิง</label>
                                    <input type="text" name="doc_no" id="out_doc_no" class="form-control form-control-lg" placeholder="เช่น DIS-6911-001 หรือ REQ-xxxx">
                                </div>

                                <!-- หมายเหตุการจ่าย -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold text-secondary">หมายเหตุการจ่าย</label>
                                    <input type="text" name="note" id="out_note" class="form-control" placeholder="ระบุเหตุผลหรือรายละเอียดเพิ่มเติม">
                                </div>

                                <div class="col-12 mt-4">
                                    <button type="submit" class="btn btn-danger btn-lg w-100 rounded-3 shadow-sm py-3 fw-bold" id="btnSubmitOut" disabled>
                                        <i class="fa-solid fa-truck-ramp-box me-2"></i>ยืนยันการตัดจ่ายเวชภัณฑ์ออกจากคลังกลาง
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- TAB 3: ประวัติการทำรายการล่าสุด -->
        <!-- ========================================== -->
        <div class="tab-pane fade" id="tab-history">
            <div class="card card-barcode p-4 bg-white">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>ประวัติการรับเข้า – จ่ายออกของคลังกลางล่าสุด
                    </h5>
                    <a href="reports.php" class="btn btn-sm btn-outline-primary rounded-pill">
                        <i class="fa-solid fa-chart-line me-1"></i>ดูรายงานประมวลผลฉบับเต็ม
                    </a>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>วัน-เวลา</th>
                                <th>ประเภท</th>
                                <th>รหัสเวชภัณฑ์ / บาร์โค้ด</th>
                                <th>รายการเวชภัณฑ์</th>
                                <th class="text-end">จำนวน</th>
                                <th class="text-end">ราคา/หน่วย</th>
                                <th class="text-end">มูลค่ารวม</th>
                                <th>บริษัท/หน่วยงานอ้างอิง</th>
                                <th>ผู้ดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_movements)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">ยังไม่มีประวัติการรับเข้าหรือจ่ายออก</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_movements as $rm): ?>
                                    <tr>
                                        <td class="small text-secondary"><?= thaiDate($rm['created_at'], true) ?></td>
                                        <td>
                                            <?php if ($rm['movement_type'] === 'in'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                    <i class="fa-solid fa-arrow-down me-1"></i>รับเข้า
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                                    <i class="fa-solid fa-arrow-up me-1"></i>จ่ายออก
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?= h($rm['material_code']) ?></span>
                                            <?php if (!empty($rm['barcode']) && $rm['barcode'] !== $rm['material_code']): ?>
                                                <small class="text-muted d-block"><?= h($rm['barcode']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold text-dark"><?= h($rm['material_name']) ?></td>
                                        <td class="text-end fw-bold <?= $rm['movement_type'] === 'in' ? 'text-success' : 'text-danger' ?>">
                                            <?= $rm['movement_type'] === 'in' ? '+' : '-' ?><?= number_format($rm['quantity']) ?> <?= h($rm['unit_unit']) ?>
                                        </td>
                                        <td class="text-end text-muted small"><?= number_format($rm['unit_price'], 2) ?> ฿</td>
                                        <td class="text-end fw-bold"><?= number_format($rm['total_price'], 2) ?> ฿</td>
                                        <td class="small text-secondary">
                                            <?= h($rm['supplier'] ?: $rm['unit_name']) ?>
                                            <?php if (!empty($rm['reference_id'])): ?>
                                                <small class="d-block text-muted">[<?= h($rm['reference_id']) ?>]</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?= h($rm['performed_by_name'] ?? 'ระบบ') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

</div>

<!-- Sound effects for barcode gun -->
<audio id="beepSuccess" src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3" preload="auto"></audio>
<audio id="beepError" src="https://assets.mixkit.co/active_storage/sfx/2873/2873-preview.mp3" preload="auto"></audio>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function playBeep(success = true) {
        try {
            const audio = document.getElementById(success ? 'beepSuccess' : 'beepError');
            if (audio) {
                audio.currentTime = 0;
                audio.play().catch(e => console.log('Audio autoplay blocked'));
            }
        } catch(e) {}
    }

    // ==========================================
    // LOGIC TAB 1: รับเข้าเวชภัณฑ์ (Stock In)
    // ==========================================
    const barcodeInputIn   = document.getElementById('barcodeInputIn');
    const selectMaterialIn = document.getElementById('selectMaterialIn');
    const btnSearchIn      = document.getElementById('btnSearchIn');
    const formStockIn      = document.getElementById('formStockIn');
    const btnSubmitIn      = document.getElementById('btnSubmitIn');
    const inQtyInput       = document.getElementById('in_quantity');
    const inPriceInput     = document.getElementById('in_unit_price');
    const inCalcTotal      = document.getElementById('in_calc_total');

    function searchItemIn(code) {
        if (!code || !code.trim()) return;
        fetch('stock_in_out.php?ajax=search_barcode&code=' + encodeURIComponent(code.trim()))
            .then(res => res.json())
            .then(data => {
                if (data.success && data.item) {
                    populateInForm(data.item);
                    playBeep(true);
                } else {
                    playBeep(false);
                    Swal.fire({
                        icon: 'warning',
                        title: 'ไม่พบเวชภัณฑ์',
                        text: data.message || 'ไม่พบรายการที่มีรหัสบาร์โค้ดนี้',
                        confirmButtonText: 'ตกลง',
                        confirmButtonColor: '#00796b'
                    });
                }
            })
            .catch(err => {
                console.error(err);
                playBeep(false);
            });
    }

    function populateInForm(item) {
        document.getElementById('in_material_id').value = item.material_id;
        document.getElementById('in_badge_code').textContent = item.material_code;
        document.getElementById('in_badge_barcode').textContent = 'Barcode: ' + (item.barcode || item.material_code);
        document.getElementById('in_display_name').textContent = item.material_name;
        document.getElementById('in_display_category').textContent = item.category || '-';
        document.getElementById('in_display_unit').textContent = item.unit_unit || 'ชิ้น';
        document.getElementById('in_display_stock').textContent = parseInt(item.central_stock || 0).toLocaleString();
        document.getElementById('in_display_unit_label').textContent = item.unit_unit || 'ชิ้น';

        document.querySelectorAll('.in-unit-addon').forEach(el => el.textContent = item.unit_unit || 'หน่วย');

        document.getElementById('in_unit_price').value = parseFloat(item.unit_price || 0).toFixed(2);
        if (item.supplier) {
            document.getElementById('in_supplier').value = item.supplier;
        }
        if (item.lot_no) {
            document.getElementById('in_lot_no').value = item.lot_no;
        }
        if (item.exp_date) {
            document.getElementById('in_exp_date').value = item.exp_date;
        }

        document.getElementById('itemDetailCardIn').style.display = 'block';
        btnSubmitIn.disabled = false;

        // Auto focus quantity
        inQtyInput.focus();
        inQtyInput.select();
        calculateInTotal();
    }

    function calculateInTotal() {
        const qty = parseFloat(inQtyInput.value) || 0;
        const price = parseFloat(inPriceInput.value) || 0;
        const total = qty * price;
        inCalcTotal.textContent = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' บาท';
    }

    inQtyInput.addEventListener('input', calculateInTotal);
    inPriceInput.addEventListener('input', calculateInTotal);

    // Barcode Gun: Enter Key Trigger
    barcodeInputIn.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchItemIn(barcodeInputIn.value);
        }
    });

    btnSearchIn.addEventListener('click', function() {
        searchItemIn(barcodeInputIn.value);
    });

    selectMaterialIn.addEventListener('change', function() {
        const opt = selectMaterialIn.selectedOptions[0];
        if (opt && opt.value) {
            searchItemIn(opt.dataset.code || opt.value);
        }
    });

    // Camera Scanner for In
    let html5QrCodeIn = null;
    let camInRunning = false;
    document.getElementById('btnToggleCamIn').addEventListener('click', function() {
        const camDiv = document.getElementById('camSectionIn');
        if (!camInRunning) {
            camDiv.style.display = 'block';
            this.innerHTML = '<i class="fa-solid fa-stop me-1"></i>ปิดกล้อง';
            html5QrCodeIn = new Html5Qrcode("readerIn");
            html5QrCodeIn.start(
                { facingMode: "environment" },
                { fps: 10, qrbox: { width: 250, height: 150 } },
                (decodedText) => {
                    barcodeInputIn.value = decodedText;
                    searchItemIn(decodedText);
                    html5QrCodeIn.stop().then(() => {
                        camDiv.style.display = 'none';
                        document.getElementById('btnToggleCamIn').innerHTML = '<i class="fa-solid fa-camera me-1"></i>เปิดกล้องสแกน';
                        camInRunning = false;
                    });
                },
                (errorMessage) => {}
            ).catch(err => {
                alert('ไม่สามารถเปิดกล้องได้: ' + err);
            });
            camInRunning = true;
        } else {
            if (html5QrCodeIn) {
                html5QrCodeIn.stop().then(() => {
                    camDiv.style.display = 'none';
                    this.innerHTML = '<i class="fa-solid fa-camera me-1"></i>เปิดกล้องสแกน';
                    camInRunning = false;
                });
            }
        }
    });

    // ==========================================
    // LOGIC TAB 2: จ่ายออกเวชภัณฑ์ (Stock Out)
    // ==========================================
    const barcodeInputOut   = document.getElementById('barcodeInputOut');
    const selectMaterialOut = document.getElementById('selectMaterialOut');
    const btnSearchOut      = document.getElementById('btnSearchOut');
    const formStockOut      = document.getElementById('formStockOut');
    const btnSubmitOut      = document.getElementById('btnSubmitOut');
    const outQtyInput       = document.getElementById('out_quantity');
    const outTypeSelect     = document.getElementById('out_type');
    const wrapperTargetUnit = document.getElementById('wrapperTargetUnit');
    let currentOutMaxStock  = 0;

    outTypeSelect.addEventListener('change', function() {
        if (this.value === 'disposal') {
            wrapperTargetUnit.style.display = 'none';
            document.getElementById('out_target_unit_id').required = false;
        } else {
            wrapperTargetUnit.style.display = 'block';
            document.getElementById('out_target_unit_id').required = true;
        }
    });

    function searchItemOut(code) {
        if (!code || !code.trim()) return;
        fetch('stock_in_out.php?ajax=search_barcode&code=' + encodeURIComponent(code.trim()))
            .then(res => res.json())
            .then(data => {
                if (data.success && data.item) {
                    populateOutForm(data.item);
                    playBeep(true);
                } else {
                    playBeep(false);
                    Swal.fire({
                        icon: 'warning',
                        title: 'ไม่พบเวชภัณฑ์',
                        text: data.message || 'ไม่พบรายการที่มีรหัสบาร์โค้ดนี้',
                        confirmButtonText: 'ตกลง',
                        confirmButtonColor: '#e53e3e'
                    });
                }
            })
            .catch(err => {
                console.error(err);
                playBeep(false);
            });
    }

    function populateOutForm(item) {
        currentOutMaxStock = parseInt(item.central_stock || 0);

        document.getElementById('out_material_id').value = item.material_id;
        document.getElementById('out_badge_code').textContent = item.material_code;
        document.getElementById('out_badge_barcode').textContent = 'Barcode: ' + (item.barcode || item.material_code);
        document.getElementById('out_display_name').textContent = item.material_name;
        document.getElementById('out_display_category').textContent = item.category || '-';
        document.getElementById('out_display_price').textContent = parseFloat(item.unit_price || 0).toFixed(2);
        document.getElementById('out_display_stock').textContent = currentOutMaxStock.toLocaleString();
        document.getElementById('out_display_unit_label').textContent = item.unit_unit || 'ชิ้น';

        document.querySelectorAll('.out-unit-addon').forEach(el => el.textContent = item.unit_unit || 'หน่วย');

        outQtyInput.max = currentOutMaxStock;

        document.getElementById('itemDetailCardOut').style.display = 'block';
        
        if (currentOutMaxStock <= 0) {
            btnSubmitOut.disabled = true;
            Swal.fire({
                icon: 'error',
                title: 'ยอดสต็อกหมด',
                text: 'เวชภัณฑ์นี้มียอดคงเหลือในคลังกลางเป็น 0 ไม่สามารถจ่ายออกได้',
                confirmButtonColor: '#e53e3e'
            });
        } else {
            btnSubmitOut.disabled = false;
            outQtyInput.focus();
            outQtyInput.select();
        }
    }

    barcodeInputOut.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchItemOut(barcodeInputOut.value);
        }
    });

    btnSearchOut.addEventListener('click', function() {
        searchItemOut(barcodeInputOut.value);
    });

    selectMaterialOut.addEventListener('change', function() {
        const opt = selectMaterialOut.selectedOptions[0];
        if (opt && opt.value) {
            searchItemOut(opt.dataset.code || opt.value);
        }
    });

    // Camera Scanner for Out
    let html5QrCodeOut = null;
    let camOutRunning = false;
    document.getElementById('btnToggleCamOut').addEventListener('click', function() {
        const camDiv = document.getElementById('camSectionOut');
        if (!camOutRunning) {
            camDiv.style.display = 'block';
            this.innerHTML = '<i class="fa-solid fa-stop me-1"></i>ปิดกล้อง';
            html5QrCodeOut = new Html5Qrcode("readerOut");
            html5QrCodeOut.start(
                { facingMode: "environment" },
                { fps: 10, qrbox: { width: 250, height: 150 } },
                (decodedText) => {
                    barcodeInputOut.value = decodedText;
                    searchItemOut(decodedText);
                    html5QrCodeOut.stop().then(() => {
                        camDiv.style.display = 'none';
                        document.getElementById('btnToggleCamOut').innerHTML = '<i class="fa-solid fa-camera me-1"></i>เปิดกล้องสแกน';
                        camOutRunning = false;
                    });
                },
                (errorMessage) => {}
            ).catch(err => {
                alert('ไม่สามารถเปิดกล้องได้: ' + err);
            });
            camOutRunning = true;
        } else {
            if (html5QrCodeOut) {
                html5QrCodeOut.stop().then(() => {
                    camDiv.style.display = 'none';
                    this.innerHTML = '<i class="fa-solid fa-camera me-1"></i>เปิดกล้องสแกน';
                    camOutRunning = false;
                });
            }
        }
    });
</script>
</body>
</html>

