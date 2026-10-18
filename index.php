<?php
/**
 * Entry Point (index.php)
 * ตรวจสอบสถานะการเข้าสู่ระบบและ Redirect ไปยังหน้าแดชบอร์ดตามสิทธิ์ หรือหน้า Login
 */
require_once __DIR__ . '/config.php';

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'super_admin') {
        header("Location: " . BASE_URL . "/admin/dashboard.php");
    } else {
        header("Location: " . BASE_URL . "/unit/dashboard.php");
    }
    exit;
}

header("Location: " . BASE_URL . "/login.php");
exit;
