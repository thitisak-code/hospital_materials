<?php
/**
 * ระบบประมวลผลการยืม-คืน เบิกจ่ายพัสดุหลายรายการ และการเบิกใช้ภายในห้อง
 * พร้อมบันทึกประวัติวันเวลา เข้า-ออก ของพัสดุ (Stock Movements Logging)
 * ระบบจัดการวัสดุ โรงพยาบาลมหาสารคาม
 */
require_once __DIR__ . '/../config.php';
checkAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

$action = $_POST['action'] ?? '';
$user_id = $_SESSION['user_id'];
$current_unit_id = (int)($_POST['current_unit_id'] ?? $_SESSION['unit_id'] ?? 2);
$redirect_to = $_POST['redirect_to'] ?? '';

// ฟังก์ชัน redirect ปลายทาง
function handleRedirect($defaultUrl, $redirect_to = '') {
    if (!empty($redirect_to)) {
        header("Location: " . BASE_URL . "/" . ltrim($redirect_to, '/'));
        exit;
    }
    header("Location: " . $defaultUrl);
    exit;
}

// ฟังก์ชันบันทึกประวัติการเคลื่อนไหว เข้า-ออก ของวัสดุ
if (!function_exists('logStockMovement')) {
    function logStockMovement($pdo, $material_id, $unit_id, $type, $qty, $ref_type, $ref_id, $desc, $user_id) {
        try {
            $stk = $pdo->prepare("SELECT quantity FROM unit_stocks WHERE unit_id = ? AND material_id = ?");
            $stk->execute([$unit_id, $material_id]);
            $bal = $stk->fetchColumn();
            $balance_after = ($bal !== false) ? (int)$bal : null;

            $stmt = $pdo->prepare("
                INSERT INTO stock_movements (material_id, unit_id, movement_type, quantity, balance_after, reference_type, reference_id, description, performed_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$material_id, $unit_id, $type, $qty, $balance_after, $ref_type, $ref_id, $desc, $user_id]);
        } catch (\Exception $e) {
            // เงียบไว้เพื่อไม่ให้กระทบกระบวนการหลัก
        }
    }
}

// -------------------------------------------------------------
// 1. สร้างใบขอเบิกพัสดุแบบตะกร้าหลายรายการ (Create Multi-Item Requisition)
// -------------------------------------------------------------
if ($action === 'create_requisition') {
    $unit_id = (int)($_POST['unit_id'] ?? $current_unit_id);
    $note    = trim($_POST['note'] ?? '');
    $items   = $_POST['items'] ?? []; // Array [material_id => quantity]

    // ตรวจสอบว่าคลังกลางเปิดรับเบิกหรือไม่
    if (getSetting('requisition_enabled', '1') !== '1') {
        $closed_msg = getSetting('requisition_closed_reason', 'ขณะนี้คลังกลางปิดรับการขอเบิกชั่วคราว');
        setFlash('warning', 'ไม่สามารถส่งใบขอเบิกได้: ' . $closed_msg);
        handleRedirect("dashboard.php?unit_id=" . $unit_id, $redirect_to);
    }

    if (empty($items) || !is_array($items)) {
        setFlash('danger', 'กรุณาเลือกรายการพัสดุที่ต้องการขอเบิกอย่างน้อย 1 รายการ');
        handleRedirect("dashboard.php?unit_id=" . $unit_id, $redirect_to);
    }

    try {
        $pdo->beginTransaction();

        $prefix = "REQ-" . date('Ym') . "-";
        $last_no_stmt = $pdo->query("SELECT requisition_no FROM requisitions WHERE requisition_no LIKE '{$prefix}%' ORDER BY requisition_id DESC LIMIT 1");
        $last_no = $last_no_stmt->fetchColumn();

        if ($last_no) {
            $last_seq = (int)substr($last_no, -4);
            $new_seq = str_pad($last_seq + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $new_seq = "0001";
        }
        $requisition_no = $prefix . $new_seq;

        $req_stmt = $pdo->prepare("
            INSERT INTO requisitions (requisition_no, unit_id, user_id, status, request_date, note)
            VALUES (?, ?, ?, 'pending', NOW(), ?)
        ");
        $req_stmt->execute([$requisition_no, $unit_id, $user_id, $note]);
        $requisition_id = $pdo->lastInsertId();

        $item_stmt = $pdo->prepare("
            INSERT INTO requisition_items (requisition_id, material_id, quantity_requested, quantity_approved)
            VALUES (?, ?, ?, ?)
        ");

        $total_item_count = 0;
        foreach ($items as $mat_id => $qty) {
            $qty = (int)$qty;
            if ($qty > 0) {
                $item_stmt->execute([$requisition_id, (int)$mat_id, $qty, $qty]);
                $total_item_count++;
            }
        }

        $pdo->commit();
        setFlash('success', "สร้างใบขอเบิกพัสดุเลขที่ [{$requisition_no}] จำนวน {$total_item_count} รายการ เรียบร้อยแล้ว รอนำส่งหรือสแกน QR เพื่อจ่ายของ");
    } catch (\PDOException $e) {
        $pdo->rollBack();
        setFlash('danger', "เกิดข้อผิดพลาดในการสร้างใบขอเบิก: " . $e->getMessage());
    }

    handleRedirect("dashboard.php?unit_id=" . $unit_id . "#requisitions_tab", $redirect_to);
}

// -------------------------------------------------------------
// 2. อนุมัติจ่ายพัสดุตามใบขอเบิก (Approve & Dispense Requisition)
// -------------------------------------------------------------
if ($action === 'approve_requisition') {
    if ($_SESSION['role'] !== 'super_admin') {
        setFlash('danger', 'คุณไม่มีสิทธิ์ในการอนุมัติจ่ายพัสดุคลังกลาง');
        header("Location: " . BASE_URL . "/login.php");
        exit;
    }

    $requisition_id = (int)($_POST['requisition_id'] ?? 0);
    $items = $_POST['items'] ?? []; // Array [item_id => quantity_approved]

    if ($requisition_id <= 0) {
        setFlash('danger', 'ไม่พบรหัสใบขอเบิก');
        handleRedirect(BASE_URL . "/admin/dashboard.php", $redirect_to);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT r.*, u.unit_name 
            FROM requisitions r 
            JOIN units u ON r.unit_id = u.unit_id 
            WHERE r.requisition_id = ? 
            FOR UPDATE
        ");
        $stmt->execute([$requisition_id]);
        $req = $stmt->fetch();

        if (!$req || $req['status'] !== 'pending') {
            $pdo->rollBack();
            setFlash('warning', 'ใบขอเบิกนี้ถูกดำเนินการไปแล้ว หรือไม่ได้อยู่ในสถานะรออนุมัติ');
            handleRedirect(BASE_URL . "/admin/dashboard.php", $redirect_to);
        }

        $req_unit_id = (int)$req['unit_id'];

        $items_stmt = $pdo->prepare("SELECT * FROM requisition_items WHERE requisition_id = ? FOR UPDATE");
        $items_stmt->execute([$requisition_id]);
        $db_items = $items_stmt->fetchAll();

        foreach ($db_items as $d_item) {
            $item_id = $d_item['item_id'];
            $mat_id  = $d_item['material_id'];
            $qty_app = isset($items[$item_id]) ? (int)$items[$item_id] : (int)$d_item['quantity_requested'];

            if ($qty_app > 0) {
                // 1. ตรวจสอบสต็อกคลังกลาง
                $central_stock_stmt = $pdo->prepare("SELECT quantity FROM unit_stocks WHERE unit_id = 1 AND material_id = ? FOR UPDATE");
                $central_stock_stmt->execute([$mat_id]);
                $c_stock = (int)$central_stock_stmt->fetchColumn();

                if ($c_stock < $qty_app) {
                    $pdo->rollBack();
                    setFlash('danger', "สต็อกคลังกลางสำหรับพัสดุรหัส ID: {$mat_id} มีไม่เพียงพอต่อการจ่าย (มี {$c_stock} แต่จ่าย {$qty_app})");
                    handleRedirect(BASE_URL . "/admin/dashboard.php", $redirect_to);
                }

                // 2. หักยอดออกจากคลังกลาง (unit_id = 1)
                $deduct_stmt = $pdo->prepare("UPDATE unit_stocks SET quantity = quantity - ? WHERE unit_id = 1 AND material_id = ?");
                $deduct_stmt->execute([$qty_app, $mat_id]);

                // 3. เพิ่มยอดเข้าห้องพัสดุประจำหน่วยงาน
                $add_stmt = $pdo->prepare("
                    INSERT INTO unit_stocks (unit_id, material_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
                ");
                $add_stmt->execute([$req_unit_id, $mat_id, $qty_app]);

                // 4. อัปเดตจำนวนที่อนุมัติ
                $up_item = $pdo->prepare("UPDATE requisition_items SET quantity_approved = ? WHERE item_id = ?");
                $up_item->execute([$qty_app, $item_id]);

                // 5. บันทึกประวัติการเคลื่อนไหว เข้า-ออก
                logStockMovement($pdo, $mat_id, 1, 'out', $qty_app, 'requisition', $req['requisition_no'], "จ่ายให้ {$req['unit_name']}", $user_id);
                logStockMovement($pdo, $mat_id, $req_unit_id, 'in', $qty_app, 'requisition', $req['requisition_no'], "รับเข้าห้องพัสดุจากคลังกลาง", $user_id);
            }
        }

        // 6. ปรับสถานะใบเบิกเป็น completed
        $up_req = $pdo->prepare("UPDATE requisitions SET status = 'completed', approve_date = NOW(), approved_by = ? WHERE requisition_id = ?");
        $up_req->execute([$user_id, $requisition_id]);

        $pdo->commit();
        setFlash('success', "อนุมัติจ่ายพัสดุตามใบขอเบิก [{$req['requisition_no']}] และโอนเข้าคลังห้องประจำหน่วยงานเรียบร้อยแล้ว");
    } catch (\PDOException $e) {
        $pdo->rollBack();
        setFlash('danger', "เกิดข้อผิดพลาดในการอนุมัติ: " . $e->getMessage());
    }

    handleRedirect(BASE_URL . "/admin/dashboard.php#requisitions_section", $redirect_to);
}

// -------------------------------------------------------------
// 3. ปฏิเสธใบขอเบิก (Reject Requisition)
// -------------------------------------------------------------
if ($action === 'reject_requisition') {
    if ($_SESSION['role'] !== 'super_admin') {
        setFlash('danger', 'คุณไม่มีสิทธิ์ในการปฏิเสธคำขอ');
        header("Location: " . BASE_URL . "/login.php");
        exit;
    }

    $requisition_id   = (int)($_POST['requisition_id'] ?? 0);
    $rejection_reason = trim($_POST['rejection_reason'] ?? 'ไม่ระบุเหตุผล');

    if ($requisition_id > 0) {
        $stmt = $pdo->prepare("
            UPDATE requisitions 
            SET status = 'rejected', rejection_reason = ?, approve_date = NOW(), approved_by = ? 
            WHERE requisition_id = ? AND status = 'pending'
        ");
        $stmt->execute([$rejection_reason, $user_id, $requisition_id]);
        setFlash('info', 'ปฏิเสธใบขอเบิกพัสดุเรียบร้อยแล้ว');
    }

    handleRedirect(BASE_URL . "/admin/dashboard.php#requisitions_section", $redirect_to);
}

// -------------------------------------------------------------
// 4. บันทึกนำพัสดุออกจากห้องตัวเองไปใช้งาน (Unit Internal Dispense/Usage)
// -------------------------------------------------------------
if ($action === 'use_internal_material') {
    $material_id        = (int)($_POST['material_id'] ?? 0);
    $quantity           = (int)($_POST['quantity'] ?? 0);
    $used_by            = trim($_POST['used_by'] ?? $_SESSION['full_name']);
    $patient_or_purpose = trim($_POST['patient_or_purpose'] ?? '');

    if ($material_id <= 0 || $quantity <= 0) {
        setFlash('danger', 'กรุณาระบุจำนวนที่ต้องการเบิกใช้งานให้ถูกต้อง');
        handleRedirect("dashboard.php?unit_id=" . $current_unit_id, $redirect_to);
    }

    try {
        $pdo->beginTransaction();

        $stock_stmt = $pdo->prepare("SELECT quantity FROM unit_stocks WHERE unit_id = ? AND material_id = ? FOR UPDATE");
        $stock_stmt->execute([$current_unit_id, $material_id]);
        $stock = (int)$stock_stmt->fetchColumn();

        if ($stock < $quantity) {
            $pdo->rollBack();
            setFlash('danger', "พัสดุในห้องมีเพียง {$stock} ชิ้น ไม่เพียงพอต่อการนำไปใช้งาน ({$quantity} ชิ้น)");
            handleRedirect("dashboard.php?unit_id=" . $current_unit_id, $redirect_to);
        }

        // 1. หักยอดออกจากห้องตนเอง
        $deduct_stmt = $pdo->prepare("UPDATE unit_stocks SET quantity = quantity - ? WHERE unit_id = ? AND material_id = ?");
        $deduct_stmt->execute([$quantity, $current_unit_id, $material_id]);

        // 2. บันทึกประวัติการใช้ลงใน unit_usages
        $log_stmt = $pdo->prepare("
            INSERT INTO unit_usages (unit_id, material_id, quantity, used_by, patient_or_purpose, used_at, user_id)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ");
        $log_stmt->execute([$current_unit_id, $material_id, $quantity, $used_by, $patient_or_purpose, $user_id]);

        // 3. บันทึกประวัติการเคลื่อนไหว ออก
        $desc = "เบิกใช้ในห้อง: " . ($patient_or_purpose ?: 'ใช้งานทั่วไป') . " (โดย {$used_by})";
        logStockMovement($pdo, $material_id, $current_unit_id, 'out', $quantity, 'internal_usage', 'USE', $desc, $user_id);

        $pdo->commit();
        setFlash('success', "บันทึกนำพัสดุออกจากห้องไปใช้งานจำนวน {$quantity} ชิ้น เรียบร้อยแล้ว");
    } catch (\PDOException $e) {
        $pdo->rollBack();
        setFlash('danger', "เกิดข้อผิดพลาดในการบันทึกการเบิกใช้: " . $e->getMessage());
    }

    handleRedirect("dashboard.php?unit_id=" . $current_unit_id . "#usages_tab", $redirect_to);
}

// -------------------------------------------------------------
// 4.1 สแกนบาร์โค้ดจ่ายเวชภัณฑ์ให้ผู้รับบริการ (คนไข้) โดยตรง
// -------------------------------------------------------------
if ($action === 'dispense_to_patient') {
    $material_id   = (int)($_POST['material_id'] ?? 0);
    $quantity      = (int)($_POST['quantity'] ?? 0);
    $patient_hn    = trim($_POST['patient_hn'] ?? '');
    $patient_name  = trim($_POST['patient_name'] ?? 'ผู้รับบริการ');
    $bed_no        = trim($_POST['bed_no'] ?? '');
    $procedure     = trim($_POST['procedure'] ?? '');
    $used_by       = trim($_POST['used_by'] ?? $_SESSION['full_name']);

    if ($material_id <= 0 || $quantity <= 0) {
        setFlash('danger', 'กรุณาระบุรายการเวชภัณฑ์และจำนวนที่ใช้ให้ถูกต้อง');
        handleRedirect("scan.php?unit_id=" . $current_unit_id, $redirect_to);
    }

    try {
        $pdo->beginTransaction();

        $stock_stmt = $pdo->prepare("SELECT quantity FROM unit_stocks WHERE unit_id = ? AND material_id = ? FOR UPDATE");
        $stock_stmt->execute([$current_unit_id, $material_id]);
        $stock = (int)$stock_stmt->fetchColumn();

        if ($stock < $quantity) {
            $pdo->rollBack();
            setFlash('danger', "เวชภัณฑ์ในห้องพัสดุมีเพียง {$stock} หน่วย ไม่เพียงพอต่อการจ่ายให้คนไข้ ({$quantity} หน่วย)");
            handleRedirect("scan.php?unit_id=" . $current_unit_id, $redirect_to);
        }

        // 1. ตัดสต็อกออกจากห้องตนเอง
        $deduct_stmt = $pdo->prepare("UPDATE unit_stocks SET quantity = quantity - ? WHERE unit_id = ? AND material_id = ?");
        $deduct_stmt->execute([$quantity, $current_unit_id, $material_id]);

        // 2. บันทึกประวัติการจ่ายให้คนไข้ลงใน unit_usages
        $purpose_text = "จ่ายให้คนไข้: {$patient_name} (HN: {$patient_hn})";
        if (!empty($bed_no)) $purpose_text .= " เตียง: {$bed_no}";
        if (!empty($procedure)) $purpose_text .= " หัตถการ: {$procedure}";

        $mat_info = $pdo->query("SELECT lot_no, unit_price, supplier FROM materials WHERE material_id = {$material_id}")->fetch();
        $lot_no = $mat_info['lot_no'] ?? null;
        $unit_price = (float)($mat_info['unit_price'] ?? 0);
        $supplier = $mat_info['supplier'] ?? '';

        $log_stmt = $pdo->prepare("
            INSERT INTO unit_usages (unit_id, material_id, patient_hn, patient_name, quantity, lot_no, used_by, patient_or_purpose, used_at, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $log_stmt->execute([$current_unit_id, $material_id, $patient_hn, $patient_name, $quantity, $lot_no, $used_by, $purpose_text, $user_id]);

        // 3. บันทึกประวัติการเคลื่อนไหวสต็อก
        logStockMovement($pdo, $material_id, $current_unit_id, 'out', $quantity, 'patient_dispense', $patient_hn, $purpose_text, $user_id, $unit_price, $supplier);

        $pdo->commit();
        setFlash('success', "สแกนจ่ายเวชภัณฑ์ให้คนไข้ HN: {$patient_hn} ({$patient_name}) จำนวน {$quantity} หน่วย เรียบร้อยแล้ว");
    } catch (\PDOException $e) {
        $pdo->rollBack();
        setFlash('danger', "เกิดข้อผิดพลาดในการบันทึกจ่ายให้คนไข้: " . $e->getMessage());
    }

    handleRedirect("scan.php?unit_id=" . $current_unit_id, $redirect_to);
}

// -------------------------------------------------------------
// 4.2 บันทึกการเบิกใช้พัสดุในห้องตนเองหลายรายการพร้อมกัน (Batch Multi-Item Room Usage)
// -------------------------------------------------------------
if ($action === 'batch_internal_usage') {
    $used_by            = trim($_POST['used_by'] ?? $_SESSION['full_name']);
    $patient_or_purpose = trim($_POST['patient_or_purpose'] ?? '');
    $items              = $_POST['items'] ?? []; // Array [material_id => quantity]

    if (empty($items) || !is_array($items)) {
        setFlash('danger', 'กรุณาเลือกรายการพัสดุและระบุจำนวนที่ต้องการเบิกใช้งานอย่างน้อย 1 รายการ');
        handleRedirect("dashboard.php?unit_id=" . $current_unit_id, $redirect_to);
    }

    try {
        $pdo->beginTransaction();

        $dispensed_count = 0;
        $total_pieces    = 0;

        foreach ($items as $material_id => $qty) {
            $material_id = (int)$material_id;
            $quantity    = (int)$qty;

            if ($material_id <= 0 || $quantity <= 0) {
                continue;
            }

            // ตรวจสอบสต็อกคงเหลือในห้องตนเอง
            $stock_stmt = $pdo->prepare("SELECT quantity FROM unit_stocks WHERE unit_id = ? AND material_id = ? FOR UPDATE");
            $stock_stmt->execute([$current_unit_id, $material_id]);
            $stock = (int)$stock_stmt->fetchColumn();

            if ($stock < $quantity) {
                $mat_name = $pdo->query("SELECT material_name FROM materials WHERE material_id = {$material_id}")->fetchColumn();
                $pdo->rollBack();
                setFlash('danger', "พัสดุ [{$mat_name}] ในห้องมีเพียง {$stock} ชิ้น ไม่เพียงพอต่อการเบิกใช้ ({$quantity} ชิ้น)");
                handleRedirect("dashboard.php?unit_id=" . $current_unit_id, $redirect_to);
            }

            // 1. หักยอดออกจากห้องตนเอง
            $deduct_stmt = $pdo->prepare("UPDATE unit_stocks SET quantity = quantity - ? WHERE unit_id = ? AND material_id = ?");
            $deduct_stmt->execute([$quantity, $current_unit_id, $material_id]);

            // 2. บันทึกประวัติการใช้ลงใน unit_usages
            $log_stmt = $pdo->prepare("
                INSERT INTO unit_usages (unit_id, material_id, quantity, used_by, patient_or_purpose, used_at, user_id)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ");
            $log_stmt->execute([$current_unit_id, $material_id, $quantity, $used_by, $patient_or_purpose, $user_id]);

            // 3. บันทึกประวัติการเคลื่อนไหว ออก
            $desc = "เบิกใช้ในห้อง: " . ($patient_or_purpose ?: 'ใช้งานทั่วไป') . " (โดย {$used_by})";
            logStockMovement($pdo, $material_id, $current_unit_id, 'out', $quantity, 'internal_usage', 'USE', $desc, $user_id);

            $dispensed_count++;
            $total_pieces += $quantity;
        }

        if ($dispensed_count === 0) {
            $pdo->rollBack();
            setFlash('warning', 'ไม่มีรายการพัสดุที่ระบุจำนวนมากกว่า 0');
            handleRedirect("dashboard.php?unit_id=" . $current_unit_id, $redirect_to);
        }

        $pdo->commit();
        setFlash('success', "บันทึกเบิกใช้วัสดุในห้องสำเร็จ {$dispensed_count} รายการ (รวม {$total_pieces} ชิ้น) เรียบร้อยแล้ว");
    } catch (\PDOException $e) {
        $pdo->rollBack();
        setFlash('danger', "เกิดข้อผิดพลาดในการบันทึกการเบิกใช้หลายรายการ: " . $e->getMessage());
    }

    handleRedirect("dashboard.php?unit_id=" . $current_unit_id . "#usages_tab", $redirect_to);
}

// -------------------------------------------------------------
// 5. ส่งคำขอยืมพัสดุระหว่างหน่วยงาน (Request Borrow)
// -------------------------------------------------------------
if ($action === 'request_borrow') {
    $material_id    = (int)($_POST['material_id'] ?? 0);
    $lender_unit_id = (int)($_POST['lender_unit_id'] ?? 0);
    $quantity       = (int)($_POST['quantity'] ?? 0);
    $due_date       = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $note           = trim($_POST['note'] ?? '');

    if ($material_id <= 0 || $lender_unit_id <= 0 || $quantity <= 0) {
        setFlash('danger', 'กรุณาระบุข้อมูลการขอยืมพัสดุให้ครบถ้วน');
        handleRedirect("dashboard.php?unit_id=" . $current_unit_id, $redirect_to);
    }

    if ($lender_unit_id === $current_unit_id) {
        setFlash('warning', 'ไม่สามารถขอยืมพัสดุจากหน่วยงานของตนเองได้');
        handleRedirect("dashboard.php?unit_id=" . $current_unit_id, $redirect_to);
    }

    try {
        $check_stmt = $pdo->prepare("SELECT quantity FROM unit_stocks WHERE unit_id = ? AND material_id = ?");
        $check_stmt->execute([$lender_unit_id, $material_id]);
        $available_stock = (int)$check_stmt->fetchColumn();

        if ($available_stock < $quantity) {
            setFlash('danger', "หน่วยงานผู้ให้ยืมมีพัสดุคงเหลือเพียง {$available_stock} ชิ้น ไม่เพียงพอต่อการขอยืม ({$quantity} ชิ้น)");
            handleRedirect("dashboard.php?unit_id=" . $current_unit_id, $redirect_to);
        }

        $stmt = $pdo->prepare("
            INSERT INTO item_borrows (borrower_unit_id, lender_unit_id, material_id, quantity, status, due_date, note)
            VALUES (?, ?, ?, ?, 'pending', ?, ?)
        ");
        $stmt->execute([$current_unit_id, $lender_unit_id, $material_id, $quantity, $due_date, $note]);

        setFlash('success', "ส่งคำขอยืมพัสดุเรียบร้อยแล้ว รอการอนุมัติจากหน่วยงานผู้ให้ยืม");
    } catch (\PDOException $e) {
        setFlash('danger', 'เกิดข้อผิดพลาดในการบันทึกคำขอยืม: ' . $e->getMessage());
    }

    handleRedirect("dashboard.php?unit_id=" . $current_unit_id . "#borrow_tabs", $redirect_to);
}

// -------------------------------------------------------------
// 6. อนุมัติการยืมพัสดุ (Approve Borrow)
// -------------------------------------------------------------
if ($action === 'approve_borrow') {
    $borrow_id = (int)($_POST['borrow_id'] ?? 0);

    if ($borrow_id > 0) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT b.*, u_l.unit_name AS lender_name, u_b.unit_name AS borrower_name
                FROM item_borrows b
                JOIN units u_l ON b.lender_unit_id = u_l.unit_id
                JOIN units u_b ON b.borrower_unit_id = u_b.unit_id
                WHERE b.borrow_id = ? 
                FOR UPDATE
            ");
            $stmt->execute([$borrow_id]);
            $borrow = $stmt->fetch();

            if (!$borrow || $borrow['status'] !== 'pending') {
                $pdo->rollBack();
                setFlash('warning', 'รายการนี้ไม่ได้อยู่ในสถานะรออนุมัติ');
                handleRedirect("dashboard.php?unit_id=" . $current_unit_id, $redirect_to);
            }

            $deduct_stmt = $pdo->prepare("UPDATE unit_stocks SET quantity = quantity - ? WHERE unit_id = ? AND material_id = ?");
            $deduct_stmt->execute([$borrow['quantity'], $borrow['lender_unit_id'], $borrow['material_id']]);

            $add_stmt = $pdo->prepare("
                INSERT INTO unit_stocks (unit_id, material_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ");
            $add_stmt->execute([$borrow['borrower_unit_id'], $borrow['material_id'], $borrow['quantity']]);

            $update_stmt = $pdo->prepare("UPDATE item_borrows SET status = 'approved', action_by = ? WHERE borrow_id = ?");
            $update_stmt->execute([$user_id, $borrow_id]);

            // บันทึกการเคลื่อนไหว
            logStockMovement($pdo, $borrow['material_id'], $borrow['lender_unit_id'], 'out', $borrow['quantity'], 'borrow_out', "BR-{$borrow_id}", "ให้หน่วยงาน {$borrow['borrower_name']} ยืม", $user_id);
            logStockMovement($pdo, $borrow['material_id'], $borrow['borrower_unit_id'], 'in', $borrow['quantity'], 'borrow_in', "BR-{$borrow_id}", "ยืมมาจากหน่วยงาน {$borrow['lender_name']}", $user_id);

            $pdo->commit();
            setFlash('success', 'อนุมัติการยืมพัสดุเรียบร้อยแล้ว');
        } catch (\PDOException $e) {
            $pdo->rollBack();
            setFlash('danger', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
    }
    handleRedirect("dashboard.php?unit_id=" . $current_unit_id . "#borrow_tabs", $redirect_to);
}

// -------------------------------------------------------------
// 7. ปฏิเสธคำขอยืม (Reject Borrow)
// -------------------------------------------------------------
if ($action === 'reject_borrow') {
    $borrow_id = (int)($_POST['borrow_id'] ?? 0);
    if ($borrow_id > 0) {
        $stmt = $pdo->prepare("UPDATE item_borrows SET status = 'rejected', action_by = ? WHERE borrow_id = ? AND status = 'pending'");
        $stmt->execute([$user_id, $borrow_id]);
        setFlash('info', 'ปฏิเสธคำขอยืมพัสดุเรียบร้อยแล้ว');
    }
    handleRedirect("dashboard.php?unit_id=" . $current_unit_id . "#borrow_tabs", $redirect_to);
}

// -------------------------------------------------------------
// 8. บันทึกส่งคืนพัสดุ (Return Item)
// -------------------------------------------------------------
if ($action === 'return_item') {
    $borrow_id = (int)($_POST['borrow_id'] ?? 0);
    if ($borrow_id > 0) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT b.*, u_l.unit_name AS lender_name, u_b.unit_name AS borrower_name
                FROM item_borrows b
                JOIN units u_l ON b.lender_unit_id = u_l.unit_id
                JOIN units u_b ON b.borrower_unit_id = u_b.unit_id
                WHERE b.borrow_id = ? 
                FOR UPDATE
            ");
            $stmt->execute([$borrow_id]);
            $borrow = $stmt->fetch();

            if ($borrow && $borrow['status'] === 'approved') {
                $deduct_stmt = $pdo->prepare("UPDATE unit_stocks SET quantity = GREATEST(0, quantity - ?) WHERE unit_id = ? AND material_id = ?");
                $deduct_stmt->execute([$borrow['quantity'], $borrow['borrower_unit_id'], $borrow['material_id']]);

                $restore_stmt = $pdo->prepare("
                    INSERT INTO unit_stocks (unit_id, material_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
                ");
                $restore_stmt->execute([$borrow['lender_unit_id'], $borrow['material_id'], $borrow['quantity']]);

                $update_stmt = $pdo->prepare("UPDATE item_borrows SET status = 'returned', return_date = NOW(), action_by = ? WHERE borrow_id = ?");
                $update_stmt->execute([$user_id, $borrow_id]);

                // บันทึกการเคลื่อนไหว คืน
                logStockMovement($pdo, $borrow['material_id'], $borrow['borrower_unit_id'], 'out', $borrow['quantity'], 'return_out', "BR-{$borrow_id}", "ส่งคืนให้ {$borrow['lender_name']}", $user_id);
                logStockMovement($pdo, $borrow['material_id'], $borrow['lender_unit_id'], 'in', $borrow['quantity'], 'return_in', "BR-{$borrow_id}", "รับคืนจาก {$borrow['borrower_name']}", $user_id);

                $pdo->commit();
                setFlash('success', 'บันทึกส่งคืนพัสดุเรียบร้อยแล้ว');
            } else {
                $pdo->rollBack();
            }
        } catch (\PDOException $e) {
            $pdo->rollBack();
            setFlash('danger', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
    }
    handleRedirect("dashboard.php?unit_id=" . $current_unit_id . "#borrow_tabs", $redirect_to);
}

handleRedirect("dashboard.php?unit_id=" . $current_unit_id, $redirect_to);
