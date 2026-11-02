<?php
/**
 * สแกน QR Code ตรวจรับ/อนุมัติใบขอเบิกพัสดุ (Scan QR Requisition Approval)
 * สำหรับ Super Admin / เจ้าหน้าที่คลังกลาง โรงพยาบาลมหาสารคาม
 */
require_once __DIR__ . '/../config.php';
checkAuth('super_admin');

// AJAX Endpoint: ดึงข้อมูลใบขอเบิกพัสดุ
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_requisition') {
    header('Content-Type: application/json; charset=utf-8');
    $req_no = trim($_GET['req_no'] ?? '');

    if (empty($req_no)) {
        echo json_encode(['success' => false, 'message' => 'ไม่ได้รับรหัสใบขอเบิก']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT r.*, u.unit_name, u.unit_code, usr.full_name AS requester_name
        FROM requisitions r
        JOIN units u ON r.unit_id = u.unit_id
        JOIN users usr ON r.user_id = usr.user_id
        WHERE r.requisition_no = ? OR r.requisition_id = ?
        LIMIT 1
    ");
    $stmt->execute([$req_no, is_numeric($req_no) ? (int)$req_no : 0]);
    $req = $stmt->fetch();

    if (!$req) {
        echo json_encode(['success' => false, 'message' => "ไม่พบใบขอเบิกรหัส [{$req_no}] ในระบบ"]);
        exit;
    }

    // ดึงรายการพัสดุในใบเบิก พร้อมสต็อกคลังกลางปัจจุบัน
    $items_stmt = $pdo->prepare("
        SELECT ri.*, m.material_code, m.material_name, m.unit_unit, m.category,
               COALESCE(us.quantity, 0) AS central_stock
        FROM requisition_items ri
        JOIN materials m ON ri.material_id = m.material_id
        LEFT JOIN unit_stocks us ON m.material_id = us.material_id AND us.unit_id = 1
        WHERE ri.requisition_id = ?
    ");
    $items_stmt->execute([$req['requisition_id']]);
    $items = $items_stmt->fetchAll();

    echo json_encode([
        'success'     => true,
        'requisition' => $req,
        'items'       => $items
    ]);
    exit;
}

// ดึงรายการใบขอเบิกที่ค้างรออนุมัติ (Status: pending) สำหรับแสดงปุ่มลัด
$pending_reqs = $pdo->query("
    SELECT r.*, u.unit_name, u.unit_code, COUNT(ri.item_id) AS total_items
    FROM requisitions r
    JOIN units u ON r.unit_id = u.unit_id
    LEFT JOIN requisition_items ri ON r.requisition_id = ri.requisition_id
    WHERE r.status = 'pending'
    GROUP BY r.requisition_id
    ORDER BY r.requisition_id DESC
")->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สแกน QR อนุมัติใบเบิกพัสดุ | โรงพยาบาลมหาสารคาม</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <!-- HTML5 QR Code Library -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --mhk-primary: #00796b;
            --mhk-primary-dark: #004d40;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f1f5f9;
        }
        h1, h2, h3, h4, h5, .nav-link, .btn {
            font-family: 'Prompt', sans-serif;
        }
        .navbar-mhk {
            background: linear-gradient(135deg, var(--mhk-primary-dark) 0%, var(--mhk-primary) 100%);
            box-shadow: 0 4px 15px rgba(0, 77, 64, 0.15);
        }
        .scanner-card {
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
        }
        #reader {
            width: 100%;
            border-radius: 14px;
            overflow: hidden;
            border: 2px dashed #00796b;
            background: #000;
        }
        .result-card {
            background: #ffffff;
            border-radius: 20px;
            border: 2px solid #00796b;
            box-shadow: 0 12px 28px rgba(0, 121, 107, 0.12);
            display: none;
            animation: fadeIn 0.3s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Mobile Bottom Navigation Bar (สำหรับโทรศัพท์มือถือ Super Admin) */
        .mobile-bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 64px;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.08);
            z-index: 1040;
            display: none;
            justify-content: space-around;
            align-items: center;
            padding: 0 4px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .bottom-nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 500;
            padding: 6px 2px;
            position: relative;
            background: none;
            border: none;
            outline: none;
            transition: color 0.15s ease;
        }
        .bottom-nav-item:hover, .bottom-nav-item:active, .bottom-nav-item.active {
            color: var(--mhk-primary);
        }
        .bottom-nav-item i {
            font-size: 1.25rem;
            margin-bottom: 2px;
        }
        .bottom-nav-scan-btn {
            position: relative;
            top: -12px;
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #ffffff !important;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 18px rgba(245, 158, 11, 0.45);
            border: 3px solid #ffffff;
            transition: transform 0.15s ease;
            text-decoration: none;
        }
        .bottom-nav-scan-btn:active {
            transform: scale(0.92);
        }
        .bottom-nav-scan-btn i {
            font-size: 1.45rem;
            margin-bottom: 0;
        }

        @media (max-width: 991px) {
            .mobile-bottom-nav {
                display: flex !important;
            }
            body {
                padding-bottom: 78px !important;
            }
            .navbar-mhk .navbar-brand {
                font-size: 0.92rem;
            }
        }
    </style>
</head>
<body>

<?php renderLoadingScreen(); ?>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-mhk sticky-top py-2">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <span class="bg-white text-dark rounded-circle p-2 me-2 d-inline-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                <i class="fa-solid fa-hospital text-teal fa-lg" style="color: var(--mhk-primary);"></i>
            </span>
            <div>
                <span class="fw-bold d-block lh-1 fs-6 fs-md-5">โรงพยาบาลมหาสารคาม</span>
                <span class="small opacity-75 fs-7">ระบบสแกน QR Code อนุมัติจ่ายพัสดุคลังกลาง</span>
            </div>
        </a>

        <div class="d-flex align-items-center gap-2">
            <a href="stock_in_out.php" class="btn btn-sm btn-outline-light rounded-3">
                <i class="fa-solid fa-barcode me-1"></i> รับ-จ่ายบาร์โค้ด
            </a>
            <a href="subunits_stock.php" class="btn btn-sm btn-outline-light rounded-3">
                <i class="fa-solid fa-hospital-user me-1"></i> สต็อกคลังย่อย
            </a>
            <a href="reports.php" class="btn btn-sm btn-outline-light rounded-3">
                <i class="fa-solid fa-chart-pie me-1"></i> รายงาน
            </a>
            <a href="dashboard.php" class="btn btn-sm btn-light text-dark fw-semibold rounded-3">
                <i class="fa-solid fa-warehouse me-1"></i> แดชบอร์ด
            </a>
        </div>
    </div>
</nav>

<!-- Mobile Bottom Navigation Bar (สำหรับโทรศัพท์มือถือ Super Admin) -->
<nav class="mobile-bottom-nav d-lg-none">
    <!-- 1. คลังกลาง -->
    <a href="dashboard.php" class="bottom-nav-item">
        <i class="fa-solid fa-warehouse"></i>
        <span>คลังกลาง</span>
    </a>

    <!-- 2. พิมพ์รหัสค้นหา -->
    <button type="button" class="bottom-nav-item" onclick="document.getElementById('manualReqInput').focus()">
        <i class="fa-solid fa-keyboard"></i>
        <span>พิมพ์เลขที่</span>
    </button>

    <!-- 3. เปิด/รีเซ็ตกล้อง (ปุ่มลอยตรงกลาง) -->
    <button type="button" class="bottom-nav-scan-btn shadow" onclick="startScanner()" title="สแกนกล้องใหม่">
        <i class="fa-solid fa-camera"></i>
    </button>

    <!-- 4. ปิดกล้อง -->
    <button type="button" class="bottom-nav-item text-secondary" onclick="stopScanner()">
        <i class="fa-solid fa-video-slash"></i>
        <span>ปิดกล้อง</span>
    </button>

    <!-- 5. ใบขอเบิกค้างจ่าย -->
    <a href="dashboard.php#requisitions_section" class="bottom-nav-item text-primary">
        <i class="fa-solid fa-file-invoice"></i>
        <span>ใบขอเบิก</span>
    </a>
</nav>

<div class="container py-4" style="max-width: 950px;">

    <!-- Flash Message -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> me-2"></i>
            <?= h($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Scanner Section -->
    <div class="card scanner-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="fa-solid fa-qrcode text-success me-2"></i> สแกน QR Code ใบขอเบิกพัสดุ
                </h3>
                <p class="text-muted small mb-0">
                    เมื่อเจ้าหน้าที่หน่วยงานนำเอกสารใบขอเบิกหรือแสดง QR Code มาขอรับของที่คลังกลาง
                </p>
            </div>
        </div>

        <div class="row g-4 align-items-center mb-3">
            <div class="col-md-6 text-center">
                <div id="reader"></div>
                <div class="mt-2">
                    <button class="btn btn-sm btn-outline-success rounded-3" onclick="startScanner()">
                        <i class="fa-solid fa-camera me-1"></i> เปิดกล้อง
                    </button>
                    <button class="btn btn-sm btn-outline-secondary rounded-3" onclick="stopScanner()">
                        <i class="fa-solid fa-video-slash me-1"></i> ปิดกล้อง
                    </button>
                </div>
            </div>

            <div class="col-md-6">
                <!-- Search by Code -->
                <div class="bg-light p-3 rounded-4 border mb-3">
                    <label class="form-label fw-bold small text-dark mb-1">
                        <i class="fa-solid fa-barcode me-1 text-primary"></i> หรือกรอกเลขที่ใบขอเบิก / ยิงบาร์โค้ด:
                    </label>
                    <div class="input-group">
                        <input type="text" id="manualReqInput" class="form-control rounded-start-3 font-monospace" placeholder="เช่น REQ-202610-0001">
                        <button class="btn btn-success rounded-end-3 px-3" onclick="processManualReq()">
                            <i class="fa-solid fa-magnifying-glass me-1"></i> ค้นหา
                        </button>
                    </div>
                </div>

                <!-- Quick Buttons for Pending Requisitions -->
                <div>
                    <label class="form-label fw-semibold small text-muted mb-2">
                        <i class="fa-solid fa-clock-rotate-left text-warning me-1"></i> ใบขอเบิกที่กำลังรออนุมัติจ่าย (คลิกเพื่อทดสอบดึงข้อมูล):
                    </label>
                    <?php if (empty($pending_reqs)): ?>
                        <div class="alert alert-light border small text-muted py-2 mb-0">ไม่มีใบขอเบิกค้างรออนุมัติในขณะนี้</div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($pending_reqs as $pr): ?>
                                <button type="button" class="btn btn-outline-dark text-start p-2 rounded-3" 
                                        onclick="lookupRequisition('<?= h($pr['requisition_no']) ?>')">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong class="font-monospace text-primary"><?= h($pr['requisition_no']) ?></strong>
                                        <span class="badge bg-warning text-dark">รออนุมัติ</span>
                                    </div>
                                    <div class="small text-muted mt-1">
                                        หน่วยงาน: <strong><?= h($pr['unit_name']) ?></strong> (<?= $pr['total_items'] ?> รายการ)
                                    </div>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Scanned Requisition Result & Approval Table -->
    <div class="card result-card p-4 mb-4" id="requisitionResultBox">
        <div class="d-flex flex-wrap align-items-start justify-content-between border-bottom pb-3 mb-3 gap-2">
            <div>
                <span class="badge bg-primary font-monospace fs-6" id="resReqNo"></span>
                <span class="badge bg-warning text-dark ms-2" id="resReqStatus"></span>
                <h4 class="fw-bold text-dark mt-2 mb-0">
                    หน่วยงานผู้ขอเบิก: <span id="resReqUnitName" class="text-teal" style="color: var(--mhk-primary);"></span>
                </h4>
                <div class="text-muted small mt-1">
                    ผู้ทำรายการ: <strong id="resReqUser"></strong> &nbsp;|&nbsp; 
                    วันที่ขอ: <span id="resReqDate"></span>
                </div>
                <div class="small text-secondary mt-1" id="resReqNote"></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle" onclick="closeResultBox()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Approval Form (POST to borrow_action.php) -->
        <form method="POST" action="<?= BASE_URL ?>/unit/borrow_action.php" id="approveForm">
            <input type="hidden" name="action" value="approve_requisition">
            <input type="hidden" name="requisition_id" id="formRequisitionId">
            <input type="hidden" name="redirect_to" value="admin/scan_approve.php">

            <div class="table-responsive mb-4">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 120px;">รหัสพัสดุ</th>
                            <th>รายการพัสดุ / เวชภัณฑ์</th>
                            <th class="text-center" style="width: 140px;">สต็อกคลังกลาง</th>
                            <th class="text-center" style="width: 140px;">จำนวนที่ขอเบิก</th>
                            <th class="text-center" style="width: 160px;">จำนวนที่จ่ายจริง</th>
                        </tr>
                    </thead>
                    <tbody id="requisitionItemsBody">
                        <!-- Populated dynamically via JS -->
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <a id="btnPdfLink" href="#" target="_blank" class="btn btn-outline-danger rounded-3">
                    <i class="fa-solid fa-file-pdf me-1"></i> พิมพ์ใบเบิกนี้ (PDF)
                </a>

                <div class="d-flex gap-2" id="actionButtonsArea">
                    <button type="button" class="btn btn-outline-danger rounded-3 px-3" onclick="rejectThisRequisition()">
                        <i class="fa-solid fa-ban me-1"></i> ไม่อนุมัติ
                    </button>
                    <button type="submit" class="btn btn-success btn-lg rounded-3 px-4 shadow-sm" id="btnApproveSubmit">
                        <i class="fa-solid fa-circle-check me-2"></i> อนุมัติและตัดจ่ายพัสดุเข้าหน่วยงาน
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    let html5QrCode = null;

    function startScanner() {
        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode("reader");
        }
        const config = { fps: 10, qrbox: { width: 250, height: 250 } };

        html5QrCode.start(
            { facingMode: "environment" },
            config,
            (decodedText) => {
                lookupRequisition(decodedText);
            },
            (error) => {}
        ).catch(err => {
            console.error(err);
        });
    }

    function stopScanner() {
        if (html5QrCode && html5QrCode.isScanning) {
            html5QrCode.stop();
        }
    }

    function processManualReq() {
        const reqNo = document.getElementById('manualReqInput').value.trim();
        if (!reqNo) {
            Swal.fire('กรุณากรอกเลขที่ใบขอเบิก', '', 'warning');
            return;
        }
        lookupRequisition(reqNo);
    }

    function lookupRequisition(reqNo) {
        Swal.showLoading();

        fetch(`scan_approve.php?ajax=get_requisition&req_no=${encodeURIComponent(reqNo)}`)
            .then(res => res.json())
            .then(data => {
                Swal.close();
                if (!data.success) {
                    Swal.fire('ไม่พบใบขอเบิก', data.message, 'error');
                    return;
                }
                renderRequisition(data);
            })
            .catch(err => {
                Swal.close();
                Swal.fire('ข้อผิดพลาด', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
            });
    }

    function renderRequisition(data) {
        const r = data.requisition;
        const items = data.items;

        document.getElementById('resReqNo').innerText = r.requisition_no;
        document.getElementById('resReqUnitName').innerText = `${r.unit_name} (${r.unit_code})`;
        document.getElementById('resReqUser').innerText = r.requester_name;
        document.getElementById('resReqDate').innerText = r.request_date;
        document.getElementById('resReqNote').innerText = r.note ? `หมายเหตุ: ${r.note}` : '';
        document.getElementById('formRequisitionId').value = r.requisition_id;

        // Status badge
        const statusBadge = document.getElementById('resReqStatus');
        if (r.status === 'pending') {
            statusBadge.className = 'badge bg-warning text-dark ms-2';
            statusBadge.innerText = 'รออนุมัติ / ยังไม่จ่ายของ';
            document.getElementById('actionButtonsArea').style.display = 'flex';
        } else if (r.status === 'approved' || r.status === 'completed') {
            statusBadge.className = 'badge bg-success ms-2';
            statusBadge.innerText = 'อนุมัติและตัดจ่ายเข้าหน่วยแล้ว';
            document.getElementById('actionButtonsArea').style.display = 'none';
        } else {
            statusBadge.className = 'badge bg-danger ms-2';
            statusBadge.innerText = 'ปฏิเสธคำขอ';
            document.getElementById('actionButtonsArea').style.display = 'none';
        }

        // PDF link
        document.getElementById('btnPdfLink').href = `<?= BASE_URL ?>/report_pdf.php?type=requisition&req_id=${r.requisition_id}`;

        // Populate items
        const tbody = document.getElementById('requisitionItemsBody');
        tbody.innerHTML = '';

        let canApprove = true;

        items.forEach(item => {
            const tr = document.createElement('tr');
            const isOutOfStock = parseInt(item.central_stock) < parseInt(item.quantity_requested);

            tr.innerHTML = `
                <td class="font-monospace fw-bold text-center">${item.material_code}</td>
                <td>
                    <div class="fw-semibold text-dark">${item.material_name}</div>
                    <small class="text-muted">${item.category || '-'}</small>
                </td>
                <td class="text-center fw-bold ${item.central_stock > 0 ? 'text-success' : 'text-danger'}">
                    ${item.central_stock} ${item.unit_unit}
                </td>
                <td class="text-center fs-6 fw-bold text-dark">
                    ${item.quantity_requested} ${item.unit_unit}
                </td>
                <td>
                    <input type="number" name="items[${item.item_id}]" class="form-control text-center fw-bold" 
                           value="${item.quantity_requested}" min="0" max="${item.central_stock}" 
                           ${r.status !== 'pending' ? 'readonly' : ''} required>
                    ${isOutOfStock ? '<small class="text-danger d-block text-center mt-1"><i class="fa-solid fa-triangle-exclamation me-1"></i>คลังกลางมีไม่พอ</small>' : ''}
                </td>
            `;
            tbody.appendChild(tr);
        });

        const box = document.getElementById('requisitionResultBox');
        box.style.display = 'block';
        box.scrollIntoView({ behavior: 'smooth' });
    }

    function closeResultBox() {
        document.getElementById('requisitionResultBox').style.display = 'none';
    }

    function rejectThisRequisition() {
        const reqId = document.getElementById('formRequisitionId').value;
        Swal.fire({
            title: 'ระบุเหตุผลที่ไม่อนุมัติ',
            input: 'text',
            inputPlaceholder: 'เช่น สินค้าหมดสต็อกคลังกลาง หรือข้อมูลไม่ครบถ้วน',
            showCancelButton: true,
            confirmButtonText: 'ยืนยันปฏิเสธ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#d33',
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?= BASE_URL ?>/unit/borrow_action.php';

                const aInput = document.createElement('input');
                aInput.type = 'hidden'; aInput.name = 'action'; aInput.value = 'reject_requisition';
                form.appendChild(aInput);

                const idInput = document.createElement('input');
                idInput.type = 'hidden'; idInput.name = 'requisition_id'; idInput.value = reqId;
                form.appendChild(idInput);

                const rInput = document.createElement('input');
                rInput.type = 'hidden'; rInput.name = 'rejection_reason'; rInput.value = result.value || '';
                form.appendChild(rInput);

                const redInput = document.createElement('input');
                redInput.type = 'hidden'; redInput.name = 'redirect_to'; redInput.value = 'admin/scan_approve.php';
                form.appendChild(redInput);

                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    window.addEventListener('load', () => {
        startScanner();
    });
</script>

</body>
</html>
