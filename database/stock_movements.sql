CREATE TABLE IF NOT EXISTS `stock_movements` (
    `movement_id` INT AUTO_INCREMENT PRIMARY KEY,
    `material_id` INT NOT NULL,
    `unit_id` INT NOT NULL,
    `movement_type` ENUM('in', 'out') NOT NULL,
    `quantity` INT NOT NULL,
    `balance_after` INT NULL,
    `reference_type` ENUM('requisition', 'internal_usage', 'borrow_out', 'borrow_in', 'return_in', 'return_out', 'adjust', 'initial') NOT NULL,
    `reference_id` VARCHAR(50) NULL,
    `description` TEXT NULL,
    `performed_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`material_id`) ON DELETE CASCADE,
    FOREIGN KEY (`unit_id`) REFERENCES `units`(`unit_id`) ON DELETE CASCADE,
    FOREIGN KEY (`performed_by`) REFERENCES `users`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ข้อมูลจำลองประวัติเข้า-ออกสำหรับพัสดุ
INSERT INTO `stock_movements` (`material_id`, `unit_id`, `movement_type`, `quantity`, `balance_after`, `reference_type`, `reference_id`, `description`, `performed_by`, `created_at`) VALUES
-- คลังกลาง นำเข้าพัสดุตั้งต้น
(1, 1, 'in', 500, 500, 'initial', 'INIT', 'รับเวชภัณฑ์เข้าคลังกลางจากผู้จัดจำหน่าย', 1, DATE_SUB(NOW(), INTERVAL 14 DAY)),
(2, 1, 'in', 400, 400, 'initial', 'INIT', 'รับถุงมือตรวจโรคเข้าคลังกลาง', 1, DATE_SUB(NOW(), INTERVAL 14 DAY)),
(3, 1, 'in', 1500, 1500, 'initial', 'INIT', 'รับหน้ากากอนามัยเข้าคลังกลาง', 1, DATE_SUB(NOW(), INTERVAL 14 DAY)),
(4, 1, 'in', 700, 700, 'initial', 'INIT', 'รับน้ำเกลือ 1000ml เข้าคลังกลาง', 1, DATE_SUB(NOW(), INTERVAL 14 DAY)),

-- คลังกลาง จ่ายออกให้ ER และ ER รับเข้า
(1, 1, 'out', 50, 450, 'requisition', 'REQ-202610-0001', 'จ่ายให้ แผนกอุบัติเหตุและฉุกเฉิน (ER)', 1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 2, 'in', 50, 50, 'requisition', 'REQ-202610-0001', 'รับเข้าห้องพัสดุ ER จากคลังกลาง', 2, DATE_SUB(NOW(), INTERVAL 5 DAY)),

-- ER เบิกใช้ในห้องกับผู้ป่วย
(1, 2, 'out', 10, 40, 'internal_usage', 'USE', 'ฉีดยาผู้ป่วยอุบัติเหตุฉุกเฉิน', 2, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1, 2, 'out', 5, 35, 'internal_usage', 'USE', 'หัตถการฉีดวัคซีนบาดทะยัก เตียง 2', 2, DATE_SUB(NOW(), INTERVAL 1 DAY)),

-- ER และ OR ยืม-คืน
(2, 3, 'out', 5, 55, 'borrow_out', 'BR-0001', 'ให้แผนก ER ยืมถุงมือไซส์ M', 3, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2, 2, 'in', 5, 40, 'borrow_in', 'BR-0001', 'ยืมถุงมือไซส์ M มาจากห้องผ่าตัด OR', 2, DATE_SUB(NOW(), INTERVAL 2 DAY)),

-- คลังกลาง เติมสต็อกเพิ่ม
(5, 1, 'in', 100, 250, 'adjust', 'ADJ', 'ตรวจนับและเติมแอลกอฮอล์ 70% เข้าคลังกลาง', 1, DATE_SUB(NOW(), INTERVAL 2 DAY));
