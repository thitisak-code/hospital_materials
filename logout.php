<?php
/**
 * ออกจากระบบ (Logout)
 */
require_once __DIR__ . '/config.php';

// ล้าง Token ในฐานข้อมูลหากมีผู้ใช้ล็อกอินอยู่
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
}

// ล้าง Cookie Remember Me
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

// ล้าง Session ทั้งหมด
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header("Location: " . BASE_URL . "/login.php?logged_out=1");
exit;
