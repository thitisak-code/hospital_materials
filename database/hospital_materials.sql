-- ========================================================
-- โครงสร้างฐานข้อมูล: ระบบจัดการวัสดุและเวชภัณฑ์ โรงพยาบาลมหาสารคาม
-- Mahasarakham Hospital Medical Supply & Material Management System
-- รองรับระบบคลังใหญ่, คลังย่อย, การรับเข้า-จ่ายออกด้วยบาร์โค้ด,
-- ระบบ Refill เติมเวชภัณฑ์, สแกนจ่ายให้คนไข้ (ผู้รับบริการ),
-- และระบบประมวลผลรายงานแยกตาม วัน/เดือน/ไตรมาส/ปี, Stock Card, บริษัทคู่ค้า, มูลค่าคงคลัง
-- ========================================================

CREATE DATABASE IF NOT EXISTS `hospital_materials` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `hospital_materials`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `refill_orders`;
DROP TABLE IF EXISTS `stock_movements`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `unit_usages`;
DROP TABLE IF EXISTS `requisition_items`;
DROP TABLE IF EXISTS `requisitions`;
DROP TABLE IF EXISTS `item_borrows`;
DROP TABLE IF EXISTS `unit_stocks`;
DROP TABLE IF EXISTS `materials`;
DROP TABLE IF EXISTS `suppliers`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `units`;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. ตารางหน่วยงานในโรงพยาบาล (Units / Departments)
CREATE TABLE `units` (
    `unit_id` INT AUTO_INCREMENT PRIMARY KEY,
    `unit_code` VARCHAR(50) NOT NULL UNIQUE,
    `unit_name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `location` VARCHAR(150) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ตารางผู้ใช้งานระบบ (Users)
CREATE TABLE `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `role` ENUM('super_admin', 'unit_admin') NOT NULL,
    `unit_id` INT NULL,
    `remember_token` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`unit_id`) REFERENCES `units`(`unit_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. ตารางหมวดหมู่วัสดุ/เวชภัณฑ์ (Categories)
CREATE TABLE `categories` (
    `category_id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_name` VARCHAR(150) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. ตารางบริษัทคู่ค้า/ผู้จัดจำหน่ายที่จัดซื้อ (Suppliers)
CREATE TABLE `suppliers` (
    `supplier_id` INT AUTO_INCREMENT PRIMARY KEY,
    `supplier_name` VARCHAR(200) NOT NULL UNIQUE,
    `contact_person` VARCHAR(150) NULL,
    `phone` VARCHAR(50) NULL,
    `address` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. ตารางรายการวัสดุและเวชภัณฑ์ (Materials & Medical Supplies)
CREATE TABLE `materials` (
    `material_id` INT AUTO_INCREMENT PRIMARY KEY,
    `material_code` VARCHAR(100) NOT NULL UNIQUE,
    `barcode` VARCHAR(100) NULL,
    `material_name` VARCHAR(200) NOT NULL,
    `category` VARCHAR(100) DEFAULT 'เวชภัณฑ์สิ้นเปลือง',
    `unit_unit` VARCHAR(50) NOT NULL DEFAULT 'ชิ้น',
    `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `supplier` VARCHAR(150) NULL DEFAULT 'องค์การเภสัชกรรม (GPO)',
    `lot_no` VARCHAR(100) NULL,
    `exp_date` DATE NULL,
    `min_stock` INT DEFAULT 10,
    `description` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. ตารางคลังวัสดุประจำห้องหน่วยงาน (Unit Stocks)
CREATE TABLE `unit_stocks` (
    `stock_id` INT AUTO_INCREMENT PRIMARY KEY,
    `unit_id` INT NOT NULL,
    `material_id` INT NOT NULL,
    `quantity` INT DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_unit_material` (`unit_id`, `material_id`),
    FOREIGN KEY (`unit_id`) REFERENCES `units`(`unit_id`) ON DELETE CASCADE,
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`material_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. ตารางใบขอเบิกพัสดุจากคลังกลาง (Multi-Item Requisitions)
CREATE TABLE `requisitions` (
    `requisition_id` INT AUTO_INCREMENT PRIMARY KEY,
    `requisition_no` VARCHAR(50) NOT NULL UNIQUE,
    `unit_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending',
    `request_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `approve_date` TIMESTAMP NULL DEFAULT NULL,
    `approved_by` INT NULL,
    `note` TEXT NULL,
    `rejection_reason` TEXT NULL,
    FOREIGN KEY (`unit_id`) REFERENCES `units`(`unit_id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`),
    FOREIGN KEY (`approved_by`) REFERENCES `users`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. ตารางรายการพัสดุในใบเบิก (Requisition Items)
CREATE TABLE `requisition_items` (
    `item_id` INT AUTO_INCREMENT PRIMARY KEY,
    `requisition_id` INT NOT NULL,
    `material_id` INT NOT NULL,
    `quantity_requested` INT NOT NULL,
    `quantity_approved` INT DEFAULT 0,
    FOREIGN KEY (`requisition_id`) REFERENCES `requisitions`(`requisition_id`) ON DELETE CASCADE,
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`material_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. ตารางการยืม-คืนพัสดุระหว่างหน่วยงาน (Item Borrows)
CREATE TABLE `item_borrows` (
    `borrow_id` INT AUTO_INCREMENT PRIMARY KEY,
    `borrower_unit_id` INT NOT NULL,
    `lender_unit_id` INT NOT NULL,
    `material_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected', 'returned') DEFAULT 'pending',
    `borrow_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `due_date` DATE DEFAULT NULL,
    `return_date` TIMESTAMP NULL DEFAULT NULL,
    `note` TEXT NULL,
    `action_by` INT NULL,
    FOREIGN KEY (`borrower_unit_id`) REFERENCES `units`(`unit_id`),
    FOREIGN KEY (`lender_unit_id`) REFERENCES `units`(`unit_id`),
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`material_id`),
    FOREIGN KEY (`action_by`) REFERENCES `users`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. ตารางประวัติการเบิกใช้พัสดุกับผู้รับบริการ/คนไข้ หรือใช้ในห้อง (Unit Usages / Patient Dispensing)
CREATE TABLE `unit_usages` (
    `usage_id` INT AUTO_INCREMENT PRIMARY KEY,
    `unit_id` INT NOT NULL,
    `material_id` INT NOT NULL,
    `patient_hn` VARCHAR(50) NULL,
    `patient_name` VARCHAR(150) NULL,
    `quantity` INT NOT NULL,
    `lot_no` VARCHAR(100) NULL,
    `used_by` VARCHAR(150) NULL,
    `patient_or_purpose` TEXT NULL,
    `used_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `user_id` INT NULL,
    FOREIGN KEY (`unit_id`) REFERENCES `units`(`unit_id`) ON DELETE CASCADE,
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`material_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. ตารางประวัติการนำเข้า-จ่ายออกสำหรับ Stock Card และการประมวลผล (Stock Movements)
CREATE TABLE `stock_movements` (
    `movement_id` INT AUTO_INCREMENT PRIMARY KEY,
    `material_id` INT NOT NULL,
    `unit_id` INT NOT NULL,
    `movement_type` ENUM('in', 'out') NOT NULL,
    `quantity` INT NOT NULL,
    `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `balance_after` INT NULL,
    `supplier` VARCHAR(150) NULL,
    `reference_type` VARCHAR(50) NOT NULL, -- requisition, internal_usage, patient_dispense, refill, receive_purchase, borrow_out, borrow_in, return_in, return_out, adjust, initial
    `reference_id` VARCHAR(50) NULL,
    `description` TEXT NULL,
    `performed_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`material_id`) ON DELETE CASCADE,
    FOREIGN KEY (`unit_id`) REFERENCES `units`(`unit_id`) ON DELETE CASCADE,
    FOREIGN KEY (`performed_by`) REFERENCES `users`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. ตารางการ Refill เวชภัณฑ์จากคลังใหญ่ไปคลังย่อย (Refill Orders)
CREATE TABLE `refill_orders` (
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

-- 13. ตารางการตั้งค่าระบบ (System Settings)
CREATE TABLE `system_settings` (
    `setting_key` VARCHAR(50) NOT NULL PRIMARY KEY,
    `setting_value` TEXT NULL,
    `description` VARCHAR(255) NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================================
-- ข้อมูลเริ่มต้นระบบ (Initial Seed Data)
-- ========================================================

-- หมวดหมู่
INSERT INTO `categories` (`category_id`, `category_name`, `description`) VALUES
(1, 'เวชภัณฑ์สิ้นเปลือง', 'กระบอกฉีดยา เข็ม สายยาง ผ้าก๊อซ และวัสดุใช้ครั้งเดียวทิ้ง'),
(2, 'อุปกรณ์ป้องกัน', 'ถุงมือ หน้ากากอนามัย ชุด PPE แว่นตานิรภัย'),
(3, 'สารน้ำและยา', 'น้ำเกลือ สารละลายทางการแพทย์'),
(4, 'สารฆ่าเชื้อ', 'แอลกอฮอล์ น้ำยาทำความสะอาดเครื่องมือและผิวหนัง'),
(5, 'ครุภัณฑ์/เครื่องมือแพทย์', 'เครื่องวัดความดัน เครื่องวัดออกซิเจน เครื่องตรวจคลื่นหัวใจ');

-- บริษัทผู้จัดซื้อ / คู่ค้า
INSERT INTO `suppliers` (`supplier_id`, `supplier_name`, `contact_person`, `phone`, `address`) VALUES
(1, 'องค์การเภสัชกรรม (GPO)', 'ฝ่ายขายเวชภัณฑ์', '02-203-8000', '75/1 ถนนพระรามที่ 6 แขวงทุ่งพญาไท เขตราชเทวี กรุงเทพฯ'),
(2, 'บริษัท ซิลลิค ฟาร์มา จำกัด', 'ฝ่ายจัดจำหน่ายโรงพยาบาล', '02-790-9999', 'อาคารเพลินจิตเซ็นเตอร์ ชั้น 8-9 แขวงคลองเตย กรุงเทพฯ'),
(3, 'บริษัท ดีเคเอสเอช (ประเทศไทย) จำกัด', 'ฝ่ายบริการลูกค้าเวชภัณฑ์', '02-790-4000', '2535 ถนนสุขุมวิท แขวงบางจาก เขตพระโขนง กรุงเทพฯ'),
(4, 'บริษัท บี. บราวน์ (ประเทศไทย) จำกัด', 'ฝ่ายอุปกรณ์การแพทย์', '02-617-5000', 'อาคารศรีจุลทรัพย์ ชั้น 16 ปทุมวัน กรุงเทพฯ'),
(5, 'บริษัท เมดิคอล ซัพพลาย แอนด์ เทค จำกัด', 'ฝ่ายบริการงานขาย', '043-222-333', '123 ถนนแจ้งสนิท อำเภอเมือง จังหวัดมหาสารคาม');

-- หน่วยงานในโรงพยาบาลมหาสารคาม
INSERT INTO `units` (`unit_id`, `unit_code`, `unit_name`, `phone`, `location`) VALUES 
(1, 'CENTRAL', 'คลังพัสดุและเวชภัณฑ์กลาง', '043-711750 ต่อ 101', 'อาคารอำนวยการ ชั้น 1'),
(2, 'ER01', 'แผนกอุบัติเหตุและฉุกเฉิน (ER)', '043-711750 ต่อ 1669', 'อาคารอุบัติเหตุ ชั้น 1'),
(3, 'OR01', 'กลุ่มงานห้องผ่าตัด (OR)', '043-711750 ต่อ 201', 'อาคารเฉลิมพระเกียรติ ชั้น 3'),
(4, 'WARD4', 'หอผู้ป่วยอายุรกรรม 4', '043-711750 ต่อ 404', 'อาคารผู้ป่วยใน ชั้น 4'),
(5, 'ICU01', 'หอผู้ป่วยวิกฤต (ICU)', '043-711750 ต่อ 301', 'อาคารเฉลิมพระเกียรติ ชั้น 2');

-- ผู้ใช้งานระบบ (Password เริ่มต้นคือ: 123456)
INSERT INTO `users` (`user_id`, `username`, `password`, `full_name`, `role`, `unit_id`) VALUES 
(1, 'admin', '$2y$10$n3yBabG6.o6vJY5rjN/MHuy4IMcRrPxVftIin7yuC.h3s9vWzjO46', 'นายสมชาย พัสดุมั่นคง (หัวหน้าคลังกลาง)', 'super_admin', 1),
(2, 'er_user', '$2y$10$n3yBabG6.o6vJY5rjN/MHuy4IMcRrPxVftIin7yuC.h3s9vWzjO46', 'พว.สุดารัตน์ ใจดี (หัวหน้าเวชภัณฑ์ ER)', 'unit_admin', 2),
(3, 'or_user', '$2y$10$n3yBabG6.o6vJY5rjN/MHuy4IMcRrPxVftIin7yuC.h3s9vWzjO46', 'พว.เกรียงศักดิ์ คมมีด (ผู้รับผิดชอบคลัง OR)', 'unit_admin', 3),
(4, 'ward4_user', '$2y$10$n3yBabG6.o6vJY5rjN/MHuy4IMcRrPxVftIin7yuC.h3s9vWzjO46', 'พว.นงลักษณ์ พิทักษ์ชน (ผู้ดูแลพัสดุ วอร์ด 4)', 'unit_admin', 4);

-- รายการพัสดุและเวชภัณฑ์พร้อมราคาและบริษัทคู่ค้า
INSERT INTO `materials` (`material_id`, `material_code`, `barcode`, `material_name`, `category`, `unit_unit`, `unit_price`, `supplier`, `lot_no`, `exp_date`, `min_stock`, `description`) VALUES 
(1, 'MAT-1001', '8850001001011', 'Syringe 10ml (กระบอกฉีดยา 10 มล.)', 'เวชภัณฑ์สิ้นเปลือง', 'กล่อง (100 ชิ้น)', 180.00, 'องค์การเภสัชกรรม (GPO)', 'LOT-2026-001', '2028-12-31', 15, 'ปราศจากเชื้อ ปลอดสารพิษ ชนิดใช้ครั้งเดียว'),
(2, 'MAT-1002', '8850001002018', 'Nitrile Examination Gloves Size M', 'อุปกรณ์ป้องกัน', 'กล่อง (50 คู่)', 220.00, 'บริษัท ซิลลิค ฟาร์มา จำกัด', 'LOT-2026-002', '2028-10-31', 25, 'ถุงมือยางสังเคราะห์ไนไตร สีฟ้า ไม่ก่ออาการแพ้'),
(3, 'MAT-1003', '8850001003015', 'Surgical Mask 3-Ply ทางการแพทย์', 'อุปกรณ์ป้องกัน', 'กล่อง (50 ชิ้น)', 75.00, 'องค์การเภสัชกรรม (GPO)', 'LOT-2026-003', '2029-01-31', 50, 'หน้ากากอนามัย 3 ชั้น กรองเชื้อแบคทีเรีย BFE 99%'),
(4, 'MAT-1004', '8850001004012', 'Normal Saline 0.9% 1000ml (NSS)', 'สารน้ำและยา', 'ขวด', 45.00, 'บริษัท บี. บราวน์ (ประเทศไทย) จำกัด', 'LOT-2026-004', '2028-06-30', 30, 'น้ำเกลือไอโซโทนิกสำหรับล้างแผลและให้ทางหลอดเลือด'),
(5, 'MAT-1005', '8850001005019', 'Ethyl Alcohol 70% 450ml', 'สารฆ่าเชื้อ', 'ขวด', 55.00, 'องค์การเภสัชกรรม (GPO)', 'LOT-2026-005', '2028-11-30', 20, 'แอลกอฮอล์สำหรับฆ่าเชื้อบนผิวหนังและอุปกรณ์'),
(6, 'MAT-1006', '8850001006016', 'Sterile Gauze 3x3 นิ้ว', 'เวชภัณฑ์สิ้นเปลือง', 'ซอง (5 แผ่น)', 120.00, 'บริษัท เมดิคอล ซัพพลาย แอนด์ เทค จำกัด', 'LOT-2026-006', '2029-03-31', 40, 'ผ้าก๊อซพับปลอดเชื้อสำหรับทำแผล'),
(7, 'MAT-1007', '8850001007013', 'Micropore Surgical Tape 1 นิ้ว', 'เวชภัณฑ์สิ้นเปลือง', 'ม้วน', 35.00, 'บริษัท ดีเคเอสเอช (ประเทศไทย) จำกัด', 'LOT-2026-007', '2028-08-31', 20, 'เทปแต่งแผลเยื่อกระดาษระบายอากาศได้ดี'),
(8, 'MAT-1008', '8850001008010', 'N95 Respirator Particulate Mask', 'อุปกรณ์ป้องกัน', 'กล่อง (20 ชิ้น)', 450.00, 'บริษัท ซิลลิค ฟาร์มา จำกัด', 'LOT-2026-008', '2028-12-31', 15, 'หน้ากากป้องกันอนุภาคและละอองสารคัดหลั่ง N95'),
(9, 'MAT-1009', '8850001009017', 'Digital Blood Pressure Monitor', 'ครุภัณฑ์/เครื่องมือแพทย์', 'เครื่อง', 1850.00, 'บริษัท บี. บราวน์ (ประเทศไทย) จำกัด', 'LOT-2026-009', '2031-12-31', 2, 'เครื่องวัดความดันโลหิตแบบดิจิทัลพร้อมปลอกแขน'),
(10, 'MAT-1010', '8850001010013', 'Pulse Oximeter Fingertip', 'ครุภัณฑ์/เครื่องมือแพทย์', 'เครื่อง', 650.00, 'บริษัท บี. บราวน์ (ประเทศไทย) จำกัด', 'LOT-2026-010', '2031-12-31', 3, 'เครื่องวัดปริมาณออกซิเจนในเลือดและชีพจรปลายนิ้ว');

-- สต็อกคลังใหญ่
INSERT INTO `unit_stocks` (`unit_id`, `material_id`, `quantity`) VALUES 
(1, 1, 450), (1, 2, 380), (1, 3, 1200), (1, 4, 600), (1, 5, 250), (1, 6, 800), (1, 7, 300), (1, 8, 200), (1, 9, 15), (1, 10, 25);

-- สต็อกห้องพัสดุ ER
INSERT INTO `unit_stocks` (`unit_id`, `material_id`, `quantity`) VALUES 
(2, 1, 35), (2, 2, 40), (2, 3, 80), (2, 4, 65), (2, 5, 12), (2, 6, 90), (2, 7, 28), (2, 8, 8), (2, 9, 3), (2, 10, 4);

-- สต็อกห้องพัสดุ OR
INSERT INTO `unit_stocks` (`unit_id`, `material_id`, `quantity`) VALUES 
(3, 1, 50), (3, 2, 60), (3, 3, 150), (3, 4, 80), (3, 5, 30), (3, 6, 120), (3, 7, 45), (3, 8, 30), (3, 9, 2), (3, 10, 5);

-- สต็อกห้องพัสดุ วอร์ด 4
INSERT INTO `unit_stocks` (`unit_id`, `material_id`, `quantity`) VALUES 
(4, 1, 18), (4, 2, 15), (4, 3, 45), (4, 4, 25), (4, 5, 8), (4, 6, 35), (4, 7, 10), (4, 9, 1);

-- ข้อมูลตัวอย่างใบขอเบิกพัสดุ
INSERT INTO `requisitions` (`requisition_id`, `requisition_no`, `unit_id`, `user_id`, `status`, `request_date`, `note`) VALUES
(1, 'REQ-202610-0001', 2, 2, 'pending', NOW(), 'ขอเบิกเวชภัณฑ์และถุงมือสำรองสำหรับเคสอุบัติเหตุฉุกเฉินประจำสัปดาห์');

INSERT INTO `requisition_items` (`requisition_id`, `material_id`, `quantity_requested`, `quantity_approved`) VALUES
(1, 1, 20, 20),
(1, 2, 15, 15),
(1, 4, 10, 10);

-- ข้อมูลตัวอย่างการยืม-คืน
INSERT INTO `item_borrows` (`borrow_id`, `borrower_unit_id`, `lender_unit_id`, `material_id`, `quantity`, `status`, `borrow_date`, `due_date`, `note`, `action_by`) VALUES 
(1, 2, 3, 2, 5, 'approved', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'ขอสำรองถุงมือไนไตรเคสอุบัติเหตุหมู่ฉุกเฉิน', 3),
(2, 4, 2, 9, 1, 'pending', NOW(), DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'เครื่องวัดความดันที่วอร์ดส่งซ่อมบำรุง ขอยืมสำรองใช้ 3 วัน', NULL);

-- ข้อมูลตัวอย่างการเบิกใช้กับผู้รับบริการ/คนไข้
INSERT INTO `unit_usages` (`unit_id`, `material_id`, `patient_hn`, `patient_name`, `quantity`, `lot_no`, `used_by`, `patient_or_purpose`, `used_at`, `user_id`) VALUES
(2, 1, 'HN-690124', 'นายสมคิด ยั่งยืน', 2, 'LOT-2026-001', 'พว.สุดารัตน์ ใจดี', 'ฉีดยาผู้ป่วยห้องฉุกเฉิน เตียง 1', DATE_SUB(NOW(), INTERVAL 3 HOUR), 2),
(2, 5, NULL, NULL, 1, 'LOT-2026-005', 'พว.สุดารัตน์ ใจดี', 'เช็ดทำความสะอาดเครื่องมือหัตถการ', DATE_SUB(NOW(), INTERVAL 1 HOUR), 2);

-- ข้อมูลตัวอย่างประวัติการเคลื่อนไหว เข้า-ออก (Stock Movements พร้อมราคาและบริษัทจัดซื้อ)
INSERT INTO `stock_movements` (`material_id`, `unit_id`, `movement_type`, `quantity`, `unit_price`, `total_price`, `balance_after`, `supplier`, `reference_type`, `reference_id`, `description`, `performed_by`, `created_at`) VALUES
-- คลังกลาง รับเข้าเวชภัณฑ์จากบริษัทคู่ค้า
(1, 1, 'in', 500, 180.00, 90000.00, 500, 'องค์การเภสัชกรรม (GPO)', 'receive_purchase', 'PO-6910-001', 'รับเวชภัณฑ์เข้าคลังกลางจากผู้จัดจำหน่าย', 1, DATE_SUB(NOW(), INTERVAL 14 DAY)),
(2, 1, 'in', 400, 220.00, 88000.00, 400, 'บริษัท ซิลลิค ฟาร์มา จำกัด', 'receive_purchase', 'PO-6910-002', 'รับถุงมือตรวจโรคเข้าคลังกลาง', 1, DATE_SUB(NOW(), INTERVAL 14 DAY)),
(3, 1, 'in', 1500, 75.00, 112500.00, 1500, 'องค์การเภสัชกรรม (GPO)', 'receive_purchase', 'PO-6910-003', 'รับหน้ากากอนามัยเข้าคลังกลาง', 1, DATE_SUB(NOW(), INTERVAL 14 DAY)),
(4, 1, 'in', 700, 45.00, 31500.00, 700, 'บริษัท บี. บราวน์ (ประเทศไทย) จำกัด', 'receive_purchase', 'PO-6910-004', 'รับน้ำเกลือ 1000ml เข้าคลังกลาง', 1, DATE_SUB(NOW(), INTERVAL 14 DAY)),

-- คลังกลาง จ่ายออกให้ ER และ ER รับเข้า
(1, 1, 'out', 50, 180.00, 9000.00, 450, 'องค์การเภสัชกรรม (GPO)', 'requisition', 'REQ-202610-0001', 'จ่ายให้ แผนกอุบัติเหตุและฉุกเฉิน (ER)', 1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 2, 'in', 50, 180.00, 9000.00, 50, 'องค์การเภสัชกรรม (GPO)', 'requisition', 'REQ-202610-0001', 'รับเข้าห้องพัสดุ ER จากคลังกลาง', 2, DATE_SUB(NOW(), INTERVAL 5 DAY)),

-- ER เบิกใช้กับผู้รับบริการ/คนไข้
(1, 2, 'out', 10, 180.00, 1800.00, 40, 'องค์การเภสัชกรรม (GPO)', 'patient_dispense', 'HN-690101', 'ฉีดยาผู้ป่วยอุบัติเหตุฉุกเฉิน นายประสิทธิ์', 2, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1, 2, 'out', 5, 180.00, 900.00, 35, 'องค์การเภสัชกรรม (GPO)', 'patient_dispense', 'HN-690115', 'หัตถการฉีดวัคซีนบาดทะยัก เตียง 2', 2, DATE_SUB(NOW(), INTERVAL 1 DAY)),

-- คลังกลาง Refill เวชภัณฑ์ให้ ER
(5, 1, 'out', 20, 55.00, 1100.00, 230, 'องค์การเภสัชกรรม (GPO)', 'refill', 'REF-202610-0001', 'Refill เติมสต็อกแอลกอฮอล์ให้แผนก ER', 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(5, 2, 'in', 20, 55.00, 1100.00, 32, 'องค์การเภสัชกรรม (GPO)', 'refill', 'REF-202610-0001', 'รับ Refill จากคลังกลาง', 2, DATE_SUB(NOW(), INTERVAL 2 DAY));

-- การตั้งค่าระบบ
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('requisition_enabled', '1', 'สถานะเปิด/ปิดรับใบขอเบิกพัสดุ (1=เปิด, 0=ปิด)'),
('requisition_closed_reason', 'ระบบคลังกลางปิดรับการขอเบิกชั่วคราวเนื่องจากอยู่ระหว่างการตรวจนับสต็อกประจำงวด ขออภัยในความไม่สะดวก', 'ข้อความแจ้งเตือนเมื่อปิดรับเบิกพัสดุ'),
('site_url', '', 'URL เว็บไซต์สำหรับสร้าง QR Code (เช่น http://192.168.1.100/hospital_materials)'),
('hospital_name', 'โรงพยาบาลมหาสารคาม', 'ชื่อโรงพยาบาล/องค์กร'),
('hospital_logo', 'assets/img/logo.svg', 'ที่อยู่ไฟล์โลโก้โรงพยาบาล'),
('enable_loading_screen', '1', 'เปิดใช้งานหน้า Loading Screen พร้อมโลโก้ (1=เปิด, 0=ปิด)');
