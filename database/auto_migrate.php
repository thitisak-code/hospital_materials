<?php
/**
 * Auto-migration script for Hospital Materials System
 * ระบบอัปเดตโครงสร้างฐานข้อมูลอัตโนมัติ (รองรับทั้ง Localhost และ Web Hosting)
 * ช่วยเพิ่มตารางและคอลัมน์ที่จำเป็นอัตโนมัติเมื่อมีการอัปเดตระบบ
 */

if (!isset($pdo)) {
    return;
}

try {
    // 1. ตาราง suppliers (บริษัทผู้จัดจำหน่าย / จัดซื้อ)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `suppliers` (
            `supplier_id` INT AUTO_INCREMENT PRIMARY KEY,
            `supplier_name` VARCHAR(200) NOT NULL UNIQUE,
            `contact_person` VARCHAR(150) NULL,
            `phone` VARCHAR(50) NULL,
            `address` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // ข้อมูลเริ่มต้นสำหรับ suppliers
    $default_suppliers = [
        ['องค์การเภสัชกรรม (GPO)', 'ฝ่ายขายเวชภัณฑ์', '02-203-8000', '75/1 ถนนพระรามที่ 6 แขวงทุ่งพญาไท เขตราชเทวี กรุงเทพฯ 10400'],
        ['บริษัท ซิลลิค ฟาร์มา จำกัด', 'ฝ่ายจัดจำหน่ายโรงพยาบาล', '02-790-9999', 'อาคารเพลินจิตเซ็นเตอร์ ชั้น 8-9 แขวงคลองเตย เขตคลองเตย กรุงเทพฯ'],
        ['บริษัท ดีเคเอสเอช (ประเทศไทย) จำกัด', 'ฝ่ายบริการลูกค้าเวชภัณฑ์', '02-790-4000', '2535 ถนนสุขุมวิท แขวงบางจาก เขตพระโขนง กรุงเทพฯ'],
        ['บริษัท บี. บราวน์ (ประเทศไทย) จำกัด', 'ฝ่ายการตลาดอุปกรณ์การแพทย์', '02-617-5000', 'อาคารศรีจุลทรัพย์ ชั้น 16 ปทุมวัน กรุงเทพฯ'],
        ['บริษัท เมดิคอล ซัพพลาย แอนด์ เทค จำกัด', 'ฝ่ายบริการงานขาย', '043-222-333', '123 ถนนแจ้งสนิท อำเภอเมือง จังหวัดมหาสารคาม'],
    ];

    $sup_check = $pdo->query("SELECT COUNT(*) FROM `suppliers`")->fetchColumn();
    if ($sup_check == 0) {
        $ins_sup = $pdo->prepare("
            INSERT INTO `suppliers` (`supplier_name`, `contact_person`, `phone`, `address`)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($default_suppliers as $s) {
            $ins_sup->execute($s);
        }
    }

    // 2. ตรวจสอบและเพิ่มฟิลด์ในตาราง materials
    $mat_cols = $pdo->query("SHOW COLUMNS FROM `materials`")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('barcode', $mat_cols)) {
        $pdo->exec("ALTER TABLE `materials` ADD COLUMN `barcode` VARCHAR(100) NULL AFTER `material_code`");
        $pdo->exec("UPDATE `materials` SET `barcode` = `material_code` WHERE `barcode` IS NULL OR `barcode` = ''");
    }
    if (!in_array('unit_price', $mat_cols)) {
        $pdo->exec("ALTER TABLE `materials` ADD COLUMN `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `unit_unit`");
        // กำหนดราคาตัวอย่างเริ่มต้น
        $pdo->exec("UPDATE `materials` SET `unit_price` = 180.00 WHERE `material_id` = 1");
        $pdo->exec("UPDATE `materials` SET `unit_price` = 220.00 WHERE `material_id` = 2");
        $pdo->exec("UPDATE `materials` SET `unit_price` = 75.00  WHERE `material_id` = 3");
        $pdo->exec("UPDATE `materials` SET `unit_price` = 45.00  WHERE `material_id` = 4");
        $pdo->exec("UPDATE `materials` SET `unit_price` = 55.00  WHERE `material_id` = 5");
        $pdo->exec("UPDATE `materials` SET `unit_price` = 120.00 WHERE `material_id` = 6");
        $pdo->exec("UPDATE `materials` SET `unit_price` = 35.00  WHERE `material_id` = 7");
        $pdo->exec("UPDATE `materials` SET `unit_price` = 450.00 WHERE `material_id` = 8");
        $pdo->exec("UPDATE `materials` SET `unit_price` = 1850.00 WHERE `material_id` = 9");
        $pdo->exec("UPDATE `materials` SET `unit_price` = 650.00 WHERE `material_id` = 10");
    }
    if (!in_array('supplier', $mat_cols)) {
        $pdo->exec("ALTER TABLE `materials` ADD COLUMN `supplier` VARCHAR(150) NULL DEFAULT 'องค์การเภสัชกรรม (GPO)' AFTER `unit_price`");
        $pdo->exec("UPDATE `materials` SET `supplier` = 'องค์การเภสัชกรรม (GPO)' WHERE `supplier` IS NULL");
        $pdo->exec("UPDATE `materials` SET `supplier` = 'บริษัท ซิลลิค ฟาร์มา จำกัด' WHERE `material_id` IN (2, 8)");
        $pdo->exec("UPDATE `materials` SET `supplier` = 'บริษัท บี. บราวน์ (ประเทศไทย) จำกัด' WHERE `material_id` IN (4, 9, 10)");
    }
    if (!in_array('lot_no', $mat_cols)) {
        $pdo->exec("ALTER TABLE `materials` ADD COLUMN `lot_no` VARCHAR(100) NULL AFTER `supplier`");
        $pdo->exec("UPDATE `materials` SET `lot_no` = CONCAT('LOT-', YEAR(NOW()), '-', LPAD(material_id, 3, '0')) WHERE `lot_no` IS NULL");
    }
    if (!in_array('exp_date', $mat_cols)) {
        $pdo->exec("ALTER TABLE `materials` ADD COLUMN `exp_date` DATE NULL AFTER `lot_no`");
        $pdo->exec("UPDATE `materials` SET `exp_date` = DATE_ADD(CURDATE(), INTERVAL 2 YEAR) WHERE `exp_date` IS NULL");
    }

    // 3. ตรวจสอบและเพิ่มฟิลด์ในตาราง stock_movements
    $mov_cols = $pdo->query("SHOW COLUMNS FROM `stock_movements`")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('unit_price', $mov_cols)) {
        $pdo->exec("ALTER TABLE `stock_movements` ADD COLUMN `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `quantity`");
        $pdo->exec("ALTER TABLE `stock_movements` ADD COLUMN `total_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`");
    }
    if (!in_array('supplier', $mov_cols)) {
        $pdo->exec("ALTER TABLE `stock_movements` ADD COLUMN `supplier` VARCHAR(150) NULL AFTER `total_price`");
    }
    // ปรับ reference_type ให้เป็น VARCHAR(50) เพื่อความยืดหยุ่นรองรับ refill, receive_purchase, patient_dispense
    $pdo->exec("ALTER TABLE `stock_movements` MODIFY COLUMN `reference_type` VARCHAR(50) NOT NULL");

    // อัปเดตราคาใน stock_movements เก่า
    $pdo->exec("
        UPDATE stock_movements sm 
        JOIN materials m ON sm.material_id = m.material_id 
        SET sm.unit_price = m.unit_price, 
            sm.total_price = (sm.quantity * m.unit_price),
            sm.supplier = m.supplier
        WHERE sm.unit_price = 0 AND m.unit_price > 0
    ");

    // 4. ตรวจสอบและเพิ่มฟิลด์ในตาราง unit_usages
    $use_cols = $pdo->query("SHOW COLUMNS FROM `unit_usages`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('patient_hn', $use_cols)) {
        $pdo->exec("ALTER TABLE `unit_usages` ADD COLUMN `patient_hn` VARCHAR(50) NULL AFTER `material_id`");
    }
    if (!in_array('patient_name', $use_cols)) {
        $pdo->exec("ALTER TABLE `unit_usages` ADD COLUMN `patient_name` VARCHAR(150) NULL AFTER `patient_hn`");
    }
    if (!in_array('lot_no', $use_cols)) {
        $pdo->exec("ALTER TABLE `unit_usages` ADD COLUMN `lot_no` VARCHAR(100) NULL AFTER `quantity`");
    }

    // 5. ตาราง refill_orders สำหรับระบบ Refill เวชภัณฑ์จากคลังใหญ่ไปคลังย่อย
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `refill_orders` (
            `refill_id` INT AUTO_INCREMENT PRIMARY KEY,
            `refill_no` VARCHAR(50) NOT NULL UNIQUE,
            `from_unit_id` INT NOT NULL DEFAULT 1,
            `to_unit_id` INT NOT NULL,
            `material_id` INT NOT NULL,
            `quantity` INT NOT NULL,
            `status` ENUM('completed', 'pending', 'cancelled') DEFAULT 'completed',
            `note` TEXT NULL,
            `created_by` INT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`to_unit_id`) REFERENCES `units`(`unit_id`) ON DELETE CASCADE,
            FOREIGN KEY (`material_id`) REFERENCES `materials`(`material_id`) ON DELETE CASCADE,
            FOREIGN KEY (`created_by`) REFERENCES `users`(`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

} catch (\Exception $e) {
    // บันทึก log หรือข้ามหากดำเนินการแล้ว
    error_log("Auto-migration notice: " . $e->getMessage());
}

