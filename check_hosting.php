<?php
/**
 * หน้าตรวจสอบความพร้อมของ Web Hosting (Hosting Diagnostic & Self-Check Tool)
 * ระบบจัดการวัสดุและเวชภัณฑ์ โรงพยาบาลมหาสารคาม
 * 
 * ใช้สำหรับตรวจสอบปัญหาเมื่ออัปโหลดเว็บไซต์ขึ้นโฮสต์จริง (cPanel / DirectAdmin / Plesk / Cloud)
 * เปิดผ่านเบราว์เซอร์: http(s)://your-domain.com/check_hosting.php
 */

// เปิดแสดงข้อผิดพลาดเพื่อการวินิจฉัยปัญหา
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// อ่านค่า config.php โดยตรง
$config_file = __DIR__ . '/config.php';
$has_config = file_exists($config_file);

// ตัวแปรเก็บผลการตรวจสอบ
$checks = [];
$db_connected = false;
$db_error = '';
$existing_tables = [];
$missing_tables = [];
$pdo = null;

// 1. ตรวจสอบเวอร์ชัน PHP
$php_version = PHP_VERSION;
$php_ok = version_compare($php_version, '7.4.0', '>=');
$checks[] = [
    'title' => 'เวอร์ชัน PHP (PHP Version)',
    'status' => $php_ok ? 'pass' : 'fail',
    'value' => 'PHP ' . $php_version,
    'detail' => $php_ok ? 'รองรับการทำงานปกติ (แนะนำ PHP 8.1 - 8.2)' : 'ต้องการ PHP เวอร์ชั่น 7.4 ขึ้นไป (แนะนำ PHP 8.1+)'
];

// 2. ตรวจสอบ PHP Extensions ที่จำเป็น
$required_exts = ['pdo', 'pdo_mysql', 'mbstring', 'session'];
$missing_exts = [];
foreach ($required_exts as $ext) {
    if (!extension_loaded($ext)) {
        $missing_exts[] = $ext;
    }
}
$checks[] = [
    'title' => 'ส่วนขยาย PHP ที่จำเป็น (PHP Extensions)',
    'status' => empty($missing_exts) ? 'pass' : 'fail',
    'value' => empty($missing_exts) ? 'ครบถ้วน (PDO, PDO_MySQL, Mbstring, Session)' : 'ขาดส่วนขยาย: ' . implode(', ', $missing_exts),
    'detail' => empty($missing_exts) ? 'พร้อมใช้งาน' : 'กรุณาเปิด Extension ใน cPanel/DirectAdmin (เมนู Select PHP Version)'
];

// 3. ตรวจสอบไฟล์ config.php
$checks[] = [
    'title' => 'ไฟล์ตั้งค่าระบบ (config.php)',
    'status' => $has_config ? 'pass' : 'fail',
    'value' => $has_config ? 'พบไฟล์ config.php' : 'ไม่พบไฟล์ config.php',
    'detail' => $has_config ? realpath($config_file) : 'กรุณาอัปโหลดไฟล์ config.php ขึ้นโฮสต์'
];

// 4. ทดสอบเชื่อมต่อฐานข้อมูล
if ($has_config) {
    // ดึงค่า Constants จาก config.php
    $config_content = file_get_contents($config_file);
    preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/", $config_content, $m_host);
    preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/", $config_content, $m_name);
    preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/", $config_content, $m_user);
    preg_match("/define\s*\(\s*['\"]DB_PASS['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/", $config_content, $m_pass);

    $db_host = $m_host[1] ?? 'localhost';
    $db_name = $m_name[1] ?? '';
    $db_user = $m_user[1] ?? '';
    $db_pass = $m_pass[1] ?? '';

    try {
        $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $db_connected = true;
    } catch (\Exception $e) {
        $db_error = $e->getMessage();
    }

    $checks[] = [
        'title' => 'การเชื่อมต่อฐานข้อมูล (Database Connection)',
        'status' => $db_connected ? 'pass' : 'fail',
        'value' => $db_connected ? "เชื่อมต่อสำเร็จ (Host: {$db_host}, DB: {$db_name}, User: {$db_user})" : "เชื่อมต่อล้มเหลว",
        'detail' => $db_connected ? 'พร้อมใช้งาน' : "Error: " . htmlspecialchars($db_error)
    ];
}

// 5. ตรวจสอบตารางในฐานข้อมูล (Tables Check)
$expected_tables = [
    'units', 'users', 'categories', 'materials', 'unit_stocks', 
    'requisitions', 'requisition_items', 'item_borrows', 'unit_usages', 
    'stock_movements', 'system_settings', 'suppliers', 'refill_orders'
];

$missing_columns = [];
$admin_user_found = false;

if ($db_connected && $pdo) {
    try {
        $existing_tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($expected_tables as $tb) {
            if (!in_array($tb, $existing_tables)) {
                $missing_tables[] = $tb;
            }
        }

        // ตรวจสอบคอลัมน์สำคัญใน materials
        if (in_array('materials', $existing_tables)) {
            $m_cols = $pdo->query("SHOW COLUMNS FROM `materials`")->fetchAll(PDO::FETCH_COLUMN);
            $chk_cols = ['barcode', 'unit_price', 'supplier', 'lot_no', 'exp_date'];
            foreach ($chk_cols as $c) {
                if (!in_array($c, $m_cols)) {
                    $missing_columns[] = "materials.{$c}";
                }
            }
        }

        // ตรวจสอบคอลัมน์ใน stock_movements
        if (in_array('stock_movements', $existing_tables)) {
            $sm_cols = $pdo->query("SHOW COLUMNS FROM `stock_movements`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('unit_price', $sm_cols)) $missing_columns[] = "stock_movements.unit_price";
            if (!in_array('total_price', $sm_cols)) $missing_columns[] = "stock_movements.total_price";
            if (!in_array('supplier', $sm_cols)) $missing_columns[] = "stock_movements.supplier";
        }

        // ตรวจสอบผู้ใช้ Admin
        if (in_array('users', $existing_tables)) {
            $admin_check = $pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'super_admin'")->fetchColumn();
            $admin_user_found = ($admin_check > 0);
        }

    } catch (\Exception $e) {
        $db_error = $e->getMessage();
    }

    $checks[] = [
        'title' => 'ตารางในฐานข้อมูล (Database Tables: 13 ตาราง)',
        'status' => empty($missing_tables) ? 'pass' : 'fail',
        'value' => empty($missing_tables) ? 'พบครบทั้ง 13 ตาราง' : 'ขาด ' . count($missing_tables) . ' ตาราง (' . implode(', ', $missing_tables) . ')',
        'detail' => empty($missing_tables) ? 'โครงสร้างฐานข้อมูลสมบูรณ์' : 'กรุณาเปิด phpMyAdmin บนโฮสต์ แล้ว Import ไฟล์ hospital_materials.sql'
    ];

    $checks[] = [
        'title' => 'ฟิลด์ข้อมูลเวชภัณฑ์ขั้นสูง (Advanced Columns)',
        'status' => empty($missing_columns) ? 'pass' : 'fail',
        'value' => empty($missing_columns) ? 'ครบถ้วน (บาร์โค้ด, ราคาทุน, บริษัท, ล็อต, วันหมดอายุ)' : 'ขาดฟิลด์: ' . implode(', ', $missing_columns),
        'detail' => empty($missing_columns) ? 'รองรับระบบบาร์โค้ดและรายงานครบถ้วน' : 'เรียกใช้งาน auto_migrate.php เพื่อเพิ่มฟิลด์อัตโนมัติ'
    ];

    $checks[] = [
        'title' => 'บัญชีผู้ดูแลระบบคลังใหญ่ (Admin Account)',
        'status' => $admin_user_found ? 'pass' : 'fail',
        'value' => $admin_user_found ? 'พบบัญชีผู้ดูแลระบบ (super_admin)' : 'ไม่พบบัญชี super_admin',
        'detail' => $admin_user_found ? 'username: admin' : 'กรุณานำเข้าข้อมูลเริ่มต้นจาก hospital_materials.sql'
    ];
}

// 6. ตรวจสอบการตั้งค่า Path & Base URL
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$base_dir = str_replace('\\', '/', dirname($script_name));
if ($base_dir === '/' || $base_dir === '\\' || $base_dir === '.') {
    $base_dir = '';
}
$clean_base = preg_replace('/(\/admin|\/unit)$/i', '', $base_dir);
$detected_base_url = rtrim($clean_base, '/');

$checks[] = [
    'title' => 'การตรวจจับที่อยู่เว็บ (URL & Path Detection)',
    'status' => 'pass',
    'value' => 'BASE_URL = "' . ($detected_base_url ?: '(Root Directory)') . '"',
    'detail' => 'Host: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ' | Path: ' . $script_name
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตรวจสอบความพร้อม Web Hosting | ระบบจัดการวัสดุและเวชภัณฑ์</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f1f5f9; color: #1e293b; }
        h1, h2, h3, h4, h5, .fw-title { font-family: 'Prompt', sans-serif; }
        .card-custom { border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .badge-pass { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 0.9rem; padding: 6px 12px; }
        .badge-fail { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; font-size: 0.9rem; padding: 6px 12px; }
    </style>
</head>
<body class="py-5">
    <div class="container" style="max-width: 900px;">
        <!-- Header -->
        <div class="card card-custom bg-white p-4 mb-4 text-center">
            <div class="mb-3">
                <i class="fa-solid fa-server fa-3x text-primary"></i>
            </div>
            <h3 class="fw-bold text-dark mb-1">ระบบตรวจสอบความพร้อมการติดตั้งบน Web Hosting</h3>
            <p class="text-muted mb-0">ระบบจัดการวัสดุและเวชภัณฑ์ โรงพยาบาลมหาสารคาม</p>
        </div>

        <!-- Checklist -->
        <div class="card card-custom bg-white p-4 mb-4">
            <h5 class="fw-bold text-dark mb-3">
                <i class="fa-solid fa-clipboard-check text-primary me-2"></i>ผลการตรวจสอบระบบ (Diagnostic Checklist)
            </h5>

            <div class="list-group list-group-flush">
                <?php foreach ($checks as $c): ?>
                    <div class="list-group-item px-0 py-3 d-flex justify-content-between align-items-start">
                        <div class="me-3">
                            <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($c['title']) ?></div>
                            <div class="text-secondary small mt-1"><?= htmlspecialchars($c['value']) ?></div>
                            <div class="text-muted small mt-1"><i class="fa-solid fa-circle-info me-1"></i><?= htmlspecialchars($c['detail']) ?></div>
                        </div>
                        <div>
                            <?php if ($c['status'] === 'pass'): ?>
                                <span class="badge badge-pass rounded-pill"><i class="fa-solid fa-check me-1"></i>ผ่าน (OK)</span>
                            <?php else: ?>
                                <span class="badge badge-fail rounded-pill"><i class="fa-solid fa-triangle-exclamation me-1"></i>ต้องแก้ไข</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- กล่องแนะนำวิธีแก้ไขปัญหา (Troubleshooting & Quick Fixes) -->
        <div class="card card-custom bg-white p-4 mb-4">
            <h5 class="fw-bold text-dark mb-3">
                <i class="fa-solid fa-wrench text-warning me-2"></i>วิธีแก้ไขปัญหาที่พบบ่อยเมื่ออัปโหลดขึ้นโฮสต์
            </h5>

            <div class="accordion" id="fixAccordion">
                <!-- Fix 1 -->
                <div class="accordion-item border-0 mb-3 rounded-3 shadow-sm" style="background: #f8fafc;">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-bold text-dark bg-transparent" type="button" data-bs-toggle="collapse" data-bs-target="#fixDb">
                            1. กรณีเชื่อมต่อฐานข้อมูลล้มเหลว (Database Connection Error)
                        </button>
                    </h2>
                    <div id="fixDb" class="accordion-collapse collapse" data-bs-parent="#fixAccordion">
                        <div class="accordion-body text-secondary small pt-0">
                            เปิดไฟล์ <code>config.php</code> แล้วเปลี่ยนค่าการเชื่อมต่อให้ตรงกับที่สร้างไว้บน Web Hosting:
                            <pre class="bg-dark text-white p-3 rounded-3 mt-2"><code>define('DB_HOST', 'localhost'); // โฮสต์ส่วนใหญ่ใช้ localhost
define('DB_NAME', 'ชื่อฐานข้อมูลที่สร้างบนโฮสต์'); // เช่น u123456_hospital
define('DB_USER', 'ชื่อผู้ใช้ฐานข้อมูลที่สร้างบนโฮสต์'); // เช่น u123456_admin
define('DB_PASS', 'รหัสผ่านฐานข้อมูลที่ตั้งไว้บนโฮสต์');</code></pre>
                            <span class="text-danger fw-bold">*ข้อควรระวัง:</span> อย่าลืมเข้าไปที่เมนู <strong>MySQL Databases</strong> ใน cPanel/DirectAdmin เพื่อทำการ <strong>Add User To Database</strong> และติ๊กเลือก <strong>ALL PRIVILEGES</strong> ด้วย
                        </div>
                    </div>
                </div>

                <!-- Fix 2 -->
                <div class="accordion-item border-0 mb-3 rounded-3 shadow-sm" style="background: #f8fafc;">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-bold text-dark bg-transparent" type="button" data-bs-toggle="collapse" data-bs-target="#fixSql">
                            2. กรณีตารางไม่ครบ หรือแจ้งว่า Table doesn't exist
                        </button>
                    </h2>
                    <div id="fixSql" class="accordion-collapse collapse" data-bs-parent="#fixAccordion">
                        <div class="accordion-body text-secondary small pt-0">
                            1. เปิด <strong>phpMyAdmin</strong> บนโฮสต์ของท่าน<br>
                            2. คลิกเลือกชื่อฐานข้อมูลที่สร้างไว้ทางแถบซ้ายมือ<br>
                            3. คลิกแท็บ <strong>Import</strong> ด้านบน แล้วเลือกไฟล์ <code>hospital_materials.sql</code><br>
                            4. กดปุ่ม <strong>Go / Import</strong> ด้านล่างเพื่อนำเข้าข้อมูลให้ครบ 13 ตาราง
                        </div>
                    </div>
                </div>

                <!-- Fix 3 -->
                <div class="accordion-item border-0 mb-3 rounded-3 shadow-sm" style="background: #f8fafc;">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-bold text-dark bg-transparent" type="button" data-bs-toggle="collapse" data-bs-target="#fixUrl">
                            3. ปัญหาหน้าเว็บแสดง 404 Not Found หรือ CSS ไม่โหลด
                        </button>
                    </h2>
                    <div id="fixUrl" class="accordion-collapse collapse" data-bs-parent="#fixAccordion">
                        <div class="accordion-body text-secondary small pt-0">
                            ระบบปัจจุบันได้รับการอัปเดตให้รองรับทั้ง Root Domain (เช่น <code>https://yourdomain.com/</code>) และ Subfolder แล้วอัตโนมัติ<br>
                            หากเข้าหน้าแรกไม่ได้ ให้ลองพิมพ์ URL ตรงๆ เช่น <code>https://yourdomain.com/login.php</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ลิงก์เข้าสู่ระบบ -->
        <div class="text-center">
            <a href="login.php" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold shadow-sm">
                <i class="fa-solid fa-arrow-right-to-bracket me-2"></i>ไปที่หน้าเข้าสู่ระบบ (Login)
            </a>
            <div class="text-muted small mt-2">
                <i class="fa-solid fa-shield-halved me-1 text-danger"></i>หมายเหตุ: เพื่อความปลอดภัย เมื่อตรวจสอบและใช้งานได้เรียบร้อยแล้ว แนะนำให้ลบไฟล์ <code>check_hosting.php</code> ทิ้ง
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

