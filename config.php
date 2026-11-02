<?php
/**
 * ========================================================
 * ไฟล์การตั้งค่าระบบหลัก (Core Configuration)
 * ระบบจัดการวัสดุ โรงพยาบาลมหาสารคาม
 * ========================================================
 */

// 1. ตั้งค่าฐานข้อมูล (Database Configuration)
define('DB_HOST', 'localhost');
define('DB_NAME', 'hospital_materials');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ตั้งค่า Base URL อัตโนมัติ (รองรับทั้ง Root Domain เช่น domain.com, Subdomain และ Subfolder เช่น domain.com/hospital_materials)
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$base_dir = str_replace('\\', '/', dirname($script_name));
if ($base_dir === '/' || $base_dir === '\\' || $base_dir === '.') {
    $clean_base = '';
} else {
    $clean_base = preg_replace('/(\/admin|\/unit)$/i', '', $base_dir);
    if ($clean_base === '/' || $clean_base === '\\') {
        $clean_base = '';
    }
}
define('BASE_URL', rtrim($clean_base, '/'));

// 2. ตั้งค่า Timezone ประเทศไทย
date_default_timezone_set('Asia/Bangkok');

// 3. เริ่มต้น Session
if (session_status() === PHP_SESSION_NONE) {
    // กำหนดอายุของ Session cookie (เช่น 24 ชั่วโมง)
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_start();
}

// 4. เชื่อมต่อฐานข้อมูลด้วย PDO (Charset utf8mb4)
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Auto-migration: ตรวจสอบและอัปเดตโครงสร้างฐานข้อมูลอัตโนมัติ (ช่วยให้ใช้งานได้ทันทีทั้ง Local และ Web Hosting)
    if (file_exists(__DIR__ . '/database/auto_migrate.php')) {
        require_once __DIR__ . '/database/auto_migrate.php';
    }
} catch (\PDOException $e) {
    die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
}

// 5. ระบบตรวจสอบ Cookie (Remember Me) อัตโนมัติ
if (!isset($_SESSION['user_id']) && !empty($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    
    // ค้นหาผู้ใช้จาก remember_token ในฐานข้อมูล
    $stmt = $pdo->prepare("
        SELECT u.*, un.unit_name, un.unit_code 
        FROM users u 
        LEFT JOIN units un ON u.unit_id = un.unit_id 
        WHERE u.remember_token = ? 
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        // ต่ออายุ Session จากข้อมูล Cookie อัตโนมัติ
        $_SESSION['user_id']   = (int)$user['user_id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role']      = $user['role'];
        $_SESSION['unit_id']   = $user['unit_id'] ? (int)$user['unit_id'] : null;
        $_SESSION['unit_name'] = $user['unit_name'] ?? 'ไม่มีสังกัด';
        $_SESSION['unit_code'] = $user['unit_code'] ?? '-';
    } else {
        // หาก Token ไม่ถูกต้องหรือถูกเพิกถอน ให้ลบ Cookie ทิ้ง
        setcookie('remember_token', '', time() - 3600, '/');
    }
}

/**
 * 6. ฟังก์ชันสำหรับตรวจสอบสิทธิ์การเข้าถึงหน้าเว็บแยกตามบทบาท
 * @param string|null $required_role 'super_admin' หรือ 'unit_admin'
 */
function checkAuth($required_role = null) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . BASE_URL . "/login.php?error=unauthorized");
        exit;
    }

    if ($required_role !== null && $_SESSION['role'] !== $required_role) {
        // หากสิทธิ์ไม่ตรง ให้ redirect ไปยัง Dashboard ของบทบาทที่ตนเองมีสิทธิ์
        if ($_SESSION['role'] === 'super_admin') {
            header("Location: " . BASE_URL . "/admin/dashboard.php?error=forbidden");
        } else {
            header("Location: " . BASE_URL . "/unit/dashboard.php?error=forbidden");
        }
        exit;
    }
}

/**
 * ฟังก์ชันช่วยแปลงข้อความป้องกัน XSS
 */
function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * ฟังก์ชันบันทึกข้อความแจ้งเตือน Flash Message
 */
function setFlash($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type, // success, danger, warning, info
        'message' => $message
    ];
}

/**
 * ฟังก์ชันดึงและล้างข้อความแจ้งเตือน Flash Message
 */
function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * แปลงวันที่เป็นรูปแบบภาษาไทย พ.ศ.
 */
function thaiDate($datetime, $showTime = true) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    $timestamp = strtotime($datetime);
    $thai_months = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.',
        7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
    ];
    $year = date('Y', $timestamp) + 543;
    $month = $thai_months[(int)date('n', $timestamp)];
    $day = date('j', $timestamp);
    
    if ($showTime) {
        return "$day $month $year " . date('H:i', $timestamp) . " น.";
    }
    return "$day $month $year";
}

/**
 * บันทึกประวัติการเคลื่อนไหว เข้า-ออก ของพัสดุ (In-Out Stock Movements)
 */
if (!function_exists('logStockMovement')) {
    function logStockMovement($pdo, $material_id, $unit_id, $type, $qty, $ref_type, $ref_id, $desc, $user_id, $unit_price = null, $supplier = null) {
        try {
            $stk = $pdo->prepare("SELECT quantity FROM unit_stocks WHERE unit_id = ? AND material_id = ?");
            $stk->execute([$unit_id, $material_id]);
            $bal = $stk->fetchColumn();
            $balance_after = ($bal !== false) ? (int)$bal : null;

            if ($unit_price === null || $supplier === null) {
                $mat_stmt = $pdo->prepare("SELECT unit_price, supplier FROM materials WHERE material_id = ?");
                $mat_stmt->execute([$material_id]);
                $mat_info = $mat_stmt->fetch();
                if ($mat_info) {
                    if ($unit_price === null) $unit_price = (float)($mat_info['unit_price'] ?? 0);
                    if ($supplier === null) $supplier = $mat_info['supplier'] ?? '';
                } else {
                    if ($unit_price === null) $unit_price = 0.00;
                    if ($supplier === null) $supplier = '';
                }
            }
            $total_price = (float)$unit_price * (int)$qty;

            $stmt = $pdo->prepare("
                INSERT INTO stock_movements (material_id, unit_id, movement_type, quantity, balance_after, reference_type, reference_id, description, performed_by, unit_price, total_price, supplier, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$material_id, $unit_id, $type, $qty, $balance_after, $ref_type, $ref_id, $desc, $user_id, $unit_price, $total_price, $supplier]);
        } catch (\Exception $e) {
            // ไม่ให้กระทบการทำงานหลัก
        }
    }
}

/**
 * -------------------------------------------------------------
 * ระบบการตั้งค่าส่วนกลาง (System Settings Helpers)
 * -------------------------------------------------------------
 */
$GLOBALS['_system_settings_cache'] = null;

function loadAllSettings($pdo) {
    if ($GLOBALS['_system_settings_cache'] === null) {
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
            $GLOBALS['_system_settings_cache'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Exception $e) {
            $GLOBALS['_system_settings_cache'] = [];
        }
    }
    return $GLOBALS['_system_settings_cache'];
}

function getSetting($key, $default = '') {
    global $pdo;
    $settings = loadAllSettings($pdo);
    return isset($settings[$key]) ? $settings[$key] : $default;
}

function updateSetting($pdo, $key, $value) {
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
    if ($GLOBALS['_system_settings_cache'] !== null) {
        $GLOBALS['_system_settings_cache'][$key] = $value;
    }
}

/**
 * ดึง Base URL / Domain สำหรับสร้าง QR Code นำทางจากกล้องมือถือภายนอกแอป
 */
function getQrBaseUrl() {
    $configured = trim(getSetting('site_url', ''));
    if (!empty($configured)) {
        return rtrim($configured, '/');
    }
    // หากไม่ได้ตั้งค่า ให้ Auto-detect จาก Hostname & Base URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return rtrim($protocol . $host . BASE_URL, '/');
}

/**
 * แสดงหน้าจอโหลดพร้อมโลโก้โรงพยาบาล (Splash Loading Screen)
 */
function renderLoadingScreen() {
    if (getSetting('enable_loading_screen', '1') !== '1') {
        return;
    }
    $logo = getSetting('hospital_logo', 'assets/img/logo.svg');
    $name = getSetting('hospital_name', 'โรงพยาบาลมหาสารคาม');
    $logoUrl = (strpos($logo, 'http') === 0) ? $logo : BASE_URL . '/' . ltrim($logo, '/');
    ?>
    <!-- System Loading Screen Overlay -->
    <div id="mhkLoadingScreen" style="
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: #ffffff;
        z-index: 99999;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        transition: opacity 0.35s ease, visibility 0.35s ease;
    ">
        <div style="position: relative; width: 90px; height: 90px; margin-bottom: 18px;">
            <div style="
                position: absolute;
                top: -8px; left: -8px; right: -8px; bottom: -8px;
                border: 3px solid rgba(0, 121, 107, 0.15);
                border-top-color: #00796b;
                border-radius: 50%;
                animation: mhkSpin 0.9s linear infinite;
            "></div>
            <img src="<?= h($logoUrl) ?>" alt="Logo" style="
                width: 100%; height: 100%; object-fit: contain; border-radius: 50%;
                animation: mhkPulse 1.8s ease-in-out infinite;
            " onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><circle cx=\'50\' cy=\'50\' r=\'45\' fill=\'%2300796b\'/><path d=\'M40 25h20v50H40zM25 40h50v20H25z\' fill=\'%23fff\'/></svg>'">
        </div>
        <h5 style="font-family: 'Prompt', sans-serif; font-weight: 600; color: #004d40; margin-bottom: 4px;">
            <?= h($name) ?>
        </h5>
        <div style="font-size: 0.85rem; color: #64748b; font-family: 'Sarabun', sans-serif;">
            <i class="fa-solid fa-boxes-stacked me-1" style="color: #00796b;"></i> ระบบจัดการวัสดุและเวชภัณฑ์
        </div>
        <style>
            @keyframes mhkSpin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            @keyframes mhkPulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.05); }
            }
            .mhk-loaded #mhkLoadingScreen {
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
            }
        </style>
        <script>
            window.addEventListener('load', function() {
                setTimeout(function() {
                    document.body.classList.add('mhk-loaded');
                }, 100);
            });
            setTimeout(function() {
                document.body.classList.add('mhk-loaded');
            }, 800);
        </script>
    </div>
    <?php
}


