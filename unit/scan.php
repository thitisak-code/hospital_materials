<?php
/**
 * หน้าสแกน QR Code / เบิกวัสดุของหน่วย (QR Scanner & Quick Requisition)
 * ระบบจัดการวัสดุ โรงพยาบาลมหาสารคาม
 */
require_once __DIR__ . '/../config.php';
checkAuth();

$current_unit_id = (int)($_SESSION['unit_id'] ?? 2);
if ($_SESSION['role'] === 'super_admin' && isset($_GET['unit_id'])) {
    $current_unit_id = (int)$_GET['unit_id'];
}

// AJAX API Endpoint: ค้นหาข้อมูลพัสดุตามรหัส QR Code แบบ Real-time
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_material') {
    header('Content-Type: application/json; charset=utf-8');
    $code = trim($_GET['code'] ?? '');

    if (empty($code)) {
        echo json_encode(['success' => false, 'message' => 'ไม่ได้รับรหัสพัสดุ']);
        exit;
    }

    // ค้นหาข้อมูลวัสดุ รองรับทั้งรหัสบาร์โค้ด, รหัสพัสดุ, หรือ ID
    $stmt = $pdo->prepare("SELECT * FROM materials WHERE barcode = ? OR material_code = ? OR material_id = ? LIMIT 1");
    $stmt->execute([$code, $code, is_numeric($code) ? (int)$code : 0]);
    $material = $stmt->fetch();

    if (!$material) {
        echo json_encode(['success' => false, 'message' => "ไม่พบข้อมูลพัสดุรหัส [{$code}] ในระบบ"]);
        exit;
    }

    $mat_id = (int)$material['material_id'];

    // สต็อกในหน่วยงานปัจจุบัน
    $unit_stock_stmt = $pdo->prepare("SELECT quantity FROM unit_stocks WHERE unit_id = ? AND material_id = ?");
    $unit_stock_stmt->execute([$current_unit_id, $mat_id]);
    $my_stock = (int)$unit_stock_stmt->fetchColumn();

    // สต็อกในคลังกลาง (Unit ID = 1)
    $central_stock_stmt = $pdo->prepare("SELECT quantity FROM unit_stocks WHERE unit_id = 1 AND material_id = ?");
    $central_stock_stmt->execute([$mat_id]);
    $central_stock = (int)$central_stock_stmt->fetchColumn();

    // ดึงสต็อกในหน่วยงานอื่นๆ ทั้งหมดที่มีของรายการนี้ให้ยืม
    $other_stocks_stmt = $pdo->prepare("
        SELECT u.unit_id, u.unit_code, u.unit_name, COALESCE(us.quantity, 0) AS quantity
        FROM units u
        JOIN unit_stocks us ON u.unit_id = us.unit_id
        WHERE us.material_id = ? AND u.unit_id != ? AND u.unit_id != 1 AND us.quantity > 0
        ORDER BY us.quantity DESC
    ");
    $other_stocks_stmt->execute([$mat_id, $current_unit_id]);
    $other_units = $other_stocks_stmt->fetchAll();

    echo json_encode([
        'success'       => true,
        'material'      => $material,
        'my_stock'      => $my_stock,
        'central_stock' => $central_stock,
        'other_units'   => $other_units
    ]);
    exit;
}

// ข้อมูลหน่วยงานปัจจุบัน
$unit_stmt = $pdo->prepare("SELECT * FROM units WHERE unit_id = ?");
$unit_stmt->execute([$current_unit_id]);
$current_unit = $unit_stmt->fetch();

// ดึงรายการวัสดุทั้งหมดสำหรับตัวเลือกจำลองการสแกน (Quick Scan Simulator)
$all_materials = $pdo->query("SELECT material_id, material_code, barcode, material_name, category FROM materials ORDER BY material_id ASC")->fetchAll();

// ดึงประวัติการจ่ายใช้กับผู้รับบริการ (คนไข้) ล่าสุดของหน่วยนี้
$patient_usages_stmt = $pdo->prepare("
    SELECT u.*, m.material_code, m.barcode, m.material_name, m.unit_unit
    FROM unit_usages u
    JOIN materials m ON u.material_id = m.material_id
    WHERE u.unit_id = ?
    ORDER BY u.usage_id DESC
    LIMIT 15
");
$patient_usages_stmt->execute([$current_unit_id]);
$recent_patient_usages = $patient_usages_stmt->fetchAll();

$req_enabled = getSetting('requisition_enabled', '1') === '1';
$closed_reason = getSetting('requisition_closed_reason', 'ระบบปิดรับการเบิกพัสดุชั่วคราวเพื่อตรวจนับสต็อกประจำงวด');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สแกน QR Code พัสดุ | โรงพยาบาลมหาสารคาม</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <!-- HTML5 QR Code Scanner Library -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f1f5f9;
        }
        h1, h2, h3, h4, h5, .nav-link, .btn {
            font-family: 'Prompt', sans-serif;
        }
        .scanner-card {
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        #reader {
            width: 100%;
            border-radius: 12px;
            overflow: hidden;
            border: 2px dashed #cbd5e1;
            background: #000;
        }
        #reader video {
            border-radius: 12px;
        }
        .result-card {
            background: #ffffff;
            border-radius: 20px;
            border: 2px solid #0284c7;
            box-shadow: 0 12px 28px rgba(2, 132, 199, 0.12);
            display: none;
            animation: fadeIn 0.3s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .quick-item-badge {
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.82rem;
            padding: 8px 12px;
            border-radius: 10px;
        }
        .quick-item-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
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
            color: #0284c7;
        }
        .bottom-nav-item i {
            font-size: 1.25rem;
            margin-bottom: 2px;
        }
        .bottom-nav-scan-btn {
            position: relative;
            top: -12px;
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff !important;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 18px rgba(2, 132, 199, 0.45);
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

        @media (max-width: 991px) {
            .mobile-bottom-nav {
                display: flex !important;
            }
            body {
                padding-bottom: 78px !important;
            }
        }
    </style>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/app.css?v=20261102">
<script src="<?= BASE_URL ?>/assets/app.js?v=20261102" defer></script>
</head>
<body>

<?php renderLoadingScreen(); ?>

<!-- Navbar -->
<?php require __DIR__ . '/../includes/navigation.php'; ?>

<!-- Mobile Bottom Navigation Bar (สำหรับโทรศัพท์มือถือ) -->
<nav class="mobile-bottom-nav d-lg-none">
    <!-- 1. กลับห้องพัสดุ -->
    <a href="<?= BASE_URL ?>/unit/dashboard.php<?= isset($_GET['unit_id']) ? '?unit_id='.$current_unit_id : '' ?>" class="bottom-nav-item">
        <i class="fa-solid fa-house"></i>
        <span>ห้องพัสดุ</span>
    </a>

    <!-- 2. กรอกรหัสค้นหา -->
    <button type="button" class="bottom-nav-item" onclick="document.getElementById('manualCodeInput').focus()">
        <i class="fa-solid fa-keyboard"></i>
        <span>พิมพ์รหัส</span>
    </button>

    <!-- 3. เปิด/รีเซ็ตกล้อง (ปุ่มลอยตรงกลาง) -->
    <button type="button" class="bottom-nav-scan-btn shadow" onclick="startScanner()" title="สแกนกล้องใหม่">
        <i class="fa-solid fa-camera"></i>
    </button>

    <!-- 4. ปิดกล้อง -->
    <button type="button" class="bottom-nav-item text-secondary" onclick="stopScanner()">
        <i class="fa-solid fa-video-slash"></i>
        <span>ปิดกล้อง</span>
    </button>

    <!-- 5. รายการวัสดุในห้อง -->
    <a href="<?= BASE_URL ?>/unit/dashboard.php#materialsSection" class="bottom-nav-item">
        <i class="fa-solid fa-boxes-stacked"></i>
        <span>พัสดุในห้อง</span>
    </a>
</nav>

<div class="container py-4" style="max-width: 900px;">

    <!-- Current Unit Info Badge -->
    <div class="d-flex align-items-center justify-content-between bg-white p-3 rounded-4 border shadow-sm mb-4">
        <div class="d-flex align-items-center">
            <div class="p-2 bg-primary bg-opacity-10 text-primary rounded-3 me-3">
                <i class="fa-solid fa-hospital-user fa-2x"></i>
            </div>
            <div>
                <span class="badge bg-dark font-monospace"><?= h($current_unit['unit_code']) ?></span>
                <h5 class="fw-bold text-dark mb-0"><?= h($current_unit['unit_name']) ?></h5>
            </div>
        </div>
        <span class="badge bg-success bg-opacity-10 text-success border px-3 py-2">
            <i class="fa-solid fa-circle-check me-1"></i> จุดสแกนพร้อมทำงาน
        </span>
    </div>

    <!-- Scanner Box -->
    <div class="card scanner-card p-4 mb-4">
        <h4 class="fw-bold mb-1 text-dark">
            <i class="fa-solid fa-camera me-2 text-primary"></i> สแกน QR Code ด้วยกล้อง หรือ ค้นหารหัสพัสดุ
        </h4>
        <p class="text-muted small mb-3">
            ส่องกล้องไปยัง QR Code บนกล่องหรือฉลากพัสดุเพื่อดึงข้อมูลสต็อกและทำรายการทันที
        </p>

        <!-- Live Camera Container -->
        <div class="row g-4 align-items-center mb-3">
            <div class="col-md-6 text-center">
                <div id="reader"></div>
                <div class="mt-2">
                    <button class="btn btn-sm btn-outline-primary rounded-3" id="btnRestartCamera" onclick="startScanner()">
                        <i class="fa-solid fa-camera-rotate me-1"></i> เปิด/รีเซ็ตกล้อง
                    </button>
                    <button class="btn btn-sm btn-outline-secondary rounded-3" id="btnStopCamera" onclick="stopScanner()">
                        <i class="fa-solid fa-video-slash me-1"></i> ปิดกล้อง
                    </button>
                </div>
            </div>

            <!-- Manual Barcode Search -->
            <div class="col-md-6">
                <div class="bg-light p-3 rounded-4 border">
                    <label class="form-label fw-bold small text-dark mb-2">
                        <i class="fa-solid fa-keyboard me-1 text-primary"></i> หรือกรอกรหัสพัสดุด้วยตนเอง:
                    </label>
                    <div class="input-group mb-2">
                        <input type="text" id="manualCodeInput" class="form-control rounded-start-3 font-monospace" placeholder="เช่น MAT-1001">
                        <button class="btn btn-primary rounded-end-3" onclick="processManualCode()">
                            <i class="fa-solid fa-magnifying-glass me-1"></i> ค้นหา
                        </button>
                    </div>
                    <small class="text-muted d-block">รองรับการสแกนด้วยเครื่องยิงบาร์โค้ด USB ได้โดยตรง</small>
                </div>

                <!-- Quick Simulate Buttons -->
                <div class="mt-3">
                    <label class="form-label fw-semibold small text-muted mb-2">
                        <i class="fa-solid fa-wand-magic-sparkles text-warning me-1"></i> ปุ่มจำลองการสแกนพัสดุทดสอบ (คลิกเพื่อทดสอบ):
                    </label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (array_slice($all_materials, 0, 5) as $sm): ?>
                            <span class="badge bg-white text-dark border quick-item-badge" 
                                  onclick="lookupMaterial('<?= h($sm['material_code']) ?>')">
                                <i class="fa-solid fa-box text-primary me-1"></i> <?= h($sm['material_code']) ?>
                                <small class="text-muted d-block"><?= h($sm['material_name']) ?></small>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scanned Result & Requisition / Borrowing Box -->
    <div class="card result-card p-4 mb-4" id="scannedResultBox">
        <div class="d-flex flex-wrap align-items-center justify-content-between border-bottom pb-3 mb-3 gap-2">
            <div>
                <span class="badge bg-primary font-monospace fs-6" id="resMaterialCode"></span>
                <span class="badge bg-secondary bg-opacity-10 text-secondary border ms-2" id="resCategory"></span>
                <h3 class="fw-bold text-dark mt-2 mb-0" id="resMaterialName"></h3>
                <p class="text-muted small mb-0 mt-1" id="resDescription"></p>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle" onclick="closeResultBox()" title="ปิดหน้านี้">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Stock Statistics Comparison -->
        <div class="row g-3 mb-4">
            <div class="col-sm-4">
                <div class="bg-light p-3 rounded-3 text-center border">
                    <small class="text-muted d-block mb-1">สต็อกในห้องเราปัจจุบัน</small>
                    <span class="fs-4 fw-bold text-dark" id="resMyStock">0</span> 
                    <small class="text-muted" id="resUnit1">ชิ้น</small>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="bg-success bg-opacity-10 p-3 rounded-3 text-center border border-success">
                    <small class="text-success fw-semibold d-block mb-1">สต็อกพร้อมเบิกในคลังกลาง</small>
                    <span class="fs-4 fw-bold text-success" id="resCentralStock">0</span> 
                    <small class="text-muted" id="resUnit2">ชิ้น</small>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="bg-info bg-opacity-10 p-3 rounded-3 text-center border border-info">
                    <small class="text-info fw-semibold d-block mb-1">หน่วยอื่นที่มีของให้ยืม</small>
                    <span class="fs-4 fw-bold text-info" id="resOtherUnitsCount">0</span> 
                    <small class="text-muted">หน่วยงาน</small>
                </div>
            </div>
        </div>

        <!-- Action Selection Tabs -->
        <div class="border rounded-4 p-3 bg-light">
            <ul class="nav nav-pills nav-fill mb-3" id="actionPills">
                <li class="nav-item">
                    <button class="nav-link active fw-semibold text-danger" id="pill-patient-tab" data-bs-toggle="pill" data-bs-target="#pill-patient">
                        <i class="fa-solid fa-hospital-user me-2"></i> 1. จ่ายให้ผู้รับบริการ (คนไข้)
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link fw-semibold" id="pill-use-tab" data-bs-toggle="pill" data-bs-target="#pill-use">
                        <i class="fa-solid fa-hand-holding-medical me-2"></i> 2. เบิกใช้ในห้องตนเอง
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link fw-semibold" id="pill-req-tab" data-bs-toggle="pill" data-bs-target="#pill-req">
                        <i class="fa-solid fa-arrow-down-to-bracket me-2"></i> 3. ขอเบิกคลังกลาง
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link fw-semibold" id="pill-borrow-tab" data-bs-toggle="pill" data-bs-target="#pill-borrow">
                        <i class="fa-solid fa-handshake me-2"></i> 4. ขอยืมหน่วยอื่น
                    </button>
                </li>
            </ul>

            <div class="tab-content bg-white p-4 rounded-3 border">
                
                <!-- Option 1: สแกนจ่ายให้ผู้รับบริการ (คนไข้) -->
                <div class="tab-pane fade show active" id="pill-patient">
                    <form method="POST" action="borrow_action.php">
                        <input type="hidden" name="action" value="dispense_to_patient">
                        <input type="hidden" name="material_id" id="formPatientMaterialId">
                        <input type="hidden" name="current_unit_id" value="<?= $current_unit_id ?>">
                        <input type="hidden" name="redirect_to" value="unit/scan.php">

                        <div class="alert alert-danger bg-danger-subtle border-danger-subtle text-danger p-2 px-3 rounded-3 small mb-3">
                            <i class="fa-solid fa-circle-info me-1"></i> ยิงบาร์โค้ดสายรัดข้อมือ หรือกรอก HN และชื่อคนไข้เพื่อตัดจ่ายเวชภัณฑ์สำหรับผู้รับบริการ
                        </div>

                        <div class="row g-3">
                            <!-- รหัส HN คนไข้ -->
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark">
                                    <i class="fa-solid fa-barcode text-danger me-1"></i>รหัสคนไข้ (HN) <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="patient_hn" id="patientHnInput" class="form-control form-control-lg font-monospace fw-bold" placeholder="เช่น HN-690124" required autofocus>
                                <small class="text-muted">ยิงบาร์โค้ดข้อมือผู้ป่วยได้</small>
                            </div>

                            <!-- ชื่อ-สกุล คนไข้ -->
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark">ชื่อ-สกุล ผู้รับบริการ (คนไข้) <span class="text-danger">*</span></label>
                                <input type="text" name="patient_name" class="form-control form-control-lg" placeholder="เช่น นายสมคิด ยั่งยืน" required>
                            </div>

                            <!-- เตียง / ห้อง -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-secondary">เตียง / ห้องตรวจ</label>
                                <input type="text" name="bed_no" class="form-control form-control-lg" placeholder="เช่น เตียง 2 หรือ ห้องฉุกเฉิน 1">
                            </div>

                            <!-- จำนวนที่จ่าย -->
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark">จำนวนที่จ่ายให้คนไข้ <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="number" name="quantity" id="formPatientQty" class="form-control form-control-lg fw-bold text-danger" value="1" min="1" required>
                                    <span class="input-group-text bg-light patient-unit-label">ชิ้น</span>
                                </div>
                            </div>

                            <!-- หัตถการ / วัตถุประสงค์ -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-secondary">หัตถการ / การรักษา</label>
                                <input type="text" name="procedure" class="form-control form-control-lg" placeholder="เช่น ทำแผล, ฉีดยา, เย็บแผล">
                            </div>

                            <!-- ผู้จ่าย (พยาบาล/เจ้าหน้าที่) -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-secondary">ผู้บันทึกจ่าย</label>
                                <input type="text" name="used_by" class="form-control form-control-lg" value="<?= h($_SESSION['full_name']) ?>" required>
                            </div>

                            <div class="col-12 mt-3">
                                <button type="submit" class="btn btn-danger btn-lg w-100 rounded-3 shadow-sm py-3 fw-bold" id="btnSubmitPatient">
                                    <i class="fa-solid fa-hospital-user me-2"></i> ยืนยันตัดจ่ายเวชภัณฑ์ให้ผู้รับบริการ (คนไข้)
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Option 2: เบิกใช้ในห้องตนเองทั่วไป -->
                <div class="tab-pane fade" id="pill-use">
                    <form method="POST" action="borrow_action.php">
                        <input type="hidden" name="action" value="use_internal_material">
                        <input type="hidden" name="material_id" id="formUseMaterialId">
                        <input type="hidden" name="current_unit_id" value="<?= $current_unit_id ?>">
                        <input type="hidden" name="redirect_to" value="unit/scan.php">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">จำนวนที่นำไปใช้ <span class="text-danger">*</span></label>
                                <input type="number" name="quantity" id="formUseQty" class="form-control form-control-lg rounded-3" value="1" min="1" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">ผู้เบิกใช้</label>
                                <input type="text" name="used_by" class="form-control form-control-lg rounded-3" value="<?= h($_SESSION['full_name']) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">วัตถุประสงค์การใช้ในห้อง</label>
                                <input type="text" name="patient_or_purpose" class="form-control form-control-lg rounded-3" placeholder="เช่น ทำความสะอาดอุปกรณ์ประจำห้อง">
                            </div>
                            <div class="col-12 mt-3">
                                <button type="submit" class="btn btn-warning text-dark btn-lg w-100 rounded-3 shadow-sm" id="btnSubmitUse">
                                    <i class="fa-solid fa-hand-holding-medical me-2"></i> ยืนยันตัดยอดนำไปใช้ในห้องพัสดุ
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Option 3: ขอเบิกคลังกลาง -->
                <div class="tab-pane fade" id="pill-req">
                    <?php if (!$req_enabled): ?>
                        <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-3 d-flex align-items-center gap-3 p-3">
                            <i class="fa-solid fa-lock fa-2x text-warning"></i>
                            <div>
                                <h6 class="fw-bold mb-1">คลังกลางปิดรับใบขอเบิกพัสดุชั่วคราว</h6>
                                <p class="small mb-0 text-muted"><?= nl2br(h($closed_reason)) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="borrow_action.php">
                        <input type="hidden" name="action" value="requisition_central">
                        <input type="hidden" name="material_id" id="formReqMaterialId">
                        <input type="hidden" name="current_unit_id" value="<?= $current_unit_id ?>">
                        <input type="hidden" name="redirect_to" value="unit/scan.php">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">จำนวนที่ต้องการเบิก <span class="text-danger">*</span></label>
                                <input type="number" name="quantity" id="formReqQty" class="form-control form-control-lg rounded-3" value="10" min="1" required <?= !$req_enabled ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">หมายเหตุ / เหตุผลการเบิก</label>
                                <input type="text" name="note" class="form-control form-control-lg rounded-3" placeholder="ระบุเหตุผลการเบิก (ถ้ามี)" <?= !$req_enabled ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-12 mt-3">
                                <button type="submit" class="btn <?= $req_enabled ? 'btn-success' : 'btn-secondary' ?> btn-lg w-100 rounded-3 shadow-sm" id="btnSubmitReq" <?= !$req_enabled ? 'disabled' : '' ?>>
                                    <i class="fa-solid <?= $req_enabled ? 'fa-check' : 'fa-lock' ?> me-2"></i> <?= $req_enabled ? 'ยืนยันเบิกเข้าห้องพัสดุ' : 'คลังกลางปิดรับเบิกชั่วคราว' ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Option 4: ขอยืมจากหน่วยอื่น -->
                <div class="tab-pane fade" id="pill-borrow">
                    <form method="POST" action="borrow_action.php">
                        <input type="hidden" name="action" value="request_borrow">
                        <input type="hidden" name="material_id" id="formBorrowMaterialId">
                        <input type="hidden" name="current_unit_id" value="<?= $current_unit_id ?>">
                        <input type="hidden" name="redirect_to" value="unit/scan.php">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">เลือกหน่วยงานที่ต้องการขอยืม <span class="text-danger">*</span></label>
                                <select name="lender_unit_id" id="formBorrowLenderSelect" class="form-select rounded-3" required>
                                    <option value="">-- รอผลการสแกน --</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">จำนวนที่ขอยืม <span class="text-danger">*</span></label>
                                <input type="number" name="quantity" class="form-control rounded-3" value="1" min="1" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">กำหนดส่งคืน</label>
                                <input type="date" name="due_date" class="form-control rounded-3" value="<?= date('Y-m-d', strtotime('+3 days')) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">หมายเหตุ / ความจำเป็นเร่งด่วน</label>
                                <textarea name="note" class="form-control rounded-3" rows="2" placeholder="เช่น ขอสำรองสำหรับเคสฉุกเฉิน"></textarea>
                            </div>
                            <div class="col-12 mt-3">
                                <button type="submit" class="btn btn-primary btn-lg w-100 rounded-3 shadow-sm">
                                    <i class="fa-solid fa-paper-plane me-2"></i> ส่งคำขอยืมไปยังหน่วยงาน
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ตารางประวัติการจ่ายเวชภัณฑ์ให้คนไข้ (ผู้รับบริการ) ล่าสุด -->
    <div class="card p-4 rounded-4 border shadow-sm bg-white mt-4">
        <h5 class="fw-bold text-dark mb-3">
            <i class="fa-solid fa-clock-rotate-left text-danger me-2"></i>ประวัติการจ่ายเวชภัณฑ์ให้ผู้รับบริการ (คนไข้) ล่าสุด
        </h5>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>วัน-เวลา</th>
                        <th>HN ผู้ป่วย</th>
                        <th>ชื่อผู้รับบริการ (คนไข้)</th>
                        <th>รายการเวชภัณฑ์</th>
                        <th class="text-end">จำนวนที่ใช้</th>
                        <th>หัตถการ / วัตถุประสงค์</th>
                        <th>ผู้บันทึกจ่าย</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_patient_usages)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">ยังไม่มีประวัติการเบิกจ่ายให้คนไข้ในแผนกนี้</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_patient_usages as $pu): ?>
                            <tr>
                                <td class="small text-secondary"><?= thaiDate($pu['used_at'], true) ?></td>
                                <td>
                                    <?php if (!empty($pu['patient_hn'])): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-semibold">
                                            <?= h($pu['patient_hn']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold text-dark"><?= h($pu['patient_name'] ?: 'ผู้รับบริการทั่วไป') ?></td>
                                <td>[<?= h($pu['material_code']) ?>] <?= h($pu['material_name']) ?></td>
                                <td class="text-end fw-bold text-danger">-<?= number_format($pu['quantity']) ?> <?= h($pu['unit_unit']) ?></td>
                                <td class="small text-secondary"><?= h($pu['patient_or_purpose'] ?: '-') ?></td>
                                <td class="small text-muted"><?= h($pu['used_by']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    let html5QrCode = null;

    // เริ่มต้นระบบสแกนกล้อง
    function startScanner() {
        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode("reader");
        }

        const config = { fps: 10, qrbox: { width: 250, height: 250 } };

        html5QrCode.start(
            { facingMode: "environment" },
            config,
            (decodedText, decodedResult) => {
                // เมื่อสแกนสำเร็จ
                console.log(`Scan result: ${decodedText}`);
                lookupMaterial(decodedText);
            },
            (errorMessage) => {
                // ข้อผิดพลาดระหว่างกล้องหมุนหา (เงียบไว้เพื่อไม่ให้รบกวน)
            }
        ).catch((err) => {
            console.error("Camera start error:", err);
            Swal.fire({
                icon: 'info',
                title: 'ไม่สามารถเปิดกล้องได้',
                text: 'กรุณาอนุญาตสิทธิ์การเข้าถึงกล้องบนเบราว์เซอร์ หรือใช้ปุ่มจำลองการสแกน/กรอกรหัสพัสดุด้านล่าง',
                confirmButtonText: 'ตกลง'
            });
        });
    }

    // หยุดกล้อง
    function stopScanner() {
        if (html5QrCode && html5QrCode.isScanning) {
            html5QrCode.stop().then(() => {
                console.log("Camera stopped");
            }).catch(err => console.error(err));
        }
    }

    // ประมวลผลจากช่องกรอกรหัสด้วยมือ
    function processManualCode() {
        const code = document.getElementById('manualCodeInput').value.trim();
        if (!code) {
            Swal.fire('กรุณากรอกรหัสพัสดุ', '', 'warning');
            return;
        }
        lookupMaterial(code);
    }

    // ดึงรหัสพัสดุจาก URL หรือ String QR Code
    function extractCode(text) {
        if (!text) return '';
        text = text.trim();
        try {
            if (text.startsWith('http://') || text.startsWith('https://')) {
                const url = new URL(text);
                const codeParam = url.searchParams.get('code');
                if (codeParam) return codeParam;
            }
        } catch(e) {}
        return text;
    }

    // ฟังก์ชันค้นหาข้อมูลพัสดุผ่าน AJAX
    function lookupMaterial(rawCode) {
        const code = extractCode(rawCode);
        if (!code) return;
        Swal.showLoading();

        fetch(`scan.php?ajax=get_material&code=${encodeURIComponent(code)}&unit_id=<?= $current_unit_id ?>`)
            .then(res => res.json())
            .then(data => {
                Swal.close();
                if (!data.success) {
                    Swal.fire('ไม่พบพัสดุ', data.message, 'error');
                    return;
                }

                renderScannedMaterial(data);
            })
            .catch(err => {
                Swal.close();
                console.error(err);
                Swal.fire('ข้อผิดพลาด', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
            });
    }

    // แสดงผลข้อมูลที่สแกนได้ใน UI
    function renderScannedMaterial(data) {
        const m = data.material;
        document.getElementById('resMaterialCode').innerText = m.material_code;
        document.getElementById('resCategory').innerText = m.category || 'ทั่วไป';
        document.getElementById('resMaterialName').innerText = m.material_name;
        document.getElementById('resDescription').innerText = m.description || '-';

        document.getElementById('resMyStock').innerText = data.my_stock.toLocaleString();
        document.getElementById('resCentralStock').innerText = data.central_stock.toLocaleString();
        document.getElementById('resOtherUnitsCount').innerText = data.other_units.length;

        document.getElementById('resUnit1').innerText = m.unit_unit;
        document.getElementById('resUnit2').innerText = m.unit_unit;

        // ฟอร์มจ่ายให้ผู้รับบริการ (คนไข้)
        document.getElementById('formPatientMaterialId').value = m.material_id;
        document.getElementById('formPatientQty').max = data.my_stock;
        document.querySelectorAll('.patient-unit-label').forEach(el => el.textContent = m.unit_unit || 'ชิ้น');

        if (data.my_stock <= 0) {
            document.getElementById('btnSubmitPatient').disabled = true;
            document.getElementById('btnSubmitPatient').innerHTML = '<i class="fa-solid fa-ban me-2"></i> เวชภัณฑ์ในห้องหมด (ไม่มียอดให้จ่ายคนไข้)';
        } else {
            document.getElementById('btnSubmitPatient').disabled = false;
            document.getElementById('btnSubmitPatient').innerHTML = '<i class="fa-solid fa-hospital-user me-2"></i> ยืนยันตัดจ่ายเวชภัณฑ์ให้ผู้รับบริการ (คนไข้)';
            // Focus HN input
            setTimeout(() => {
                const hnInput = document.getElementById('patientHnInput');
                if (hnInput && !hnInput.value) {
                    hnInput.focus();
                }
            }, 300);
        }

        // ฟอร์มเบิกใช้ในห้องตนเอง
        document.getElementById('formUseMaterialId').value = m.material_id;
        document.getElementById('formUseQty').max = data.my_stock;
        if (data.my_stock <= 0) {
            document.getElementById('btnSubmitUse').disabled = true;
            document.getElementById('btnSubmitUse').innerHTML = '<i class="fa-solid fa-ban me-2"></i> พัสดุในห้องหมด (ไม่มียอดให้เบิกใช้)';
        } else {
            document.getElementById('btnSubmitUse').disabled = false;
            document.getElementById('btnSubmitUse').innerHTML = '<i class="fa-solid fa-hand-holding-medical me-2"></i> ยืนยันตัดยอดนำไปใช้งานทันที';
        }

        // ฟอร์มขอเบิกคลังกลาง
        document.getElementById('formReqMaterialId').value = m.material_id;
        document.getElementById('formReqQty').max = data.central_stock;
        if (data.central_stock <= 0) {
            document.getElementById('btnSubmitReq').disabled = true;
            document.getElementById('btnSubmitReq').innerHTML = '<i class="fa-solid fa-ban me-2"></i> สต็อกคลังกลางหมด (ไม่สามารถเบิกได้)';
            document.getElementById('btnSubmitReq').classList.replace('btn-success', 'btn-secondary');
        } else if (<?= $req_enabled ? 'true' : 'false' ?>) {
            document.getElementById('btnSubmitReq').disabled = false;
            document.getElementById('btnSubmitReq').innerHTML = '<i class="fa-solid fa-check me-2"></i> ยืนยันขอเบิกเข้าห้องพัสดุ';
            document.getElementById('btnSubmitReq').classList.replace('btn-secondary', 'btn-success');
        }

        // ฟอร์มขอยืม
        document.getElementById('formBorrowMaterialId').value = m.material_id;
        const lenderSelect = document.getElementById('formBorrowLenderSelect');
        lenderSelect.innerHTML = '';

        if (data.other_units.length === 0) {
            lenderSelect.innerHTML = '<option value="">-- ขณะนี้ไม่มีหน่วยงานอื่นที่มีพัสดุนี้ให้ยืม --</option>';
        } else {
            lenderSelect.innerHTML = '<option value="">-- เลือกหน่วยงานที่มีของให้ยืม --</option>';
            data.other_units.forEach(u => {
                const opt = document.createElement('option');
                opt.value = u.unit_id;
                opt.innerText = `${u.unit_name} (${u.unit_code}) - มีสต็อกให้ยืม ${u.quantity} ${m.unit_unit}`;
                lenderSelect.appendChild(opt);
            });
        }

        // แสดงกล่องผลลัพธ์
        const box = document.getElementById('scannedResultBox');
        box.style.display = 'block';
        box.scrollIntoView({ behavior: 'smooth' });
    }

    function closeResultBox() {
        document.getElementById('scannedResultBox').style.display = 'none';
    }

    // เริ่มต้นเปิดกล้องอัตโนมัติ หรือโหลดข้อมูลจาก URL parameter ทันที
    window.addEventListener('load', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const codeFromUrl = urlParams.get('code');
        if (codeFromUrl) {
            lookupMaterial(codeFromUrl);
        } else {
            startScanner();
        }
    });
</script>

</body>
</html>
