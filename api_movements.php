<?php
/**
 * API ดึงประวัติวันและเวลาการนำเข้า-จ่ายออกของวัสดุ (Material In-Out Movement History API)
 * ระบบจัดการวัสดุ โรงพยาบาลมหาสารคาม
 */
require_once __DIR__ . '/config.php';
checkAuth();

header('Content-Type: application/json; charset=utf-8');

$material_id = (int)($_GET['material_id'] ?? 0);
$unit_id     = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;

if ($material_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ไม่ระบุรหัสพัสดุ']);
    exit;
}

// ดึงข้อมูลพัสดุ
$mat_stmt = $pdo->prepare("SELECT * FROM materials WHERE material_id = ?");
$mat_stmt->execute([$material_id]);
$material = $mat_stmt->fetch();

if (!$material) {
    echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลพัสดุ']);
    exit;
}

// สร้างคำสั่ง SQL สำหรับดึงประวัติการเคลื่อนไหว เข้า-ออก
$sql = "
    SELECT sm.*, 
           u.unit_name, u.unit_code,
           usr.full_name AS performed_by_name
    FROM stock_movements sm
    JOIN units u ON sm.unit_id = u.unit_id
    LEFT JOIN users usr ON sm.performed_by = usr.user_id
    WHERE sm.material_id = ?
";
$params = [$material_id];

if ($unit_id > 0) {
    $sql .= " AND sm.unit_id = ?";
    $params[] = $unit_id;
}

$sql .= " ORDER BY sm.movement_id DESC LIMIT 50";

$move_stmt = $pdo->prepare($sql);
$move_stmt->execute($params);
$movements = $move_stmt->fetchAll();

$formatted_movements = [];
foreach ($movements as $m) {
    $formatted_movements[] = [
        'movement_id'   => $m['movement_id'],
        'movement_type' => $m['movement_type'], // 'in' or 'out'
        'quantity'      => (int)$m['quantity'],
        'unit_price'    => (float)($m['unit_price'] ?? 0),
        'total_price'   => (float)($m['total_price'] ?? 0),
        'supplier'      => $m['supplier'] ?? '',
        'balance_after' => $m['balance_after'] !== null ? (int)$m['balance_after'] : null,
        'reference_type'=> $m['reference_type'],
        'reference_id'  => $m['reference_id'] ?? '-',
        'description'   => $m['description'] ?? '-',
        'unit_name'     => $m['unit_name'],
        'unit_code'     => $m['unit_code'],
        'performed_by'  => $m['performed_by_name'] ?? 'ระบบ',
        'created_at'    => thaiDate($m['created_at'], true),
        'raw_date'      => $m['created_at']
    ];
}

// คำนวณยอดสต็อกปัจจุบัน
$current_stock = 0;
if ($unit_id > 0) {
    $stk_stmt = $pdo->prepare("SELECT quantity FROM unit_stocks WHERE unit_id = ? AND material_id = ?");
    $stk_stmt->execute([$unit_id, $material_id]);
    $current_stock = (int)$stk_stmt->fetchColumn();
} else {
    $stk_stmt = $pdo->prepare("SELECT SUM(quantity) FROM unit_stocks WHERE material_id = ?");
    $stk_stmt->execute([$material_id]);
    $current_stock = (int)$stk_stmt->fetchColumn();
}

echo json_encode([
    'success'       => true,
    'material'      => $material,
    'current_stock' => $current_stock,
    'movements'     => $formatted_movements
]);
exit;
