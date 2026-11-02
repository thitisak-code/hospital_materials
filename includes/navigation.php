<?php
// Shared navigation: presentation only; page-level authorization stays in each page.
$navIsUnit = basename(dirname($_SERVER['SCRIPT_NAME'])) === 'unit';
$navPage = basename($_SERVER['SCRIPT_NAME']);
$navUnitQuery = $navIsUnit && isset($_GET['unit_id']) && $_SESSION['role'] === 'super_admin'
    ? '?unit_id=' . (int)$current_unit_id : '';
$navHome = BASE_URL . ($navIsUnit ? '/unit/dashboard.php' . $navUnitQuery : '/admin/dashboard.php');
$navGroups = $navIsUnit ? [
    'งานพัสดุ' => [
        [$navHome . '#requisitions_tab', 'ใบขอเบิกคลังกลาง'],
        [$navHome . '#usages_tab', 'ประวัติเบิกใช้'],
        [$navHome . '#borrows_tab', 'ยืม–คืนพัสดุ'],
        [BASE_URL . '/unit/scan.php' . $navUnitQuery, 'สแกนบาร์โค้ด / QR'],
    ],
    'รายงาน' => [[BASE_URL . '/report_pdf.php?type=unit&unit_id=' . (int)$current_unit_id, 'รายงานพัสดุในห้อง']],
] : [
    'งานคลัง' => [
        [BASE_URL . '/admin/stock_in_out.php', 'รับเข้า–จ่ายออกบาร์โค้ด'],
        [BASE_URL . '/admin/dashboard.php#requisitions_section', 'ใบขอเบิกพัสดุ'],
        [BASE_URL . '/admin/scan_approve.php', 'สแกน QR อนุมัติ'],
        [BASE_URL . '/admin/subunits_stock.php', 'สต็อกคลังย่อย'],
        [BASE_URL . '/admin/refill.php', 'เติมพัสดุ (Refill)'],
    ],
    'รายงาน' => [
        [BASE_URL . '/admin/reports.php', 'รายงานสถิติ'],
        [BASE_URL . '/report_pdf.php?type=inventory', 'PDF สต็อกคลังกลาง'],
        [BASE_URL . '/report_pdf.php?type=borrows', 'PDF การยืม–คืน'],
    ],
    'จัดการระบบ' => [
        [BASE_URL . '/admin/users.php', 'ผู้ใช้งาน'],
        [BASE_URL . '/admin/units.php', 'หน่วยงาน / แผนก'],
        [BASE_URL . '/admin/categories.php', 'หมวดหมู่พัสดุ'],
        [BASE_URL . '/admin/settings.php', 'ตั้งค่าระบบ'],
    ],
];
?>
<nav class="navbar navbar-expand-lg navbar-dark app-navbar sticky-top no-print" aria-label="เมนูหลัก">
    <div class="container-fluid">
        <a class="navbar-brand app-brand" href="<?= h($navHome) ?>">
            <i class="fa-solid fa-hospital" aria-hidden="true"></i>
            <span>Hospital Materials<small><?= $navIsUnit ? 'คลังประจำหน่วย' : 'คลังพัสดุกลาง' ?></small></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#appNavigation" aria-controls="appNavigation" aria-expanded="false" aria-label="เปิดหรือปิดเมนู">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="appNavigation">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link <?= $navPage === 'dashboard.php' ? 'active' : '' ?>" href="<?= h($navHome) ?>" <?= $navPage === 'dashboard.php' ? 'aria-current="page"' : '' ?>><?= $navIsUnit ? 'พัสดุในห้อง' : 'ภาพรวม' ?></a></li>
                <?php foreach ($navGroups as $navLabel => $navLinks):
                    $navGroupActive = false;
                    foreach ($navLinks as [$navCheckUrl]) {
                        if (!str_contains($navCheckUrl, '#') && basename(parse_url($navCheckUrl, PHP_URL_PATH)) === $navPage) $navGroupActive = true;
                    }
                ?>
                <li class="nav-item dropdown">
                    <button class="nav-link dropdown-toggle <?= $navGroupActive ? 'active' : '' ?>" data-bs-toggle="dropdown" type="button" aria-expanded="false"><?= h($navLabel) ?></button>
                    <ul class="dropdown-menu">
                        <?php foreach ($navLinks as [$navUrl, $navText]):
                            $navActive = !str_contains($navUrl, '#') && basename(parse_url($navUrl, PHP_URL_PATH)) === $navPage;
                        ?>
                        <li><a class="dropdown-item <?= $navActive ? 'active' : '' ?>" href="<?= h($navUrl) ?>" <?= $navActive ? 'aria-current="page"' : '' ?>><?= h($navText) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="dropdown app-account">
                <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="fa-solid fa-user me-1" aria-hidden="true"></i><span><?= h($_SESSION['full_name'] ?? 'บัญชีผู้ใช้') ?></span></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <?php if ($navIsUnit && $_SESSION['role'] === 'super_admin'): ?>
                    <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/dashboard.php">กลับคลังใหญ่</a></li>
                    <?php endif; ?>
                    <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php">ออกจากระบบ</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>
