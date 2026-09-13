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
<!-- Round 2 item 7b -- Feedback section's demo buttons need real SweetAlert2 rendering. -->
<link href="../../node_modules/sweetalert2/dist/sweetalert2.min.css" rel="stylesheet">
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
<!-- 2026-09-13, real bug found and fixed (explicit reports: emp-header-card rendering stacked
     vertically instead of the styled flex row, "initRowToggles is not defined" in the console) --
     confirmed via direct HTTP fetch (bypasses browser cache entirely) that the LIVE style.css/app.js
     on disk were both already correct at the time of both reports -- the actual cause was this page's
     own <link>/<script> tags for every LOCAL project file having no cache-busting query string at
     all, unlike every real page (which gets this for free from `asset()`, app/helpers/helpers.php --
     not usable here directly since it needs BASE_URL, which this standalone page deliberately never
     defines, see the file's own top docblock) -- across a session that edited style.css/app.js
     repeatedly (Round 2 items 1-5), a browser could easily be holding an old cached copy of either
     from several edits back. `assetVersion($path)` alone (the ONE half of that helper with no
     BASE_URL dependency -- just `filemtime()`) is enough on its own to fix this: every local
     link/script tag below now carries a version query string built from a live assetVersion() call,
     exactly mirroring what a real page already gets. Vendor/node_modules files are untouched --
     their own path never changes between edits in this project, so there's nothing to bust.
     NOTE: this comment itself deliberately never spells out the literal PHP short-echo tag syntax
     used below -- CLAUDE.md's own Process rule (added this same session) is about `?>` inside a
     comment prematurely CLOSING PHP mode; writing out a literal opening short-echo tag as prose text
     inside an HTML comment has the mirror-image problem -- PHP does not know this is "inside an HTML
     comment" at all, so it would try to parse whatever followed as real code, exactly what happened
     the first time this comment was drafted (a literal example of the tag syntax here broke this
     file's own `php -l`, caught by that exact check before this was ever committed). -->
<link rel="stylesheet" href="../../public/css/style.css?v=<?=assetVersion('public/css/style.css')?>">
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

<!-- 2026-09-13, explicit request: a missing library (the select2.min.js gap fixed above, found via
     a raw console error) should be visible on-page, not something to re-diagnose from devtools
     every time this dev-only file's own script list drifts out of sync with a real page's again.
     Hidden by default; the very last <script> on this page (after every other library has had its
     one chance to load) fills it in and un-hides it ONLY if something is actually missing. -->
<div id="cpDepBanner" class="alert alert-danger d-none" role="alert"></div>

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
    <p class="cp-section-note">ไม่ใช่ component โดดๆ -- วางเรียงตามหน้าจริง (page-header → Tabs → Status Tabs → filter-bar panel → ตาราง). สังเกต 2 จุด: <b>(1)</b> "รอบปกติ"/"รอบพิเศษ / Incentive" (Tabs ทั่วไป) ตัวที่เลือกตัวหนังสือ <code>--c-text</code> (ไม่ใช่ส้ม) เส้นใต้ <code>--c-primary</code> เท่านั้น -- เจอบั๊กจริงระหว่างตรวจ: มี CSS rule เก่าค้างอยู่ (`!important`) ที่ทำให้ตัวหนังสือ tab ที่เลือกเป็นส้มมาตลอดทั้งแอป ไม่ใช่แค่หน้านี้ ลบออกแล้ว. <b>(2)</b> filter-bar panel วางเต็มความกว้างต่อท้าย Status Tabs ตามลำดับปกติ (option <code>toolbarTarget</code> ที่เคยยัดปุ่มเข้าแถว Status Tabs ถูกยกเลิกแล้วรอบนี้) -- ลองกดวงกลม chevron มุมขวาบนของ panel เพื่อกาง.</p>
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

<!-- ==================== Checkbox / Radio / Switch (§9) ==================== -->
<div class="cp-section">
    <h2>Checkbox / Radio / Switch (§9)</h2>
    <p class="cp-section-note">Bootstrap <code>.form-check</code>/<code>.form-switch</code> ตรงๆ (ไม่สร้าง component ใหม่) แต่ override สีใน <code>style.css</code> เพราะ Bootstrap hardcode <code>#0d6efd</code> ตรงๆ (ไม่ใช่ CSS variable) ทั้งใน <code>.form-check-input:checked</code>/<code>:indeterminate</code> และ SVG ของ switch -- ยืนยันจากอ่าน compiled CSS ตรงๆ ก่อนเขียน override เหมือนที่ <code>.btn-primary</code>/<code>.form-control:focus</code> ทำไว้แล้วในรอบ 1. <b>เลือก/เปิด = ส้ม เป็นข้อยกเว้นที่ระบุไว้ใน §3 ตรงๆ</b> (เหมือน tab ที่เลือก) ไม่ใช่กฎใหม่ที่เพิ่งคิด -- disabled ใช้ <code>--c-text-faint</code> แทน opacity เฉยๆ ให้สอดคล้องกับ element disabled อื่นในแอป. ลอง tab ผ่านฟิลด์ด้านล่างดู focus ring ส้ม, สลับ theme มุมขวาบนดู dark mode.</p>
    <div class="row g-4">
        <div class="col-md-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="cpCheckSingle" checked>
                <label class="form-check-label" for="cpCheckSingle">ส่งอีเมลแจ้งเตือน</label>
            </div>
            <div class="form-text">พนักงานจะได้รับอีเมลทุกครั้งที่มีการอนุมัติ</div>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1 d-block">ประเภทวันลาที่เปิดให้ใช้</label>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="cpCheckGroup1" checked>
                <label class="form-check-label" for="cpCheckGroup1">ลาป่วย</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="cpCheckGroup2">
                <label class="form-check-label" for="cpCheckGroup2">ลากิจ</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="cpCheckGroup3" disabled>
                <label class="form-check-label" for="cpCheckGroup3">ลาพักร้อน (ปิดใช้งาน)</label>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1 d-block">รอบการจ่าย</label>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="cpRadioDemo" id="cpRadio1" checked>
                <label class="form-check-label" for="cpRadio1">รายเดือน</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="cpRadioDemo" id="cpRadio2">
                <label class="form-check-label" for="cpRadio2">รายปักษ์</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="cpRadioDemo" id="cpRadio3" disabled>
                <label class="form-check-label" for="cpRadio3">รายสัปดาห์ (ปิดใช้งาน)</label>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1 d-block">Switch</label>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="cpSwitchOn" checked>
                <label class="form-check-label" for="cpSwitchOn">เปิดใช้งาน</label>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="cpSwitchOff">
                <label class="form-check-label" for="cpSwitchOff">ปิดใช้งาน</label>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="cpSwitchDisabled" checked disabled>
                <label class="form-check-label" for="cpSwitchDisabled">ปิดการแก้ไข (checked+disabled)</label>
            </div>
        </div>
    </div>
</div>

<!-- ==================== DataTable (§7) ==================== -->
<div class="cp-section">
    <h2>DataTable (§7)</h2>
    <p class="cp-section-note">30 แถว mock ครบทุกชนิดคอลัมน์ตามตาราง §7: checkbox / avatar / ข้อความ / วันที่
        (<code>.col-date</code>) / เงิน (<code>.num.col-money</code>) / ตัวเลข (<code>.num</code>) / สถานะ (badge
        ธรรมดา -- 3 ค่านี้เป็น mock ที่ไม่มีใน <code>status_map.php</code> จริง จึงยังไม่ผ่าน <code>statusBadgeHtml()</code>
        ในตารางนี้โดยเจตนา ดู section "Badge / สถานะ (ข้อ 5)" ด้านล่างสำหรับตัวอย่างที่ผ่าน <code>statusBadgeHtml()</code> จริงทุก context) / ใช้งาน (<code>.col-toggle</code>, ใหม่ข้อ (2)) / action (<code>.col-actions</code>).
        เรียกผ่าน <code>initSharedDataTable(selector, { stickyColumns:{left:2,right:1}, columnFilters:{...},
        export:{onSelect}, dtOptions:{} })</code> -- ไม่ต้องเขียน <code>drawCallback</code>/<code>initComplete</code>/
        <code>initExcelColumnFilters()</code> เองอีกเลย ทุกอย่างมาจาก class บน <code>&lt;th&gt;</code> + option 3 ตัวนี้
        ล้วนๆ (ลองลากตารางแนวนอน, ลองกดตัวกรองที่หัวคอลัมน์ "สถานะ", ลองกด "Export" ด้านบนขวา).</p>
    <p class="cp-section-note"><b>checkbox เลือกแถว (ข้อ (2)):</b> checkbox หัวตาราง = "เลือกทั้งหน้า" จริง (เฉพาะแถวที่แสดงอยู่หน้านี้ -- DataTables paging) เข้า indeterminate เองเมื่อเลือกบางแถวไม่ครบ, เคลียร์กลับเป็นว่างเมื่อเปลี่ยนหน้า/sort/ค้นหา (ผูกกับ DataTables' <code>draw</code> event) -- ลองติ๊กบางแถวแล้วดู header, ติ๊กที่ header แล้วดูทั้งหน้าติ๊กตาม.
        <b>switch "ใช้งาน" (ข้อ (2)):</b> <code>initRowToggles($table, {onChange})</code> (<code>app.js</code>, ใหม่) -- ปิด switch ระหว่างรอ response, revert กลับที่เดิมถ้า error -- <b>แถวที่ id=3 จงใจให้ error เสมอ</b> ลองปิด/เปิดแถวนั้นดู switch จะ disable ชั่วครู่แล้ว revert กลับที่เดิมพร้อม toast error ส่วนแถวอื่นจะสำเร็จปกติ.</p>
    <table id="cpDemoTable" class="table table-sm table-hover w-100">
        <thead>
            <tr>
                <th class="col-check"><input type="checkbox" id="cpSelectAllHeader"></th>
                <th class="col-avatar">พนักงาน</th>
                <th>ชื่อ-นามสกุล</th>
                <th class="col-date">วันที่เริ่มงาน</th>
                <th class="num col-money">เงินเดือน</th>
                <th class="num">% ผลงาน</th>
                <th>สถานะ</th>
                <th class="col-toggle">ใช้งาน</th>
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
                <td class="col-toggle">
                    <div class="form-check form-switch form-switch-sm d-inline-block m-0">
                        <input class="form-check-input row-toggle-switch" type="checkbox" role="switch" data-id="<?=$i?>"<?=$i % 5 !== 0 ? ' checked' : ''?>>
                    </div>
                </td>
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
    <p class="cp-section-note"><code>app/views/partials/filter-bar.php</code> + JS <code>initFilterBar()</code> -- 6 ช่องตามหน้า Employee List จริง (ดู/สถานะ/แผนก/ทีม/ตำแหน่ง/สาขา) <code>col-sm-2</code> เท่ากันทุกช่อง เหมือน <code>.station-filter</code> เดิม, ไม่มีไอคอนหน้า label. panel แบ่ง 3 ส่วน: <b>หัว</b> (ป้าย "ตัวกรอง (N)" ซ้าย + วงกลม <code>.btn-icon</code> chevron ขวา -- กดเพื่อกาง/ยุบเท่านั้น), <b>ตัว</b> (grid ฟิลด์ กางเมื่อกดวงกลม), <b>ท้าย</b> (ติดล่างเสมอไม่ว่ากางหรือยุบ -- chips ซ้าย/"ล้างตัวกรอง" ขวา). ตั้งค่าเริ่มต้นเป็น "สถานะ=ทำงานอยู่" + "แผนก=ไอที" (N=2, ยุบ, เห็น chips ที่ท้ายแผง) ไว้แล้วให้ทดสอบครบ 3 สถานะได้ทันที: <b>(1) ยุบ N=2 มี chips "สถานะ: ทำงานอยู่ ×"/"แผนก: ไอที ×"</b> (สถานะเริ่มต้นตอนนี้), <b>(2) กาง</b> (กดวงกลม chevron), <b>(3) ยุบ N=0 เห็นข้อความ "ไม่ได้กรอง" แทน chips</b> (กด × ที่ chip ทั้ง 2 หรือกด "ล้างตัวกรอง"). สถานะกาง/ยุบจำไว้ต่อ reload ผ่าน <code>pageKey</code> ที่ตั้งไว้ (ลอง reload หน้านี้หลังกางดู). ทุกช่องเป็น <code>select2-native</code> จริง (เหมือน Employee List จริงทุกช่องเป็น Select2) -- <code>initFilterBar()</code> อ่าน/ล้างค่าผ่าน <code>.val()</code>/<code>.val(x).trigger('change')</code> บน <code>&lt;select&gt;</code> เดิมที่ Select2 ครอบอยู่ ซึ่งคือ Select2 v4's เอง official API สำหรับตั้งค่าแบบ programmatic (v4 ไม่มี <code>.select2('val')</code> แยกต่างหากแบบ v3 แล้ว) -- ลอง "ล้างตัวกรอง"/กด × ที่ chip แล้วดู Select2 dropdown ที่ถูกครอบเปลี่ยนค่าตามจริง ไม่ใช่แค่ underlying select.</p>
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

<!-- ==================== Notification (§6, item 6d) ==================== -->
<div class="cp-section">
    <h2>Notification (ข้อ 6d) — UI เท่านั้น ยังไม่ต่อ backend</h2>
    <p class="cp-section-note">ของจริงมีอยู่แล้ว (<code>layout/header.php</code>'s bell + <code>public/js/notifications.js</code> + <code>NotificationModel</code>, ต่อ backend จริงครบ) -- <b>ไม่แตะรอบนี้</b> demo นี้คือ target design ใหม่ที่ต่างจากของจริงจริงๆ 3 จุด (ไม่ใช่แค่สีที่ยังไม่ผ่าน token): <b>(1)</b> ไอคอนของจริงมีพื้นสี (<code>.row-type-icon</code>, เหมือนหน้า Report) อันนี้ไม่มีพื้นสี สีที่ตัวไอคอนเอง <b>(2)</b> จุดยังไม่อ่านของจริงอยู่ขวาของ item อันนี้อยู่ซ้าย <b>(3)</b> ป้ายจำนวนของจริงโชว์ "99+" อันนี้โชว์ ">99" -- บันทึกไว้ใน rules.md §6 ให้รอบ 4 ตัดสินใจตอน migrate จริง ไม่ใช่เดาแทนตอนนี้. กระดิ่งเป็น <code>.btn-icon</code> วงกลมเดียวกับ row action (ของจริงเป็น <code>&lt;img&gt;</code> เปล่าไม่มีวงกลม) วางไว้ขวาสุดของ section นี้จำลองตำแหน่งจริงบน top bar (มุมขวาบน) -- dropdown เป็น Bootstrap dropdown จริง (<code>data-bs-toggle="dropdown"</code> + <code>dropdown-menu-end</code>, Popper คุมตำแหน่ง/พลิกด้านเองอัตโนมัติเมื่อชิดขอบจอ ไม่ hardcode ทิศทางเอง) กว้าง 360px สูงสุด 480px ส่วนรายการ scroll เอง หัว/ท้ายอยู่กับที่, badge เริ่มต้น = 3 (ตั้งตรงผ่าน <code>setNotificationCount()</code> ไม่ได้นับจาก list -- คนละ state กับของจริงที่ unread-count มาจาก endpoint แยกจาก dropdown เอง), list มี 5 รายการ (2 ยังไม่อ่าน) ผ่าน <code>renderNotifications()</code> -- กด "ทำเครื่องหมายว่าอ่านแล้วทั้งหมด" แล้วดู badge หาย + จุด/พื้น unread ของทั้ง 5 รายการหายไปด้วย (re-render ผ่าน <code>renderNotifications()</code> เดิม, ไม่ใช่ฟังก์ชันแยก -- เมนูไม่ปิดตอนกดปุ่มนี้เพราะไม่ใช่ <code>.dropdown-item</code> และ Bootstrap ปิด dropdown แค่ตอนคลิกนอกเมนูหรือคลิก <code>.dropdown-item</code>). ทุกสีเป็น token ล้วน ลองสลับ theme มุมขวาบนดูด้วย.</p>
    <div class="d-flex justify-content-end">
        <div class="dropdown d-inline-block">
            <button type="button" class="btn-icon notif-bell-btn" id="cpNotifBellBtn" data-bs-toggle="dropdown" data-bs-display="dynamic" aria-expanded="false" aria-label="Notifications">
                <i class="fa-solid fa-bell"></i>
                <span class="notif-badge d-none" id="cpNotifBadge">0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end notif-dropdown" id="cpNotifDropdown">
                <div class="notif-dropdown-header">
                    <span data-i18n="notifications">การแจ้งเตือน</span>
                    <button type="button" class="btn btn-link btn-sm" id="cpNotifMarkAllBtn" data-i18n="notif_mark_all_read">ทำเครื่องหมายว่าอ่านแล้วทั้งหมด</button>
                </div>
                <div class="notif-list" id="cpNotifList"></div>
                <div class="notif-dropdown-footer">
                    <a href="#" class="btn btn-link btn-sm" data-i18n="notif_view_all">ดูทั้งหมด</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== Empty state (§6, item 6e) ==================== -->
<div class="cp-section">
    <h2>Empty state (ข้อ 6e)</h2>
    <p class="cp-section-note">ใช้ 4 ที่: ตารางว่างทั้งตาราง (ผ่าน <code>initSharedDataTable()</code>'s <code>emptyState</code> option ใหม่), tab/section ว่าง, รายการว่าง (notification/timeline -- ดู <code>renderNotifications()</code> ข้อ 6d เองก็เรียก <code>emptyStateHtml()</code> ตัวนี้เวลา 0 รายการ), ผลค้นหา/กรองไม่พบ -- 2 ความหมายแยกคำกันชัดเจน: <b>"ยังไม่มีข้อมูล"</b> (แนะนำสร้าง) กับ <b>"ไม่พบตามที่กรอง"</b> (แนะนำล้างตัวกรอง) 3 แบบด้านล่าง:</p>
    <div class="row g-4">
        <div class="col-md-4">
            <div class="small text-muted mb-2 fw-bold">(1) ว่างจริง + ปุ่มสร้าง (primary)</div>
            <table id="cpEmptyTable1" class="table table-sm table-hover w-100">
                <thead><tr><th>ชื่อ</th><th>อีเมล</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="col-md-4">
            <div class="small text-muted mb-2 fw-bold">(2) กรองไม่พบ + "ล้างตัวกรอง" (auto)</div>
            <table id="cpEmptyTable2" class="table table-sm table-hover w-100">
                <thead><tr><th>ชื่อ</th><th>อีเมล</th></tr></thead>
                <tbody></tbody>
            </table>
            <p class="small text-muted mt-2">ตารางนี้มีข้อมูลจริง 2 แถว แต่ตั้งคำค้นหาไว้ล่วงหน้าให้ไม่ตรงกับแถวไหนเลย (<code>dt.search('...').draw()</code>) เพื่อสาธิต -- ล้างช่องค้นหาหรือกด "ล้างตัวกรอง" เพื่อดู 2 แถวจริงกลับมา</p>
        </div>
        <div class="col-md-4">
            <div class="small text-muted mb-2 fw-bold">(3) รายการว่าง ไม่มี action</div>
            <div class="card-surface">
                <?php
                $icon = 'fa-solid fa-clock-rotate-left';
                $title = 'ยังไม่มีเวอร์ชันอัตรา';
                $text = 'เมื่อมีการเพิ่มเวอร์ชันอัตราใหม่ จะแสดงที่นี่';
                $action = null;
                include __DIR__ . '/../../app/views/partials/empty-state.php';
                ?>
            </div>
        </div>
    </div>
</div>

<div class="cp-section">
    <h2>Row action button (ข้อ 5) — ตัดสินใหม่: คงทรงวงกลม</h2>
    <p class="cp-section-note"><code>.btn-icon</code> = วงกลม 32px ขอบ 1px <code>--c-border</code> พื้น <code>--c-bg</code> ไอคอน 12px (ลดจาก 14px เดิม -- ดูเหตุผลที่ไม่เปลี่ยนเป็น <code>fa-regular</code> ใน §7) <code>--c-text-muted</code>; hover พื้น <code>--c-bg-hover</code> ไอคอน <code>--c-text</code>; focus ring ส้ม; disabled <code>--c-text-faint</code> -- <b>ย้อนกลับข้อความ "ปุ่มกลมเลิกใช้ทั้งหมด" ใน §4 เดิม</b> ปัญหาจริงคือสีต่อปุ่ม (7 tone ต่อ action) ไม่ใช่ทรง ดังนั้นคงวงกลมไว้ตามที่เคย approve แล้วจริงๆ (<code>.btn-circle-action</code>, 14 ไฟล์) แต่ลบสีทั้งหมดออก. <code>.btn-circle-action</code> ตอนนี้เป็น <b>alias ชั่วคราวของ <code>.btn-icon</code></b> (ประกาศร่วม selector เดียวกันใน <code>style.css</code>, <code>!important</code> บังคับเทาเสมอไม่ว่าจะมี <code>.text-{color}</code> วางซ้อนมาจากไฟล์จริงไหนก็ตาม) -- <b>ไม่ต้องแตะ 14 ไฟล์เดิมเลย ได้สไตล์ใหม่ทันทีที่ commit</b> รอบ 4 ค่อย rename เป็น <code>.btn-icon</code> ทีละไฟล์แล้วลบ alias ทิ้ง. ปุ่มลบไม่มีสีแดง (สีแดงใช้ได้แค่ปุ่มยืนยันใน confirm dialog เท่านั้น).</p>
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <div>
            <div class="small text-muted mb-1">ปกติ (≤2 ปุ่ม, gap <code>--sp-1</code>)</div>
            <div class="d-flex gap-1">
                <button type="button" class="btn btn-icon" title="ดู"><i class="fa-solid fa-eye"></i></button>
                <button type="button" class="btn btn-icon" title="ลบ"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
        <div>
            <div class="small text-muted mb-1">> 2 action -- ⋮ วงกลมเดียวกัน</div>
            <div class="dropdown">
                <button type="button" class="btn btn-icon dropdown-toggle" data-bs-toggle="dropdown" title="เพิ่มเติม"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#"><i class="fa-solid fa-pen me-2"></i>แก้ไข</a></li>
                    <li><a class="dropdown-item" href="#"><i class="fa-solid fa-copy me-2"></i>ทำสำเนา</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="#"><i class="fa-solid fa-trash me-2"></i>ลบ</a></li>
                </ul>
            </div>
        </div>
        <div>
            <div class="small text-muted mb-1">disabled</div>
            <button type="button" class="btn btn-icon" title="ดู" disabled><i class="fa-solid fa-eye"></i></button>
        </div>
        <div>
            <div class="small text-muted mb-1"><code>.btn-circle-action</code> (alias, 14 ไฟล์จริงใช้ชื่อนี้ -- เคยมี <code>text-danger</code> ซ้อนมาด้วย)</div>
            <div class="d-flex gap-1">
                <button type="button" class="btn btn-circle-action text-primary" title="ดู"><i class="fa-solid fa-eye"></i></button>
                <button type="button" class="btn btn-circle-action text-danger" title="ลบ"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
    </div>
</div>

<div class="cp-section">
    <h2>Badge / สถานะ (ข้อ 5)</h2>
    <p class="cp-section-note"><code>app/config/status_map.php</code> (data เดียวที่มา, ที่เดียวจริงๆ) + PHP <code>statusBadge($enum, $context)</code> (<code>app/helpers/helpers.php</code>) + JS <code>statusBadgeHtml(enum, context)</code> (<code>app.js</code>). <code>layout/header.php</code> (จุดเดียวกับที่ inject <code>BASE_URL</code>/<code>LANG_VERSION</code> อยู่แล้ว, ยกเว้นจากกฎ "ห้ามแตะหน้าจริง" เฉพาะบรรทัดนี้) ใส่ <code>window.STATUS_MAP = &lt;?=json_encode(loadStatusMap())?&gt;;</code> จาก PHP ตรงๆ ทุกหน้า -- <code>app.js</code> อ่านจาก <code>window.STATUS_MAP</code> เท่านั้น (ไม่มี copy ของตัวเองแล้ว ไม่มีความเสี่ยงเรื่อง drift อีกต่อไป) หน้านี้เองก็ใส่บรรทัดเดียวกันจาก <code>loadStatusMap()</code> จริงที่ require ไว้ตอนต้นไฟล์. ทุก context/enum ด้านล่าง render จริงผ่าน <code>statusBadgeHtml()</code> (ไม่ใช่ hardcode) -- enum ที่ไม่มีใน map จะเห็น badge เทา + label ดิบ + <code>console.warn()</code> (ลองเปิด console ดู "unmapped_demo" ท้ายสุด). <code>tone</code> ของ <code>run_state.approved</code> เป็น <code>warning</code> (ไม่ใช่ success) เพราะ "อนุมัติแล้ว" สำหรับคนทำเงินเดือนคือ "ต้องไปจ่ายต่อ" -- คนละความหมายกับ <code>approval_status.approved</code> ที่เป็น success (คำขอจบแล้ว) ตั้งใจให้ต่างกัน ไม่ใช่ bug. <code>data_source</code> ไม่ใช่สถานะจริง (§5) ใส่ไว้ชั่วคราวเป็น neutral ทั้งหมดเพื่อไม่พังตอน migrate รอบ 4.</p>
    <div id="cpBadgeShowcase"></div>
</div>

<div class="cp-section">
    <h2>Stepper (ข้อ 6)</h2>
    <p class="cp-section-note"><code>app/views/partials/status-stepper.php</code> + JS <code>renderStatusStepper(steps, current)</code> (<code>app.js</code>) -- render อย่างเดียว ไม่มี state-machine/ปุ่ม/วันที่/ไอคอน branch แบบ <code>runLifecycleSteps()</code> ตัวจริง (โลจิกขั้นยังอยู่ที่เดิม -- <code>payroll/detail.js</code>'s <code>renderProcessTimeline()</code>/<code>payroll/index.js</code>'s mini-timeline ยังไม่ถูกแตะรอบนี้ ตามกฎ "ห้ามแตะหน้าจริง" §13, ย้ายมาใช้จริงเป็นงานรอบ 4). ด้านล่างคือ 5 ขั้นจริงของรอบเงินเดือน (สร้างรายการ → ส่งอนุมัติ → อนุมัติ → จ่ายเงิน → ปิดรอบ) ที่ 3 สถานะจริง: <b>draft</b> (ยังไม่ส่งอนุมัติ -- ปัจจุบัน = "ส่งอนุมัติ"), <b>approved</b> (อนุมัติแล้ว รอจ่าย -- ปัจจุบัน = "จ่ายเงิน"), <b>paid</b> (จ่ายแล้ว รอปิดรอบ -- ปัจจุบัน = "ปิดรอบ") -- <code>current</code> คำนวณตามกฎเดียวกับ <code>runLifecycleSteps()</code> จริง (<code>currentIndex = reachedIdx + 1</code>) เสร็จ = วงกลมเทา + ✓ ตัวหนังสือจาง, ปัจจุบัน = วงกลมส้มตัน ตัวหนังสือเข้ม+หนา, ถัดไป = วงกลมขอบเทาว่าง, เส้นเชื่อมสีเทาเดียวกันทุกช่วงไม่ว่าขั้นไหนจะเสร็จแล้วหรือยัง ไม่มีสีพาสเทล 5 สี ไม่มีกล่อง/การ์ดต่อขั้น (สลับ theme มุมขวาบนดู dark mode ด้วย).</p>
    <div id="cpStepperShowcase" class="d-flex flex-column gap-4"></div>
</div>

<div class="cp-section">
    <h2>Timeline (ข้อ (3)/6b)</h2>
    <p class="cp-section-note"><code>app/views/partials/timeline.php</code> + JS <code>renderTimeline(items, {groupByDay})</code> (<code>app.js</code>) -- feed กิจกรรมแนวตั้งความยาวเท่าไหร่ก็ได้ (audit log/ประวัติอนุมัติ) <b>คนละตัวกับ Stepper ด้านบน</b> (นั่นคือ milestone คงที่ 5 ขั้นของรอบ, นี่คือ log ที่ยาวไม่จำกัด caller เรียงมาใหม่สุดบนสุดเอง ไม่ sort/dedupe เอง) -- เส้นแนวตั้ง <code>--c-border</code> ซ้าย, จุด 10px สีตาม <code>tone</code> (default เทา), ไม่มีการ์ด/พื้นสีต่อรายการ, avatar 24px (ใช้ <code>.apv-person-avatar</code> class เดียวกับ <code>apvAvatarHtml()</code> -- PHP เรียก JS function ไม่ได้ เลย mirror เฉพาะภาพ ไม่ใช่ทั้งฟังก์ชัน), badge (ถ้ามี) ผ่าน <code>statusBadge()</code>/<code>statusBadgeHtml()</code> จริง (ข้อ 5) -- เปิดด้วย <code>groupByDay:true</code> ด้านล่าง เห็นหัววัน "dd/mm/yyyy" คั่นเป็นข้อความเทาเล็ก (เส้นเชื่อมหยุดเองตรงหัววันโดยไม่ต้องเขียน JS พิเศษ เพราะหัววันไม่มี <code>::before</code> ของตัวเอง).</p>
    <p class="cp-section-note"><b>ผสมกับ Stepper (จำลอง modal ไทม์ไลน์อนุมัติของรอบ, Batch 2/3C):</b> Stepper แนวนอนบน + Timeline นี้ล่าง คือหน้าตาที่ modal ไทม์ไลน์อนุมัติจริงจะเป็นถ้าย้ายมาใช้ 2 component นี้ในรอบ 4 -- <b>ไม่แตะโค้ดจริงของ <code>payroll/detail.js</code>'s <code>renderApprovalTimelineBody()</code> เลยรอบนี้</b> แค่ demo ให้เห็นภาพรวมประกอบกันด้านล่าง (เรื่องราวเดียวกับ log 6 รายการ: สร้าง → แก้ → ส่งอนุมัติ → ไม่อนุมัติ → อนุมัติ → จ่ายเงิน จบที่ "จ่ายเงิน" จึง current ของ stepper = "ปิดรอบ" ตามกฎเดียวกับ Stepper section ด้านบน).</p>
    <div id="cpTimelineComboShowcase" class="d-flex flex-column gap-3 mb-4" style="max-width:420px;"></div>
    <p class="cp-section-note mb-1"><b>Log เดี่ยว (ไม่มี stepper) light/dark:</b> สลับ theme มุมขวาบนดูสี tone ของจุด</p>
    <div id="cpTimelineShowcase" style="max-width:420px;"></div>
</div>

<div class="cp-section">
    <h2>emp-header-card + Quick-view (ข้อ 6c)</h2>
    <p class="cp-section-note"><code>app/views/partials/emp-header-card.php</code> (ใหม่) + JS <code>employeeHeaderCardHtml(emp)</code> (<code>app.js</code>, <b>generalize ของเดิมจาก Batch 3C item 8 ไม่สร้างซ้ำ</b>) -- พื้น <code>--c-bg-subtle</code> ขอบล่าง <code>--c-border</code> ไม่มีขอบสี/เงา, avatar 40px, บรรทัด 1 ชื่อ+รหัส, บรรทัด 2 แผนก·ตำแหน่ง, ขวาสุด badge สถานะพนักงานจริงผ่าน <code>statusBadge()</code> (context <code>employee_status</code>, ข้อ 5) -- <b>ข้อควรระวัง: ต่างจาก component อื่นในรอบนี้ ฟังก์ชัน JS ตัวนี้มี 6 real call site ผูกอยู่แล้วจริงใน <code>payroll/detail.js</code> (Calculation Breakdown/Raw Sync Data/Manage Items/Comments/Adjustments/Bank Account Assignment) ตั้งแต่ก่อนรอบนี้จะเริ่ม (โค้ดเดิมเขียน comment ตรงๆ ว่า "ยังไม่จัดสไตล์การ์ด -- design phase มาทีหลัง") -- งานรอบนี้คือ design phase ที่รอไว้นั้นเอง ไม่ใช่ scope ใหม่ ทั้ง 6 modal จริงจะได้สไตล์ใหม่ (avatar 48px→40px, บรรทัด 2 ใหม่, badge slot ใหม่) ทันทีที่ commit รอบนี้ โดยไม่ต้องแตะ payroll/detail.js เลยแม้แต่บรรทัดเดียว</b> (ปรับ shared helper เดิม เหมือน <code>showConfirm()</code>/<code>fmtNum()</code> ที่ทำมาก่อนหน้านี้).</p>
    <div class="row">
        <div class="col-md-6">
            <p class="cp-section-note mb-1">Render จริงผ่าน PHP partial ตรงๆ (ไม่ใช่ mockup):</p>
            <div class="border rounded" style="max-width:420px;">
                <?php
                $employee = [
                    'name_th' => 'สมชาย', 'surname_th' => 'ใจดี',
                    'employee_no' => 'EMP0142',
                    'department_name_th' => 'บัญชีและการเงิน',
                    'position_name_th' => 'เจ้าหน้าที่บัญชีอาวุโส',
                    'employee_status' => 'active',
                ];
                include __DIR__ . '/../../app/views/partials/emp-header-card.php';
                ?>
            </div>
        </div>
        <div class="col-md-6">
            <p class="cp-section-note mb-1"><b>Quick-view (§9):</b> modal ตัวอย่าง -- ตัดสินใจแล้ว: <code>modal-md</code> ไม่ทำ popover, หัวเป็น <code>.emp-header-card</code>, เนื้อหา 2 คอลัมน์เท่ากัน label/value, footer แค่ [ปิด] [ดูข้อมูลเต็ม]</p>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#cpQuickViewModal">เปิด Quick-view ตัวอย่าง</button>
        </div>
    </div>
</div>

<div class="modal fade" id="cpQuickViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ข้อมูลพนักงาน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <?php
            $employee = [
                'name_th' => 'สมหญิง', 'surname_th' => 'ขยันดี',
                'employee_no' => 'EMP0089',
                'department_name_th' => 'ทรัพยากรบุคคล',
                'position_name_th' => 'เจ้าหน้าที่ HR',
                'employee_status' => 'probation',
            ];
            include __DIR__ . '/../../app/views/partials/emp-header-card.php';
            ?>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="small text-muted">วันที่เริ่มงาน</div>
                        <div>01/03/2569</div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted">ประเภทการจ้าง</div>
                        <div>พนักงานประจำ</div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted">สาขา</div>
                        <div>สำนักงานใหญ่</div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted">อีเมล</div>
                        <div>somying@example.com</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                <button type="button" class="btn btn-primary">ดูข้อมูลเต็ม</button>
            </div>
        </div>
    </div>
</div>

<div class="cp-section">
    <h2>ตัวเลข/เงิน (ข้อ 7a)</h2>
    <p class="cp-section-note">PHP <code>fmtMoney($n)</code> (<code>app/helpers/helpers.php</code>, ใหม่) / JS <code>fmtNum(n)</code> (<code>format-helpers.js</code>, <b>มีอยู่แล้ว ไม่ต้องแก้</b> -- ตรวจแล้วให้ 2 ทศนิยม + comma เสมอตรงตามกฎอยู่แล้ว, ตรวจ caller เดิมทุกจุดที่เรียก <code>fmtNum()</code> แล้วไม่มีจุดไหนพึ่งทศนิยมจำนวนอื่น) -- ตารางซ้ายคอลัมน์ "PHP" render จาก <code>fmtMoney()</code> ตรงๆ ตอน request, คอลัมน์ "JS" render จาก <code>fmtNum()</code> ตรงๆ ตอน runtime (ค่าเดียวกัน 5 ค่า, ตรงกันทุกแถว) -- ยืนยันเพิ่มอีก 10 ค่าด้วย <code>tests/fmt_money_test.php</code> (รวมติดลบ/ศูนย์/ทศนิยมยาว/null/ว่าง/mask "XXXX"). ฝั่งขวา <code>&lt;input class="money-input"&gt;</code> + <code>initMoneyInputs($scope)</code> (เรียกอัตโนมัติจาก app.js's <code>$(document).ready()</code> อยู่แล้ว ไม่ต้องเขียน init เพิ่มในหน้านี้ -- ลองพิมพ์ตัวเลข/จุด แล้ว blur ดู comma+ทศนิยม 2 ตำแหน่ง แล้วคลิกกลับเข้าไป (focus) ดู comma หายกลับเป็นตัวเลขล้วนพร้อมแก้ต่อ) ค่าที่จะส่ง server เป็นตัวเลขล้วนเสมออยู่ที่ <code>data-raw-value</code> attribute ของ input เอง (เปิด devtools ดูตอน blur) -- ดู note ท้ายส่วนนี้เรื่อง <code>collect*FormData</code>.</p>
    <div class="row g-4">
        <div class="col-md-7">
            <table class="table table-sm">
                <thead><tr><th>ค่าดิบ</th><th class="num">PHP <code>fmtMoney()</code></th><th class="num">JS <code>fmtNum()</code></th></tr></thead>
                <tbody>
                <?php
                $cpFmtDemoValues = [0, 1234567.89, -1234.5, 0.1, null];
                foreach ($cpFmtDemoValues as $cpI => $cpV):
                    $cpVLabel = $cpV === null ? 'null' : (string)$cpV;
                ?>
                    <tr>
                        <td><code><?=htmlspecialchars($cpVLabel)?></code></td>
                        <td class="num"><?=htmlspecialchars(fmtMoney($cpV))?></td>
                        <td class="num" data-cp-fmtnum-index="<?=$cpI?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="col-md-5">
            <label class="form-label small mb-1">Money input</label>
            <input type="text" class="form-control money-input" value="1234.5">
            <p class="cp-section-note mt-2 mb-0">เริ่มด้วยค่าดิบ <code>1234.5</code> ในโค้ด -- <code>initMoneyInputs()</code> format ให้เป็น <code>1,234.50</code> ทันทีตอนหน้าโหลดเสร็จ (เหมือนฟิลด์ที่ populate ค่าจาก server มาแล้ว) ไม่ต้องรอ blur ครั้งแรก -- ค่านี้ไม่ใช่ตัวอย่างจากหน้าจริงที่ไหน (ยังไม่มีหน้าจริงใช้ <code>.money-input</code> รอบนี้ -- ห้ามแตะหน้าจริง §13, ย้ายมาใช้จริงเป็นงานรอบ 4).</p>
        </div>
    </div>
    <p class="cp-section-note mt-3 mb-0"><b>ตรวจแล้ว: แอปนี้ไม่มี central form-serializer จุดเดียว</b> -- grep เจอ 8 ฟังก์ชัน <code>collect*FormData()</code> แยกกันคนละหน้า (<code>collectRunFormData</code>/<code>collectRcFormData</code>/<code>collectEmployeeFormData</code>/<code>collectEedFormData</code>/<code>collectPedTypeFormData</code>/<code>collectCycleFormData</code>/<code>collectSrDetailsFormData</code>/<code>collectSrRateVersionFormData</code>) ไม่มีตัวกลางให้ strip comma รวมจุดเดียวได้จริงตอนนี้ -- <code>parseMoneyInput(str)</code> (<code>format-helpers.js</code>, ใหม่) จึงเป็นจุดร่วมเดียวที่มี ให้ฟังก์ชันพวกนี้เรียกแทนการเขียน <code>.replace(/,/g,'')</code> เองทีละหน้า เมื่อหน้าไหนย้ายมาใช้ <code>.money-input</code> จริง (รอบ 4). พบเพิ่ม (ไม่แก้รอบนี้ เพราะเป็นหน้าจริง): <b>60 จุดใน 10 ไฟล์</b> ยัง <code>toLocaleString()</code> ตรงๆ ไม่ผ่าน <code>fmtNum()</code> เลย (บางจุดตั้งใจใช้ทศนิยมอื่น เช่น ปี/เปอร์เซ็นต์ 1 ตำแหน่ง ไม่ใช่เงิน) และ <code>employee/reports.js</code>'s <code>fmtMoneyList()</code> เป็นสำเนาใกล้เคียง <code>fmtNum()</code> ที่หลุดรอดตอน consolidate ใน Phase 11 T065 (ต่างกันตรง null/undefined กลายเป็น "0.00" แทน "-") -- ทั้งหมดเป็นงาน migrate รอบ 4.</p>
</div>

<div class="cp-section">
    <h2>Feedback (ข้อ 7b)</h2>
    <p class="cp-section-note"><code>showConfirm()</code> (<code>alert.js</code>) รับ object form <code>{title, message, confirmText, cancelText, danger, onYes, onNo}</code> ใหม่ -- positional เดิม <code>showConfirm(title, msg, yes, no)</code> ยังทำงานเหมือนเดิม 100% (เช็ค <code>typeof arg1 === 'object'</code>). ปุ่มยืนยันปกติเป็น <code>--c-primary</code> (ส้ม), <code>danger:true</code> เป็น <code>--c-danger</code> (แดง) -- <b>เปลี่ยน default ทั้งระบบ</b> (ย้อนกลับการตัดสินใจ 2026-09-05 ที่เคยเก็บสีม่วง/แดง/เทาเดิมของ Swal2 ไว้โดยตั้งใจ -- ยืนยันกับผู้ใช้ตรงๆ ก่อนแล้วว่าให้เปลี่ยน) และแก้ไอคอน info/question จากฟ้า (§3 ห้ามฟ้า) เป็น <code>--c-info</code> (เทา) ไปด้วยในตัว เพราะเป็นบั๊กจริงในจุดเดียวกันที่เพิ่งไปแก้สไตล์ปุ่ม. <code>showSuccess()</code> เปลี่ยนจาก modal บล็อกกลางจอ (ต้องกด OK) เป็น <b>toast มุมขวาบนเสมอ</b> ตาม §10 -- ข้อความ &le;60 ตัวอักษร = 3 วินาทีไม่มีปุ่ม, &gt;60 ตัวอักษร = 6 วินาที + ปุ่ม &times; ปิดเอง (ไม่ใช่ OK), hover ค้างไว้ timer หยุดนับ -- ยืนยันแล้วว่า 135 จุดที่เรียก <code>showSuccess()</code> จริงในแอปไม่มีจุดไหนพึ่ง <code>confirm=false</code> เลย (grep 0 จุด) จึงเปลี่ยน default ได้โดยไม่มี call site ไหนพังพฤติกรรม. <code>showError()</code> <b>ไม่เปลี่ยน</b> -- ยังเป็น dialog บล็อกกลางจอต้องกด OK เหมือนเดิม (§10 พูดถึง toast แค่ฝั่งสำเร็จ, ข้อผิดพลาดไม่ควรถูกมองข้ามได้ง่ายๆ จากมุมจอ).</p>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <button type="button" class="btn btn-primary" id="cpConfirmNormalBtn">ยิง confirm ปกติ</button>
        <button type="button" class="btn btn-outline-secondary" id="cpConfirmDangerBtn">ยิง confirm danger</button>
        <button type="button" class="btn btn-outline-secondary" id="cpToastShortBtn">Toast สั้น (&le;60 ตัวอักษร, 3 วิ)</button>
        <button type="button" class="btn btn-outline-secondary" id="cpToastLongBtn">Toast ยาว (&gt;60 ตัวอักษร, 6 วิ + &times;)</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#cpDirtyModal">เปิด modal dirty-guard</button>
    </div>
    <p class="cp-section-note mb-0"><code>data-dirty-guard</code> + <code>isFormDirty()</code>/<code>confirmIfDirtyThen()</code>/<code>refreshDirtyGuard()</code> (<code>app.js</code>, §9) -- opt-in ต่อ modal (ไม่ใช่ทุก <code>.modal</code> เหมือนกลไกที่เคยถูกสั่งปิดทั้งระบบไปเมื่อ 2026-09-09 เพราะสับสน -- ยืนยันกับผู้ใช้ตรงๆ ก่อนสร้างกลไกนี้กลับมาว่าออกแบบต่างจากเดิมจริง). ลอง 3 แบบ: <b>(1)</b> เปิดแล้ว<b>ปิดทันที</b>ไม่แตะอะไร -- ปิดได้เลยไม่ถาม. <b>(2)</b> เปิดแล้ว<b>พิมพ์อะไรสักอย่าง</b>ในช่องแล้วกด &times; หรือปุ่ม "ยกเลิก" (ปุ่ม "ยกเลิก" เป็นแค่ <code>data-bs-dismiss="modal"</code> ธรรมดา ไม่ได้เรียก <code>confirmIfDirtyThen()</code> เองเลยสักบรรทัด -- ผ่านกลไกเดียวกับ &times;/Esc/backdrop โดยอัตโนมัติ) -- ต้องเจอ dialog "มีข้อมูลที่ยังไม่ได้บันทึก" ก่อนปิดจริง. <b>(3)</b> พิมพ์อะไรสักอย่างแล้วกด "บันทึก (demo)" แล้วกด &times; อีกที -- ปิดได้เลยไม่ถาม (baseline ถูก refresh หลัง save).</p>
</div>

<div class="modal fade" id="cpDirtyModal" data-dirty-guard tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ทดสอบ modal dirty-guard</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small mb-1" for="cpDirtyDemoField">ลองพิมพ์อะไรสักอย่าง</label>
                <input type="text" class="form-control" name="cp_dirty_demo_field" id="cpDirtyDemoField">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="cpDirtySaveBtn">บันทึก (demo)</button>
            </div>
        </div>
    </div>
</div>

<script src="../../node_modules/jquery/dist/jquery.min.js"></script>
<script src="../../node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<!-- Round 2 item 7b -- this page never loaded SweetAlert2 or alert.js at all before this (needed now
     for the Feedback section's own showConfirm()/showSuccess() demo buttons), same 2 files/same
     relative paths layout/footer.php's real page chain uses. -->
<script src="../../node_modules/sweetalert2/dist/sweetalert2.all.min.js"></script>
<script src="../../public/js/alert.js?v=<?=assetVersion('public/js/alert.js')?>"></script>
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
<script src="../../public/js/format-helpers.js?v=<?=assetVersion('public/js/format-helpers.js')?>"></script>
<!-- 2026-09-13, real bug found and fixed (console error "$this.select2 is not a function" --
     input.js:371's initSelect2(), called unconditionally from app.js's own sidebar-setup
     $(document).ready() block, line ~616 there): this page's own <link> for select2's CSS (2 of
     them, above in <head>) was added long ago, but the actual select2 PLUGIN SCRIPT
     (node_modules/select2/dist/js/select2.min.js) was never added at all -- confirmed by grepping
     this file's own full <script> list before this fix, 0 hits. Real pages (layout/header.php +
     footer.php) do NOT have this bug -- confirmed both files are completely untouched by every
     Round 2 commit so far (0-line git diff) -- their own select2.min.js has always been present,
     in footer.php, loaded near the very end of <body> (after bootstrap/sweetalert2/DataTables).
     That position looks "too late" relative to header.php's own much-earlier app.js tag, but it
     isn't actually a problem there OR here: $(document).ready()'s callback only ever fires at
     DOMContentLoaded, which cannot happen until every earlier synchronous, non-deferred <script>
     tag in the WHOLE document (including one placed near the very end of <body>, like this one)
     has already finished executing -- so it is a script's PRESENCE somewhere before </body>, not
     its ORDER relative to app.js's own <script> tag, that actually matters for this class of bug.
     Added here in the same relative grouping real footer.php uses (near the other footer-loaded
     libraries) for readability, not because the position is functionally required. -->
<script src="../../node_modules/select2/dist/js/select2.min.js"></script>
<script src="../../public/js/input.js?v=<?=assetVersion('public/js/input.js')?>"></script>
<script src="../../public/js/table-column-filter.js?v=<?=assetVersion('public/js/table-column-filter.js')?>"></script>
<script src="../../public/js/sticky-table-columns.js?v=<?=assetVersion('public/js/sticky-table-columns.js')?>"></script>
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
<script src="../../public/js/app.js?v=<?=assetVersion('public/js/app.js')?>"></script>
<script>
$(function () {
    // 2026-09-13, real bug found and fixed (explicit report: DataTable/notification demo copy stayed
    // English even with the real th.json present) -- app.js's own $(document).ready() calls
    // `window.langReady = loadLang(currentLang); await window.langReady;` (a REAL async fetch of
    // public/lang/{lang}.json, fully functional on this standalone page since it's a plain static
    // file, needs no session/backend) but that `await` only pauses THAT SPECIFIC ready callback --
    // jQuery does not wait for one ready() callback's own async work before firing the NEXT
    // registered one, so THIS script's own ready callback (registered after app.js's) was running
    // immediately, synchronously, before the fetch had any chance to resolve -- every getTableLang()/
    // langData/getLangValue() read below happened against a still-empty {} every time. Same
    // established fix real per-page JS files already use for exactly this (confirmed via grep --
    // employee/list.js, payroll/index.js both wrap their own ready-handler body the same way):
    // `(window.langReady || Promise.resolve()).then(...)` -- the `|| Promise.resolve()` fallback
    // covers a page that doesn't define langReady at all, resolving immediately instead of hanging.
    (window.langReady || Promise.resolve()).then(function () {
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

    // ข้อ (2) -- row-select checkbox: header = "select all" scoped to the CURRENTLY RENDERED page
    // only (this demo does not track selection across pages -- a real page with that need already
    // has its own bespoke cross-page selection-set logic, e.g. Employee List's bulk-sync picker;
    // this is a style/indeterminate-state demo, not a new shared selection-tracking helper).
    const $cpDemoTable = $('#cpDemoTable');
    const $cpSelectAllHeader = $('#cpSelectAllHeader');
    function cpSyncSelectAllState() {
        const $rowBoxes = $cpDemoTable.find('tbody td.col-check input[type=checkbox]');
        const total = $rowBoxes.length;
        const checked = $rowBoxes.filter(':checked').length;
        $cpSelectAllHeader.prop('checked', total > 0 && checked === total);
        $cpSelectAllHeader.prop('indeterminate', checked > 0 && checked < total);
    }
    $cpDemoTable.on('change', 'tbody td.col-check input[type=checkbox]', cpSyncSelectAllState);
    $cpSelectAllHeader.on('change', function () {
        $cpDemoTable.find('tbody td.col-check input[type=checkbox]').prop('checked', $cpSelectAllHeader.is(':checked'));
        cpSyncSelectAllState();
    });
    // DataTables replaces the whole <tbody> on page/sort/search -- the header checkbox must reset
    // (this demo's "select all" is per-page only, so a fresh page always starts unselected).
    $cpDemoTable.on('draw.dt', cpSyncSelectAllState);
    cpSyncSelectAllState();

    // ข้อ (2) -- row-toggle switch ("ใช้งาน" column). Row id=3 is a deliberate, permanent failure so
    // this demo can actually SHOW the revert-on-error behavior, not just claim it works.
    initRowToggles($cpDemoTable, {
        onChange: function (rowId, checked) {
            return new Promise(function (resolve, reject) {
                setTimeout(function () {
                    if (String(rowId) === '3') {
                        showError('จำลอง error -- แถวนี้ (id=3) จะ revert กลับที่เดิมเสมอเพื่อสาธิต initRowToggles()');
                        reject();
                    } else {
                        resolve();
                    }
                }, 500);
            });
        },
    });

    initFilterBar('#cpFilterBarDemo', {
        onChange: function () {
            console.log('[filter-bar demo] onChange fired -- a real caller would reload its own table here.');
        },
    });

    // Notification (§6, item 6d) -- UI only, no polling/backend. The dropdown itself is a REAL
    // Bootstrap dropdown (data-bs-toggle="dropdown" + dropdown-menu-end, see the markup) -- Bootstrap's
    // own dropdown.js already wires open/close globally via event delegation the moment that
    // attribute exists in the DOM (confirmed: the row-action "..." dropdown demo elsewhere on this
    // page uses the exact same mechanism with zero extra JS), so no click-toggle handler is written
    // here at all. 2026-09-13, real bug found and fixed: the FIRST version of this demo hand-rolled
    // its own `.notif-dropdown { position:absolute; right:0 }` + a manual $(...).toggleClass('d-none')
    // click handler instead -- that's exactly `dropdown-menu-end`'s own right-aligned-to-toggle
    // behavior, reimplemented by hand, which only looks correct when the toggle itself sits near the
    // right edge of the viewport (a real top bar's own bell). This demo's bell was rendered at the
    // LEFT of its own section, so the dropdown's right edge locked to the bell's right edge pushed the
    // whole 360px panel off the LEFT of the screen. Fixed 2 ways together, not one alone: (1) the bell
    // now sits inside a `.d-flex.justify-content-end` wrapper, right-aligned, actually simulating
    // where it lives on a real top bar; (2) the hand-rolled positioning/toggle was replaced with a
    // genuine Bootstrap dropdown, whose Popper-driven `data-bs-display="dynamic"` (Bootstrap's own
    // documented default -- named explicitly here so a future edit doesn't accidentally add
    // `data-bs-display="static"`, which disables Popper) auto-flips the menu to the LEFT on its own if
    // it would otherwise overflow the right edge -- so this no longer breaks even if a future round 4
    // top bar places the real bell somewhere this demo doesn't anticipate. Checked whether
    // `.cp-section`'s own box would clip the menu the way `overflow:hidden` sometimes does to a
    // dropdown (this exact class of bug) -- confirmed no `overflow` property on `.cp-section` at all
    // (see this file's own inline <style>), so `popperConfig: { strategy: 'fixed' }` is not needed
    // here; a future host container that DOES clip it (round 4) should add that option rather than
    // fighting the clip with z-index or negative margins.
    let cpNotifItems = [
        { unread: true, tone: 'success', title: 'อนุมัติรอบเงินเดือนกันยายน #1 แล้ว', detail: 'อนุมัติโดย สมชาย ใจดี', time: '5 นาทีที่แล้ว', link: '#' },
        { unread: true, tone: 'danger', title: 'ไม่อนุมัติคำขอใบรับรองการทำงาน', detail: 'เหตุผล: ข้อมูลไม่ครบถ้วน', time: '1 ชั่วโมงที่แล้ว', link: '#' },
        { unread: false, tone: 'warning', title: 'ข้อมูลจาก Origami มีการเปลี่ยนแปลง', detail: 'พนักงาน 3 คนมีข้อมูลใหม่รอ sync', time: '3 ชั่วโมงที่แล้ว', link: '#' },
        { unread: false, tone: 'neutral', title: 'ระบบจะปิดปรับปรุงคืนนี้ 23:00-01:00 น.', time: 'เมื่อวาน', link: '#' },
        { unread: false, tone: 'success', title: 'สร้างรอบเงินเดือนตุลาคม #1 สำเร็จ', time: '2 วันที่แล้ว', link: '#' },
    ];
    function cpRenderNotifDemo() {
        $('#cpNotifList').html(renderNotifications(cpNotifItems));
    }
    cpRenderNotifDemo();
    setNotificationCount('#cpNotifBadge', 3);
    $('#cpNotifMarkAllBtn').on('click', function () {
        cpNotifItems = cpNotifItems.map(function (item) { return Object.assign({}, item, { unread: false }); });
        cpRenderNotifDemo();
        setNotificationCount('#cpNotifBadge', 0);
    });

    // Empty state (§6, item 6e). (1) genuinely empty (data:[]) with a primary create action -- this
    // demo has no OTHER primary action anywhere on the page, so `variant:'primary'` is correct per
    // §6's own rule ("primary ได้เฉพาะเมื่อหน้านั้นไม่มีปุ่มหลักที่อื่น"). (2) has REAL data (2 rows)
    // but is pre-searched to a term that matches neither, on purpose, so the "กรองไม่พบ" auto-detect
    // (initSharedDataTable()'s own emptyState option comparing DataTables' recordsTotal vs
    // recordsDisplay) is visible immediately on page load without requiring the visitor to type
    // anything first -- clearing the search box (or the auto "ล้างตัวกรอง" button it renders) proves
    // the loop actually closes, not just that the empty message can render once.
    initSharedDataTable('#cpEmptyTable1', {
        dtOptions: {
            data: [],
            columns: [{ data: 'name', title: 'ชื่อ' }, { data: 'email', title: 'อีเมล' }],
        },
        emptyState: {
            icon: 'fa-solid fa-users-slash',
            title: 'ยังไม่มีพนักงาน',
            text: 'เพิ่มพนักงานคนแรกเพื่อเริ่มต้นใช้งาน',
            action: {
                label: 'เพิ่มพนักงาน',
                variant: 'primary',
                onClick: function () { alert('เพิ่มพนักงาน -- demo only, no backend call. A real page would open its own Add Employee modal here.'); },
            },
        },
    });
    const cpEmptyTable2 = initSharedDataTable('#cpEmptyTable2', {
        searchThreshold: 0,
        dtOptions: {
            data: [
                { name: 'สมชาย ใจดี', email: 'somchai@example.com' },
                { name: 'สมหญิง ขยันดี', email: 'somying@example.com' },
            ],
            columns: [{ data: 'name', title: 'ชื่อ' }, { data: 'email', title: 'อีเมล' }],
        },
        // Only reached if this table's data were ever genuinely empty -- it never is here, this
        // config exists purely so the option is present the way a real caller would always supply
        // one, not left undefined.
        emptyState: { icon: 'fa-solid fa-user', title: 'ยังไม่มีพนักงาน', text: 'เพิ่มพนักงานคนแรก' },
    });
    cpEmptyTable2.search('ไม่มีชื่อนี้แน่นอนในตาราง').draw();

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
    // DataTable sections above. 2026-09-13: the `toolbarTarget` option this used to pass (relocating
    // the toggle/chips/clear row into the status-tabs row above it) is REMOVED -- the panel now
    // always renders as a normal block right after wherever the partial was included, which for this
    // full-page mockup is already right after the Status Tabs pipeline in markup order.
    initFilterBar('#cpFullFilterBar', { onChange: function () {} });
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

    // Stepper (ข้อ 6) -- 5 ขั้นจริงของรอบเงินเดือน (ชื่อขั้นตรงกับ app.js's own RUN_LIFECYCLE_STEPS'
    // doneKey labels: step_draft_done/step_submit_done/state_approved/state_paid/state_locked) --
    // พิมพ์ตรงๆ ที่นี่แทนอ่านจาก langData เพราะหน้านี้ไม่มี i18n fetch จริง (ดู comment ด้านบนเรื่อง
    // app.js's own $(document).ready() ที่ทำอะไรไม่ได้บนหน้านี้). `current` ต่อ state ตามกฎเดียวกับ
    // runLifecycleSteps() ของจริง (currentIndex = reachedIdx + 1): draft (reachedIdx=0) -> current=1,
    // approved (reachedIdx=2) -> current=3, paid (reachedIdx=3) -> current=4.
    const CP_STEPPER_LABELS = ['สร้างรายการ', 'ส่งอนุมัติ', 'อนุมัติ', 'จ่ายเงิน', 'ปิดรอบ'];
    const $cpStepperShowcase = $('#cpStepperShowcase');
    [
        { title: 'draft', current: 1 },
        { title: 'approved', current: 3 },
        { title: 'paid', current: 4 },
    ].forEach(function (demo) {
        const $block = $('<div></div>');
        $block.append($('<div class="fw-semibold small text-uppercase text-muted mb-2"></div>').text(demo.title));
        $block.append(renderStatusStepper(CP_STEPPER_LABELS, demo.current));
        $cpStepperShowcase.append($block);
    });

    // Timeline (ข้อ (3)/6b) -- 6 รายการ 2 วัน, เรียงใหม่สุดบนสุดเอง (renderTimeline() ไม่ sort เอง) --
    // เรื่องราวเดียวกับที่ combo demo ด้านล่างใช้ประกอบกับ stepper.
    const CP_TIMELINE_ITEMS = [
        { time: '2026-09-11 16:45:00', actor: { name: 'ฝ่ายบัญชี' }, title: 'จ่ายเงิน', tone: 'success' },
        { time: '2026-09-11 13:00:00', actor: { name: 'สมหญิง อนุมัติ' }, title: 'อนุมัติ', tone: 'success', badge: { enum: 'approved', context: 'approval_status' } },
        { time: '2026-09-11 09:30:00', actor: { name: 'สมหญิง อนุมัติ' }, title: 'ไม่อนุมัติ', detail: 'ข้อมูลไม่ครบ กรุณาแก้ไขแล้วส่งใหม่', tone: 'danger', badge: { enum: 'rejected', context: 'approval_status' } },
        { time: '2026-09-10 14:00:00', actor: { name: 'สมชาย ทำเงินเดือน' }, title: 'ส่งอนุมัติ' },
        { time: '2026-09-10 10:15:00', actor: { name: 'สมชาย ทำเงินเดือน' }, title: 'แก้ไขรายการ', detail: 'ปรับยอดค่าล่วงเวลา' },
        { time: '2026-09-10 09:00:00', actor: { name: 'สมชาย ทำเงินเดือน' }, title: 'สร้างรายการ' },
    ];
    $('#cpTimelineShowcase').html(renderTimeline(CP_TIMELINE_ITEMS, { groupByDay: true }));
    // Combo demo -- same story, stepper on top ending at "จ่ายเงิน" so current = "ปิดรอบ" (same
    // currentIndex = reachedIdx + 1 rule the Stepper section above already uses).
    const $cpCombo = $('#cpTimelineComboShowcase');
    $cpCombo.append('<div class="fw-semibold small text-uppercase text-muted mb-2">จำลอง modal ไทม์ไลน์อนุมัติของรอบ</div>');
    $cpCombo.append(renderStatusStepper(CP_STEPPER_LABELS, 4));
    $cpCombo.append('<hr class="my-3">');
    $cpCombo.append(renderTimeline(CP_TIMELINE_ITEMS, { groupByDay: true }));

    // ตัวเลข/เงิน (ข้อ 7a) -- SAME 5 values the PHP side already rendered via fmtMoney() (kept in sync
    // by hand, this dev-only page has no shared JSON to source both sides from) run through the REAL
    // fmtNum() at runtime -- if the 2 columns ever visibly disagree, that's a real fmtMoney()/fmtNum()
    // parity bug, not a demo bug.
    const CP_FMT_DEMO_VALUES = [0, 1234567.89, -1234.5, 0.1, null];
    CP_FMT_DEMO_VALUES.forEach(function (v, i) {
        $(`[data-cp-fmtnum-index="${i}"]`).text(fmtNum(v));
    });
    // initMoneyInputs() itself needs no call here -- the .money-input field above is already in the
    // DOM by the time app.js's own $(document).ready() runs (this <script> block runs AFTER app.js
    // loads, see the <script> tag order above, but initMoneyInputs(document) fires from app.js's OWN
    // ready handler, which jQuery guarantees runs once, after the DOM -- including this page's static
    // markup -- is fully parsed).

    // Feedback (ข้อ 7b) -- real showConfirm()/showSuccess()/modal-dirty-guard calls, not mockups.
    $('#cpConfirmNormalBtn').on('click', function () {
        showConfirm({
            title: 'ยืนยันการทำรายการ',
            message: 'ต้องการดำเนินการต่อหรือไม่?',
            onYes: function () { showSuccess('ทำรายการแล้ว'); },
        });
    });
    $('#cpConfirmDangerBtn').on('click', function () {
        showConfirm({
            title: 'ลบรายการนี้?',
            message: 'การลบไม่สามารถย้อนกลับได้',
            confirmText: 'ลบ',
            danger: true,
            onYes: function () { showSuccess('ลบแล้ว'); },
        });
    });
    // <=60 chars -> 3000ms, no close button (the auto-decided branch, no explicit timer passed).
    $('#cpToastShortBtn').on('click', function () {
        showSuccess('บันทึกแล้ว');
    });
    // >60 chars (93 here) -> 6000ms + close ("x") button, still no OK button, per the same auto rule.
    $('#cpToastLongBtn').on('click', function () {
        showSuccess('บันทึกข้อมูลเรียบร้อยแล้ว ระบบได้ทำการปรับปรุงรายการที่เกี่ยวข้องทั้งหมดให้ตรงกันโดยอัตโนมัติ');
    });
    // Demo-only "save" -- no real backend, just proves refreshDirtyGuard() re-baselines the modal so
    // the very next close attempt sees it as clean.
    $('#cpDirtySaveBtn').on('click', function () {
        refreshDirtyGuard('#cpDirtyModal');
        showSuccess('บันทึกแล้ว (demo)');
    });
    }); // end (window.langReady || Promise.resolve()).then(...)
});
</script>
<script>
// Self-contained theme toggle for this preview page only -- same 3-state semantics/attribute
// layout/header.php stamps server-side ('light'/'dark' set data-bs-theme; 'system' removes it so
// tokens.css's own @media (prefers-color-scheme:dark) decides), NOT a call into app.js's own
// applyTheme() -- this page DOES load the real app.js now (since Round 2 item 4), but that function
// reads a real user session's saved theme preference, which this standalone dev page has none of;
// kept as its own tiny self-contained toggle instead of trying to fake a session for it.
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
<script>
// 2026-09-13, explicit request -- a missing library on this dev-only page should be visible ON the
// page, not something to re-diagnose from the console every time this file's own <script> list
// drifts out of sync with a real page's again (exactly what just happened: select2.min.js's own
// <script> tag was missing entirely, only caught via a raw console error). This is the LAST script
// in the whole file specifically so every other library above has already had its one chance to
// load before this checks for it.
//
// Deliberately native `document.addEventListener('DOMContentLoaded', ...)`, NOT `$(document).ready()`
// -- if some OTHER ready() callback registered earlier on this page (e.g. app.js's own sidebar-setup
// block) throws an uncaught exception, that must not be able to silently stop THIS check from ever
// running too (native DOMContentLoaded listeners are independent of each other; jQuery's own single
// internal ready-callback list is not guaranteed to be, depending on version).
document.addEventListener('DOMContentLoaded', function () {
    const missing = [];
    if (typeof window.jQuery === 'undefined' || !window.jQuery.fn.select2) {
        missing.push('select2 (node_modules/select2/dist/js/select2.min.js)');
    }
    if (typeof window.jQuery === 'undefined' || !window.jQuery.fn.DataTable) {
        missing.push('DataTables (node_modules/datatables.net/js/dataTables.min.js)');
    }
    if (typeof window.Swal === 'undefined') {
        missing.push('SweetAlert2 (node_modules/sweetalert2/dist/sweetalert2.all.min.js)');
    }
    if (typeof window.bootstrap === 'undefined') {
        missing.push('Bootstrap JS (node_modules/bootstrap/dist/js/bootstrap.bundle.min.js)');
    }
    // 2026-09-13, explicit follow-up request (after "initRowToggles is not defined" slipped past
    // this exact banner -- it existed for LIBRARIES, but not for this app's OWN Round 2 shared
    // helper functions, which is exactly what that error was about). Every one of these is a plain
    // top-level `function` declared in app.js/alert.js (confirmed -- none are wrapped in an IIFE or
    // a $(document).ready() closure), so a genuine load failure/parse error/cache issue in either
    // file shows up here as a missing `window.<name>`, the same class of bug this banner already
    // exists to surface instead of a console re-diagnosis.
    const cpRound2Helpers = [
        'initSharedDataTable', 'initFilterBar', 'initStatusTabs', 'renderStatusStepper',
        'renderTimeline', 'employeeHeaderCardHtml', 'statusBadgeHtml', 'initMoneyInputs',
        'initRowToggles', 'showConfirm', 'renderNotifications', 'setNotificationCount',
        'emptyStateHtml', 'dtRenderEmptyState',
    ];
    cpRound2Helpers.forEach(function (name) {
        if (typeof window[name] !== 'function') {
            missing.push(name + '() (app.js/alert.js)');
        }
    });
    if (missing.length) {
        const $banner = document.getElementById('cpDepBanner');
        $banner.textContent = 'ขาด dependency บนหน้านี้: ' + missing.join(', ') + ' -- เช็ค <script> list ของไฟล์นี้เทียบกับ layout/header.php + layout/footer.php';
        $banner.classList.remove('d-none');
    }
});
</script>
</body>
</html>
