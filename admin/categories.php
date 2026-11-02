<?php
/**
 * จัดการหมวดหมู่วัสดุ (Material Categories Management)
 * สำหรับ Super Admin โรงพยาบาลมหาสารคาม
 */
require_once __DIR__ . '/../config.php';
checkAuth('super_admin');

// จัดการการส่งฟอร์ม (POST Request Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. เพิ่มหมวดหมู่ใหม่
    if ($action === 'add_category') {
        $category_name = trim($_POST['category_name'] ?? '');
        $description   = trim($_POST['description'] ?? '');

        if (!empty($category_name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO categories (category_name, description) VALUES (?, ?)");
                $stmt->execute([$category_name, $description]);
                setFlash('success', "เพิ่มหมวดหมู่ '{$category_name}' เรียบร้อยแล้ว");
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) {
                    setFlash('danger', "หมวดหมู่ '{$category_name}' มีอยู่ในระบบแล้ว");
                } else {
                    setFlash('danger', "เกิดข้อผิดพลาด: " . $e->getMessage());
                }
            }
        } else {
            setFlash('danger', 'กรุณาระบุชื่อหมวดหมู่');
        }
        header("Location: categories.php");
        exit;
    }

    // 2. แก้ไขหมวดหมู่
    if ($action === 'edit_category') {
        $category_id   = (int)($_POST['category_id'] ?? 0);
        $category_name = trim($_POST['category_name'] ?? '');
        $description   = trim($_POST['description'] ?? '');

        if ($category_id > 0 && !empty($category_name)) {
            try {
                // อัปเดตชื่อหมวดหมู่ในตาราง materials ด้วย (เพื่อให้สอดคล้องกัน)
                $old_name_stmt = $pdo->prepare("SELECT category_name FROM categories WHERE category_id = ?");
                $old_name_stmt->execute([$category_id]);
                $old_name = $old_name_stmt->fetchColumn();

                $stmt = $pdo->prepare("UPDATE categories SET category_name = ?, description = ? WHERE category_id = ?");
                $stmt->execute([$category_name, $description, $category_id]);

                if ($old_name && $old_name !== $category_name) {
                    $up_mat = $pdo->prepare("UPDATE materials SET category = ? WHERE category = ?");
                    $up_mat->execute([$category_name, $old_name]);
                }

                setFlash('success', "แก้ไขข้อมูลหมวดหมู่เรียบร้อยแล้ว");
            } catch (\PDOException $e) {
                setFlash('danger', "เกิดข้อผิดพลาด: " . $e->getMessage());
            }
        }
        header("Location: categories.php");
        exit;
    }

    // 3. ลบหมวดหมู่
    if ($action === 'delete_category') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        if ($category_id > 0) {
            $cat_stmt = $pdo->prepare("SELECT category_name FROM categories WHERE category_id = ?");
            $cat_stmt->execute([$category_id]);
            $cat_name = $cat_stmt->fetchColumn();

            // ตรวจสอบว่ามีพัสดุใช้หมวดหมู่นี้อยู่หรือไม่
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM materials WHERE category = ?");
            $count_stmt->execute([$cat_name]);
            $used_count = $count_stmt->fetchColumn();

            if ($used_count > 0) {
                setFlash('danger', "ไม่สามารถลบหมวดหมู่ '{$cat_name}' ได้ เนื่องจากมีรายการพัสดุผูกอยู่จำนวน {$used_count} รายการ");
            } else {
                $del_stmt = $pdo->prepare("DELETE FROM categories WHERE category_id = ?");
                $del_stmt->execute([$category_id]);
                setFlash('success', "ลบหมวดหมู่เรียบร้อยแล้ว");
            }
        }
        header("Location: categories.php");
        exit;
    }
}

// ดึงรายการหมวดหมู่ทั้งหมด พร้อมจำนวนพัสดุในหมวดหมู่นั้น
$categories_stmt = $pdo->query("
    SELECT c.*, COUNT(m.material_id) AS material_count
    FROM categories c
    LEFT JOIN materials m ON c.category_name = m.category
    GROUP BY c.category_id
    ORDER BY c.category_id ASC
");
$categories = $categories_stmt->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการหมวดหมู่วัสดุ | Super Admin โรงพยาบาลมหาสารคาม</title>
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
    <a href="categories.php" class="bottom-nav-item active">
        <i class="fa-solid fa-tags"></i>
        <span>หมวดหมู่</span>
    </a>
    <a href="dashboard.php" class="bottom-nav-item">
        <i class="fa-solid fa-bars"></i>
        <span>เมนูหลัก</span>
    </a>
</nav>

<div class="container py-4" style="max-width: 900px;">

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
                <i class="fa-solid fa-tags me-2 text-primary"></i> จัดการหมวดหมู่วัสดุและเวชภัณฑ์
            </h2>
            <p class="text-muted small mb-0">กำหนดหมวดหมู่สำหรับการจัดกลุ่มพัสดุและการค้นหาในโรงพยาบาลมหาสารคาม</p>
        </div>
        <button class="btn btn-success rounded-3 shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
            <i class="fa-solid fa-plus-circle me-1"></i> เพิ่มหมวดหมู่ใหม่
        </button>
    </div>

    <div class="card content-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 80px;">ลำดับ</th>
                        <th>ชื่อหมวดหมู่วัสดุ</th>
                        <th>คำอธิบาย / รายละเอียด</th>
                        <th class="text-center" style="width: 140px;">จำนวนพัสดุ</th>
                        <th class="text-center" style="width: 150px;">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($categories as $cat): ?>
                        <tr>
                            <td class="text-muted font-monospace"><?= $i++ ?></td>
                            <td>
                                <span class="fw-bold text-dark fs-6"><?= h($cat['category_name']) ?></span>
                            </td>
                            <td>
                                <span class="text-muted small"><?= h($cat['description'] ?? '-') ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary bg-opacity-10 text-primary border fs-6">
                                    <?= number_format($cat['material_count']) ?> รายการ
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" 
                                            onclick="openEditCategory(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES, 'UTF-8') ?>)"
                                            title="แก้ไขหมวดหมู่">
                                        <i class="fa-solid fa-pen"></i> แก้ไข
                                    </button>
                                    <button class="btn btn-outline-danger" 
                                            onclick="confirmDeleteCategory(<?= $cat['category_id'] ?>, '<?= addslashes(h($cat['category_name'])) ?>', <?= $cat['material_count'] ?>)"
                                            title="ลบหมวดหมู่">
                                        <i class="fa-solid fa-trash"></i>
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

<!-- Modal: เพิ่มหมวดหมู่ใหม่ -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i> เพิ่มหมวดหมู่ใหม่</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_category">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">ชื่อหมวดหมู่ <span class="text-danger">*</span></label>
                        <input type="text" name="category_name" class="form-control rounded-3" placeholder="เช่น เวชภัณฑ์สิ้นเปลือง, อุปกรณ์ป้องกัน" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">คำอธิบายรายละเอียด</label>
                        <textarea name="description" class="form-control rounded-3" rows="3" placeholder="ระบุขอบเขตหรือชนิดของพัสดุในหมวดหมู่นี้"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success rounded-3 px-4">บันทึกหมวดหมู่</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: แก้ไขหมวดหมู่ -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen me-2"></i> แก้ไขหมวดหมู่</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_category">
                <input type="hidden" name="category_id" id="edit_cat_id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">ชื่อหมวดหมู่ <span class="text-danger">*</span></label>
                        <input type="text" name="category_name" id="edit_cat_name" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">คำอธิบายรายละเอียด</label>
                        <textarea name="description" id="edit_cat_desc" class="form-control rounded-3" rows="3"></textarea>
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
<form id="deleteCatForm" method="POST" action="" style="display: none;">
    <input type="hidden" name="action" value="delete_category">
    <input type="hidden" name="category_id" id="delete_cat_id">
</form>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    function openEditCategory(cat) {
        document.getElementById('edit_cat_id').value = cat.category_id;
        document.getElementById('edit_cat_name').value = cat.category_name;
        document.getElementById('edit_cat_desc').value = cat.description || '';
        new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
    }

    function confirmDeleteCategory(id, name, count) {
        if (count > 0) {
            Swal.fire('ไม่สามารถลบได้', `หมวดหมู่นี้มีพัสดุผูกอยู่ ${count} รายการ กรุณาย้ายหรือลบพัสดุก่อน`, 'warning');
            return;
        }

        Swal.fire({
            title: 'ยืนยันการลบหมวดหมู่?',
            text: `ต้องการลบหมวดหมู่ "${name}" หรือไม่?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'ใช่, ต้องการลบ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete_cat_id').value = id;
                document.getElementById('deleteCatForm').submit();
            }
        });
    }
</script>

</body>
</html>
