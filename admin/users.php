<?php
/**
 * จัดการผู้ใช้งานระบบ (User Management)
 * สำหรับ Super Admin โรงพยาบาลมหาสารคาม
 * สามารถเพิ่ม ลบ แก้ไข ผู้ใช้ สลับสิทธิ์ และกำหนดหน่วยงานที่สังกัดได้ทุกอย่าง
 */
require_once __DIR__ . '/../config.php';
checkAuth('super_admin');

// จัดการการส่งฟอร์ม (POST Request Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. เพิ่มผู้ใช้งานใหม่
    if ($action === 'add_user') {
        $username  = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $full_name = trim($_POST['full_name'] ?? '');
        $role      = $_POST['role'] ?? 'unit_admin';
        $unit_id   = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;

        if (!empty($username) && !empty($password) && !empty($full_name)) {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password, full_name, role, unit_id)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$username, $hash, $full_name, $role, $unit_id]);
                setFlash('success', "เพิ่มผู้ใช้งาน '{$username}' ({$full_name}) เรียบร้อยแล้ว");
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) {
                    setFlash('danger', "ชื่อผู้ใช้งาน '{$username}' มีอยู่ในระบบแล้ว กรุณาใช้ชื่ออื่น");
                } else {
                    setFlash('danger', "เกิดข้อผิดพลาด: " . $e->getMessage());
                }
            }
        } else {
            setFlash('danger', 'กรุณากรอกข้อมูลผู้ใช้งานให้ครบถ้วน');
        }
        header("Location: users.php");
        exit;
    }

    // 2. แก้ไขข้อมูลผู้ใช้งาน
    if ($action === 'edit_user') {
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $role      = $_POST['role'] ?? 'unit_admin';
        $unit_id   = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
        $password  = $_POST['new_password'] ?? '';

        if ($user_id > 0 && !empty($full_name)) {
            try {
                if (!empty($password)) {
                    // หากมีการระบุรหัสผ่านใหม่ ให้แฮชและอัปเดตด้วย
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET full_name = ?, role = ?, unit_id = ?, password = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$full_name, $role, $unit_id, $hash, $user_id]);
                } else {
                    // ไม่อัปเดตรหัสผ่าน
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET full_name = ?, role = ?, unit_id = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$full_name, $role, $unit_id, $user_id]);
                }

                // หากแก้ข้อมูลตนเอง ให้อัปเดตข้อมูลใน Session ปัจจุบันด้วย
                if ($user_id === (int)$_SESSION['user_id']) {
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['role']      = $role;
                    $_SESSION['unit_id']   = $unit_id;
                }

                setFlash('success', "อัปเดตข้อมูลผู้ใช้งานเรียบร้อยแล้ว");
            } catch (\PDOException $e) {
                setFlash('danger', "เกิดข้อผิดพลาด: " . $e->getMessage());
            }
        }
        header("Location: users.php");
        exit;
    }

    // 3. ลบผู้ใช้งาน
    if ($action === 'delete_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);

        if ($user_id === (int)$_SESSION['user_id']) {
            setFlash('danger', 'ไม่สามารถลบบัญชีผู้ใช้ที่กำลังเข้าสู่ระบบอยู่ในขณะนี้ได้');
        } elseif ($user_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->execute([$user_id]);
                setFlash('success', "ลบบัญชีผู้ใช้งานเรียบร้อยแล้ว");
            } catch (\PDOException $e) {
                setFlash('danger', "ไม่สามารถลบผู้ใช้งานนี้ได้ เนื่องจากมีข้อมูลประวัติการขอเบิกหรือบันทึกผูกอยู่");
            }
        }
        header("Location: users.php");
        exit;
    }
}

// ดึงรายการผู้ใช้ทั้งหมดพร้อมข้อมูลหน่วยงาน
$users_stmt = $pdo->query("
    SELECT u.*, un.unit_name, un.unit_code, un.location
    FROM users u
    LEFT JOIN units un ON u.unit_id = un.unit_id
    ORDER BY u.user_id ASC
");
$users = $users_stmt->fetchAll();

// ดึงหน่วยงานทั้งหมดสำหรับตัวเลือก
$units_stmt = $pdo->query("SELECT * FROM units ORDER BY unit_id ASC");
$all_units = $units_stmt->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการผู้ใช้งานระบบ (Users) | Super Admin โรงพยาบาลมหาสารคาม</title>
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
    <a href="users.php" class="bottom-nav-item active">
        <i class="fa-solid fa-users-gear"></i>
        <span>ผู้ใช้</span>
    </a>
    <a href="units.php" class="bottom-nav-item">
        <i class="fa-solid fa-hospital-user"></i>
        <span>หน่วยงาน</span>
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
                <i class="fa-solid fa-users-gear me-2 text-primary"></i> จัดการผู้ใช้งานระบบและกำหนดหน่วยงาน (User Management)
            </h2>
            <p class="text-muted small mb-0">เพิ่มผู้ใช้ แก้ไขสิทธิ์ กำหนดสังกัดห้องพัสดุประจำหน่วยงาน และรีเซ็ตรหัสผ่าน</p>
        </div>
        <button class="btn btn-success rounded-3 shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="fa-solid fa-user-plus me-1"></i> เพิ่มผู้ใช้งานใหม่
        </button>
    </div>

    <div class="card content-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="usersTable">
                <thead class="table-light">
                    <tr>
                        <th style="width: 70px;">ID</th>
                        <th>ชื่อผู้ใช้งาน (Username)</th>
                        <th>ชื่อ-นามสกุล / ตำแหน่ง</th>
                        <th>บทบาท (Role)</th>
                        <th>หน่วยงานที่สังกัด</th>
                        <th>วันที่สร้าง</th>
                        <th class="text-center" style="width: 170px;">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="text-muted font-monospace"><?= $u['user_id'] ?></td>
                            <td>
                                <span class="fw-bold text-dark font-monospace"><?= h($u['username']) ?></span>
                                <?php if ($u['user_id'] === (int)$_SESSION['user_id']): ?>
                                    <span class="badge bg-secondary ms-1">คุณ</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?= h($u['full_name']) ?></div>
                            </td>
                            <td>
                                <?php if ($u['role'] === 'super_admin'): ?>
                                    <span class="badge bg-warning text-dark"><i class="fa-solid fa-crown me-1"></i> Super Admin</span>
                                <?php else: ?>
                                    <span class="badge bg-info text-dark"><i class="fa-solid fa-user me-1"></i> Unit Admin</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($u['unit_name'])): ?>
                                    <span class="badge bg-light text-dark border">
                                        <i class="fa-solid fa-hospital-user me-1 text-teal"></i> <?= h($u['unit_name']) ?> (<?= h($u['unit_code']) ?>)
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">- ไม่มีสังกัด -</span>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted"><?= thaiDate($u['created_at'], false) ?></small></td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" 
                                            onclick="openEditUser(<?= htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8') ?>)"
                                            title="แก้ไขข้อมูล/สังกัดหน่วย/รหัสผ่าน">
                                        <i class="fa-solid fa-pen-to-square me-1"></i> แก้ไข
                                    </button>
                                    <?php if ($u['user_id'] !== (int)$_SESSION['user_id']): ?>
                                        <button class="btn btn-outline-danger" 
                                                onclick="confirmDeleteUser(<?= $u['user_id'] ?>, '<?= addslashes(h($u['username'])) ?>')"
                                                title="ลบผู้ใช้">
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

<!-- ==================== MODALS ==================== -->

<!-- Modal: เพิ่มผู้ใช้งานใหม่ -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus me-2"></i> เพิ่มผู้ใช้งานใหม่</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_user">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">ชื่อผู้ใช้งาน (Username) <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control rounded-3 font-monospace" placeholder="เช่น nurse_er01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">รหัสผ่าน (Password) <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control rounded-3" placeholder="กำหนดรหัสผ่าน เช่น 123456" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">ชื่อ-นามสกุล / ตำแหน่ง <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control rounded-3" placeholder="เช่น พว.สมศรี สุขเกษม (พยาบาลวิชาชีพ)" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">บทบาทในระบบ (Role) <span class="text-danger">*</span></label>
                        <select name="role" class="form-select rounded-3" required>
                            <option value="unit_admin">ผู้ดูแลพัสดุประจำหน่วยงาน (Unit Admin)</option>
                            <option value="super_admin">ผู้ดูแลคลังกลางหลัก (Super Admin)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">หน่วยงานที่สังกัด (ห้องพัสดุ) <span class="text-danger">*</span></label>
                        <select name="unit_id" class="form-select rounded-3" required>
                            <option value="">-- เลือกหน่วยงานที่สังกัด --</option>
                            <?php foreach ($all_units as $un): ?>
                                <option value="<?= $un['unit_id'] ?>"><?= h($un['unit_name']) ?> (<?= h($un['unit_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success rounded-3 px-4">บันทึกผู้ใช้</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: แก้ไขข้อมูลผู้ใช้งาน -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-pen me-2"></i> แก้ไขข้อมูลผู้ใช้งาน</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">ชื่อผู้ใช้งาน (Username)</label>
                        <input type="text" id="edit_username" class="form-control rounded-3 bg-light font-monospace" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">ชื่อ-นามสกุล / ตำแหน่ง <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="edit_full_name" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">บทบาทในระบบ (Role) <span class="text-danger">*</span></label>
                        <select name="role" id="edit_role" class="form-select rounded-3" required>
                            <option value="unit_admin">ผู้ดูแลพัสดุประจำหน่วยงาน (Unit Admin)</option>
                            <option value="super_admin">ผู้ดูแลคลังกลางหลัก (Super Admin)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">หน่วยงานที่สังกัด (ห้องพัสดุ) <span class="text-danger">*</span></label>
                        <select name="unit_id" id="edit_unit_id" class="form-select rounded-3" required>
                            <option value="">-- เลือกหน่วยงานที่สังกัด --</option>
                            <?php foreach ($all_units as $un): ?>
                                <option value="<?= $un['unit_id'] ?>"><?= h($un['unit_name']) ?> (<?= h($un['unit_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="border-top pt-3 mt-3">
                        <label class="form-label fw-semibold text-danger">
                            <i class="fa-solid fa-key me-1"></i> เปลี่ยน / รีเซ็ตรหัสผ่านใหม่
                        </label>
                        <input type="password" name="new_password" class="form-control rounded-3" placeholder="เว้นว่างไว้หากไม่ต้องการเปลี่ยนรหัสผ่าน">
                        <small class="text-muted d-block mt-1">กรอกเมื่อต้องการตั้งรหัสผ่านใหม่ให้ผู้ใช้รายนี้</small>
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
<form id="deleteUserForm" method="POST" action="" style="display: none;">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="user_id" id="delete_user_id">
</form>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    $(document).ready(function() {
        $('#usersTable').DataTable({
            language: { search: "ค้นหาผู้ใช้:", lengthMenu: "แสดง _MENU_ รายการ", paginate: { previous: "ก่อน", next: "ถัดไป" } },
            pageLength: 10
        });
    });

    function openEditUser(u) {
        document.getElementById('edit_user_id').value = u.user_id;
        document.getElementById('edit_username').value = u.username;
        document.getElementById('edit_full_name').value = u.full_name;
        document.getElementById('edit_role').value = u.role;
        document.getElementById('edit_unit_id').value = u.unit_id || '';
        new bootstrap.Modal(document.getElementById('editUserModal')).show();
    }

    function confirmDeleteUser(id, username) {
        Swal.fire({
            title: 'ยืนยันการลบผู้ใช้?',
            text: `ต้องการลบบัญชีผู้ใช้ "${username}" หรือไม่?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'ใช่, ต้องการลบ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete_user_id').value = id;
                document.getElementById('deleteUserForm').submit();
            }
        });
    }
</script>

</body>
</html>
