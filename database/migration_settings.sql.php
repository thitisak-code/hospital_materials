<?php
require_once __DIR__ . '/../config.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `system_settings` (
            `setting_key` VARCHAR(50) NOT NULL PRIMARY KEY,
            `setting_value` TEXT NULL,
            `description` VARCHAR(255) NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $default_settings = [
        ['requisition_enabled', '1', 'สถานะเปิด/ปิดรับใบขอเบิกพัสดุ (1=เปิด, 0=ปิด)'],
        ['requisition_closed_reason', 'ระบบคลังกลางปิดรับการขอเบิกชั่วคราวเนื่องจากอยู่ระหว่างการตรวจนับสต็อกประจำงวด ขออภัยในความไม่สะดวก', 'ข้อความแจ้งเตือนเมื่อปิดรับเบิกพัสดุ'],
        ['site_url', '', 'URL เว็บไซต์สำหรับสร้าง QR Code (เช่น http://192.168.1.100/hospital_materials)'],
        ['hospital_name', 'โรงพยาบาลมหาสารคาม', 'ชื่อโรงพยาบาล/องค์กร'],
        ['hospital_logo', 'assets/img/logo.svg', 'ที่อยู่ไฟล์โลโก้โรงพยาบาล'],
        ['enable_loading_screen', '1', 'เปิดใช้งานหน้า Loading Screen พร้อมโลโก้ (1=เปิด, 0=ปิด)']
    ];

    $stmt = $pdo->prepare("
        INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE `description` = VALUES(`description`)
    ");

    foreach ($default_settings as $s) {
        $stmt->execute($s);
    }

    echo "system_settings table created and seeded successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
