<?php
/**
 * พิมพ์ QR Code สติกเกอร์สำหรับติดพัสดุ/เวชภัณฑ์ (Batch QR Code Label Printing)
 * รองรับการเลือกหลายรายการพร้อมกัน และกำหนดจำนวนดวงพิมพ์แยกตามรายการ
 * ระบบจัดการวัสดุ โรงพยาบาลมหาสารคาม
 */
require_once __DIR__ . '/../config.php';
checkAuth('super_admin');

$layout_type = $_POST['layout_type'] ?? $_GET['layout_type'] ?? 'a4_3col';
$copies_input = $_POST['copies'] ?? $_GET['copies'] ?? [];

// ดึงการตั้งค่าองค์กรและ QR Base URL
$hospital_name = getSetting('hospital_name', 'โรงพยาบาลมหาสารคาม');
$hospital_logo = getSetting('hospital_logo', 'assets/img/logo.svg');
$site_url      = getQrBaseUrl();

// กรองรายการและจำนวนดวง
$valid_items = [];
$material_ids = [];

if (is_array($copies_input)) {
    foreach ($copies_input as $mat_id => $count) {
        $mat_id = (int)$mat_id;
        $count  = (int)$count;
        if ($mat_id > 0 && $count > 0) {
            $valid_items[$mat_id] = min(100, $count); // จำกัดไม่เกิน 100 ดวงต่อรายการเพื่อความเสถียร
            $material_ids[] = $mat_id;
        }
    }
}

$materials = [];
if (!empty($material_ids)) {
    $placeholders = implode(',', array_fill(0, count($material_ids), '?'));
    $stmt = $pdo->prepare("SELECT material_id, material_code, material_name, category, unit_unit FROM materials WHERE material_id IN ($placeholders) ORDER BY material_code ASC");
    $stmt->execute($material_ids);
    $materials = $stmt->fetchAll();
}

// สร้าง Flat List สำหรับแสดงผลสติกเกอร์ตามจำนวนดวงที่เลือก
$label_list = [];
$total_labels = 0;
foreach ($materials as $m) {
    $mat_id = $m['material_id'];
    $count  = $valid_items[$mat_id] ?? 1;
    for ($i = 0; $i < $count; $i++) {
        $label_list[] = [
            'material_id'   => $m['material_id'],
            'material_code' => $m['material_code'],
            'material_name' => $m['material_name'],
            'category'      => $m['category'],
            'unit_unit'     => $m['unit_unit'],
            'copy_index'    => ($i + 1),
            'total_copies'  => $count,
            'qr_payload'    => $site_url . '/unit/scan.php?code=' . urlencode($m['material_code'])
        ];
        $total_labels++;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>พิมพ์ QR Code สติกเกอร์ (<?= $total_labels ?> ดวง) | <?= h($hospital_name) ?></title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&family=Sarabun:wght@400;500;600;700&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <!-- QRCode.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        :root {
            --mhk-primary: #0f766e;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            margin: 0;
            padding: 0;
        }
        .code-font {
            font-family: 'JetBrains Mono', monospace;
        }
        /* Top Navigation Bar */
        .print-control-bar {
            background: #ffffff;
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 1000;
            padding: 12px 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        /* Paper Page Simulation */
        .print-page-wrapper {
            max-width: 1000px;
            margin: 20px auto;
            background: #ffffff;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 12mm 10mm;
            border-radius: 8px;
        }
        
        /* Grid Layouts */
        .sticker-grid-a4_3col {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6mm;
        }
        .sticker-grid-a4_2col {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8mm;
        }
        .sticker-grid-thermal_single {
            display: grid;
            grid-template-columns: 1fr;
            max-width: 320px;
            margin: 0 auto;
            gap: 6mm;
        }

        /* Sticker Card Design */
        .sticker-card {
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 8px 10px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            page-break-inside: avoid;
            break-inside: avoid;
            position: relative;
            box-sizing: border-box;
        }
        .sticker-card:hover {
            border-color: var(--mhk-primary);
        }
        .sticker-header {
            display: flex;
            align-items: center;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 4px;
            margin-bottom: 6px;
        }
        .sticker-logo {
            width: 22px;
            height: 22px;
            object-fit: contain;
            margin-right: 6px;
        }
        .sticker-hospital-name {
            font-size: 0.72rem;
            font-weight: 700;
            color: #334155;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sticker-body {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .sticker-qr-box {
            width: 76px;
            height: 76px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 4px;
        }
        .sticker-info {
            flex-grow: 1;
            min-width: 0;
        }
        .sticker-mat-code {
            font-size: 0.82rem;
            font-weight: 700;
            color: #0f172a;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-block;
            margin-bottom: 3px;
        }
        .sticker-mat-name {
            font-size: 0.8rem;
            font-weight: 600;
            color: #1e293b;
            line-height: 1.25;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 3px;
        }
        .sticker-meta {
            font-size: 0.68rem;
            color: #64748b;
        }

        /* 2-Column Large Sticker Styles */
        .sticker-grid-a4_2col .sticker-card {
            padding: 12px 14px;
        }
        .sticker-grid-a4_2col .sticker-qr-box {
            width: 95px;
            height: 95px;
        }
        .sticker-grid-a4_2col .sticker-mat-code {
            font-size: 0.95rem;
        }
        .sticker-grid-a4_2col .sticker-mat-name {
            font-size: 0.92rem;
            -webkit-line-clamp: 3;
        }

        /* Thermal Roll Styles */
        .sticker-grid-thermal_single .sticker-card {
            padding: 10px;
            border: 1px solid #94a3b8;
        }

        /* Print Media Styles */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .no-print {
                display: none !important;
            }
            .print-page-wrapper {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }
            @page {
                size: A4 portrait;
                margin: 8mm 6mm;
            }
            .sticker-card {
                border: 0.5px dashed #94a3b8 !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
        }
    </style>
</head>
<body>

<!-- Top Print Control Bar -->
<div class="print-control-bar no-print">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center">
            <a href="dashboard.php" class="btn btn-sm btn-outline-secondary me-3">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าคลัง
            </a>
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-print me-2 text-primary"></i> พิมพ์ QR Code สติกเกอร์
                </h5>
                <span class="small text-muted">
                    รวมทั้งสิ้น <strong><?= number_format($total_labels) ?></strong> ดวง (จาก <?= count($materials) ?> รายการวัสดุ)
                </span>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Selector เปลี่ยนรูปแบบเลย์เอาต์ -->
            <form method="POST" action="" class="d-flex align-items-center gap-2 mb-0">
                <?php foreach ($valid_items as $mid => $qty): ?>
                    <input type="hidden" name="copies[<?= $mid ?>]" value="<?= $qty ?>">
                <?php endforeach; ?>
                <select name="layout_type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="a4_3col" <?= $layout_type === 'a4_3col' ? 'selected' : '' ?>>A4 (3 คอลัมน์ x 7 แถว)</option>
                    <option value="a4_2col" <?= $layout_type === 'a4_2col' ? 'selected' : '' ?>>A4 ขนาดใหญ่ (2 คอลัมน์ x 5 แถว)</option>
                    <option value="thermal_single" <?= $layout_type === 'thermal_single' ? 'selected' : '' ?>>ม้วน Thermal Label (5x3 cm)</option>
                </select>
            </form>

            <button type="button" class="btn btn-primary btn-sm px-3 fw-semibold" onclick="window.print()">
                <i class="fa-solid fa-print me-1"></i> สั่งพิมพ์ทันที (Print)
            </button>
        </div>
    </div>
</div>

<div class="container-fluid py-3">
    <?php if (empty($label_list)): ?>
        <div class="text-center py-5">
            <i class="fa-solid fa-qrcode fa-4x text-muted opacity-50 mb-3"></i>
            <h4 class="fw-bold text-muted">ไม่มีรายการสติกเกอร์ที่เลือกพิมพ์</h4>
            <p class="text-muted">กรุณากลับไปที่หน้าคลังใหญ่ และเลือกรายการพัสดุที่ต้องการพิมพ์ QR Code</p>
            <a href="dashboard.php" class="btn btn-primary rounded-3">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับไปเลือกพัสดุ
            </a>
        </div>
    <?php else: ?>
        <div class="print-page-wrapper">
            <div class="sticker-grid-<?= htmlspecialchars($layout_type) ?>">
                <?php foreach ($label_list as $index => $label): ?>
                    <div class="sticker-card">
                        <!-- Sticker Header -->
                        <div class="sticker-header">
                            <img src="<?= BASE_URL . '/' . ltrim(h($hospital_logo), '/') ?>" alt="Logo" class="sticker-logo">
                            <span class="sticker-hospital-name"><?= h($hospital_name) ?></span>
                        </div>

                        <!-- Sticker Body -->
                        <div class="sticker-body">
                            <div class="sticker-qr-box">
                                <div id="qrcode_<?= $index ?>" class="d-flex align-items-center justify-content-center"></div>
                            </div>
                            <div class="sticker-info">
                                <div class="sticker-mat-code code-font"><?= h($label['material_code']) ?></div>
                                <div class="sticker-mat-name" title="<?= h($label['material_name']) ?>"><?= h($label['material_name']) ?></div>
                                <div class="sticker-meta">
                                    <span><?= h($label['category']) ?></span> | 
                                    <span><?= h($label['unit_unit']) ?></span>
                                    <?php if ($label['total_copies'] > 1): ?>
                                        <span class="badge bg-light text-muted border float-end"><?= $label['copy_index'] ?>/<?= $label['total_copies'] ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Render QR Codes -->
<script>
    const labels = <?= json_encode($label_list, JSON_UNESCAPED_UNICODE) ?>;
    const isLarge = <?= $layout_type === 'a4_2col' ? 'true' : 'false' ?>;
    const qrSize = isLarge ? 88 : 70;

    labels.forEach((label, idx) => {
        const container = document.getElementById(`qrcode_${idx}`);
        if (container) {
            new QRCode(container, {
                text: label.qr_payload,
                width: qrSize,
                height: qrSize,
                colorDark: "#000000",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });
        }
    });

    // Auto open print dialog if requested
    <?php if (isset($_GET['autoprint']) && $_GET['autoprint'] === '1'): ?>
        window.addEventListener('load', () => {
            setTimeout(() => { window.print(); }, 600);
        });
    <?php endif; ?>
</script>

</body>
</html>
