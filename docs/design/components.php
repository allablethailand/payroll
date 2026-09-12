<?php
/**
 * Phase Design Round 2, item 2 (docs/design/rules.md §13 row 2). One page showing every shared
 * component built so far, side by side, for a visual sanity check -- NOT a real app page (no
 * Router entry, no session/layout dependency) and dev-only per this round's own instruction.
 *
 * Dev-only gate: this file sits next to index.php's own document root (docs/ is NOT under public/,
 * see .htaccess's RewriteBase) so it IS network-reachable by direct path unless the web server
 * blocks it -- gated here on the request's own REMOTE_ADDR being the server's loopback address,
 * not on $_ENV['APP_ENV'] (checked first: this environment's real .env has APP_ENV=production on
 * what is clearly a local dev box, so that value can't be trusted here -- a network-level check is
 * the one that can't be silently misconfigured the same way). Deliberately has NO dependency on
 * config.php/vendor/autoload.php/the app's session bootstrap at all -- a standalone tool, not a
 * page routed through app/core/Controller.php.
 */
$__remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($__remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Design component preview -- dev only (localhost access required).\n";
    exit;
}
// 2026-09-13, item 5 -- stat-card.php's own 'badge' field now calls the shared statusBadge()
// helper internally (app/helpers/helpers.php), which this standalone page needs explicitly since it
// deliberately skips the app's normal bootstrap (see docblock above) -- helpers.php itself has no
// dependency on config.php/BASE_URL/a session, safe to require in isolation like this.
require_once __DIR__ . '/../../app/helpers/helpers.php';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Design Components -- Phase Design Round 2</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<link href="../../node_modules/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../../node_modules/@fortawesome/fontawesome-free/css/all.min.css" rel="stylesheet">
<!-- 2026-09-12, follow-up fix -- input.js (loaded further down) wires up select2/datepicker/
     intl-tel-input unconditionally on $(document).ready(); their own CSS is included here so
     anything it touches at least LOOKS right, matching layout/header.php's own load list. -->
<!-- 2026-09-12, real bug found: this link was missing entirely -- only the bs5 skin's own .js was
     loaded, never its .css. Without it, DataTables' own .dt-search/.dt-length internal markup
     (label + input/select as separate elements) has no display:inline-block/margin-left rule to
     sit them side by side at all, so the browser's own default block flow stacked them vertically
     (search label above the input, "Show"/length-select/"entries" each on their own line) -- exactly
     matching the reported symptom. Same file layout/header.php itself loads. -->
<link href="../../node_modules/datatables.net-bs5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="../../node_modules/select2/dist/css/select2.min.css" rel="stylesheet">
<link href="../../node_modules/select2-bootstrap-5-theme/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link rel="stylesheet" href="../../node_modules/bootstrap-datepicker/dist/css/bootstrap-datepicker.standalone.min.css">
<link rel="stylesheet" href="../../node_modules/intl-tel-input/dist/css/intlTelInput.min.css">
<link rel="stylesheet" href="../../public/css/style.css">
<style>
    /* Page chrome for THIS preview tool only -- not a shared component, not counted against §12
       lint (this file itself is exempt the same way tokens.css/vendor are, see rules.md §12's own
       "app/views/**, public/js/**, public/css/**" scan scope -- docs/design/ isn't in it at all). */
    body { padding: var(--sp-6) var(--sp-5); background: var(--c-bg-subtle); color: var(--c-text); }
    .cp-section { background: var(--c-bg); border: 1px solid var(--c-border); border-radius: var(--radius); padding: var(--sp-5); margin-bottom: var(--sp-6); }
    .cp-section h2 { font-size: var(--fs-lg); margin-bottom: var(--sp-2); }
    .cp-section .cp-section-note { color: var(--c-text-muted); font-size: var(--fs-sm); margin-bottom: var(--sp-4); }
    .cp-row { display: flex; flex-wrap: wrap; gap: var(--sp-4); align-items: flex-start; }
    .cp-swatch-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: var(--sp-3); }
    .cp-swatch { border: 1px solid var(--c-border); border-radius: var(--radius); overflow: hidden; }
    .cp-swatch-color { height: 48px; }
    .cp-swatch-label { padding: var(--sp-2) var(--sp-3); font-size: var(--fs-xs); font-family: monospace; background: var(--c-bg); }
    .cp-swatch-label .cp-swatch-name { font-weight: 700; color: var(--c-text); display: block; }
    .cp-swatch-label .cp-swatch-values { color: var(--c-text-muted); }
    .cp-empty { border: 1px dashed var(--c-border-strong); border-radius: var(--radius); padding: var(--sp-5); color: var(--c-text-faint); text-align: center; }
    .cp-theme-toggle { position: sticky; top: var(--sp-3); z-index: 10; }
</style>
</head>
<body>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 style="font-size: var(--fs-xl);">Design Components</h1>
        <p class="cp-section-note mb-0">Phase Design Round 2 -- ตัวอย่างทุก shared component ในหน้าเดียว (dev only). อ้าง § ตาม docs/design/rules.md เสมอ -- ดู comment ในโค้ดของแต่ละ section.</p>
    </div>
    <!-- ปุ่มสลับ Light/Dark/System (ตามที่ระบุ) -- ใช้ data-bs-theme attribute เดียวกับที่ app.js's
         applyTheme() ใช้จริง (ไม่ใช่กลไกแยก): 'light'/'dark' ตั้ง attribute ตรงๆ, 'system' ลบ attribute
         ทิ้งเพื่อให้ tokens.css's @media (prefers-color-scheme:dark) เป็นคนตัดสินเอง -- 3 สถานะเดียวกับที่
         layout/header.php สร้างไว้ฝั่ง server จริง (ดู rules.md §1's "3-state model" comment
         ใน style.css). เก็บค่าไว้ใน localStorage เฉพาะของหน้านี้เอง (ไม่ผ่าน UserPreferenceController --
         หน้านี้ไม่ใช่ผู้ใช้จริง ไม่ต้อง persist ข้ามอุปกรณ์). -->
    <div class="btn-group cp-theme-toggle" role="group" aria-label="Theme toggle">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-cp-theme="light"><i class="fa-solid fa-sun me-1"></i>Light</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-cp-theme="dark"><i class="fa-solid fa-moon me-1"></i>Dark</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-cp-theme="system"><i class="fa-solid fa-circle-half-stroke me-1"></i>System</button>
    </div>
</div>

<!-- ==================== Tokens (§1) ==================== -->
<div class="cp-section">
    <h2>Tokens (§1)</h2>
    <p class="cp-section-note">Swatch ใช้ <code>var(--c-*)</code> จริง -- สีจะเปลี่ยนตามปุ่มสลับ theme ด้านบนจริง (ไม่ใช่ mockup) ป้ายใต้ swatch โชว์ทั้ง 2 theme เป็นข้อความ (light / dark) เพราะหน้าเดียวแสดง 2 theme พร้อมกันจริงไม่ได้ -- ค่าที่ใช้จริงต้องเป็น token เดียวกันนี้เท่านั้น ห้าม hardcode hex ที่อื่น (ดู §12.1).</p>
    <div class="cp-swatch-grid">
        <?php
        // ค่า light/dark คัดลอกมาจาก tokens.css เอง (ไม่ใช่แหล่งที่สอง -- หน้านี้แค่แสดงเป็นข้อความประกอบ
        // swatch สีจริงที่ render ข้างบนอ่านจาก var(--c-*) ของ tokens.css ตรงๆ อยู่แล้ว).
        $tokens = [
            ['--c-primary', '#FF9900', '#FF9900 (คงเดิม)'],
            ['--c-primary-hover', '#E68A00', '#FFAD33'],
            ['--c-primary-soft', '#FFF4E0', '#3A2A10'],
            ['--c-text', '#1F2328', '#E6E8EB'],
            ['--c-text-muted', '#6B7280', '#9AA3AE'],
            ['--c-text-faint', '#9CA3AF', '#6B7480'],
            ['--c-border', '#E5E7EB', '#2A2F36'],
            ['--c-border-strong', '#D1D5DB', '#3A414A'],
            ['--c-bg', '#FFFFFF', '#15181C'],
            ['--c-bg-subtle', '#F7F8FA', '#1C2026'],
            ['--c-bg-hover', '#F1F3F5', '#232830'],
            ['--c-danger', '#D92D20', '#F97066'],
            ['--c-danger-soft', '#FEE4E2', '#3B1A18'],
            ['--c-warning', '#B54708', '#F5B14C'],
            ['--c-warning-soft', '#FEF0C7', '#3A2A12'],
            ['--c-success', '#067647', '#4ADE80'],
            ['--c-success-soft', '#DCFAE6', '#12301E'],
            ['--c-info', '#6B7280', '#9AA3AE'],
            ['--c-info-soft', '#F1F3F5', '#232830'],
        ];
        foreach ($tokens as [$name, $light, $dark]):
        ?>
        <div class="cp-swatch">
            <div class="cp-swatch-color" style="background: var(<?=htmlspecialchars($name)?>);"></div>
            <div class="cp-swatch-label">
                <span class="cp-swatch-name"><?=htmlspecialchars($name)?></span>
                <span class="cp-swatch-values">light <?=htmlspecialchars($light)?> / dark <?=htmlspecialchars($dark)?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ==================== ปุ่ม (§4) ==================== -->
<div class="cp-section">
    <h2>ปุ่ม (§4)</h2>
    <p class="cp-section-note">4 ระดับ + disabled/loading -- <code>.btn-outline-brand</code> ไม่แสดงแยกที่นี่ เพราะเป็น alias ชั่วคราวของ <code>.btn-outline-secondary</code> เป๊ะ (ดู §1) หน้าตาเหมือนกันทุกสถานะ.</p>
    <div class="cp-row">
        <button type="button" class="btn btn-primary">Primary</button>
        <button type="button" class="btn btn-primary" disabled>Primary (disabled)</button>
        <button type="button" class="btn btn-primary" disabled><span class="spinner-border spinner-border-sm me-1"></span>Primary (loading)</button>
    </div>
    <div class="cp-row mt-3">
        <button type="button" class="btn btn-outline-secondary">Secondary</button>
        <button type="button" class="btn btn-outline-secondary" disabled>Secondary (disabled)</button>
        <button type="button" class="btn btn-outline-secondary" disabled><span class="spinner-border spinner-border-sm me-1"></span>Secondary (loading)</button>
    </div>
    <div class="cp-row mt-3">
        <button type="button" class="btn btn-link">Tertiary</button>
        <button type="button" class="btn btn-link" disabled>Tertiary (disabled)</button>
    </div>
    <div class="cp-row mt-3">
        <button type="button" class="btn btn-danger">Danger</button>
        <button type="button" class="btn btn-danger" disabled>Danger (disabled)</button>
        <button type="button" class="btn btn-danger" disabled><span class="spinner-border spinner-border-sm me-1"></span>Danger (loading)</button>
    </div>
</div>

<!-- ==================== Tabs (§6) ==================== -->
<div class="cp-section">
    <h2>Tabs (§6)</h2>
    <p class="cp-section-note">ไม่มีไอคอน ไม่มี chevron -- tab ที่เลือกใช้เส้นใต้ 2px <code>--c-primary</code> ตัวหนังสือ <code>--c-text</code>, ที่เหลือ <code>--c-text-muted</code>.</p>
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#cp-tab-1" type="button">ภาพรวม</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#cp-tab-2" type="button">รายละเอียด</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#cp-tab-3" type="button">รออนุมัติ (3)</button></li>
    </ul>
    <div class="tab-content pt-3">
        <div class="tab-pane fade show active" id="cp-tab-1">เนื้อหาแท็บ "ภาพรวม"</div>
        <div class="tab-pane fade" id="cp-tab-2">เนื้อหาแท็บ "รายละเอียด"</div>
        <div class="tab-pane fade" id="cp-tab-3">เนื้อหาแท็บ "รออนุมัติ" -- ตัวเลขในวงเล็บเป็นตัวหนังสือเทาธรรมดา ไม่ใช่ badge สี.</div>
    </div>
</div>

<!-- ==================== Status Tabs (§6, item 4b) ==================== -->
<?php
// Shared mock data for both status-tabs demos on this page (the individual section below AND the
// full-page mockup further down) -- ONE source array so both renders stay consistent with each
// other. 'direction' => 'back' marks the 3 "ย้อนกลับ" (exception) steps -- rejected/need_info/
// cancelled -- per rules.md §6's own decision, always last in the array. `cancelled`'s own tone is
// deliberately 'neutral' (NOT danger) -- per the same decision, its count > 0 should never demand
// attention as a colored idle pill (nothing left to act on once a run is cancelled), but IF you
// click directly into it, it still needs to read as a serious/final color -- initStatusTabs() itself
// falls back to danger for an ACTIVE back-direction tab whose own tone is neutral/success, so
// clicking "ยกเลิก" below still shows red despite this tone value. `cancelled`'s count is set to 4
// (not 0) specifically so this demo actually PROVES the idle-pill exception, not just trivially
// shows gray because there's nothing to show.
// 2026-09-13, item 5 -- tone/direction are no longer hand-typed here at all: each key's own entry is
// looked up from app/config/status_map.php's 'payroll_process_tab' context via statusMapEntry()
// (app/helpers/helpers.php), so this demo can never drift out of sync with the shared map. Only the
// Thai LABEL text stays hand-typed -- status-tabs.php's own $tabs shape has no data-i18n mechanism
// for labels yet (unchanged from the prior round, out of scope for item 5).
$cpProcessStatusTabLabels = [
    'pending_sync' => 'รอดึงข้อมูล',
    'draft' => 'ฉบับร่าง',
    'pending_approval' => 'รออนุมัติ',
    'approved' => 'อนุมัติแล้ว',
    'paid' => 'จ่ายแล้ว',
    'locked' => 'ปิดรอบ',
    'rejected' => 'ถูกปฏิเสธ',
    'need_info' => 'ขอข้อมูลเพิ่ม',
    'cancelled' => 'ยกเลิก',
];
$cpProcessStatusTabs = [];
foreach ($cpProcessStatusTabLabels as $cpKey => $cpLabel) {
    $cpEntry = statusMapEntry($cpKey, 'payroll_process_tab');
    $cpProcessStatusTabs[] = [
        'key' => $cpKey,
        'label' => $cpLabel,
        'tone' => $cpEntry['tone'] ?? 'neutral',
        'direction' => $cpEntry['direction'] ?? 'forward',
        'active' => $cpKey === 'draft',
    ];
}
?>
<div class="cp-section">
    <h2>Status Tabs (§6, item 4b) — ตัดสินใจแล้ว: chevron</h2>
    <p class="cp-section-note"><code>app/views/partials/status-tabs.php</code> + JS <code>initStatusTabs()</code> -- คง chevron pipeline เดิม (<code>.station-row</code>/<code>.station-card</code>) ไว้ตามที่ approve แล้ว รอบนี้แค่รวม markup ที่ซ้ำกัน 2 ไฟล์ + แทน hex ด้วย token เท่านั้น. เคยมี variant ที่สอง (<code>path</code>, ไม่มีพื้นสี) ให้เทียบคู่กัน -- <b>ตัดสินใจแล้วเลือก chevron</b>, ลบ <code>path</code> ออกจาก partial/JS/CSS ทั้งหมดแล้ว ไม่เหลือ dead code. กลุ่ม "ย้อนกลับ" (ถูกปฏิเสธ/ขอข้อมูลเพิ่ม/ยกเลิก) แยกเป็นกลุ่มที่ 2 ท้ายแถว เว้นช่อง <code>--sp-4</code> จากกลุ่มเดินหน้า และลูกศรชี้กลับ -- ทั้งหมดมาจาก <code>direction: 'back'</code> ต่อ tab ไม่ hardcode ในตัว view. **ตอนนี้ tone/direction ของทุก tab มาจาก <code>statusMapEntry($enum, 'payroll_process_tab')</code> จริง (ข้อ 5)** ไม่ใช่ค่าที่พิมพ์เองในไฟล์นี้อีกแล้ว. สังเกต <b>"รออนุมัติ"/"ขอข้อมูลเพิ่ม"</b> (tone warning, ไม่ถูกเลือก, จำนวน &gt; 0) เป็น badge เตือน, <b>"ถูกปฏิเสธ"</b> (ย้อนกลับ, ไม่ถูกเลือก, จำนวน &gt; 0) เป็น badge แดง, ส่วน <b>"ยกเลิก"</b> (ย้อนกลับ, tone neutral, จำนวน = 4 &gt; 0) <b>ยังคงเป็นข้อความเทาธรรมดา</b> -- ไม่มีอะไรต้องทำต่อแม้จำนวนจะไม่ใช่ 0 -- <b>ลองคลิก "ถูกปฏิเสธ" แล้ว "ขอข้อมูลเพิ่ม" แล้วก็ "ยกเลิก"</b> ดูสี: ถูกปฏิเสธ/ยกเลิก = แดงทั้งการ์ด, ขอข้อมูลเพิ่ม = ส้ม/เหลืองอำพันทั้งการ์ด แทนส้มปกติ ตัวหนังสือขาวเสมอ (สลับ theme มุมขวาบนเพื่อดู dark mode ด้วย).</p>
    <?php
    $id = 'cpStatusTabsChevron';
    $tabs = $cpProcessStatusTabs;
    include __DIR__ . '/../../app/views/partials/status-tabs.php';
    ?>
</div>

<!-- ==================== Payroll Process, full page ==================== -->
<div class="cp-section">
    <h2>ภาพรวมทั้งหน้า Payroll Process</h2>
    <p class="cp-section-note">ไม่ใช่ component โดดๆ -- วางเรียงตามหน้าจริง (page-header → Tabs → Status Tabs (+ filter-bar toolbar ต่อท้ายแถวเดียวกัน) → ตาราง). สังเกต 2 จุด: <b>(1)</b> "รอบปกติ"/"รอบพิเศษ / Incentive" (Tabs ทั่วไป) ตัวที่เลือกตัวหนังสือ <code>--c-text</code> (ไม่ใช่ส้ม) เส้นใต้ <code>--c-primary</code> เท่านั้น -- เจอบั๊กจริงระหว่างตรวจ: มี CSS rule เก่าค้างอยู่ (`!important`) ที่ทำให้ตัวหนังสือ tab ที่เลือกเป็นส้มมาตลอดทั้งแอป ไม่ใช่แค่หน้านี้ ลบออกแล้ว. <b>(2)</b> ปุ่ม "ตัวกรอง (N)" + chips + "ล้าง" อยู่ขวาสุดของแถว Status Tabs แถวเดียวกัน (ผ่าน <code>initFilterBar()</code>'s <code>toolbarTarget</code> option) -- ลองกด "ตัวกรอง" เพื่อกางแผงใต้ pipeline, ลองเลือกค่าดู chips โผล่ในแถวเดิม.</p>
    <?php
    $title = 'ประมวลผลเงินเดือน';
    $breadcrumb = [
        ['label' => 'หน้าหลัก', 'href' => '#'],
        ['label' => 'ประมวลผลเงินเดือน', 'href' => null],
    ];
    $secondary_actions = [
        ['label' => 'ดึงข้อมูลจาก Origami', 'id' => 'cpFullSyncBtn', 'icon' => 'fa-solid fa-rotate'],
    ];
    $primary_action = ['label' => 'สร้างรอบใหม่', 'id' => 'cpFullNewRunBtn', 'icon' => 'fa-solid fa-plus'];
    $description = null;
    include __DIR__ . '/../../app/views/partials/page-header.php';
    ?>
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" type="button">รอบปกติ</button></li>
        <li class="nav-item"><button class="nav-link" type="button">รอบพิเศษ / Incentive</button></li>
    </ul>
    <?php
    $id = 'cpFullStatusTabs';
    $tabs = $cpProcessStatusTabs;
    include __DIR__ . '/../../app/views/partials/status-tabs.php';
    ?>
    <?php
    ob_start();
    ?>
    <div class="row g-3">
        <div class="col-sm-3">
            <label class="form-label small mb-1">รอบการจ่าย</label>
            <select class="form-select form-select-sm select2-native" id="cpFullFilterCycle">
                <option value="all">ทั้งหมด</option>
                <option value="1">รอบที่ 1 (1-15)</option>
                <option value="2">รอบที่ 2 (16-31)</option>
            </select>
        </div>
        <div class="col-sm-3">
            <label class="form-label small mb-1">ประเภทการจ่าย</label>
            <select class="form-select form-select-sm select2-native" id="cpFullFilterPurpose">
                <option value="all">ทั้งหมด</option>
                <option value="payroll">เงินเดือนปกติ</option>
                <option value="incentive">Incentive</option>
            </select>
        </div>
    </div>
    <?php
    $filter_fields_html = ob_get_clean();
    $id = 'cpFullFilterBar';
    $pageKey = null;
    include __DIR__ . '/../../app/views/partials/filter-bar.php';
    ?>
    <table id="cpFullTable" class="table table-sm table-hover w-100 mt-3">
        <thead>
            <tr>
                <th class="col-avatar">รอบ</th>
                <th>ชื่อรอบ</th>
                <th class="col-date">งวด</th>
                <th class="num col-money">ยอดสุทธิ</th>
                <th>สถานะ</th>
                <th class="col-actions">จัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php for ($cpFull = 1; $cpFull <= 6; $cpFull++): ?>
            <tr>
                <td class="col-avatar">R<?=$cpFull?></td>
                <td>รอบเงินเดือน กันยายน #<?=$cpFull?></td>
                <td class="col-date" data-order="2026-09-<?=str_pad((string)$cpFull, 2, '0', STR_PAD_LEFT)?>"><?=str_pad((string)$cpFull, 2, '0', STR_PAD_LEFT)?>/09/2026</td>
                <td class="num col-money" data-order="<?=$cpFull * 125000?>"><?=number_format($cpFull * 125000, 2)?></td>
                <td><span class="badge bg-secondary-subtle text-secondary">ฉบับร่าง</span></td>
                <td class="col-actions">
                    <button type="button" class="btn btn-icon btn-sm" title="ดู"><i class="fa-solid fa-eye"></i></button>
                </td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>
</div>

<!-- ==================== Form control (§9) ==================== -->
<div class="cp-section">
    <h2>Form control (§9)</h2>
    <p class="cp-section-note">Focus ring ส้ม (§3) คลิกเข้าช่องด้านล่างเพื่อดูจริง (:focus ทำ mockup ไม่ได้) -- ช่องที่ 3 คือ invalid state. ช่อง "Select" ใช้ <code>class="select2-native"</code> จริง (ไม่ใช่ <code>&lt;select&gt;</code> เปล่า) -- app.js's own <code>$(document).ready()</code> auto-init ให้ (<code>initSelect2('.select2-native', {mode:'native'})</code>, ไม่ต้องเขียน init เพิ่มในหน้านี้) โหมด native อ่าน <code>&lt;option&gt;</code> ที่มีอยู่แล้วตรงๆ ไม่ต้อง ajax/i18n key เหมาะกับ demo ที่ไม่มี session/backend จริง.</p>
    <div class="cp-row">
        <div>
            <label class="form-label">ปกติ</label>
            <input type="text" class="form-control" placeholder="พิมพ์เพื่อดู focus ring">
        </div>
        <div>
            <label class="form-label">Select</label>
            <select class="form-select select2-native">
                <option>ตัวเลือก 1</option>
                <option>ตัวเลือก 2</option>
            </select>
        </div>
        <div>
            <label class="form-label">Invalid</label>
            <input type="text" class="form-control is-invalid" value="ค่าที่ผิด">
            <div class="invalid-feedback">กรุณากรอกให้ถูกต้อง</div>
        </div>
    </div>
</div>

<!-- ==================== Dropdown (§1/§3) ==================== -->
<div class="cp-section">
    <h2>Dropdown (§1/§3)</h2>
    <p class="cp-section-note">รายการที่เลือก (active) ต้องไม่ใช่ฟ้า (§3) -- ตัวอย่าง "รายการ B" ด้านล่าง.</p>
    <div class="dropdown">
        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">เลือกรายการ</button>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="#">รายการ A</a></li>
            <li><a class="dropdown-item active" href="#">รายการ B (active)</a></li>
            <li><a class="dropdown-item" href="#">รายการ C</a></li>
        </ul>
    </div>
</div>

<!-- ==================== Toggle ใช่/ไม่ใช่ (§9) ==================== -->
<div class="cp-section">
    <h2>Toggle ใช่/ไม่ใช่ (§9)</h2>
    <p class="cp-section-note"><code>.btn-group</code> ของ <code>.btn-outline-secondary.btn-sm</code> -- ตัวที่เลือกพื้น <code>--c-bg-subtle</code> ขอบ <code>--c-border-strong</code> ไม่ใช้ส้ม.</p>
    <div class="btn-group" role="group">
        <input type="radio" class="btn-check" name="cp-toggle-yn" id="cp-toggle-yes" autocomplete="off" checked>
        <label class="btn btn-outline-secondary btn-sm" for="cp-toggle-yes">ใช่</label>
        <input type="radio" class="btn-check" name="cp-toggle-yn" id="cp-toggle-no" autocomplete="off">
        <label class="btn btn-outline-secondary btn-sm" for="cp-toggle-no">ไม่ใช่</label>
    </div>
</div>

<!-- ==================== DataTable (§7) ==================== -->
<div class="cp-section">
    <h2>DataTable (§7)</h2>
    <p class="cp-section-note">30 แถว mock ครบทุกชนิดคอลัมน์ตามตาราง §7: checkbox / avatar / ข้อความ / วันที่
        (<code>.col-date</code>) / เงิน (<code>.num.col-money</code>) / ตัวเลข (<code>.num</code>) / สถานะ (badge
        ธรรมดา -- 3 ค่านี้เป็น mock ที่ไม่มีใน <code>status_map.php</code> จริง จึงยังไม่ผ่าน <code>statusBadgeHtml()</code>
        ในตารางนี้โดยเจตนา ดู section "Badge / สถานะ (ข้อ 5)" ด้านล่างสำหรับตัวอย่างที่ผ่าน <code>statusBadgeHtml()</code> จริงทุก context) / action (<code>.col-actions</code>).
        เรียกผ่าน <code>initSharedDataTable(selector, { stickyColumns:{left:2,right:1}, columnFilters:{...},
        export:{onSelect}, dtOptions:{} })</code> -- ไม่ต้องเขียน <code>drawCallback</code>/<code>initComplete</code>/
        <code>initExcelColumnFilters()</code> เองอีกเลย ทุกอย่างมาจาก class บน <code>&lt;th&gt;</code> + option 3 ตัวนี้
        ล้วนๆ (ลองลากตารางแนวนอน, ลองกดตัวกรองที่หัวคอลัมน์ "สถานะ", ลองกด "Export" ด้านบนขวา).</p>
    <table id="cpDemoTable" class="table table-sm table-hover w-100">
        <thead>
            <tr>
                <th class="col-check"><input type="checkbox" disabled></th>
                <th class="col-avatar">พนักงาน</th>
                <th>ชื่อ-นามสกุล</th>
                <th class="col-date">วันที่เริ่มงาน</th>
                <th class="num col-money">เงินเดือน</th>
                <th class="num">% ผลงาน</th>
                <th>สถานะ</th>
                <th class="col-actions">จัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $cpStatuses = [
                ['label' => 'ทำงานอยู่', 'tone' => 'success'],
                ['label' => 'รอตรวจสอบ', 'tone' => 'warning'],
                ['label' => 'ลาออก', 'tone' => 'danger'],
            ];
            for ($i = 1; $i <= 30; $i++):
                $status = $cpStatuses[$i % 3];
                $salary = 18000 + ($i * 733);
                $pct = ($i * 7) % 100;
                $day = str_pad((string)(($i % 28) + 1), 2, '0', STR_PAD_LEFT);
                $iso = sprintf('2026-%02d-%s', ($i % 12) + 1, $day);
                $dmy = sprintf('%s/%02d/2026', $day, ($i % 12) + 1);
            ?>
            <tr>
                <td class="col-check"><input type="checkbox"></td>
                <td class="col-avatar"><span class="rounded-circle bg-secondary-subtle d-inline-flex align-items-center justify-content-center" style="width:32px;height:32px;font-size:.75rem;">E<?=$i?></span></td>
                <td>พนักงานตัวอย่าง <?=$i?></td>
                <td class="col-date" data-order="<?=$iso?>"><?=$dmy?></td>
                <td class="num col-money" data-order="<?=$salary?>"><?=number_format($salary, 2)?></td>
                <td class="num"><?=$pct?>.0</td>
                <td><span class="badge bg-<?=$status['tone']?>-subtle text-<?=$status['tone']?>"><?=$status['label']?></span></td>
                <td class="col-actions">
                    <button type="button" class="btn btn-icon btn-sm" title="ดู"><i class="fa-solid fa-eye"></i></button>
                    <button type="button" class="btn btn-icon btn-sm" title="ลบ"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>
</div>

<!-- ==================== Page Header (§2) ==================== -->
<div class="cp-section">
    <h2>Page Header (§2)</h2>
    <p class="cp-section-note"><code>app/views/partials/page-header.php</code> -- ไม่มี card ครอบ ไม่มีไอคอนหน้า ไม่มีพื้นหลังสี. ตัวอย่างด้านล่าง include ไฟล์จริง (ไม่ใช่ mockup) ตาม page header ของหน้า Employee List จริง: <code>secondary_actions</code> 2 ตัว ("ซิงค์จาก Origami"/"นำเข้า Excel" -- action ระดับหน้า ไม่ใช่ bulk) อยู่ซ้ายของ primary "เพิ่มพนักงาน".</p>
    <?php
    $title = 'พนักงาน';
    $breadcrumb = [
        ['label' => 'หน้าหลัก', 'href' => '#'],
        ['label' => 'พนักงาน', 'href' => null],
    ];
    $secondary_actions = [
        ['label' => 'ซิงค์จาก Origami', 'id' => 'cpPhSyncBtn', 'icon' => 'fa-solid fa-rotate'],
        ['label' => 'นำเข้า Excel', 'id' => 'cpPhImportBtn', 'icon' => 'fa-solid fa-file-import'],
    ];
    $primary_action = ['label' => 'เพิ่มพนักงาน', 'id' => 'cpPhDemoBtn', 'icon' => 'fa-solid fa-plus'];
    $description = 'รายชื่อพนักงานทั้งหมดในบริษัท พร้อมตัวกรองและการนำเข้า/ส่งออกข้อมูล';
    include __DIR__ . '/../../app/views/partials/page-header.php';
    ?>
</div>

<!-- ==================== Stat Card (§2) ==================== -->
<?php
// Deliberately covers every combination the partial's own footer slot supports: no footer at all,
// sub-only, badge+link together, and badge-only with a danger tone -- proving the fixed-height
// bottom slot lines up across all 4 regardless of what's actually inside it.
// 'badge' is now {enum, context} (item 5) -- stat-card.php itself calls statusBadge() with these,
// forcing every badge shown here through the real app/config/status_map.php, not a hand-typed label.
$cpStats = [
    ['label' => 'พนักงานทั้งหมด', 'value' => '128', 'icon' => 'fa-solid fa-users', 'sub' => null, 'badge' => null, 'link' => null],
    ['label' => 'เงินเดือนรวม (บาท)', 'value' => number_format(2456000, 2), 'icon' => 'fa-solid fa-sack-dollar', 'sub' => 'เดือนนี้', 'badge' => null, 'link' => null],
    ['label' => 'รออนุมัติ', 'value' => '3', 'icon' => 'fa-solid fa-hourglass-half', 'sub' => null, 'badge' => ['enum' => 'pending_approval', 'context' => 'run_state'], 'link' => ['label' => 'ดูทั้งหมด', 'href' => '#']],
    ['label' => 'ค้างนาน (ถูกปฏิเสธ)', 'value' => '2', 'icon' => 'fa-solid fa-triangle-exclamation', 'sub' => null, 'badge' => ['enum' => 'rejected', 'context' => 'run_state'], 'link' => null],
];
?>
<div class="cp-section">
    <h2>Stat Card (§2) — ตัดสินใจแล้ว (แก้กลับ): ไอคอนมุมขวาบน</h2>
    <p class="cp-section-note"><code>app/views/partials/stat-card.php</code>, class <code>.stat</code> -- พื้นขาว ขอบเทา ไม่มีสีพื้น/ขอบสี (ตรงข้าม <code>.stat-card</code> เดิมทุกประการ) แต่**อนุญาตไอคอน (optional) 1 ตัว/การ์ด** ที่มุมขวาบน ขนาด 20px สี <code>--c-text-faint</code> ไม่มีวงกลม/พื้นของตัวเอง, label เทาเล็กซ้ายบน, ตัวเลข <code>--fs-xl</code> ตัวหนา -- เคยตัดสินใจเลือกแบบไอคอนวงกลมซ้าย 40px ไปรอบก่อน **ตอนนี้แก้กลับมาเป็นแบบนี้แทน**, ลบ CSS/markup ของแบบวงกลมออกจาก partial/CSS/components.php ทั้งหมดแล้วรอบที่ 2 ไม่เหลือ dead code. แถวเดียว การ์ดเท่ากันเสมอ (caller ครอบ <code>.row.g-3</code> เอง, Bootstrap row เองยืด column เท่ากันให้อยู่แล้ว) โดยมี slot ล่างคงที่สำหรับ sub/badge/link -- สังเกตการ์ด <b>"พนักงานทั้งหมด"</b> (ไม่มี footer เลย) กับ <b>"รออนุมัติ"</b> (badge+link พร้อมกัน) สูงเท่ากันเป๊ะทั้งที่เนื้อหาต่างกันมาก เพราะ slot ล่างเว้นพื้นที่ไว้เท่ากันเสมอ (ใช้ <code>margin-top:auto</code> ดันลงขอบล่าง). <code>badge</code> เป็น <code>{enum, context}</code> จริง (ข้อ 5) -- การ์ด "รออนุมัติ" ส่ง <code>{enum:'pending_approval', context:'run_state'}</code>, การ์ด "ค้างนาน" ส่ง <code>{enum:'rejected', context:'run_state'}</code> -- <code>stat-card.php</code> เรียก PHP <code>statusBadge()</code> เองข้างใน ไม่มีทางส่ง label/สีดิบเข้ามาแทนได้อีกแล้ว -- **การ์ดทั้งใบไม่เปลี่ยนสี** แม้แต่การ์ด "ค้างนาน" ที่ badge เป็น tone danger (สลับ theme มุมขวาบนดู dark mode ด้วย).</p>
    <div class="row g-3">
        <?php foreach ($cpStats as $stat): ?>
        <div class="col-md-3">
            <?php include __DIR__ . '/../../app/views/partials/stat-card.php'; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="cp-section-note mt-3">ไม่ส่ง <code>icon</code>: ไม่เว้นที่มุมขวาบนไว้ -- label ยังชิดซ้ายปกติเหมือนการ์ดทั่วไป (เทียบกับการ์ดที่มีไอคอนมุมขวา ให้เห็นว่าไม่มีช่องว่างเหลืออยู่ฝั่งขวา).</p>
    <div class="row g-3">
        <div class="col-md-3">
            <?php $stat = ['label' => 'สาขาทั้งหมด', 'value' => '4', 'icon' => null, 'sub' => null, 'badge' => null, 'link' => null]; include __DIR__ . '/../../app/views/partials/stat-card.php'; ?>
        </div>
        <div class="col-md-3">
            <?php $stat = ['label' => 'แผนกทั้งหมด', 'value' => '9', 'icon' => 'fa-solid fa-sitemap', 'sub' => null, 'badge' => null, 'link' => null]; include __DIR__ . '/../../app/views/partials/stat-card.php'; ?>
        </div>
    </div>
</div>

<!-- ==================== Filter Bar (§6) ==================== -->
<div class="cp-section">
    <h2>Filter Bar (§6)</h2>
    <p class="cp-section-note"><code>app/views/partials/filter-bar.php</code> + JS <code>initFilterBar()</code> -- 6 ช่องตามหน้า Employee List จริง (ดู/สถานะ/แผนก/ทีม/ตำแหน่ง/สาขา) <code>col-sm-2</code> เท่ากันทุกช่อง เหมือน <code>.station-filter</code> เดิม, ไม่มีไอคอนหน้า label. ตั้งค่าเริ่มต้นเป็น "สถานะ=ทำงานอยู่" + "แผนก=ไอที" (N=2, ยุบ, เห็น chips) ไว้แล้วให้ทดสอบครบ 3 สถานะได้ทันที: <b>(1) ยุบ N=2 มี chips</b> (สถานะเริ่มต้นตอนนี้), <b>(2) กาง</b> (กดปุ่ม "ตัวกรอง"), <b>(3) ยุบ N=0</b> (กด × ที่ chip ทั้ง 2 หรือกด "ล้าง"). สถานะกาง/ยุบจำไว้ต่อ reload ผ่าน <code>pageKey</code> ที่ตั้งไว้ (ลอง reload หน้านี้หลังกางดู). ทุกช่องเป็น <code>select2-native</code> จริง (เหมือน Employee List จริงทุกช่องเป็น Select2) -- <code>initFilterBar()</code> อ่าน/ล้างค่าผ่าน <code>.val()</code>/<code>.val(x).trigger('change')</code> บน <code>&lt;select&gt;</code> เดิมที่ Select2 ครอบอยู่ ซึ่งคือ Select2 v4's เอง official API สำหรับตั้งค่าแบบ programmatic (v4 ไม่มี <code>.select2('val')</code> แยกต่างหากแบบ v3 แล้ว) -- ลอง "ล้าง"/กด × ที่ chip แล้วดู Select2 dropdown ที่ถูกครอบเปลี่ยนค่าตามจริง ไม่ใช่แค่ underlying select.</p>
    <?php
    ob_start();
    ?>
    <div class="row g-3">
        <div class="col-sm-2">
            <label class="form-label small mb-1">มุมมอง</label>
            <select class="form-select form-select-sm select2-native" id="cpFilterView">
                <option value="all">ทั้งหมด</option>
                <option value="active_only">เฉพาะที่ทำงานอยู่</option>
            </select>
        </div>
        <div class="col-sm-2">
            <label class="form-label small mb-1">สถานะ</label>
            <select class="form-select form-select-sm select2-native" id="cpFilterStatus">
                <option value="all">ทั้งหมด</option>
                <option value="active" selected>ทำงานอยู่</option>
                <option value="resigned">ลาออก</option>
            </select>
        </div>
        <div class="col-sm-2">
            <label class="form-label small mb-1">แผนก</label>
            <select class="form-select form-select-sm select2-native" id="cpFilterDept">
                <option value="all">ทั้งหมด</option>
                <option value="1">บัญชี</option>
                <option value="3" selected>ไอที</option>
                <option value="2">ขาย</option>
            </select>
        </div>
        <div class="col-sm-2">
            <label class="form-label small mb-1">ทีม</label>
            <select class="form-select form-select-sm select2-native" id="cpFilterTeam">
                <option value="all">ทั้งหมด</option>
                <option value="1">ทีม A</option>
                <option value="2">ทีม B</option>
            </select>
        </div>
        <div class="col-sm-2">
            <label class="form-label small mb-1">ตำแหน่ง</label>
            <select class="form-select form-select-sm select2-native" id="cpFilterPosition">
                <option value="all">ทั้งหมด</option>
                <option value="1">เจ้าหน้าที่</option>
                <option value="2">หัวหน้างาน</option>
            </select>
        </div>
        <div class="col-sm-2">
            <label class="form-label small mb-1">สาขา</label>
            <select class="form-select form-select-sm select2-native" id="cpFilterBranch">
                <option value="all">ทั้งหมด</option>
                <option value="1">สำนักงานใหญ่</option>
                <option value="2">สาขาเชียงใหม่</option>
            </select>
        </div>
    </div>
    <?php
    $filter_fields_html = ob_get_clean();
    $id = 'cpFilterBarDemo';
    $pageKey = 'components-demo';
    include __DIR__ . '/../../app/views/partials/filter-bar.php';
    ?>
</div>

<div class="cp-section">
    <h2>Badge / สถานะ (ข้อ 5)</h2>
    <p class="cp-section-note"><code>app/config/status_map.php</code> (data เดียวที่มา, ที่เดียวจริงๆ) + PHP <code>statusBadge($enum, $context)</code> (<code>app/helpers/helpers.php</code>) + JS <code>statusBadgeHtml(enum, context)</code> (<code>app.js</code>). <code>layout/header.php</code> (จุดเดียวกับที่ inject <code>BASE_URL</code>/<code>LANG_VERSION</code> อยู่แล้ว, ยกเว้นจากกฎ "ห้ามแตะหน้าจริง" เฉพาะบรรทัดนี้) ใส่ <code>window.STATUS_MAP = &lt;?=json_encode(loadStatusMap())?&gt;;</code> จาก PHP ตรงๆ ทุกหน้า -- <code>app.js</code> อ่านจาก <code>window.STATUS_MAP</code> เท่านั้น (ไม่มี copy ของตัวเองแล้ว ไม่มีความเสี่ยงเรื่อง drift อีกต่อไป) หน้านี้เองก็ใส่บรรทัดเดียวกันจาก <code>loadStatusMap()</code> จริงที่ require ไว้ตอนต้นไฟล์. ทุก context/enum ด้านล่าง render จริงผ่าน <code>statusBadgeHtml()</code> (ไม่ใช่ hardcode) -- enum ที่ไม่มีใน map จะเห็น badge เทา + label ดิบ + <code>console.warn()</code> (ลองเปิด console ดู "unmapped_demo" ท้ายสุด). <code>tone</code> ของ <code>run_state.approved</code> เป็น <code>warning</code> (ไม่ใช่ success) เพราะ "อนุมัติแล้ว" สำหรับคนทำเงินเดือนคือ "ต้องไปจ่ายต่อ" -- คนละความหมายกับ <code>approval_status.approved</code> ที่เป็น success (คำขอจบแล้ว) ตั้งใจให้ต่างกัน ไม่ใช่ bug. <code>data_source</code> ไม่ใช่สถานะจริง (§5) ใส่ไว้ชั่วคราวเป็น neutral ทั้งหมดเพื่อไม่พังตอน migrate รอบ 4.</p>
    <div id="cpBadgeShowcase"></div>
</div>

<div class="cp-section">
    <h2>Stepper (ข้อ 6)</h2>
    <p class="cp-section-note"><code>status-stepper.php</code> + <code>renderStatusStepper()</code> (§6)</p>
    <div class="cp-empty">ยังไม่ทำ -- รอข้อ 6</div>
</div>

<div class="cp-section">
    <h2>ตัวเลข/เงิน + Feedback (ข้อ 7)</h2>
    <p class="cp-section-note">PHP <code>fmtMoney()</code> / JS <code>fmtNum()</code> + <code>initMoneyInputs()</code> (§8); <code>showConfirm()</code> object form, <code>isFormDirty()</code>/<code>confirmIfDirtyThen()</code> wired app-wide, <code>showSuccess</code>/<code>showError</code> wording (§9/§10)</p>
    <div class="cp-empty">ยังไม่ทำ -- รอข้อ 7</div>
</div>

<script src="../../node_modules/jquery/dist/jquery.min.js"></script>
<script src="../../node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../node_modules/datatables.net/js/dataTables.min.js"></script>
<script src="../../node_modules/datatables.net-bs5/js/dataTables.bootstrap5.min.js"></script>
<!-- 2026-09-12, follow-up fix: a real browser check of this page surfaced 2 console errors
     ("BASE_URL is not defined" at app.js's own loadLang(), "initSelect2Remote is not defined") --
     this page loaded fewer scripts/globals than layout/header.php+footer.php actually set up for
     every real page. Fixed by matching that real chain for every piece app.js (and the 2 helper
     files below it) genuinely reference, in the SAME order:
     - format-helpers.js: escapeHtml()/escapeAttr()/fmtNum() -- app.js's own apvAvatarHtml()/
       apvPersonLineHtml() AND this file's own initFilterBar() (Round 2 item 4, added after this
       comment was first written) both call escapeHtml() directly.
     - input.js: initSelect2()/initSelect2Remote() -- called unconditionally inside app.js's own
       $(document).ready() (the sidebar-setup block), which is exactly the 2nd console error above.
     - BASE_URL/LANG_VERSION/COMPANY_CURRENCY_CODE: the only 3 server-injected globals app.js ITSELF
       references (confirmed by grepping every ALL-CAPS identifier in app.js and cross-checking each
       one against header.php's own <script> block -- SESSION_EMPLOYEE_ID/ORIGAMI_BASE_URL/
       SESSION_IDLE_TIMEOUT_SECONDS/IS_ORIGAMI_HR_LINKED/IS_ORIGAMI_PAYROLL_LINKED/
       QUICK_LINK_CATALOG/QUICK_LINK_SELECTED are real header.php globals too, but only
       session-guard.js/quick-links.js read them -- neither is loaded on this page, so defining
       those would be dead weight, not a real fix). BASE_URL specifically is derived from the
       CURRENT page's own URL rather than hardcoded, so this file stays correct in any environment
       (any host/port), not just this session's own dev server. LANG_VERSION/
       COMPANY_CURRENCY_CODE get harmless static placeholders -- this page never actually needs
       real i18n/currency data for its own demo purpose, they only need to EXIST so nothing throws
       a ReferenceError touching them. -->
<script>
    var BASE_URL = window.location.origin + window.location.pathname.replace(/\/docs\/design\/components\.php$/, '');
    var LANG_VERSION = { th: 1, en: 1 };
    var COMPANY_CURRENCY_CODE = 'THB';
    // Same single line layout/header.php now injects on every real page (§5) -- from the REAL
    // app/config/status_map.php via the same loadStatusMap() this page already required at the top,
    // not a hand-typed copy. Must run before app.js loads below (app.js's own top-level `const
    // STATUS_MAP = ...` reads window.STATUS_MAP at parse time).
    window.STATUS_MAP = <?=json_encode(loadStatusMap())?>;
</script>
<script src="../../public/js/format-helpers.js"></script>
<script src="../../public/js/input.js"></script>
<script src="../../public/js/table-column-filter.js"></script>
<script src="../../public/js/sticky-table-columns.js"></script>
<!-- Loads the REAL app.js (not a copy) so initSharedDataTable()/initFilterBar() below are always
     byte-identical to production, zero drift risk -- deliberately NOT reimplemented/copy-pasted
     here. app.js still does a few things on $(document).ready() this standalone page has no real
     answer for (an i18n fetch against a /api/lang.get this page isn't logged into, login-timezone
     recording, theme/font-size sync reading a real user session) -- those specific bits fail/no-op
     quietly (rejected fetch promises, not thrown synchronously into anything else's path) since
     nothing on this page calls into them; every DOM-dependent initializer elsewhere in app.js
     already guards on `$('#selector').length` per this project's own established convention, so
     nothing here throws just because the normal navbar/sidebar/etc. markup doesn't exist.
     initSharedDataTable()/initFilterBar() themselves have no dependency on any of that -- confirmed
     by reading both functions' own bodies. -->
<script src="../../public/js/app.js"></script>
<script>
$(function () {
    initSharedDataTable('#cpDemoTable', {
        searchThreshold: 0,
        stickyColumns: { left: 2, right: 1 },
        columnFilters: {
            mode: 'client',
            columns: [{ index: 6, key: 'status' }],
        },
        export: {
            onSelect: function (format) {
                alert('Export ' + format + ' -- demo only, no backend call. A real caller would trigger its own report-generation endpoint here.');
            },
        },
    });
    initFilterBar('#cpFilterBarDemo', {
        onChange: function () {
            console.log('[filter-bar demo] onChange fired -- a real caller would reload its own table here.');
        },
    });
    // Mock counts -- shared by both status-tabs demos below (the individual section + the full-page
    // mockup) so both renders show the exact same story. "รออนุมัติ"/"ขอข้อมูลเพิ่ม" (both count>0,
    // tone warning) demonstrate the idle badge firing on a FORWARD step too, not just a back-direction
    // one (direction only changes the ACTIVE-state color rule). `cancelled: 4` is deliberately NOT 0
    // -- its own tone is 'neutral', so even with a real count sitting there, the idle pill must stay
    // plain gray (nothing left to act on once cancelled) -- proving the exception actually works, not
    // just trivially gray because the count happened to be 0.
    const cpStatusCounts = {
        pending_sync: 3, draft: 12, pending_approval: 5, approved: 8, paid: 20,
        locked: 15, rejected: 2, need_info: 1, cancelled: 4,
    };
    function cpWireStatusTabs(selector) {
        const inst = initStatusTabs(selector, {
            onChange: function (key) {
                console.log('[status-tabs demo] onChange fired for key=' + key + ' on ' + selector + ' -- a real caller would re-filter its own table here.');
            },
        });
        inst.update(cpStatusCounts);
        return inst;
    }
    cpWireStatusTabs('#cpStatusTabsChevron');
    cpWireStatusTabs('#cpFullStatusTabs');

    // Full-page mockup -- same initFilterBar()/initSharedDataTable() calls a real page would make,
    // just against the mockup's own scoped ids so they don't collide with the dedicated Filter Bar/
    // DataTable sections above. `toolbarTarget` relocates the filter-bar's own toggle/chips/clear
    // row into the status-tabs row above it (flush right, per explicit instruction) -- the
    // collapsible field panel itself stays put, right under the pipeline.
    initFilterBar('#cpFullFilterBar', { toolbarTarget: '#cpFullStatusTabs', onChange: function () {} });
    initSharedDataTable('#cpFullTable', { searchThreshold: 0 });

    // Badge (ข้อ 5) -- builds itself directly off the REAL STATUS_MAP (app.js), one row per context,
    // one statusBadgeHtml() call per enum value -- so this showcase can never go stale/hand-typed out
    // of sync with the actual map; adding a context/enum to STATUS_MAP later shows up here for free.
    // One extra, deliberately UNMAPPED context/enum pair at the end demonstrates the missing-entry
    // fallback (gray badge + raw label + a console.warn()) -- open devtools to see the warning fire.
    const $cpBadgeShowcase = $('#cpBadgeShowcase');
    Object.keys(STATUS_MAP).sort().forEach(function (context) {
        const $row = $(
            '<div class="mb-3">' +
                '<div class="fw-semibold small text-uppercase text-muted mb-1"></div>' +
                '<div class="d-flex flex-wrap gap-2 align-items-center"></div>' +
            '</div>'
        );
        $row.find('.text-uppercase').text(context);
        const $chips = $row.find('.d-flex');
        Object.keys(STATUS_MAP[context]).forEach(function (enumValue) {
            const $chip = $('<span class="d-inline-flex align-items-center gap-1 border rounded-2 px-2 py-1"></span>');
            $chip.append($('<code class="small text-muted"></code>').text(enumValue));
            $chip.append(statusBadgeHtml(enumValue, context));
            $chips.append($chip);
        });
        $cpBadgeShowcase.append($row);
    });
    const $cpUnmappedRow = $(
        '<div class="mb-3">' +
            '<div class="fw-semibold small text-uppercase text-muted mb-1">(unmapped -- demonstrates the missing-entry fallback)</div>' +
            '<div class="d-flex flex-wrap gap-2 align-items-center"></div>' +
        '</div>'
    );
    const $cpUnmappedChip = $('<span class="d-inline-flex align-items-center gap-1 border rounded-2 px-2 py-1"></span>');
    $cpUnmappedChip.append($('<code class="small text-muted"></code>').text('unmapped_demo'));
    $cpUnmappedChip.append(statusBadgeHtml('unmapped_demo', 'run_state'));
    $cpUnmappedRow.find('.d-flex').append($cpUnmappedChip);
    $cpBadgeShowcase.append($cpUnmappedRow);
});
</script>
<script>
// Self-contained theme toggle for this preview page only -- same 3-state semantics/attribute
// layout/header.php stamps server-side ('light'/'dark' set data-bs-theme; 'system' removes it so
// tokens.css's own @media (prefers-color-scheme:dark) decides), NOT a call into app.js's
// applyTheme() (this page never loads app.js -- see file header docblock for why).
(function () {
    var KEY = 'cp_theme_preview';
    function apply(theme) {
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-bs-theme', 'dark');
        } else if (theme === 'light') {
            document.documentElement.setAttribute('data-bs-theme', 'light');
        } else {
            document.documentElement.removeAttribute('data-bs-theme');
        }
        document.querySelectorAll('[data-cp-theme]').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-cp-theme') === theme);
        });
        try { localStorage.setItem(KEY, theme); } catch (e) {}
    }
    document.querySelectorAll('[data-cp-theme]').forEach(function (btn) {
        btn.addEventListener('click', function () { apply(btn.getAttribute('data-cp-theme')); });
    });
    var saved = 'system';
    try { saved = localStorage.getItem(KEY) || 'system'; } catch (e) {}
    apply(saved);
})();
</script>
</body>
</html>
