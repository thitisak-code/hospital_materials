<?php
/**
 * ตั้งค่าระบบส่วนกลาง (System Settings)
 * สำหรับ Super Admin โรงพยาบาลมหาสารคาม
 * ควบคุมการเปิด-ปิดรับเบิกพัสดุ, ที่อยู่เว็บสำหรับ QR Code, โลโก้ และหน้า Loading Screen
 */
require_once __DIR__ . '/../config.php';
checkAuth('super_admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $req_enabled       = isset($_POST['requisition_enabled']) ? '1' : '0';
        $closed_reason     = trim($_POST['requisition_closed_reason'] ?? '');
        $site_url          = trim($_POST['site_url'] ?? '');
        $hospital_name     = trim($_POST['hospital_name'] ?? 'โรงพยาบาลมหาสารคาม');
        $enable_loading    = isset($_POST['enable_loading_screen']) ? '1' : '0';

        updateSetting($pdo, 'requisition_enabled', $req_enabled);
        updateSetting($pdo, 'requisition_closed_reason', $closed_reason);
        updateSetting($pdo, 'site_url', $site_url);
        updateSetting($pdo, 'hospital_name', $hospital_name);
        updateSetting($pdo, 'enable_loading_screen', $enable_loading);

        // จัดการอัปโหลดไฟล์โลโก้ (ถ้ามี)
        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $file     = $_FILES['logo_file'];
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed  = ['png', 'jpg', 'jpeg', 'svg', 'webp'];

            if (in_array($ext, $allowed)) {
                $target_dir = __DIR__ . '/../assets/img/';
                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                $new_filename = 'logo_' . time() . '.' . $ext;
                $target_file  = $target_dir . $new_filename;

                if (move_uploaded_file($file['tmp_name'], $target_file)) {
                    updateSetting($pdo, 'hospital_logo', 'assets/img/' . $new_filename);
                }
            } else {
                setFlash('danger', 'ประเภทไฟล์โลโก้ไม่ถูกต้อง (รองรับเฉพาะ PNG, JPG, JPEG, SVG, WEBP)');
                header("Location: settings.php");
                exit;
            }
        }

        setFlash('success', 'บันทึกการตั้งค่าระบบเรียบร้อยแล้ว');
        header("Location: settings.php");
        exit;
    }
}

$req_enabled       = getSetting('requisition_enabled', '1');
$closed_reason     = getSetting('requisition_closed_reason', 'ระบบคลังกลางปิดรับการขอเบิกชั่วคราวเนื่องจากอยู่ระหว่างการตรวจนับสต็อกประจำงวด');
$site_url          = getSetting('site_url', '');
$hospital_name     = getSetting('hospital_name', 'โรงพยาบาลมหาสารคาม');
$hospital_logo     = getSetting('hospital_logo', 'assets/img/logo.svg');
$enable_loading    = getSetting('enable_loading_screen', '1');

$detected_url      = getQrBaseUrl();
$flash             = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าระบบ (System Settings) | Super Admin โรงพยาบาลมหาสารคาม</title>
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
        .content-card {
            background: #ffffff;
            border-radius: 18px;
            border: none;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.04);
            margin-bottom: 25px;
        }
        .logo-preview-box {
            width: 110px;
            height: 110px;
            border-radius: 20px;
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 10px;
        }
        .form-check-input:checked {
            background-color: var(--mhk-primary);
            border-color: var(--mhk-primary);
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
    <a href="settings.php" class="bottom-nav-item active">
        <i class="fa-solid fa-sliders"></i>
        <span>ตั้งค่า</span>
    </a>
    <a href="dashboard.php" class="bottom-nav-item">
        <i class="fa-solid fa-bars"></i>
        <span>เมนูหลัก</span>
    </a>
</nav>

<div class="container-fluid px-lg-4 py-4" style="max-width: 1050px;">

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
                <i class="fa-solid fa-sliders me-2 text-teal" style="color: var(--mhk-primary);"></i> ตั้งค่าระบบส่วนกลาง (System Settings)
            </h2>
            <p class="text-muted small mb-0">ควบคุมสถานะการเปิด-ปิดรับเบิกพัสดุ, ที่อยู่ URL สำหรับสร้าง QR Code บนมือถือ, โลโก้ และหน้าจอ Loading Screen</p>
        </div>
    </div>

    <form method="POST" action="" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_settings">

        <!-- 1. การควบคุมการเปิด-ปิดระบบเบิกพัสดุ -->
        <div class="card content-card p-4">
            <div class="d-flex align-items-center mb-3">
                <span class="rounded-circle bg-warning bg-opacity-10 p-2 me-3 text-warning">
                    <i class="fa-solid fa-power-off fa-lg"></i>
                </span>
                <div>
                    <h5 class="fw-bold mb-0">1. ควบคุมการเปิด-ปิดรับใบขอเบิกพัสดุ (Requisition Control)</h5>
                    <small class="text-muted">ใช้ในกรณีคลังกลางไม่พร้อมจ่ายของชั่วคราว เช่น มีการตรวจนับสต็อกประจำปี หรือปิดปรับปรุง</small>
                </div>
            </div>

            <div class="p-3 rounded-4 bg-light mb-3 border">
                <div class="form-check form-switch fs-5 mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="reqEnabledSwitch" name="requisition_enabled" <?= $req_enabled === '1' ? 'checked' : '' ?> onchange="toggleRequisitionUi()">
                    <label class="form-check-label fw-bold fs-6" for="reqEnabledSwitch" id="reqEnabledLabel">
                        <?= $req_enabled === '1' ? '<span class="text-success"><i class="fa-solid fa-circle-check me-1"></i> เปิดรับใบขอเบิกตามปกติ</span>' : '<span class="text-danger"><i class="fa-solid fa-ban me-1"></i> ปิดรับใบขอเบิกชั่วคราว (หน่วยงานจะไม่สามารถกดส่งใบเบิกได้)</span>' ?>
                    </label>
                </div>
                <div class="small text-muted ps-4 ms-2">
                    หากปิดสวิตช์นี้ ทุกหน่วยงานในโรงพยาบาลจะเห็นข้อความแจ้งเตือน และปุ่มส่งใบขอเบิกจะถูกระงับชั่วคราว (แต่หน่วยงานยังสามารถเบิกใช้ของในห้องตนเองได้ตามปกติ)
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">ข้อความชี้แจงแจ้งเตือนหน่วยงาน (เมื่อปิดรับเบิก) <span class="text-danger">*</span></label>
                <textarea name="requisition_closed_reason" id="closedReasonInput" class="form-control rounded-3" rows="3" required><?= h($closed_reason) ?></textarea>
                <div class="form-text">ข้อความนี้จะแสดงในกล่องแจ้งเตือนสีส้มเด่นชัดที่หน้าจอห้องพัสดุของทุกหน่วยงาน</div>
            </div>
        </div>

        <!-- 2. ที่อยู่เว็บไซต์สำหรับสร้าง QR Code (QR Code Mobile URL) -->
        <div class="card content-card p-4">
            <div class="d-flex align-items-center mb-3">
                <span class="rounded-circle bg-primary bg-opacity-10 p-2 me-3 text-primary">
                    <i class="fa-solid fa-qrcode fa-lg"></i>
                </span>
                <div>
                    <h5 class="fw-bold mb-0">2. ที่อยู่เว็บไซต์สำหรับสร้าง QR Code (QR Code Base URL / Mobile Access)</h5>
                    <small class="text-muted">กำหนดที่อยู่เว็บเพื่อให้การสแกน QR Code จากกล้องมือถือทั่วไปหรือสมาร์ทโฟนนอกแอป สามารถลิงก์มาเปิดเว็บได้ถูกต้อง</small>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">URL เว็บไซต์หลัก (Site URL / IP Address)</label>
                <div class="input-group">
                    <span class="input-group-text bg-light font-monospace"><i class="fa-solid fa-globe"></i></span>
                    <input type="text" name="site_url" id="siteUrlInput" class="form-control rounded-end-3 font-monospace" placeholder="เช่น http://192.168.1.100/hospital_materials หรือ https://materials.mhk-hospital.go.th" value="<?= h($site_url) ?>">
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <small class="text-muted">
                        ค่าที่ระบบตรวจพบปัจจุบัน: <strong class="text-dark font-monospace"><?= h($detected_url) ?></strong>
                    </small>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="useDetectedUrl('<?= h($detected_url) ?>')">
                        <i class="fa-solid fa-wand-magic-sparkles me-1"></i> ใช้ค่าที่ระบบตรวจพบ
                    </button>
                </div>
                <div class="alert alert-info border-0 rounded-4 mt-3 py-2 px-3 small">
                    <i class="fa-solid fa-circle-info me-1"></i> <strong>คำแนะนำ:</strong> หากต้องการให้เจ้าหน้าที่ใช้กล้องมือถือสแกนสติกเกอร์ที่ติดอยู่บนกล่องพัสดุ ให้ใส่เป็น IP Address ของเครื่องเซิร์ฟเวอร์ในวง LAN ของโรงพยาบาล (เช่น <code>http://192.168.1.XX/hospital_materials</code>) หรือ Domain Intranet ของโรงพยาบาล
                </div>
            </div>
        </div>

        <!-- 3. โลโก้และชื่อโรงพยาบาล + หน้า Loading Screen -->
        <div class="card content-card p-4">
            <div class="d-flex align-items-center mb-3">
                <span class="rounded-circle bg-success bg-opacity-10 p-2 me-3 text-success">
                    <i class="fa-solid fa-image fa-lg"></i>
                </span>
                <div>
                    <h5 class="fw-bold mb-0">3. โลโก้ ชื่อองค์กร และหน้าจอโหลด (Branding & Splash Loading Screen)</h5>
                    <small class="text-muted">ปรับแต่งชื่อโรงพยาบาล โลโก้ที่แสดงบนหัวเว็บ รายงาน PDF และอนิเมชันหน้า Loading Screen</small>
                </div>
            </div>

            <div class="row g-4 align-items-center mb-4">
                <div class="col-auto">
                    <div class="logo-preview-box shadow-sm">
                        <?php
                            $logo_src = (strpos($hospital_logo, 'http') === 0) ? $hospital_logo : BASE_URL . '/' . ltrim($hospital_logo, '/');
                        ?>
                        <img src="<?= h($logo_src) ?>" id="logoPreviewImg" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                    </div>
                </div>
                <div class="col">
                    <label class="form-label fw-semibold">อัปโหลดไฟล์โลโก้ใหม่</label>
                    <input type="file" name="logo_file" id="logoFileInput" class="form-control rounded-3" accept=".png, .jpg, .jpeg, .svg, .webp" onchange="previewLogo(event)">
                    <small class="text-muted d-block mt-1">รองรับไฟล์ PNG, JPG, JPEG, SVG หรือ WEBP (แนะนำไฟล์ภาพพื้นหลังโปร่งใส Transparent)</small>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">ชื่อโรงพยาบาล / หน่วยงานหลัก <span class="text-danger">*</span></label>
                <input type="text" name="hospital_name" class="form-control rounded-3 fs-6" value="<?= h($hospital_name) ?>" required>
            </div>

            <div class="p-3 rounded-4 bg-light border">
                <div class="form-check form-switch fs-6 mb-1">
                    <input class="form-check-input" type="checkbox" role="switch" id="loadingSwitch" name="enable_loading_screen" <?= $enable_loading === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="loadingSwitch">
                        เปิดใช้งานหน้าจอโหลดพร้อมอนิเมชันโลโก้ (Splash Loading Screen)
                    </label>
                </div>
                <small class="text-muted ps-4 ms-2 d-block">
                    แสดงโลโก้โรงพยาบาลพร้อมวงแหวนหมุนและเอฟเฟกต์ Fade-out สวยงามเมื่อผู้ใช้เปิดหรือเปลี่ยนหน้าในระบบ
                </small>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mb-5">
            <a href="dashboard.php" class="btn btn-secondary rounded-3 px-4">ยกเลิก</a>
            <button type="submit" class="btn btn-success rounded-3 px-5 shadow-sm fw-semibold">
                <i class="fa-solid fa-floppy-disk me-1"></i> บันทึกการตั้งค่า
            </button>
        </div>
    </form>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleRequisitionUi() {
        const sw = document.getElementById('reqEnabledSwitch');
        const label = document.getElementById('reqEnabledLabel');
        if (sw.checked) {
            label.innerHTML = '<span class="text-success"><i class="fa-solid fa-circle-check me-1"></i> เปิดรับใบขอเบิกตามปกติ</span>';
        } else {
            label.innerHTML = '<span class="text-danger"><i class="fa-solid fa-ban me-1"></i> ปิดรับใบขอเบิกชั่วคราว (หน่วยงานจะไม่สามารถกดส่งใบเบิกได้)</span>';
        }
    }

    function useDetectedUrl(url) {
        document.getElementById('siteUrlInput').value = url;
    }

    function previewLogo(event) {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('logoPreviewImg').src = e.target.result;
            }
            reader.readAsDataURL(file);
        }
    }
</script>

</body>
</html>
