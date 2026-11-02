<?php
/**
 * ระบบ Refill เวชภัณฑ์จากคลังใหญ่ไปคลังย่อย (Medical Supply Refill System)
 * โรงพยาบาลมหาสารคาม
 * รองรับการวิเคราะห์สต็อกต่ำกว่าเกณฑ์และแนะนำการเติมอัตโนมัติ (Smart Refill Suggestions)
 * และการเติมตามคำขอ พร้อมบันทึกประวัติการเติมและออกเลขที่ Refill
 */
require_once __DIR__ . '/../config.php';
checkAuth('super_admin');

$user_id = $_SESSION['user_id'];

// จัดการการส่งฟอร์ม (POST Request Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ดำเนินการ Refill รายการเดี่ยว
    if ($action === 'do_refill') {
        $to_unit_id  = (int)($_POST['to_unit_id'] ?? 0);
        $material_id = (int)($_POST['material_id'] ?? 0);
        $refill_qty  = (int)($_POST['quantity'] ?? 0);
        $note        = trim($_POST['note'] ?? 'ระบบ Refill เติมเวชภัณฑ์');

        if ($to_unit_id > 1 && $material_id > 0 && $refill_qty > 0) {
            try {
                $pdo->beginTransaction();

                // ตรวจสต็อกคลังใหญ่
                $c_stock = (int)$pdo->query("SELECT quantity FROM unit_stocks WHERE unit_id = 1 AND material_id = {$material_id} FOR UPDATE")->fetchColumn();

                if ($c_stock < $refill_qty) {
                    $pdo->rollBack();
                    setFlash('danger', "สต็อกคลังใหญ่มีไม่เพียงพอต่อการเติม (มีคงเหลือ {$c_stock} แต่ต้องการเติม {$refill_qty})");
                    header("Location: refill.php");
                    exit;
                }

                $mat = $pdo->query("SELECT * FROM materials WHERE material_id = {$material_id}")->fetch();

                // ตัดคลังใหญ่
                $pdo->prepare("UPDATE unit_stocks SET quantity = quantity - ? WHERE unit_id = 1 AND material_id = ?")->execute([$refill_qty, $material_id]);

                // เพิ่มคลังย่อย
                $pdo->prepare("
                    INSERT INTO unit_stocks (unit_id, material_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
                ")->execute([$to_unit_id, $material_id, $refill_qty]);

                // เลข Refill No
                $ref_no = "REF-" . date('Ym') . "-" . strtoupper(substr(uniqid(), -4));

                $ins_ref = $pdo->prepare("
                    INSERT INTO refill_orders (refill_no, from_unit_id, to_unit_id, material_id, quantity, status, note, created_by)
                    VALUES (?, 1, ?, ?, ?, 'completed', ?, ?)
                ");
                $ins_ref->execute([$ref_no, $to_unit_id, $material_id, $refill_qty, $note, $user_id]);

                $u_name = $pdo->query("SELECT unit_name FROM units WHERE unit_id = {$to_unit_id}")->fetchColumn();

                logStockMovement($pdo, $material_id, 1, 'out', $refill_qty, 'refill', $ref_no, "Refill เติมสต็อกให้: {$u_name} ({$note})", $user_id, $mat['unit_price'], $mat['supplier']);
                logStockMovement($pdo, $material_id, $to_unit_id, 'in', $refill_qty, 'refill', $ref_no, "รับ Refill เติมสต็อกด่วนจากคลังใหญ่ ({$ref_no})", $user_id, $mat['unit_price'], $mat['supplier']);

                $pdo->commit();
                setFlash('success', "เติม Refill [{$mat['material_code']}] {$mat['material_name']} จำนวน {$refill_qty} {$mat['unit_unit']} ให้แก่ '{$u_name}' สำเร็จ (เลขที่: {$ref_no})");
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', "เกิดข้อผิดพลาดในการ Refill: " . $e->getMessage());
            }
        }
        header("Location: refill.php");
        exit;
    }

    // 2. เติมสต็อก Refill แบบชุดหลายรายการพร้อมกัน (Batch Refill Selected)
    if ($action === 'batch_refill') {
        $selected_items = $_POST['items'] ?? []; // Array of {unit_id, material_id, quantity}
        $count_success = 0;

        if (!empty($selected_items) && is_array($selected_items)) {
            try {
                $pdo->beginTransaction();

                foreach ($selected_items as $item) {
                    $u_id   = (int)($item['unit_id'] ?? 0);
                    $m_id   = (int)($item['material_id'] ?? 0);
                    $r_qty  = (int)($item['quantity'] ?? 0);

                    if ($u_id > 1 && $m_id > 0 && $r_qty > 0) {
                        $c_stock = (int)$pdo->query("SELECT quantity FROM unit_stocks WHERE unit_id = 1 AND material_id = {$m_id} FOR UPDATE")->fetchColumn();
                        if ($c_stock >= $r_qty) {
                            $mat = $pdo->query("SELECT * FROM materials WHERE material_id = {$m_id}")->fetch();
                            $u_name = $pdo->query("SELECT unit_name FROM units WHERE unit_id = {$u_id}")->fetchColumn();

                            // ตัดคลังใหญ่
                            $pdo->prepare("UPDATE unit_stocks SET quantity = quantity - ? WHERE unit_id = 1 AND material_id = ?")->execute([$r_qty, $m_id]);

                            // เพิ่มคลังย่อย
                            $pdo->prepare("
                                INSERT INTO unit_stocks (unit_id, material_id, quantity)
                                VALUES (?, ?, ?)
                                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
                            ")->execute([$u_id, $m_id, $r_qty]);

                            $ref_no = "REF-" . date('Ym') . "-" . strtoupper(substr(uniqid(), -4));

                            $ins_ref = $pdo->prepare("
                                INSERT INTO refill_orders (refill_no, from_unit_id, to_unit_id, material_id, quantity, status, note, created_by)
                                VALUES (?, 1, ?, ?, ?, 'completed', 'Batch Smart Refill', ?)
                            ");
                            $ins_ref->execute([$ref_no, $u_id, $m_id, $r_qty, $user_id]);

                            logStockMovement($pdo, $m_id, 1, 'out', $r_qty, 'refill', $ref_no, "Batch Refill เติมสต็อกให้: {$u_name}", $user_id, $mat['unit_price'], $mat['supplier']);
                            logStockMovement($pdo, $m_id, $u_id, 'in', $r_qty, 'refill', $ref_no, "รับ Batch Refill จากคลังใหญ่", $user_id, $mat['unit_price'], $mat['supplier']);

                            $count_success++;
                        }
                    }
                }

                $pdo->commit();
                setFlash('success', "ดำเนินการ Batch Refill เติมสต็อกสำเร็จรวม {$count_success} รายการ");
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', "เกิดข้อผิดพลาดในการ Refill รวม: " . $e->getMessage());
            }
        }
        header("Location: refill.php");
        exit;
    }
}

// -------------------------------------------------------------
// ค้นหารายการที่ "แนะนำให้ Refill" (Smart Refill Suggestions)
// เงื่อนไข: สต็อกในคลังย่อย <= min_stock และคลังใหญ่มีของ > 0
// -------------------------------------------------------------
$suggestions = $pdo->query("
    SELECT u.unit_id, u.unit_code, u.unit_name, u.location,
           m.material_id, m.material_code, m.barcode, m.material_name, m.unit_unit, m.min_stock, m.unit_price,
           COALESCE(us.quantity, 0) AS unit_stock,
           COALESCE(c_us.quantity, 0) AS central_stock
    FROM units u
    CROSS JOIN materials m
    LEFT JOIN unit_stocks us ON u.unit_id = us.unit_id AND m.material_id = us.material_id
    LEFT JOIN unit_stocks c_us ON c_us.unit_id = 1 AND m.material_id = c_us.material_id
    WHERE u.unit_id != 1 
      AND COALESCE(us.quantity, 0) <= m.min_stock
      AND COALESCE(c_us.quantity, 0) > 0
    ORDER BY (COALESCE(us.quantity, 0) / GREATEST(m.min_stock, 1)) ASC, u.unit_id ASC
")->fetchAll();

// คำนวณจำนวนที่แนะนำสำหรับแต่ละรายการ (ให้ยอดคลังย่อยมีระดับ 2 เท่าของ min_stock)
foreach ($suggestions as &$sug) {
    $target_level = (int)$sug['min_stock'] * 2;
    $needed = max(1, $target_level - (int)$sug['unit_stock']);
    $sug['suggested_qty'] = min($needed, (int)$sug['central_stock']);
}
unset($sug);

// ดึงประวัติการ Refill ล่าสุด
$refill_history = $pdo->query("
    SELECT ro.*, m.material_code, m.material_name, m.unit_unit, m.unit_price,
           u_from.unit_name AS from_unit_name,
           u_to.unit_name AS to_unit_name,
           usr.full_name AS created_by_name
    FROM refill_orders ro
    JOIN materials m ON ro.material_id = m.material_id
    JOIN units u_from ON ro.from_unit_id = u_from.unit_id
    JOIN units u_to ON ro.to_unit_id = u_to.unit_id
    LEFT JOIN users usr ON ro.created_by = usr.user_id
    ORDER BY ro.refill_id DESC
    LIMIT 30
")->fetchAll();

// รายชื่อคลังย่อยและเวชภัณฑ์ทั้งหมดสำหรับแบบ Manual Refill
$sub_units = $pdo->query("SELECT * FROM units WHERE unit_id != 1 ORDER BY unit_id ASC")->fetchAll();
$all_materials = $pdo->query("
    SELECT m.*, COALESCE(us.quantity, 0) AS central_stock
    FROM materials m
    LEFT JOIN unit_stocks us ON m.material_id = us.material_id AND us.unit_id = 1
    WHERE COALESCE(us.quantity, 0) > 0
    ORDER BY m.material_name ASC
")->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบ Refill เวชภัณฑ์จากคลังใหญ่ไปคลังย่อย | รพ.มหาสารคาม</title>
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
        .card-custom {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
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
                <i class="fa-solid fa-dolly fa-lg" style="color: #00796b;"></i>
            </span>
            <div>
                <span class="fw-bold d-block lh-1 fs-6 fs-md-5">โรงพยาบาลมหาสารคาม</span>
                <span class="small opacity-75 fs-7">ระบบ Refill เวชภัณฑ์จากคลังใหญ่สู่คลังย่อย</span>
            </div>
        </a>

        <div class="d-flex align-items-center gap-2">
            <a href="dashboard.php" class="btn btn-sm btn-light text-teal fw-medium rounded-3" style="color: #00796b;">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับคลังใหญ่
            </a>
            <a href="subunits_stock.php" class="btn btn-sm btn-outline-light rounded-3">
                <i class="fa-solid fa-hospital-user me-1"></i> สต็อกคลังย่อย
            </a>
            <a href="stock_in_out.php" class="btn btn-sm btn-outline-light rounded-3">
                <i class="fa-solid fa-barcode me-1"></i> รับเข้า-จ่ายออกบาร์โค้ด
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

    <!-- Title & Action -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-dolly text-teal me-2" style="color: #00796b;"></i>ศูนย์บริหารระบบ Refill เวชภัณฑ์
            </h3>
            <p class="text-muted small mb-0">ระบบตรวจจับเวชภัณฑ์ที่ใกล้หมดในแต่ละวอร์ด/แผนก และช่วยคลังใหญ่เติมสต็อกเชิงรุก (Push Refill)</p>
        </div>

        <button class="btn btn-success rounded-3 shadow-sm px-4 fw-semibold" data-bs-toggle="modal" data-bs-target="#modalManualRefill">
            <i class="fa-solid fa-plus-circle me-1"></i> สั่ง Refill กำหนดเอง
        </button>
    </div>

    <div class="row g-4">
        <!-- ฝั่งซ้าย/บน: รายการแนะนำการ Refill อัจฉริยะ (Smart Refill Suggestions) -->
        <div class="col-12">
            <div class="card card-custom bg-white p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
                    <div>
                        <h5 class="fw-bold text-dark mb-1">
                            <i class="fa-solid fa-wand-magic-sparkles text-warning me-2"></i>รายการแนะนำการ Refill เติมสต็อกอัตโนมัติ (Smart Refill)
                        </h5>
                        <small class="text-muted">เวชภัณฑ์ในคลังย่อยที่มียอดต่ำกว่าเกณฑ์ขั้นต่ำ (Min Stock) และคลังใหญ่มีของพร้อมเติม</small>
                    </div>

                    <?php if (!empty($suggestions)): ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 fs-7">
                            <i class="fa-solid fa-bell me-1"></i>พบ <?= count($suggestions) ?> รายการที่ต้องเติมด่วน
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (empty($suggestions)): ?>
                    <div class="text-center py-5">
                        <i class="fa-solid fa-circle-check text-success fs-1 mb-2"></i>
                        <h5 class="fw-bold text-dark">สต็อกเวชภัณฑ์ทุกคลังย่อยอยู่ในระดับปกติ</h5>
                        <p class="text-muted small">ไม่มีรายการใดที่ต่ำกว่าเกณฑ์ขั้นต่ำที่ต้องเติมในขณะนี้</p>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="batch_refill">

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-3">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40px;">
                                            <input type="checkbox" id="checkAllRefill" class="form-check-input" checked>
                                        </th>
                                        <th>คลังย่อย (แผนก)</th>
                                        <th>รหัส / บาร์โค้ด</th>
                                        <th>รายการเวชภัณฑ์</th>
                                        <th class="text-center">เกณฑ์ขั้นต่ำ</th>
                                        <th class="text-end">คงเหลือในห้อง</th>
                                        <th class="text-end">คลังใหญ่มี</th>
                                        <th class="text-center" style="width: 160px;">จำนวนที่แนะนำเติม</th>
                                        <th class="text-center">ดำเนินการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($suggestions as $idx => $sg): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="items[<?= $idx ?>][selected]" value="1" class="form-check-input refill-check" checked>
                                                <input type="hidden" name="items[<?= $idx ?>][unit_id]" value="<?= $sg['unit_id'] ?>">
                                                <input type="hidden" name="items[<?= $idx ?>][material_id]" value="<?= $sg['material_id'] ?>">
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary-subtle text-dark border me-1"><?= h($sg['unit_code']) ?></span>
                                                <span class="fw-semibold text-dark"><?= h($sg['unit_name']) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border"><?= h($sg['material_code']) ?></span>
                                            </td>
                                            <td class="fw-semibold text-dark"><?= h($sg['material_name']) ?></td>
                                            <td class="text-center text-muted"><?= number_format($sg['min_stock']) ?> <?= h($sg['unit_unit']) ?></td>
                                            <td class="text-end fw-bold text-danger">
                                                <?= number_format($sg['unit_stock']) ?> <?= h($sg['unit_unit']) ?>
                                            </td>
                                            <td class="text-end fw-bold text-primary">
                                                <?= number_format($sg['central_stock']) ?> <?= h($sg['unit_unit']) ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="input-group input-group-sm">
                                                    <input type="number" name="items[<?= $idx ?>][quantity]" class="form-control text-center fw-bold text-success" value="<?= $sg['suggested_qty'] ?>" min="1" max="<?= $sg['central_stock'] ?>" required>
                                                    <span class="input-group-text bg-light"><?= h($sg['unit_unit']) ?></span>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <!-- เติมรายการนี้รายการเดียว -->
                                                <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3" 
                                                        onclick="doSingleRefill(<?= $sg['unit_id'] ?>, <?= $sg['material_id'] ?>, <?= $sg['suggested_qty'] ?>, '<?= h(addslashes($sg['material_name'])) ?>', '<?= h(addslashes($sg['unit_name'])) ?>')">
                                                    <i class="fa-solid fa-paper-plane me-1"></i>เติมรายการนี้
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded-3">
                            <span class="small text-secondary">
                                <i class="fa-solid fa-circle-info me-1"></i>เลือกรายการที่ต้องการแล้วกด "อนุมัติเติมตามที่เลือก" เพื่อดำเนินการพร้อมกันในคลิกเดียว
                            </span>
                            <button type="submit" class="btn btn-success px-4 py-2 rounded-3 shadow-sm fw-bold">
                                <i class="fa-solid fa-boxes-packing me-1"></i> อนุมัติเติม Refill ตามรายการที่เลือก
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- ฝั่งล่าง: ประวัติการ Refill ล่าสุด -->
        <div class="col-12">
            <div class="card card-custom bg-white p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>ประวัติการ Refill เวชภัณฑ์ล่าสุด
                    </h5>
                    <a href="reports.php" class="btn btn-sm btn-outline-primary rounded-pill">
                        <i class="fa-solid fa-chart-line me-1"></i>รายงานการรับ-จ่ายภาพรวม
                    </a>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>เลขที่ Refill</th>
                                <th>วัน-เวลา</th>
                                <th>คลังต้นทาง</th>
                                <th>คลังย่อยปลายทาง</th>
                                <th>รายการเวชภัณฑ์</th>
                                <th class="text-end">จำนวนที่เติม</th>
                                <th class="text-end">มูลค่า</th>
                                <th>หมายเหตุ</th>
                                <th>ผู้ดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($refill_history)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">ยังไม่มีประวัติการ Refill</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($refill_history as $rh): ?>
                                    <tr>
                                        <td><span class="badge bg-success-subtle text-success border border-success-subtle fw-semibold"><?= h($rh['refill_no']) ?></span></td>
                                        <td class="small text-secondary"><?= thaiDate($rh['created_at'], true) ?></td>
                                        <td class="small text-muted"><?= h($rh['from_unit_name']) ?></td>
                                        <td class="fw-semibold text-dark"><?= h($rh['to_unit_name']) ?></td>
                                        <td>[<?= h($rh['material_code']) ?>] <?= h($rh['material_name']) ?></td>
                                        <td class="text-end fw-bold text-success">+<?= number_format($rh['quantity']) ?> <?= h($rh['unit_unit']) ?></td>
                                        <td class="text-end text-muted small"><?= number_format($rh['quantity'] * $rh['unit_price'], 2) ?> ฿</td>
                                        <td class="small text-secondary"><?= h($rh['note'] ?: '-') ?></td>
                                        <td class="small text-muted"><?= h($rh['created_by_name'] ?? 'ระบบ') ?></td>
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

<!-- Modal เติม Refill รายการเดี่ยว (Single Form) -->
<form method="POST" id="formSingleRefill" style="display: none;">
    <input type="hidden" name="action" value="do_refill">
    <input type="hidden" name="to_unit_id" id="single_unit_id">
    <input type="hidden" name="material_id" id="single_material_id">
    <input type="hidden" name="quantity" id="single_quantity">
    <input type="hidden" name="note" value="Smart Refill อัตโนมัติ">
</form>

<!-- Modal Manual Refill (กำหนดเอง) -->
<div class="modal fade" id="modalManualRefill" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus-circle me-2"></i>สร้างคำสั่ง Refill เวชภัณฑ์</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="do_refill">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">เลือกคลังย่อยปลายทาง (แผนก/วอร์ด) <span class="text-danger">*</span></label>
                        <select name="to_unit_id" class="form-select form-select-lg" required>
                            <option value="">-- เลือกหน่วยงานที่รับ --</option>
                            <?php foreach ($sub_units as $su): ?>
                                <option value="<?= $su['unit_id'] ?>">[<?= h($su['unit_code']) ?>] <?= h($su['unit_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">เลือกรายการเวชภัณฑ์ที่จะเติม <span class="text-danger">*</span></label>
                        <select name="material_id" class="form-select form-select-lg" required>
                            <option value="">-- เลือกเวชภัณฑ์ --</option>
                            <?php foreach ($all_materials as $am): ?>
                                <option value="<?= $am['material_id'] ?>">
                                    [<?= h($am['material_code']) ?>] <?= h($am['material_name']) ?> (คลังใหญ่มี: <?= (int)$am['central_stock'] ?> <?= h($am['unit_unit']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">จำนวนที่ต้องการเติม <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" class="form-control form-control-lg fw-bold text-success" min="1" placeholder="ระบุจำนวน" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary">หมายเหตุการ Refill</label>
                        <input type="text" name="note" class="form-control" placeholder="เช่น ขอเติมสำรองเคสพิเศษ หรือ เติมประจำงวด">
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success rounded-3 px-4 fw-bold">
                        <i class="fa-solid fa-paper-plane me-1"></i> ส่งของและเติม Refill ทันที
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Select all checkboxes
    const checkAll = document.getElementById('checkAllRefill');
    if (checkAll) {
        checkAll.addEventListener('change', function() {
            document.querySelectorAll('.refill-check').forEach(cb => cb.checked = checkAll.checked);
        });
    }

    function doSingleRefill(unitId, matId, qty, matName, unitName) {
        if (confirm('ยืนยันการเติม Refill ' + matName + ' จำนวน ' + qty + ' หน่วย ให้แก่ ' + unitName + ' หรือไม่?')) {
            document.getElementById('single_unit_id').value = unitId;
            document.getElementById('single_material_id').value = matId;
            document.getElementById('single_quantity').value = qty;
            document.getElementById('formSingleRefill').submit();
        }
    }
</script>
</body>
</html>

