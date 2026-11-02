<?php
/**
 * จัดการหน่วยงาน/แผนกในโรงพยาบาล (Units Management)
 * สำหรับ Super Admin โรงพยาบาลมหาสารคาม
 */
require_once __DIR__ . '/../config.php';
checkAuth('super_admin');

// จัดการการส่งฟอร์ม (POST Request Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. เพิ่มหน่วยงานใหม่
    if ($action === 'add_unit') {
        $unit_code = strtoupper(trim($_POST['unit_code'] ?? ''));
        $unit_name = trim($_POST['unit_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $location  = trim($_POST['location'] ?? '');

        if (!empty($unit_code) && !empty($unit_name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO units (unit_code, unit_name, phone, location) VALUES (?, ?, ?, ?)");
                $stmt->execute([$unit_code, $unit_name, $phone, $location]);
                setFlash('success', "เพิ่มหน่วยงาน [{$unit_code}] {$unit_name} เรียบร้อยแล้ว");
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) {
                    setFlash('danger', "รหัสหน่วยงาน '{$unit_code}' มีอยู่ในระบบแล้ว");
                } else {
                    setFlash('danger', "เกิดข้อผิดพลาด: " . $e->getMessage());
                }
            }
        } else {
            setFlash('danger', 'กรุณาระบุรหัสและชื่อหน่วยงานให้ครบถ้วน');
        }
        header("Location: units.php");
        exit;
    }

    // 2. แก้ไขหน่วยงาน
    if ($action === 'edit_unit') {
        $unit_id   = (int)($_POST['unit_id'] ?? 0);
        $unit_code = strtoupper(trim($_POST['unit_code'] ?? ''));
        $unit_name = trim($_POST['unit_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $location  = trim($_POST['location'] ?? '');

        if ($unit_id > 0 && !empty($unit_code) && !empty($unit_name)) {
            try {
                $stmt = $pdo->prepare("UPDATE units SET unit_code = ?, unit_name = ?, phone = ?, location = ? WHERE unit_id = ?");
                $stmt->execute([$unit_code, $unit_name, $phone, $location, $unit_id]);
                setFlash('success', "แก้ไขข้อมูลหน่วยงานเรียบร้อยแล้ว");
            } catch (\PDOException $e) {
                setFlash('danger', "เกิดข้อผิดพลาด: " . $e->getMessage());
            }
        }
        header("Location: units.php");
        exit;
    }

    // 3. ลบหน่วยงาน
    if ($action === 'delete_unit') {
        $unit_id = (int)($_POST['unit_id'] ?? 0);
        if ($unit_id === 1) {
            setFlash('danger', 'ไม่สามารถลบคลังพัสดุและเวชภัณฑ์กลาง (CENTRAL) ได้');
        } elseif ($unit_id > 0) {
            // ตรวจสอบว่ามีผู้ใช้ผูกอยู่หรือไม่
            $user_count = $pdo->query("SELECT COUNT(*) FROM users WHERE unit_id = {$unit_id}")->fetchColumn();
            if ($user_count > 0) {
                setFlash('danger', "ไม่สามารถลบหน่วยงานนี้ได้ เนื่องจากมีผู้ใช้งานสังกัดอยู่ {$user_count} คน");
            } else {
                try {
                    $del_stmt = $pdo->prepare("DELETE FROM units WHERE unit_id = ?");
                    $del_stmt->execute([$unit_id]);
                    setFlash('success', "ลบหน่วยงานเรียบร้อยแล้ว");
                } catch (\PDOException $e) {
                    setFlash('danger', "เกิดข้อผิดพลาดในการลบหน่วยงาน: " . $e->getMessage());
                }
            }
        }
        header("Location: units.php");
        exit;
    }
}

// ดึงรายการหน่วยงานทั้งหมด พร้อมสถิติจำนวนผู้ใช้และสต็อก
$units_stmt = $pdo->query("
    SELECT u.*, 
           (SELECT COUNT(*) FROM users usr WHERE usr.unit_id = u.unit_id) AS user_count,
           (SELECT COUNT(*) FROM unit_stocks us WHERE us.unit_id = u.unit_id AND us.quantity > 0) AS active_stock_types
    FROM units u
    ORDER BY u.unit_id ASC
");
$units = $units_stmt->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการหน่วยงาน/แผนก | Super Admin โรงพยาบาลมหาสารคาม</title>
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
            background-color: #f4f7f6;
        }
        h1, h2, h3, h4, h5, .nav-link, .btn {
            font-family: 'Prompt', sans-serif;
        }
        .content-card {
            background: #ffffff;
            border-radius: 16px;
            border: none;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.04);
        }

        /* Mobile Bottom Navigation Bar (สำหรับโทรศัพท์มือถือ Super Admin) */
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

<!-- Mobile Bottom Navigation Bar (สำหรับโทรศัพท์มือถือ Super Admin) -->
<nav class="mobile-bottom-nav d-lg-none">
    <a href="dashboard.php" class="bottom-nav-item">
        <i class="fa-solid fa-warehouse"></i>
        <span>คลังกลาง</span>
    </a>
    <a href="dashboard.php#requisitions_section" class="bottom-nav-item">
        <i class="fa-solid fa-file-invoice"></i>
        <span>ใบขอเบิก</span>
    </a>
    <a href="scan_approve.php" class="bottom-nav-scan-btn shadow" title="สแกน QR Code ตรวจรับ">
        <i class="fa-solid fa-qrcode"></i>
    </a>
    <a href="units.php" class="bottom-nav-item active">
        <i class="fa-solid fa-hospital-user"></i>
        <span>หน่วยงาน</span>
    </a>
    <a href="users.php" class="bottom-nav-item">
        <i class="fa-solid fa-users-gear"></i>
        <span>ผู้ใช้</span>
    </a>
</nav>

<div class="container-fluid px-lg-4 py-4" style="max-width: 1100px;">

    <!-- Flash Message -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> me-2"></i>
            <?= h($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3">
        <div>
            <h2 class="h3 fw-bold mb-1 text-dark">
                <i class="fa-solid fa-hospital-user me-2 text-primary"></i> จัดการหน่วยงานและแผนกในโรงพยาบาล
            </h2>
            <p class="text-muted small mb-0">สร้างและดูแลห้องพัสดุประจำหน่วยงาน แต่ละแผนกจะมีคลังห้องของตนเอง</p>
        </div>
        <button class="btn btn-success rounded-3 shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#addUnitModal">
            <i class="fa-solid fa-plus-circle me-1"></i> เพิ่มหน่วยงานใหม่
        </button>
    </div>

    <div class="card content-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 120px;">รหัสหน่วย</th>
                        <th>ชื่อหน่วยงาน / แผนก</th>
                        <th>สถานที่ตั้ง / อาคาร</th>
                        <th>เบอร์โทรศัพท์ติดต่อ</th>
                        <th class="text-center">ผู้ใช้งาน</th>
                        <th class="text-center">รายการพัสดุในห้อง</th>
                        <th class="text-center" style="width: 200px;">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($units as $u): ?>
                        <tr>
                            <td>
                                <span class="badge bg-dark font-monospace fs-6"><?= h($u['unit_code']) ?></span>
                            </td>
                            <td>
                                <div class="fw-bold text-dark fs-6"><?= h($u['unit_name']) ?></div>
                                <?php if ($u['unit_id'] === 1): ?>
                                    <span class="badge bg-warning text-dark"><i class="fa-solid fa-crown me-1"></i> คลังกลางหลัก</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="text-muted small"><i class="fa-solid fa-location-dot me-1 text-danger"></i> <?= h($u['location'] ?? '-') ?></span></td>
                            <td><span class="text-muted small"><i class="fa-solid fa-phone me-1 text-primary"></i> <?= h($u['phone'] ?? '-') ?></span></td>
                            <td class="text-center">
                                <span class="badge bg-info bg-opacity-10 text-dark border">
                                    <?= number_format($u['user_count']) ?> คน
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-success bg-opacity-10 text-success border">
                                    <?= number_format($u['active_stock_types']) ?> รายการ
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= BASE_URL ?>/unit/dashboard.php?unit_id=<?= $u['unit_id'] ?>" class="btn btn-outline-secondary" title="ดูห้องพัสดุนี้">
                                        <i class="fa-solid fa-door-open"></i> ดูห้อง
                                    </a>
                                    <button class="btn btn-outline-primary" 
                                            onclick="openEditUnit(<?= htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8') ?>)"
                                            title="แก้ไขหน่วยงาน">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <?php if ($u['unit_id'] !== 1): ?>
                                        <button class="btn btn-outline-danger" 
                                                onclick="confirmDeleteUnit(<?= $u['unit_id'] ?>, '<?= addslashes(h($u['unit_name'])) ?>', <?= $u['user_count'] ?>)"
                                                title="ลบหน่วยงาน">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: เพิ่มหน่วยงานใหม่ -->
<div class="modal fade" id="addUnitModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i> เพิ่มหน่วยงาน/แผนกใหม่</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_unit">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">รหัสย่อหน่วยงาน <span class="text-danger">*</span></label>
                            <input type="text" name="unit_code" class="form-control rounded-3 font-monospace text-uppercase" placeholder="เช่น ICU02" required>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">เบอร์โทรศัพท์ติดต่อ</label>
                            <input type="text" name="phone" class="form-control rounded-3" placeholder="เช่น 043-711750 ต่อ 302">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">ชื่อหน่วยงาน / แผนก <span class="text-danger">*</span></label>
                            <input type="text" name="unit_name" class="form-control rounded-3" placeholder="เช่น แผนกผู้ป่วยวิกฤตหัวใจและหลอดเลือด (CCU)" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">สถานที่ตั้ง / อาคาร / ชั้น</label>
                            <input type="text" name="location" class="form-control rounded-3" placeholder="เช่น อาคารเฉลิมพระเกียรติ ชั้น 4">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success rounded-3 px-4">บันทึกหน่วยงาน</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: แก้ไขหน่วยงาน -->
<div class="modal fade" id="editUnitModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen me-2"></i> แก้ไขข้อมูลหน่วยงาน</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_unit">
                <input type="hidden" name="unit_id" id="edit_unit_id">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">รหัสย่อหน่วยงาน <span class="text-danger">*</span></label>
                            <input type="text" name="unit_code" id="edit_unit_code" class="form-control rounded-3 font-monospace text-uppercase" required>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">เบอร์โทรศัพท์ติดต่อ</label>
                            <input type="text" name="phone" id="edit_phone" class="form-control rounded-3">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">ชื่อหน่วยงาน / แผนก <span class="text-danger">*</span></label>
                            <input type="text" name="unit_name" id="edit_unit_name" class="form-control rounded-3" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">สถานที่ตั้ง / อาคาร / ชั้น</label>
                            <input type="text" name="location" id="edit_location" class="form-control rounded-3">
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

<!-- Hidden Delete Form -->
<form id="deleteUnitForm" method="POST" action="" style="display: none;">
    <input type="hidden" name="action" value="delete_unit">
    <input type="hidden" name="unit_id" id="delete_unit_id">
</form>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    function openEditUnit(u) {
        document.getElementById('edit_unit_id').value = u.unit_id;
        document.getElementById('edit_unit_code').value = u.unit_code;
        document.getElementById('edit_unit_name').value = u.unit_name;
        document.getElementById('edit_phone').value = u.phone || '';
        document.getElementById('edit_location').value = u.location || '';
        new bootstrap.Modal(document.getElementById('editUnitModal')).show();
    }

    function confirmDeleteUnit(id, name, userCount) {
        if (userCount > 0) {
            Swal.fire('ไม่สามารถลบได้', `หน่วยงานนี้มีผู้ใช้สังกัดอยู่ ${userCount} คน`, 'warning');
            return;
        }

        Swal.fire({
            title: 'ยืนยันการลบหน่วยงาน?',
            text: `ต้องการลบหน่วยงาน "${name}" หรือไม่? ข้อมูลสต็อกในห้องจะถูกลบไปด้วย`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'ใช่, ต้องการลบ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete_unit_id').value = id;
                document.getElementById('deleteUnitForm').submit();
            }
        });
    }
</script>

</body>
</html>
