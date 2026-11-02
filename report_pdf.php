<?php
/**
 * ออกเอกสารรายงานราชการด้วย mPDF
 * ระบบจัดการวัสดุ โรงพยาบาลมหาสารคาม
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

checkAuth();

$type   = $_GET['type'] ?? 'inventory'; // 'inventory', 'unit', 'borrows', 'requisition'
$unit_id = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : (int)($_SESSION['unit_id'] ?? 1);
$req_id  = isset($_GET['req_id']) ? (int)$_GET['req_id'] : 0;

// ตั้งค่า mPDF พร้อม TH Sarabun Font
$defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
$fontDirs = $defaultConfig['fontDir'];

$defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
$fontData = $defaultFontConfig['fontdata'];

$customFontDir = __DIR__ . '/assets/fonts';
$hasCustomFont = file_exists($customFontDir . '/THSarabunNew.ttf');

$mpdfConfig = [
    'mode'          => 'utf-8',
    'format'        => 'A4',
    'margin_left'   => 18,
    'margin_right'  => 18,
    'margin_top'    => 18,
    'margin_bottom' => 18,
    'margin_header' => 8,
    'margin_footer' => 8,
    'default_font_size' => 16,
];

if ($hasCustomFont) {
    $mpdfConfig['fontDir'] = array_merge($fontDirs, [$customFontDir]);
    $mpdfConfig['fontdata'] = $fontData + [
        'thsarabun' => [
            'R' => 'THSarabunNew.ttf',
            'B' => 'THSarabunNew-Bold.ttf',
        ]
    ];
    $mpdfConfig['default_font'] = 'thsarabun';
} else {
    $mpdfConfig['default_font'] = 'garuda';
}

$mpdf = new \Mpdf\Mpdf($mpdfConfig);

// วันที่ปัจจุบันภาษาไทย
$todayThai = thaiDate(date('Y-m-d H:i:s'), false);
$printTimeThai = thaiDate(date('Y-m-d H:i:s'), true);

$mpdf->SetFooter('โรงพยาบาลมหาสารคาม • หน้า {PAGENO}/{nbpg} • พิมพ์เมื่อ: ' . $printTimeThai);

$stylesheet = '
<style>
    body {
        font-family: "thsarabun", "garuda", sans-serif;
        font-size: 16pt;
        color: #000000;
        line-height: 1.25;
    }
    .memo-header {
        text-align: center;
        font-weight: bold;
        font-size: 26pt;
        margin-bottom: 5px;
        letter-spacing: 1px;
    }
    .memo-meta {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
        border-bottom: 1px solid #000;
        padding-bottom: 8px;
    }
    .memo-meta td {
        padding: 3px 0;
        vertical-align: top;
        font-size: 16pt;
    }
    .table-data {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
        margin-bottom: 15px;
    }
    .table-data th, .table-data td {
        border: 1px solid #333333;
        padding: 6px 8px;
        font-size: 14pt;
        line-height: 1.2;
    }
    .table-data th {
        background-color: #f2f2f2;
        font-weight: bold;
        text-align: center;
    }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .fw-bold { font-weight: bold; }
    .signature-container {
        width: 100%;
        margin-top: 25px;
        page-break-inside: avoid;
    }
    .signature-box {
        text-align: center;
        width: 48%;
        font-size: 15pt;
        line-height: 1.4;
    }
</style>
';

$html = '';

// -------------------------------------------------------------
// แบบที่ 1: รายงานคลังพัสดุและเวชภัณฑ์กลาง (Central Inventory)
// -------------------------------------------------------------
if ($type === 'inventory') {
    $materials = $pdo->query("
        SELECT m.*, COALESCE(us.quantity, 0) AS central_stock
        FROM materials m
        LEFT JOIN unit_stocks us ON m.material_id = us.material_id AND us.unit_id = 1
        ORDER BY m.material_id ASC
    ")->fetchAll();

    $total_qty = 0;
    foreach ($materials as $m) $total_qty += (int)$m['central_stock'];

    $doc_number = "มค ๐๐๓๒.๓๐๑/" . rand(100, 999);

    $html = '
    <div class="memo-header">บันทึกข้อความ</div>
    <table class="memo-meta">
        <tr>
            <td style="width: 50%;"><strong>ส่วนราชการ:</strong> กลุ่มงานพัสดุและบำรุงรักษา โรงพยาบาลมหาสารคาม โทร. ๐๔๓-๗๑๑๗๕๐</td>
            <td style="width: 50%;"><strong>ที่:</strong> ' . $doc_number . '</td>
        </tr>
        <tr>
            <td><strong>วันที่:</strong> ' . $todayThai . '</td>
            <td><strong>เรื่อง:</strong> รายงานสรุปสถานะพัสดุและเวชภัณฑ์คงคลังกลาง</td>
        </tr>
        <tr>
            <td colspan="2"><strong>เรียน:</strong> ผู้อำนวยการโรงพยาบาลมหาสารคาม</td>
        </tr>
    </table>

    <p style="text-indent: 2.5cm; margin-bottom: 8px;">
        ด้วย กลุ่มงานพัสดุและบำรุงรักษา ได้ดำเนินการตรวจนับยอดคงคลังวัสดุและเวชภัณฑ์กลาง โรงพยาบาลมหาสารคาม ประจำงวด มีรายละเอียดพัสดุคงคลังดังตารางต่อไปนี้
    </p>

    <table class="table-data">
        <thead>
            <tr>
                <th style="width: 6%;">ลำดับ</th>
                <th style="width: 15%;">รหัสพัสดุ</th>
                <th style="width: 35%;">รายการพัสดุ / เวชภัณฑ์</th>
                <th style="width: 18%;">หมวดหมู่</th>
                <th style="width: 12%;">หน่วยนับ</th>
                <th style="width: 14%;">ยอดคงคลัง</th>
            </tr>
        </thead>
        <tbody>';

    $i = 1;
    foreach ($materials as $item) {
        $stock = (int)$item['central_stock'];
        $min = (int)$item['min_stock'];
        $is_low = $stock <= $min ? ' style="color: #b91c1c; font-weight: bold;"' : '';

        $html .= '
            <tr>
                <td class="text-center">' . $i++ . '</td>
                <td class="text-center font-monospace">' . h($item['material_code']) . '</td>
                <td>' . h($item['material_name']) . '</td>
                <td>' . h($item['category']) . '</td>
                <td class="text-center">' . h($item['unit_unit']) . '</td>
                <td class="text-right"' . $is_low . '>' . number_format($stock) . '</td>
            </tr>';
    }

    $html .= '
            <tr style="background-color: #f8f8f8; font-weight: bold;">
                <td colspan="5" class="text-center">รวมจำนวนพัสดุคงคลังทั้งสิ้น (' . count($materials) . ' รายการ)</td>
                <td class="text-right">' . number_format($total_qty) . '</td>
            </tr>
        </tbody>
    </table>

    <table class="signature-container">
        <tr>
            <td class="signature-box">
                ลงชื่อ..............................................................<br>
                ( ' . h($_SESSION['full_name']) . ' )<br>
                ตำแหน่ง เจ้าพนักงานพัสดุชำนาญงาน<br>
                วันที่ ' . $todayThai . '
            </td>
            <td class="signature-box">
                [ &nbsp; ] ทราบ / เห็นชอบ<br><br>
                ลงชื่อ..............................................................<br>
                ( นายแพทย์เชี่ยวชาญ )<br>
                ตำแหน่ง ผู้อำนวยการโรงพยาบาลมหาสารคาม<br>
                วันที่ ......../......../..............
            </td>
        </tr>
    </table>';
}

// -------------------------------------------------------------
// แบบที่ 2: รายงานพัสดุประจำห้องหน่วยงาน (Unit Room Inventory)
// -------------------------------------------------------------
elseif ($type === 'unit') {
    $unit_stmt = $pdo->prepare("SELECT * FROM units WHERE unit_id = ?");
    $unit_stmt->execute([$unit_id]);
    $unit = $unit_stmt->fetch();

    $materials = $pdo->prepare("
        SELECT m.*, COALESCE(us.quantity, 0) AS unit_stock
        FROM materials m
        LEFT JOIN unit_stocks us ON m.material_id = us.material_id AND us.unit_id = ?
        ORDER BY m.material_id ASC
    ");
    $materials->execute([$unit_id]);
    $items = $materials->fetchAll();

    $total_qty = 0;
    foreach ($items as $item) $total_qty += (int)$item['unit_stock'];

    $doc_number = "มค ๐๐๓๒." . str_pad($unit_id, 2, '0', STR_PAD_LEFT) . "/" . rand(100, 999);

    $html = '
    <div class="memo-header">บันทึกข้อความ</div>
    <table class="memo-meta">
        <tr>
            <td style="width: 50%;"><strong>ส่วนราชการ:</strong> ' . h($unit['unit_name']) . ' โรงพยาบาลมหาสารคาม</td>
            <td style="width: 50%;"><strong>ที่:</strong> ' . $doc_number . '</td>
        </tr>
        <tr>
            <td><strong>วันที่:</strong> ' . $todayThai . '</td>
            <td><strong>เรื่อง:</strong> รายงานสรุปยอดพัสดุและเวชภัณฑ์ประจำห้องหน่วยงาน</td>
        </tr>
        <tr>
            <td colspan="2"><strong>เรียน:</strong> หัวหน้ากลุ่มงานพัสดุและบำรุงรักษา โรงพยาบาลมหาสารคาม</td>
        </tr>
    </table>

    <p style="text-indent: 2.5cm; margin-bottom: 8px;">
        หน่วยงาน <strong>' . h($unit['unit_name']) . ' (' . h($unit['unit_code']) . ')</strong> ได้จัดทำสรุปยอดการถือครองและคงคลังพัสดุ/เวชภัณฑ์ประจำห้องหน่วยงาน มีรายละเอียดดังนี้
    </p>

    <table class="table-data">
        <thead>
            <tr>
                <th style="width: 7%;">ลำดับ</th>
                <th style="width: 16%;">รหัสพัสดุ</th>
                <th style="width: 37%;">ชื่อพัสดุ / เวชภัณฑ์</th>
                <th style="width: 14%;">เกณฑ์ขั้นต่ำ</th>
                <th style="width: 12%;">หน่วยนับ</th>
                <th style="width: 14%;">ยอดคงคลัง</th>
            </tr>
        </thead>
        <tbody>';

    $i = 1;
    foreach ($items as $item) {
        $qty = (int)$item['unit_stock'];
        $min = (int)$item['min_stock'];
        $is_low = $qty <= $min ? ' style="color: #dc2626; font-weight: bold;"' : '';

        $html .= '
            <tr>
                <td class="text-center">' . $i++ . '</td>
                <td class="text-center font-monospace">' . h($item['material_code']) . '</td>
                <td>' . h($item['material_name']) . '</td>
                <td class="text-center">' . number_format($min) . '</td>
                <td class="text-center">' . h($item['unit_unit']) . '</td>
                <td class="text-right"' . $is_low . '>' . number_format($qty) . '</td>
            </tr>';
    }

    $html .= '
            <tr style="background-color: #f8f8f8; font-weight: bold;">
                <td colspan="5" class="text-center">รวมจำนวนพัสดุคงคลังในหน่วยงาน (' . count($items) . ' รายการ)</td>
                <td class="text-right">' . number_format($total_qty) . '</td>
            </tr>
        </tbody>
    </table>

    <table class="signature-container">
        <tr>
            <td class="signature-box">
                ลงชื่อ..............................................................<br>
                ( ' . h($_SESSION['full_name']) . ' )<br>
                ตำแหน่ง ผู้รับผิดชอบพัสดุประจำหน่วยงาน<br>
                วันที่ ' . $todayThai . '
            </td>
            <td class="signature-box">
                ลงชื่อ..............................................................<br>
                (.......................................................)<br>
                ตำแหน่ง หัวหน้ากลุ่มงาน/หัวหน้าหน่วยงาน<br>
                วันที่ ......../......../..............
            </td>
        </tr>
    </table>';
}

// -------------------------------------------------------------
// แบบที่ 3: ใบขอเบิกพัสดุหลายรายการ (Multi-item Requisition Slip)
// -------------------------------------------------------------
elseif ($type === 'requisition') {
    $req_stmt = $pdo->prepare("
        SELECT r.*, u.unit_name, u.unit_code, u.location, usr.full_name AS requester_name,
               u_app.full_name AS approver_name
        FROM requisitions r
        JOIN units u ON r.unit_id = u.unit_id
        JOIN users usr ON r.user_id = usr.user_id
        LEFT JOIN users u_app ON r.approved_by = u_app.user_id
        WHERE r.requisition_id = ?
    ");
    $req_stmt->execute([$req_id]);
    $req = $req_stmt->fetch();

    if (!$req) {
        die("ไม่พบข้อมูลใบขอเบิกพัสดุ");
    }

    $items_stmt = $pdo->prepare("
        SELECT ri.*, m.material_code, m.material_name, m.unit_unit, m.category
        FROM requisition_items ri
        JOIN materials m ON ri.material_id = m.material_id
        WHERE ri.requisition_id = ?
    ");
    $items_stmt->execute([$req_id]);
    $req_items = $items_stmt->fetchAll();

    $status_text = 'รออนุมัติ / ยังไม่จ่ายของ';
    if ($req['status'] === 'completed' || $req['status'] === 'approved') {
        $status_text = 'อนุมัติจ่ายและรับของเรียบร้อยแล้ว';
    } elseif ($req['status'] === 'rejected') {
        $status_text = 'ไม่อนุมัติคำขอ';
    }

    $html = '
    <table style="width: 100%; margin-bottom: 5px;">
        <tr>
            <td style="width: 75%; vertical-align: top;">
                <div style="font-size: 24pt; font-weight: bold; line-height: 1.1;">ใบขอเบิกพัสดุและเวชภัณฑ์</div>
                <div style="font-size: 17pt; font-weight: bold; color: #004d40;">โรงพยาบาลมหาสารคาม</div>
                <div style="font-size: 14pt; color: #555;">เลขที่ใบเบิก: <strong>' . h($req['requisition_no']) . '</strong></div>
            </td>
            <td style="width: 25%; text-align: right; vertical-align: middle;">
                <barcode code="' . h($req['requisition_no']) . '" type="QR" class="barcode" size="1.2" error="M" disableborder="1" />
                <div style="font-size: 11pt; text-align: center; color: #666;">สแกนเพื่อจ่ายของ</div>
            </td>
        </tr>
    </table>

    <table class="memo-meta" style="margin-top: 5px;">
        <tr>
            <td style="width: 50%;"><strong>หน่วยงานที่ขอเบิก:</strong> ' . h($req['unit_name']) . ' (' . h($req['unit_code']) . ')</td>
            <td style="width: 50%;"><strong>วันที่ขอเบิก:</strong> ' . thaiDate($req['request_date'], true) . '</td>
        </tr>
        <tr>
            <td><strong>สถานที่รับส่ง:</strong> ' . h($req['location'] ?? 'คลังกลาง') . '</td>
            <td><strong>สถานะใบเบิก:</strong> ' . $status_text . '</td>
        </tr>
        <tr>
            <td colspan="2"><strong>หมายเหตุ / วัตถุประสงค์:</strong> ' . h($req['note'] ?? '-') . '</td>
        </tr>
    </table>

    <table class="table-data">
        <thead>
            <tr>
                <th style="width: 7%;">ลำดับ</th>
                <th style="width: 18%;">รหัสพัสดุ</th>
                <th style="width: 40%;">รายการพัสดุ / เวชภัณฑ์</th>
                <th style="width: 15%;">จำนวนขอเบิก</th>
                <th style="width: 10%;">หน่วยนับ</th>
                <th style="width: 10%;">จำนวนจ่าย</th>
            </tr>
        </thead>
        <tbody>';

    $i = 1;
    $total_requested = 0;
    $total_approved = 0;

    foreach ($req_items as $item) {
        $total_requested += (int)$item['quantity_requested'];
        $total_approved  += (int)$item['quantity_approved'];

        $html .= '
            <tr>
                <td class="text-center">' . $i++ . '</td>
                <td class="text-center font-monospace">' . h($item['material_code']) . '</td>
                <td>' . h($item['material_name']) . '</td>
                <td class="text-center fw-bold">' . number_format($item['quantity_requested']) . '</td>
                <td class="text-center">' . h($item['unit_unit']) . '</td>
                <td class="text-center fw-bold">' . ($item['quantity_approved'] > 0 ? number_format($item['quantity_approved']) : '-') . '</td>
            </tr>';
    }

    $html .= '
            <tr style="background-color: #f8f8f8; font-weight: bold;">
                <td colspan="3" class="text-center">รวมจำนวนพัสดุทั้งสิ้น (' . count($req_items) . ' รายการ)</td>
                <td class="text-center">' . number_format($total_requested) . '</td>
                <td></td>
                <td class="text-center">' . ($total_approved > 0 ? number_format($total_approved) : '-') . '</td>
            </tr>
        </tbody>
    </table>

    <table class="signature-container" style="margin-top: 15px;">
        <tr>
            <td class="signature-box" style="width: 25%; font-size: 13pt;">
                (๑) ผู้ขอเบิก<br><br>
                ลงชื่อ...........................................<br>
                ( ' . h($req['requester_name']) . ' )<br>
                วันที่ ......../......../..............
            </td>
            <td class="signature-box" style="width: 25%; font-size: 13pt;">
                (๒) หัวหน้าหน่วยงาน<br><br>
                ลงชื่อ...........................................<br>
                (...........................................)<br>
                วันที่ ......../......../..............
            </td>
            <td class="signature-box" style="width: 25%; font-size: 13pt;">
                (๓) เจ้าหน้าที่คลังผู้จ่ายของ<br><br>
                ลงชื่อ...........................................<br>
                ( ' . h($req['approver_name'] ?? 'เจ้าหน้าที่คลังกลาง') . ' )<br>
                วันที่ ......../......../..............
            </td>
            <td class="signature-box" style="width: 25%; font-size: 13pt;">
                (๔) ผู้รับพัสดุ<br><br>
                ลงชื่อ...........................................<br>
                (...........................................)<br>
                วันที่ ......../......../..............
            </td>
        </tr>
    </table>';
}

// -------------------------------------------------------------
// แบบที่ 4: รายงานการยืม-คืนพัสดุระหว่างหน่วยงาน (Borrows Log)
// -------------------------------------------------------------
elseif ($type === 'borrows') {
    $borrows = $pdo->query("
        SELECT b.*, 
               m.material_code, m.material_name, m.unit_unit,
               u_b.unit_name AS borrower_name,
               u_l.unit_name AS lender_name
        FROM item_borrows b
        JOIN materials m ON b.material_id = m.material_id
        JOIN units u_b ON b.borrower_unit_id = u_b.unit_id
        JOIN units u_l ON b.lender_unit_id = u_l.unit_id
        ORDER BY b.borrow_id DESC
    ")->fetchAll();

    $doc_number = "มค ๐๐๓๒.๓๐๑/ว " . rand(100, 999);

    $html = '
    <div class="memo-header">บันทึกข้อความ</div>
    <table class="memo-meta">
        <tr>
            <td style="width: 50%;"><strong>ส่วนราชการ:</strong> กลุ่มงานพัสดุและบำรุงรักษา โรงพยาบาลมหาสารคาม โทร. ๐๔๓-๗๑๑๗๕๐</td>
            <td style="width: 50%;"><strong>ที่:</strong> ' . $doc_number . '</td>
        </tr>
        <tr>
            <td><strong>วันที่:</strong> ' . $todayThai . '</td>
            <td><strong>เรื่อง:</strong> รายงานสรุปประวัติการยืม-คืนพัสดุและเวชภัณฑ์ระหว่างหน่วยงาน</td>
        </tr>
        <tr>
            <td colspan="2"><strong>เรียน:</strong> ผู้อำนวยการโรงพยาบาลมหาสารคาม</td>
        </tr>
    </table>

    <table class="table-data">
        <thead>
            <tr>
                <th style="width: 8%;">รหัสยืม</th>
                <th style="width: 14%;">วันที่ยืม</th>
                <th style="width: 18%;">หน่วยขอยืม</th>
                <th style="width: 18%;">หน่วยให้ยืม</th>
                <th style="width: 24%;">รายการพัสดุ</th>
                <th style="width: 8%;">จำนวน</th>
                <th style="width: 10%;">สถานะ</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($borrows as $b) {
        $status_thai = $b['status'];
        if ($b['status'] === 'pending')   $status_thai = 'รออนุมัติ';
        elseif ($b['status'] === 'approved') $status_thai = 'อนุมัติแล้ว';
        elseif ($b['status'] === 'returned') $status_thai = 'คืนแล้ว';
        elseif ($b['status'] === 'rejected') $status_thai = 'ไม่อนุมัติ';

        $html .= '
            <tr>
                <td class="text-center font-monospace">#BR-' . str_pad($b['borrow_id'], 4, '0', STR_PAD_LEFT) . '</td>
                <td class="text-center">' . thaiDate($b['borrow_date'], false) . '</td>
                <td>' . h($b['borrower_name']) . '</td>
                <td>' . h($b['lender_name']) . '</td>
                <td>' . h($b['material_name']) . '</td>
                <td class="text-center">' . number_format($b['quantity']) . ' ' . h($b['unit_unit']) . '</td>
                <td class="text-center">' . $status_thai . '</td>
            </tr>';
    }

    $html .= '
        </tbody>
    </table>

    <table class="signature-container">
        <tr>
            <td class="signature-box">
                ลงชื่อ..............................................................<br>
                ( ' . h($_SESSION['full_name']) . ' )<br>
                ตำแหน่ง เจ้าหน้าที่งานพัสดุและคลังกลาง<br>
                วันที่ ' . $todayThai . '
            </td>
            <td class="signature-box">
                [ &nbsp; ] ทราบ<br><br>
                ลงชื่อ..............................................................<br>
                ( นายแพทย์เชี่ยวชาญ )<br>
                ตำแหน่ง ผู้อำนวยการโรงพยาบาลมหาสารคาม<br>
                วันที่ ......../......../..............
            </td>
        </tr>
    </table>';
}
elseif ($type === 'valuation') {
    $val_sql = "
        SELECT 
            u.unit_id, u.unit_code, u.unit_name,
            COALESCE(SUM(us.quantity * m.unit_price), 0) AS current_inventory_value,
            COALESCE(in_sum.total_in_val, 0) AS period_in_value,
            COALESCE(out_sum.total_out_val, 0) AS period_out_value
        FROM units u
        LEFT JOIN unit_stocks us ON u.unit_id = us.unit_id
        LEFT JOIN materials m ON us.material_id = m.material_id
        LEFT JOIN (
            SELECT unit_id, SUM(total_price) AS total_in_val
            FROM stock_movements
            WHERE movement_type = 'in'
            GROUP BY unit_id
        ) in_sum ON u.unit_id = in_sum.unit_id
        LEFT JOIN (
            SELECT unit_id, SUM(total_price) AS total_out_val
            FROM stock_movements
            WHERE movement_type = 'out'
            GROUP BY unit_id
        ) out_sum ON u.unit_id = out_sum.unit_id
        GROUP BY u.unit_id
        ORDER BY u.unit_id ASC
    ";
    $valuation_reports = $pdo->query($val_sql)->fetchAll();

    $tot_inv = 0; $tot_in = 0; $tot_out = 0;
    foreach ($valuation_reports as $vr) {
        $tot_inv += (float)$vr['current_inventory_value'];
        $tot_in  += (float)$vr['period_in_value'];
        $tot_out += (float)$vr['period_out_value'];
    }

    $html = '
    <div class="memo-header">บันทึกข้อความ</div>
    <table class="memo-meta">
        <tr>
            <td style="width: 50%;"><strong>ส่วนราชการ:</strong> งานคลังพัสดุและเวชภัณฑ์ โรงพยาบาลมหาสารคาม</td>
            <td style="width: 50%;"><strong>วันที่:</strong> ' . $todayThai . '</td>
        </tr>
        <tr>
            <td colspan="2"><strong>เรื่อง:</strong> รายงานสรุปมูลค่าคงคลัง มูลค่ารวมรับ และมูลค่ารวมจ่ายของหน่วยเบิกย่อยและคลังใหญ่</td>
        </tr>
        <tr>
            <td colspan="2"><strong>เรียน:</strong> ผู้อำนวยการโรงพยาบาลมหาสารคาม</td>
        </tr>
    </table>

    <p style="text-indent: 2.5cm; margin-bottom: 8px;">
        งานคลังพัสดุและเวชภัณฑ์ ขอรายงานสรุปมูลค่าคงคลังปัจจุบัน มูลค่ารวมรับ และมูลค่ารวมจ่ายของคลังใหญ่และหน่วยเบิกย่อยทุกแผนก เพื่อใช้ในการบริหารจัดการพัสดุและควบคุมงบประมาณทางการแพทย์ ดังรายการต่อไปนี้
    </p>

    <table class="table-data">
        <thead>
            <tr>
                <th style="width: 12%;">รหัสหน่วย</th>
                <th>หน่วยงาน / แผนกคลัง</th>
                <th style="width: 20%; text-align: right;">มูลค่าคงคลัง (บาท)</th>
                <th style="width: 20%; text-align: right;">มูลค่ารวมรับ (บาท)</th>
                <th style="width: 20%; text-align: right;">มูลค่ารวมจ่าย (บาท)</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($valuation_reports as $vr) {
        $html .= '
            <tr>
                <td class="text-center font-monospace">' . h($vr['unit_code']) . '</td>
                <td>' . h($vr['unit_name']) . ($vr['unit_id'] == 1 ? ' <strong>(คลังใหญ่)</strong>' : '') . '</td>
                <td style="text-align: right;">' . number_format($vr['current_inventory_value'], 2) . '</td>
                <td style="text-align: right;">' . number_format($vr['period_in_value'], 2) . '</td>
                <td style="text-align: right;">' . number_format($vr['period_out_value'], 2) . '</td>
            </tr>';
    }

    $html .= '
            <tr style="background-color: #f2f2f2; font-weight: bold;">
                <td colspan="2" class="text-center">มูลค่ารวมทั้งสิ้นทั้งโรงพยาบาล</td>
                <td style="text-align: right;">' . number_format($tot_inv, 2) . '</td>
                <td style="text-align: right;">' . number_format($tot_in, 2) . '</td>
                <td style="text-align: right;">' . number_format($tot_out, 2) . '</td>
            </tr>
        </tbody>
    </table>

    <table class="signature-container">
        <tr>
            <td class="signature-box">
                ลงชื่อ..............................................................<br>
                ( ' . h($_SESSION['full_name']) . ' )<br>
                ตำแหน่ง เจ้าหน้าที่งานพัสดุและคลังกลาง<br>
                วันที่ ' . $todayThai . '
            </td>
            <td class="signature-box">
                [ &nbsp; ] ทราบ<br><br>
                ลงชื่อ..............................................................<br>
                ( นายแพทย์เชี่ยวชาญ )<br>
                ตำแหน่ง ผู้อำนวยการโรงพยาบาลมหาสารคาม<br>
                วันที่ ......../......../..............
            </td>
        </tr>
    </table>';
}

$fullHtml = $stylesheet . $html;
$mpdf->WriteHTML($fullHtml);

$filename = "mhk_report_" . $type . "_" . date('Ymd_His') . ".pdf";
$mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
exit;
