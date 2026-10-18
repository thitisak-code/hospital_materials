<?php
/**
 * หน้าเข้าสู่ระบบ (Login)
 * ระบบจัดการวัสดุ โรงพยาบาลมหาสารคาม
 */
require_once __DIR__ . '/config.php';

// หากเข้าสู่ระบบอยู่แล้ว ให้ Redirect ไปยังหน้า Dashboard ตามสิทธิ์
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'super_admin') {
        header("Location: " . BASE_URL . "/admin/dashboard.php");
    } else {
        header("Location: " . BASE_URL . "/unit/dashboard.php");
    }
    exit;
}

$error_message = '';
$success_message = '';

if (isset($_GET['logged_out'])) {
    $success_message = 'ออกจากระบบเรียบร้อยแล้ว';
} elseif (isset($_GET['error']) && $_GET['error'] === 'unauthorized') {
    $error_message = 'กรุณาเข้าสู่ระบบก่อนเข้าใช้งาน';
}

// ตรวจสอบการส่งแบบฟอร์ม POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username    = trim($_POST['username'] ?? '');
    $password    = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);

    if (empty($username) || empty($password)) {
        $error_message = 'กรุณากรอกชื่อผู้ใช้งานและรหัสผ่านให้ครบถ้วน';
    } else {
        // ค้นหาผู้ใช้งานในฐานข้อมูล
        $stmt = $pdo->prepare("
            SELECT u.*, un.unit_name, un.unit_code 
            FROM users u 
            LEFT JOIN units un ON u.unit_id = un.unit_id 
            WHERE u.username = ? 
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // รหัสผ่านถูกต้อง บันทึกข้อมูลลงใน Session
            $_SESSION['user_id']   = (int)$user['user_id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['unit_id']   = $user['unit_id'] ? (int)$user['unit_id'] : null;
            $_SESSION['unit_name'] = $user['unit_name'] ?? 'ไม่มีสังกัด';
            $_SESSION['unit_code'] = $user['unit_code'] ?? '-';

            // ระบบจำการเข้าสู่ระบบ (Remember Me) ผ่าน Cookie 30 วัน
            if ($remember_me) {
                $token = bin2hex(random_bytes(32));
                // บันทึก Token ลงฐานข้อมูล
                $update_stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE user_id = ?");
                $update_stmt->execute([$token, $user['user_id']]);

                // ส่ง Cookie กลับไปยังเบราว์เซอร์
                setcookie(
                    'remember_token',
                    $token,
                    [
                        'expires'  => time() + (86400 * 30), // 30 วัน
                        'path'     => '/',
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]
                );
            } else {
                // หากไม่ได้เลือก Remember Me ให้ล้าง token เดิม (ถ้ามี)
                $update_stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE user_id = ?");
                $update_stmt->execute([$user['user_id']]);
                if (isset($_COOKIE['remember_token'])) {
                    setcookie('remember_token', '', time() - 3600, '/');
                }
            }

            // Redirect แยกสิทธิ์ทันที
            if ($user['role'] === 'super_admin') {
                header("Location: " . BASE_URL . "/admin/dashboard.php");
            } else {
                header("Location: " . BASE_URL . "/unit/dashboard.php");
            }
            exit;
        } else {
            $error_message = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ | ระบบจัดการวัสดุ โรงพยาบาลมหาสารคาม</title>
    <!-- Google Fonts: Sarabun & Prompt -->
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
            --mhk-accent: #0288d1;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background: linear-gradient(135deg, #e0f2f1 0%, #bbdefb 50%, #f5f5f5 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px 10px;
        }
        .login-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 77, 64, 0.12);
            overflow: hidden;
            width: 100%;
            max-width: 480px;
            border: 1px solid rgba(0, 121, 107, 0.1);
            transition: all 0.3s ease;
        }
        .login-header {
            background: linear-gradient(135deg, var(--mhk-primary) 0%, var(--mhk-primary-dark) 100%);
            color: white;
            padding: 35px 25px 25px;
            text-align: center;
            position: relative;
        }
        .hospital-icon-wrapper {
            width: 75px;
            height: 75px;
            background: rgba(255, 255, 255, 0.18);
            backdrop-filter: blur(5px);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        .login-title {
            font-family: 'Prompt', sans-serif;
            font-weight: 600;
            font-size: 1.35rem;
            margin-bottom: 4px;
            letter-spacing: -0.3px;
        }
        .login-subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 0;
        }
        .login-body {
            padding: 30px 30px 25px;
        }
        .form-floating > .form-control:focus ~ label,
        .form-floating > .form-control:not(:placeholder-shown) ~ label {
            color: var(--mhk-primary);
        }
        .form-control:focus {
            border-color: var(--mhk-primary);
            box-shadow: 0 0 0 0.25rem rgba(0, 121, 107, 0.2);
        }
        .btn-mhk {
            background: linear-gradient(135deg, var(--mhk-primary) 0%, var(--mhk-primary-dark) 100%);
            color: #ffffff;
            font-family: 'Prompt', sans-serif;
            font-weight: 500;
            padding: 12px;
            border-radius: 12px;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-mhk:hover {
            background: linear-gradient(135deg, var(--mhk-primary-dark) 0%, #00251a 100%);
            color: #ffffff;
            box-shadow: 0 6px 15px rgba(0, 77, 64, 0.25);
            transform: translateY(-1px);
        }
        .test-accounts-box {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            padding: 12px 15px;
            font-size: 0.85rem;
        }
        .quick-fill-btn {
            font-size: 0.78rem;
            padding: 3px 8px;
            border-radius: 6px;
            cursor: pointer;
            transition: 0.2s;
        }
    </style>
</head>
<body>

<?php renderLoadingScreen(); ?>

<div class="login-card">
    <!-- Header -->
    <div class="login-header">
        <div class="hospital-icon-wrapper">
            <img src="<?= BASE_URL . '/' . ltrim(h(getSetting('hospital_logo', 'assets/img/logo.svg')), '/') ?>" alt="Hospital Logo" style="width: 44px; height: 44px; object-fit: contain;">
        </div>
        <h1 class="login-title"><?= h(getSetting('hospital_name', 'โรงพยาบาลมหาสารคาม')) ?></h1>
        <p class="login-subtitle">
            <i class="fa-solid fa-boxes-stacked me-1"></i> ระบบจัดการวัสดุและคลังเวชภัณฑ์
        </p>
    </div>

    <!-- Body Form -->
    <div class="login-body">
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger d-flex align-items-center py-2 px-3 mb-3 border-0 rounded-3 shadow-sm" role="alert">
                <i class="fa-solid fa-circle-exclamation me-2 fa-lg text-danger"></i>
                <div class="small fw-semibold"><?= h($error_message) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success d-flex align-items-center py-2 px-3 mb-3 border-0 rounded-3 shadow-sm" role="alert">
                <i class="fa-solid fa-circle-check me-2 fa-lg text-success"></i>
                <div class="small fw-semibold"><?= h($success_message) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off">
            <!-- Username Input -->
            <div class="form-floating mb-3">
                <input type="text" name="username" id="username" class="form-control rounded-3" 
                       placeholder="ชื่อผู้ใช้งาน" required autofocus 
                       value="<?= isset($_POST['username']) ? h($_POST['username']) : '' ?>">
                <label for="username">
                    <i class="fa-solid fa-user me-1 text-secondary"></i> ชื่อผู้ใช้งาน (Username)
                </label>
            </div>

            <!-- Password Input -->
            <div class="form-floating mb-3 position-relative">
                <input type="password" name="password" id="password" class="form-control rounded-3" 
                       placeholder="รหัสผ่าน" required>
                <label for="password">
                    <i class="fa-solid fa-lock me-1 text-secondary"></i> รหัสผ่าน (Password)
                </label>
                <button type="button" class="btn btn-link position-absolute end-0 top-50 translate-middle-y text-secondary me-2 text-decoration-none" 
                        onclick="togglePassword()" title="แสดง/ซ่อนรหัสผ่าน" style="z-index: 5;">
                    <i class="fa-solid fa-eye" id="toggleIcon"></i>
                </button>
            </div>

            <!-- Remember Me Checkbox -->
            <div class="form-check mb-4 d-flex align-items-center justify-content-between">
                <div>
                    <input class="form-check-input" type="checkbox" name="remember_me" id="remember_me" checked>
                    <label class="form-check-label text-secondary small select-none" for="remember_me">
                        จดจำการเข้าสู่ระบบไว้ (Cookie 30 วัน)
                    </label>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn btn-mhk w-100 mb-3 shadow-sm">
                <i class="fa-solid fa-right-to-bracket me-2"></i> เข้าสู่ระบบ
            </button>
        </form>

        <!-- Quick Fill Helper for Testing -->
        <div class="test-accounts-box mt-3 text-secondary">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <strong class="text-dark small"><i class="fa-solid fa-key text-warning me-1"></i> บัญชีทดสอบระบบ:</strong>
                <span class="badge bg-light text-muted border">รหัสผ่าน: 123456</span>
            </div>
            <div class="d-flex flex-wrap gap-1">
                <button type="button" class="btn btn-sm btn-outline-primary quick-fill-btn" onclick="fillLogin('admin', '123456')">
                    <i class="fa-solid fa-crown me-1 text-warning"></i> Super Admin (คลังกลาง)
                </button>
                <button type="button" class="btn btn-sm btn-outline-success quick-fill-btn" onclick="fillLogin('er_user', '123456')">
                    <i class="fa-solid fa-kit-medical me-1 text-danger"></i> ER (ฉุกเฉิน)
                </button>
                <button type="button" class="btn btn-sm btn-outline-info quick-fill-btn" onclick="fillLogin('or_user', '123456')">
                    <i class="fa-solid fa-scissors me-1 text-info"></i> OR (ห้องผ่าตัด)
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary quick-fill-btn" onclick="fillLogin('ward4_user', '123456')">
                    <i class="fa-solid fa-bed me-1 text-secondary"></i> Ward 4 (ผู้ป่วยใน)
                </button>
            </div>
        </div>

        <div class="text-center text-muted small mt-3">
            <small>© โรงพยาบาลมหาสารคาม • Mahasarakham Hospital</small>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // สลับการแสดง/ซ่อนรหัสผ่าน
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.getElementById('toggleIcon');
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.remove('fa-eye');
            toggleIcon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        }
    }

    // ฟังก์ชันช่วยคลิกกรอกบัญชีทดสอบอัตโนมัติ
    function fillLogin(user, pass) {
        document.getElementById('username').value = user;
        document.getElementById('password').value = pass;
    }
</script>

</body>
</html>
