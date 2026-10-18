-- Migration / Addition for Categories, Multi-Item Requisitions, and Unit Usages

CREATE TABLE IF NOT EXISTS `categories` (
    `category_id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_name` VARCHAR(150) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categories` (`category_name`, `description`) VALUES
('เวชภัณฑ์สิ้นเปลือง', 'กระบอกฉีดยา เข็ม สายยาง ผ้าก๊อซ และวัสดุใช้ครั้งเดียวทิ้ง'),
('อุปกรณ์ป้องกัน', 'ถุงมือ หน้ากากอนามัย ชุด PPE แว่นตานิรภัย'),
('สารน้ำและยา', 'น้ำเกลือ สารละลายทางการแพทย์'),
('สารฆ่าเชื้อ', 'แอลกอฮอล์ น้ำยาทำความสะอาดเครื่องมือและผิวหนัง'),
('ครุภัณฑ์/เครื่องมือแพทย์', 'เครื่องวัดความดัน เครื่องวัดออกซิเจน เครื่องตรวจคลื่นหัวใจ')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- ใบขอเบิกพัสดุจากคลังกลาง (Multi-Item Requisition Order)
CREATE TABLE IF NOT EXISTS `requisitions` (
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

-- รายการพัสดุในใบเบิก
CREATE TABLE IF NOT EXISTS `requisition_items` (
    `item_id` INT AUTO_INCREMENT PRIMARY KEY,
    `requisition_id` INT NOT NULL,
    `material_id` INT NOT NULL,
    `quantity_requested` INT NOT NULL,
    `quantity_approved` INT DEFAULT 0,
    FOREIGN KEY (`requisition_id`) REFERENCES `requisitions`(`requisition_id`) ON DELETE CASCADE,
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`material_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- บันทึกประวัติการเบิกใช้พัสดุภายในห้องตนเอง (Unit Internal Usage/Dispensing)
CREATE TABLE IF NOT EXISTS `unit_usages` (
    `usage_id` INT AUTO_INCREMENT PRIMARY KEY,
    `unit_id` INT NOT NULL,
    `material_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `used_by` VARCHAR(150) NULL,
    `patient_or_purpose` TEXT NULL,
    `used_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `user_id` INT NULL,
    FOREIGN KEY (`unit_id`) REFERENCES `units`(`unit_id`) ON DELETE CASCADE,
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`material_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ข้อมูลตัวอย่างใบขอเบิกพัสดุ
INSERT INTO `requisitions` (`requisition_id`, `requisition_no`, `unit_id`, `user_id`, `status`, `request_date`, `note`) VALUES
(1, 'REQ-202610-0001', 2, 2, 'pending', NOW(), 'ขอเบิกเวชภัณฑ์และถุงมือสำรองสำหรับเคสอุบัติเหตุฉุกเฉินประจำสัปดาห์')
ON DUPLICATE KEY UPDATE `requisition_no` = VALUES(`requisition_no`);

INSERT INTO `requisition_items` (`requisition_id`, `material_id`, `quantity_requested`, `quantity_approved`) VALUES
(1, 1, 20, 20),
(1, 2, 15, 15),
(1, 4, 10, 10)
ON DUPLICATE KEY UPDATE `quantity_requested` = VALUES(`quantity_requested`);
