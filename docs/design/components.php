<?php
// design:clean -- docs/design/rules.md §12, Round 2 item 8. Passes scripts/check-design.php with 0
// hits. 3 genuine violations were fixed while writing that script: 3 raw money-formatting calls
// (2 in the employee-list demo table, 1 in a stat-card value) switched to fmtMoney(); 2 hand-rolled
// status pills switched to statusBadge(); 1 avatar built from a generic rounded-corner utility class
// switched to the shared avatar span class. The rest of the FIRST lint pass's hits on this file were
// false positives (a naive whole-line scan matching this file's own prose/token-reference tables,
// not real markup) -- fixed in the lint script itself, not by editing this file further -- see that
// script's own docblock. 2 lines here are marked with the OTHER, line-level marker for cases that
// remain genuinely fine on purpose (a live per-token color preview, and one demo intentionally
// keeping an old utility class to prove a shared override neutralizes it).
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
// 2026-09-14, real bug found and fixed (explicit report: "theme light/dark หลุดเอง", re-audit --
// "components.php ห้าม seed theme เอง ให้อ่านจาก header.php เหมือนหน้าอื่น") -- this page used to seed its
// OWN initial theme from `localStorage.getItem('preferred_theme')` client-side (JS, further down the
// file), which makes localStorage act as a SOURCE for this one page instead of the pure mirror it is
// everywhere else -- the exact anti-pattern this whole bug family kept turning out to be. Fixed by
// giving this page the SAME server-side stamp layout/header.php gives every real page: read
// `$_SESSION['user']['ui_theme']` directly (byte-identical 3-way branch to header.php's own -- 'dark'
// stamps dark, 'system' stamps nothing so the `@media (prefers-color-scheme)` rule decides, anything
// else including null/never-configured stamps light) and write `data-bs-theme` on THIS page's own
// `<html>` tag before any CSS loads, exactly like header.php does. This file's own docblock above
// still holds -- no config.php/vendor/autoload.php/app bootstrap dependency added, a plain native
// `session_start()` is enough to resume whatever session cookie the browser already sent (or read
// nothing at all if there isn't one, same as a logged-out visit to any real page) -- no login
// requirement is introduced by this, the IP gate above is still the only access control this page has.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$__cpThemePref = $_SESSION['user']['ui_theme'] ?? null;
if ($__cpThemePref === 'dark') {
    $__cpThemeAttr = ' data-bs-theme="dark"';
} elseif ($__cpThemePref === 'system') {
    $__cpThemeAttr = '';
} else {
    $__cpThemeAttr = ' data-bs-theme="light"';
}
// 2026-09-13, item 5 -- stat-card.php's own 'badge' field now calls the shared statusBadge()
// helper internally (app/helpers/helpers.php), which this standalone page needs explicitly since it
// deliberately skips the app's normal bootstrap (see docblock above) -- helpers.php itself has no
// dependency on config.php/BASE_URL/a session, safe to require in isolation like this.
require_once __DIR__ . '/../../app/helpers/helpers.php';

// 2026-09-13, Round 2 item 9 leftover fix #1: the notification dropdown's chrome text (title/"mark
// all read"/"view all") kept rendering in English on this page despite an earlier fix that seeded
// localStorage's preferred_language to Thai -- that seed only ran "if unset", so a leftover English
// value from earlier testing on this same browser origin (before the seed existed, or a real
// language-switcher click) survived it indefinitely, and the page's whole i18n mechanism (this app's
// only one, everywhere -- data-i18n attributes swapped client-side by app.js's updateText(), see
// CLAUDE.md/rules.md) is entirely client-side, so a curl fetch could never have caught this class of
// bug either. Fixed at the root instead of patching the symptom again: this page now resolves its own
// language SERVER-SIDE via `?lang=` (default Thai) and renders the correct text directly in the HTML
// via cpLangText() below -- deterministic, and verifiable with a plain HTTP fetch + grep, no browser/
// JS execution needed. The client-side localStorage seed (further below) is also changed from
// "only if unset" to "always match this page's own resolved language" for the same reason -- a dev
// tool page has no real user preference worth preserving across visits, so removing that guard is
// safe here specifically (NOT a change to app.js's own real, session-driven default elsewhere).
$CP_LANG = (isset($_GET['lang']) && $_GET['lang'] === 'en') ? 'en' : 'th';
function cpLangText(string $key, string $fallback = ''): string {
    static $data = null;
    if ($data === null) {
        global $CP_LANG;
        $path = __DIR__ . '/../../public/lang/' . $CP_LANG . '.json';
        $data = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    }
    return $data[$key] ?? $fallback;
}
?>
<!doctype html>
<html lang="th"<?=$__cpThemeAttr?>>
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
<!-- 2026-09-13, Round 2 item 9 -- flatpickr (timepicker demo below). This page duplicates each real
     page's own <link>/<script> list by hand rather than including layout/header.php/footer.php
     directly (see the earlier 2026-09-12 comment on this same pattern) -- footer.php's own 2 new
     lines for flatpickr (§14) need a matching pair here too. -->
<link rel="stylesheet" href="../../node_modules/flatpickr/dist/flatpickr.min.css">
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
    /* 2026-09-13, filter-bar "กดติดบ้างไม่ติดบ้าง" bug report -- manual stress-test QA panel, this
       demo page only (not a real component, no style.css entry needed). */
    .cp-stress-test-panel { margin-top: var(--sp-3); padding: var(--sp-3); background: var(--c-bg-subtle); border: 1px dashed var(--c-border-strong); border-radius: var(--radius); }
    .cp-stress-test-row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sp-4); }
    .cp-stress-test-counter { font-size: var(--fs-sm); color: var(--c-text-muted); }
    .cp-stress-test-hint { margin: var(--sp-2) 0 0; font-size: var(--fs-xs); color: var(--c-text-faint); }
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
    <!-- 2026-09-14, real bug found and fixed (explicit report: "components.php กดสลับ Light/Dark/
         System ไม่ได้ ค้าง dark") -- this used to run its OWN separate DOM+localStorage-only toggle,
         under its own isolated `cp_theme_preview` localStorage key, deliberately NOT going through
         app.js's real preference machinery ("this page ... has none of [a real session]"). That
         isolation assumption breaks whenever whoever is previewing this page is ALSO logged into a
         real session in the SAME browser (routine during this exact kind of design work) --
         app.js's own ready-handler still runs loadUserPreferences() on every page including this
         one, which would reconcile against that OTHER real session's real saved theme, competing
         with (and, before app.js's own 2026-09-14 fix, overriding) this page's separate toggle.
         Fixed by removing the separate mechanism entirely -- these 3 buttons now call the SAME
         `setTheme()` helper (app.js) the real Settings modal's own Save button calls, so there is
         exactly one path that can ever change the live theme, not two. Real, accepted consequence:
         if a real session IS present in this browser, clicking these buttons now also persists to
         that employee's actual saved preference (same as if they'd used Settings) -- intentional,
         not a workaround, per the same explicit instruction that asked for this fix. -->
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
            <!-- design:ignore: style="" is a live per-token color PREVIEW (var(--token), never a hardcoded literal) --><div class="cp-swatch-color" style="background: var(<?=htmlspecialchars($name)?>);"></div>
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
    // 2026-09-13, real bug found and fixed: page-header.php now renders a FEW stable ids
    // unconditionally (#phTitle/#phActions/etc., Round 3 item 3a -- needed so a real page's JS can
    // hook into them once async data loads). This page includes the SAME partial twice (this
    // full-page mockup, and the real "Page Header (§2)" demo section further down) -- without a
    // distinct $id_prefix here, both would render the exact same ids, duplicate DOM ids on one page.
    // The canonical demo section keeps the DEFAULT 'ph' prefix (reset explicitly before its own
    // include, since PHP `include` shares this file's variable scope -- whatever $id_prefix this
    // include leaves behind would otherwise leak into every include after it, not just this one).
    $id_prefix = 'cpFullPh';
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
            <select class="form-select select2-native" id="cpFullFilterCycle">
                <option value="all">ทั้งหมด</option>
                <option value="1">รอบที่ 1 (1-15)</option>
                <option value="2">รอบที่ 2 (16-31)</option>
            </select>
        </div>
        <div class="col-sm-3">
            <label class="form-label small mb-1">ประเภทการจ่าย</label>
            <select class="form-select select2-native" id="cpFullFilterPurpose">
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
                <td class="num col-money" data-order="<?=$cpFull * 125000?>"><?=fmtMoney($cpFull * 125000)?></td>
                <td><?=statusBadge('draft', 'run_state')?></td>
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
    <h2>DataTable toolbar (§7) — <code>options.toolbar</code></h2>
    <p class="cp-section-note">ปุ่มใน toolbar ส่งผ่าน <code>initSharedDataTable(sel, { toolbar: { create, actions: [], export } })</code> — <b>ห้าม append เข้า <code>.dt-search</code> เอง</b>. ≥ <code>sm</code> แถวเดียว [length][actions] ··· [export][search][create]; &lt; <code>sm</code> 2 แถว (1 [length select] ··· [search ~60% placeholder, ขอบขวาตรงกับ create] · 2 [actions ชิดซ้าย] ··· [export][create] ขวา), ระยะระหว่างแถว <code>--sp-2</code>, ปุ่มไม่ย่อขนาด. ตัวอย่างจริง: ตารางพนักงานใน Payroll Detail.</p>
    <div class="cp-row">
        <pre class="cp-code">initSharedDataTable('#tb_run_detail', {
    toolbar: {
        create: `&lt;button id="btnJoinEmployees" class="btn btn-sm btn-primary"&gt;...&lt;/button&gt;`,
        actions: [
            `&lt;button id="btnBulkVerify" class="btn btn-sm btn-outline-secondary"&gt;...&lt;/button&gt;`,
            `&lt;button id="btnVerifyAllEmployees" class="btn btn-sm btn-outline-secondary"&gt;...&lt;/button&gt;`,
        ],
    },
});</pre>
    </div>
</div>

<!-- ==================== Form ใน panel (§9) ==================== -->
<div class="cp-section">
    <h2>Form ใน panel (§9) — <code>.form-compact</code></h2>
    <p class="cp-section-note">ใส่ <code>.form-compact</code> ที่ tab pane/panel ที่ครอบฟอร์ม — <b>ทุกข้อความในนั้นขนาดเดียวคือ <code>--fs-sm</code></b> (ไม่มี <code>--fs-xs</code> ในฟอร์ม) ลำดับชั้นทำด้วยสี/น้ำหนัก: label 500 <code>--c-text</code> · control 400 <code>--c-text</code> · helper/สรุป 400 <code>--c-text-muted</code> · segment ไม่เลือก 500 / เลือก 600 ขาว. ระยะ 4 ค่า: label→control <code>--sp-1</code> · control→helper <code>--sp-2</code> · แถว→แถว <code>--sp-3</code> · ก่อน section <code>--sp-4</code>. รายชื่ขนาดต้องเขียนชัดๆ เพราะ <code>.small</code>/<code>.btn</code>/ธีม select2 ตั้งขนาดของตัวเอง.</p>
    <div class="cp-row">
        <div class="form-compact" style="background: var(--c-bg-subtle); border-radius: var(--radius); padding: var(--sp-3); max-width: 520px; width: 100%;">
            <div class="segmented">
                <input type="radio" name="cp-fc-mode" id="cp-fc-m1" checked>
                <label for="cp-fc-m1">โหมดหนึ่ง</label>
                <input type="radio" name="cp-fc-mode" id="cp-fc-m2">
                <label for="cp-fc-m2">โหมดสอง</label>
            </div>
            <p class="form-text">คำอธิบายของโหมดที่เลือก — ขนาดเท่า control ต่างกันที่สีเท่านั้น</p>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label" for="cp-fc-a">ชื่อรายการ</label>
                    <input type="text" class="form-control" id="cp-fc-a" placeholder="เช่น ค่าเดินทาง">
                </div>
                <div class="col-6">
                    <label class="form-label" for="cp-fc-b">จำนวนเงิน</label>
                    <input type="text" class="form-control" id="cp-fc-b" placeholder="0.00">
                </div>
            </div>
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label" for="cp-fc-c">หมายเหตุ</label>
                    <textarea class="form-control" id="cp-fc-c" rows="2" placeholder="ไม่บังคับ"></textarea>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== Segmented (§9) ==================== -->
<div class="cp-section">
    <h2>Segmented (§9) — <code>.segmented</code></h2>
    <p class="cp-section-note"><b>≤ 3 ตัวเลือกใช้ <code>.segmented</code>, มากกว่านั้นใช้ <code>&lt;select&gt;</code></b> — แถว segmented ที่ยาวเกิน 3 อ่านไม่จบในสายตาเดียว. สร้างจาก <code>&lt;input type="radio"&gt;</code> ที่ซ่อนไว้ + <code>&lt;label&gt;</code> เป็นตัว segment — ลูกศรซ้าย-ขวา/Tab/screen reader ทำงานได้เอง ไม่ต้องเขียน JS. <b>ไม่ใช้</b> <code>.btn-group</code>+<code>.btn-check</code> ของ Bootstrap (บังคับให้ทุก segment เป็น <code>.btn</code> จึงติด padding/น้ำหนัก/เงาของปุ่มมาด้วย และ wrap ครึ่งปุ่มเวลาแคบ). ลอง Tab แล้วกดลูกศรซ้าย-ขวา และสลับ theme มุมขวาบน.</p>
    <div class="cp-row">
        <div class="segmented">
            <input type="radio" name="cp-seg-mode" id="cp-seg-1" checked>
            <label for="cp-seg-1">เลือกจากรายการ</label>
            <input type="radio" name="cp-seg-mode" id="cp-seg-2">
            <label for="cp-seg-2">ระบุรายการเอง</label>
            <input type="radio" name="cp-seg-mode" id="cp-seg-3">
            <label for="cp-seg-3">อื่นๆ</label>
        </div>
    </div>
    <div class="cp-row mt-3">
        <div class="segmented">
            <input type="radio" name="cp-seg-dest" id="cp-seg-d1" checked>
            <label for="cp-seg-d1">เลือกจากที่บันทึกไว้</label>
            <input type="radio" name="cp-seg-dest" id="cp-seg-d2">
            <label for="cp-seg-d2">ระบุใหม่</label>
        </div>
    </div>
    <p class="cp-section-note mt-4 mb-2"><b>ป้ายยาวเกิน ~10 ตัวอักษร ต้องมีป้ายสั้นสำหรับจอ &lt; <code>sm</code></b> — 2 <code>&lt;span&gt;</code> ในป้ายเดียวกัน (<code>.seg-label-full</code>/<code>.seg-label-short</code>, i18n key คนละตัว) สลับด้วย CSS ที่ breakpoint <b>ไม่ใช่ JS</b>. <b>ย่อหน้าต่างให้แคบกว่า 576px</b> แล้วดูแถวล่างนี้: ป้ายที่ 2/3 เปลี่ยนเป็นคำสั้น ไม่ใช่ถูกตัดด้วย ellipsis (แถวบนคือตัวอย่างป้ายที่สั้นพออยู่แล้ว ไม่ต้องมีคู่สั้น).</p>
    <div class="cp-row">
        <div class="segmented">
            <input type="radio" name="cp-seg-long" id="cp-seg-l1" checked>
            <label for="cp-seg-l1">หักเข้าบริษัท</label>
            <input type="radio" name="cp-seg-long" id="cp-seg-l2">
            <label for="cp-seg-l2"><span class="seg-label-full">โอนให้พนักงานคนอื่น</span><span class="seg-label-short">โอนให้พนักงาน</span></label>
            <input type="radio" name="cp-seg-long" id="cp-seg-l3">
            <label for="cp-seg-l3"><span class="seg-label-full">โอนให้บุคคล/หน่วยงานภายนอก</span><span class="seg-label-short">โอนให้ภายนอก</span></label>
        </div>
    </div>
</div>

<!-- ==================== Payee destination (§9/§15) ==================== -->
<div class="cp-section form-compact">
    <h2>Payee destination (§9/§15) — <code>partials/payee-destination.php</code></h2>
    <p class="cp-section-note">ตัวอย่างเดียวของ <b>segmented ซ้อนอยู่ใน callout</b>: เลือกปลายทาง 3 ทาง (<code>.segmented</code>, §9 "≤ 3 = segmented") แล้วคำถามย่อย/ฟอร์มของทางที่เลือกอยู่ในกล่องเยื้อง (<code>.payee-dest-subform</code>) เส้นซ้าย <code>--c-border</code> ใต้มัน. บรรทัดเทาใต้ control คือคำอธิบายของ<b>ค่าที่เลือกอยู่</b> เปลี่ยนตามค่า (<code>--fs-sm</code> <code>--c-text-muted</code>). include ไฟล์จริง + <code>initPayeeDestination()</code> จริง ไม่ใช่ mockup — ลองกดสลับ 3 ทาง และกด "บันทึก"/"ไม่บันทึก" ใต้ทางแรก, ย่อจอต่ำกว่า <code>sm</code> ดู segmented เต็มความกว้างแถวเดียว, สลับ theme มุมขวาบน. การแปลงค่า UI → <code>payee_type</code> อยู่ที่ <code>payeeDestinationType()</code> ที่เดียว (ดูบรรทัดผลลัพธ์ท้าย demo).</p>
    <?php
    ob_start(); ?>
        <div class="d-none" id="cpPayeeEmployeeWrapper">
            <label class="form-label" for="cpPayeeEmployee">พนักงานผู้รับโอน</label>
            <select class="form-select select2-native" id="cpPayeeEmployee">
                <option value="1">สมชาย ใจดี (EM001)</option>
                <option value="2">สมหญิง รักงาน (EM002)</option>
            </select>
        </div>
        <div class="d-none" id="cpPayeeCompanyWrapper">
            <label class="form-label" for="cpPayeeAccount">บัญชีธนาคารบริษัท</label>
            <select class="form-select select2-native" id="cpPayeeAccount">
                <option value="1">กสิกรไทย • ••••1234 (บัญชีหลัก)</option>
                <option value="2">ไทยพาณิชย์ • ••••5678</option>
            </select>
        </div>
        <div class="d-none" id="cpPayeeExternalWrapper">
            <label class="form-label" for="cpPayeeExternalName">ชื่อบัญชีปลายทาง</label>
            <input type="text" class="form-control" id="cpPayeeExternalName" placeholder="เช่น กรมบังคับคดี">
        </div>
    <?php
    $payee_slot = ob_get_clean();
    $payee_prefix = 'cpPayee';
    include __DIR__ . '/../../app/views/partials/payee-destination.php';
    ?>
    <p class="cp-section-note mt-3 mb-0">ค่าที่จะส่งให้ backend ตอนนี้: <code id="cpPayeeTypeOut">-</code></p>
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
        ล้วนๆ (ลองลากตารางแนวนอน, ลองกดตัวกรองที่หัวคอลัมน์ "สถานะ", ลองกด "Export" ด้านบนขวา).
        <b>column-filter popup (<code>table-column-filter.js</code>, สร้างไว้ตั้งแต่ 2026-08-27 ก่อน Phase
        Design Round 2/3 จะมีอยู่ด้วยซ้ำ) migrate เข้าระบบ token/component ปัจจุบันครบแล้ว (2026-09-13-14,
        4 รอบ):</b> checkbox ทั้ง select-all และรายการค่าได้ <code>.form-check-input</code> จริง (ติ๊กส้ม
        มาตรฐาน), กล่อง popup เป็น <code>--c-border</code>/<code>--shadow-soft</code>/<code>--radius</code>/
        <code>--sp-3</code> เต็มกล่อง, ปุ่ม × เป็น <code>.btn-icon.btn-icon-ghost</code> จริง (§7), ไอคอน
        หัวคอลัมน์เป็น <code>fa-filter</code> ตัวเดียวกับ filter-bar สี <code>--c-text-faint</code> ปกติ/
        <code>--c-primary</code> เมื่อ active.
        <b>2026-09-14, "เก็บตกรอบ 5" item 1 -- root cause จริงของบั๊ก i18n ที่ค้างมา 3-4 รอบ พบแล้ว:</b>
        ทุก label lookup ในไฟล์นี้เดิมใช้ <code>(window.langData && langData['key'])</code> -- แต่
        <code>app.js</code> ประกาศ <code>let langData = {}</code> ที่ top level ของ plain script (ไม่ห่อ
        IIFE/module) ซึ่ง <b>ไม่เคยผูกเข้ากับ <code>window</code> object เลย</b> (เป็นแค่ script-scope
        lexical binding) -- <code>window.langData</code> จึงเป็น <code>undefined</code> เสมอไม่ว่าภาษาไหน
        ทำให้ guard นี้ short-circuit ทุกครั้งก่อนจะถึง lookup จริงด้วยซ้ำ ทุกรอบก่อนหน้าที่แก้ timing/เพิ่ม
        key ใหม่จึงไม่มีทางได้ผลเลยตราบใดที่ guard นี้ยังพัง -- แก้โดยเปลี่ยนทุกจุดเป็น <code>getLangValue()</code>
        (helper กลางของ <code>app.js</code> เอง อ่าน <code>langData</code> ตรงๆ ถูกต้องอยู่แล้ว) พร้อมเปลี่ยน
        key เป็นชุดใหม่ของตัวเอง 5 ตัว <code>dt_filter_title/_search/_select_all/_clear/_apply</code>
        (แยกขาดจาก key ที่ใช้ร่วมกับหน้าอื่นทั้งหมด ไม่ borrow <code>search</code>/<code>select_all</code>
        อีกต่อไป) -- ลองสลับภาษาแล้วกดตัวกรองที่หัวคอลัมน์ "สถานะ" ดูว่าเป็นภาษาไทยล้วนแล้ว.</p>
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
            // Cycles through real employee_status enum keys (app/config/status_map.php) instead of a
            // made-up label/tone pair, so this demo row's badge renders through statusBadge() itself
            // exactly like a real page would -- not a hand-picked color that happens to look similar.
            $cpEmpStatuses = ['active', 'probation', 'resigned'];
            for ($i = 1; $i <= 30; $i++):
                $cpEmpStatus = $cpEmpStatuses[$i % 3];
                $salary = 18000 + ($i * 733);
                $pct = ($i * 7) % 100;
                $day = str_pad((string)(($i % 28) + 1), 2, '0', STR_PAD_LEFT);
                $iso = sprintf('2026-%02d-%s', ($i % 12) + 1, $day);
                $dmy = sprintf('%s/%02d/2026', $day, ($i % 12) + 1);
            ?>
            <tr>
                <td class="col-check"><input type="checkbox"></td>
                <td class="col-avatar"><span class="apv-person-avatar" style="width:32px;height:32px;min-width:32px;font-size:.75rem;">E</span></td>
                <td>พนักงานตัวอย่าง <?=$i?></td>
                <td class="col-date" data-order="<?=$iso?>"><?=$dmy?></td>
                <td class="num col-money" data-order="<?=$salary?>"><?=fmtMoney($salary)?></td>
                <td class="num"><?=$pct?>.0</td>
                <td><?=statusBadge($cpEmpStatus, 'employee_status')?></td>
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
    // 2026-09-13: reset back to the DEFAULT 'ph' prefix for this canonical demo -- the earlier
    // "ภาพรวมทั้งหน้า Payroll Process" section's own $id_prefix='cpFullPh' would otherwise leak in
    // here too (PHP `include` shares this file's variable scope across every include, not just the
    // one call site that set it).
    unset($id_prefix);
    include __DIR__ . '/../../app/views/partials/page-header.php';
    ?>
</div>

<!-- ==================== Page Header -- decision_actions (§2, item 3a follow-up ข้อ 1) ==================== -->
<div class="cp-section">
    <h2>Page Header — <code>decision_actions</code> (ใหม่, 2026-09-13, REVISED same-day -- tone ต่อปุ่ม)</h2>
    <p class="cp-section-note"><b>2 มุมมองของหน้า Payroll Detail เดียวกัน ตอน state <code>pending_approval</code></b> -- <b>มุมมองที่ 1 (มีสิทธิ์อนุมัติ)</b>: <code>secondary_actions</code> = [ไทม์ไลน์อนุมัติ] [ส่งออก ▾], <code>overflow_actions</code> = [ส่งกลับแก้ไข] -- <b>เหลือรายการเดียว (2026-09-13 "เมนูอื่นๆ" follow-up) จึง render เป็นปุ่ม <code>.btn-outline-secondary</code> ธรรมดาตรงๆ ไม่ใช่ dropdown "อื่นๆ ▾" อีกต่อไป</b> (เมนูเลือกได้ทางเดียวไม่ใช่เมนู), แล้วห่าง <code>--sp-3</code> ตามด้วย <code>decision_actions</code> 3 ปุ่ม**ตามลำดับที่ caller ส่งมา** [อนุมัติ] (<code>tone:'success'</code>) [ขอข้อมูลเพิ่มเติม] (<code>tone:'warning'</code>) [ไม่อนุมัติ] (<code>tone:'danger'</code>) -- **ทั้ง 3 พื้นตัน ตัวหนา 600 ตัวหนังสือขาวเหมือนกันหมด** (ไม่มีกฎ "รายการสุดท้าย = primary" อีกแล้ว แต่ละปุ่มลงสีพื้นตาม <code>tone</code> ของตัวเอง, §4's decision-set exception) <code>callout</code> คู่กันบอกสิ่งที่ต้องทำ ("ตรวจสอบ...แล้วกด") ตัวหนาตรงกับปุ่มจริงเป๊ะ.</p>
    <?php
    // 2026-09-13, §2 REVISED: crumb สุดท้าย = รหัสของ entity เอง (run_code) ไม่ใช่ label คงที่แบบเดิม
    // ("รายละเอียดรอบ") อีกต่อไป -- H1 = ชื่อแสดงผลจริง (คนละค่ากับ crumb ตอนนี้).
    $title = 'รอบเงินเดือน มิถุนายน 2569';
    $breadcrumb = [
        ['label' => 'Payroll', 'href' => '#'],
        ['label' => 'Payroll Process', 'href' => '#'],
        ['label' => 'RUN-2026-06-A', 'href' => null],
    ];
    $secondary_actions = [
        ['label' => 'ไทม์ไลน์อนุมัติ', 'id' => 'cpPhaTimelineBtn', 'icon' => 'fa-solid fa-list-check'],
        ['label' => 'ส่งออก', 'icon' => 'fa-solid fa-file-export', 'items' => [
            ['label' => 'Excel', 'id' => 'cpPhaExportExcelBtn', 'icon' => 'fa-solid fa-file-excel file-icon-excel'],
            ['label' => 'PDF', 'id' => 'cpPhaExportPdfBtn', 'icon' => 'fa-solid fa-file-pdf file-icon-pdf'],
        ]],
    ];
    $overflow_actions = [
        ['label' => 'ส่งกลับแก้ไข', 'id' => 'cpPhaRevertBtn', 'icon' => 'fa-solid fa-rotate-left'],
    ];
    $decision_actions = [
        ['label' => 'อนุมัติ', 'id' => 'cpPhaApproveBtn', 'icon' => 'fa-solid fa-check', 'tone' => 'success'],
        ['label' => 'ขอข้อมูลเพิ่มเติม', 'id' => 'cpPhaRequestInfoBtn', 'icon' => 'fa-solid fa-circle-info', 'tone' => 'warning'],
        ['label' => 'ไม่อนุมัติ', 'id' => 'cpPhaRejectBtn', 'icon' => 'fa-solid fa-xmark', 'tone' => 'danger'],
    ];
    $primary_action = null; // ignored anyway once $decision_actions is set -- explicit here for clarity
    // Real PHP has no langData/getLangValue() (client-only, loaded async via app.js's own loadLang())
    // -- printed directly, same "พิมพ์ตรงๆ ที่นี่แทนอ่านจาก langData เพื่อความง่าย" convention this file's
    // own Stepper demo already uses (see CP_STEPPER_LABELS further down).
    $description = 'วันจ่าย 30/06/2569';
    $id_prefix = 'cpPha';
    include __DIR__ . '/../../app/views/partials/page-header.php';
    ?>
    <?php $text = 'ตรวจสอบรายละเอียดพนักงาน แล้วกด <b>อนุมัติ</b> / <b>ไม่อนุมัติ</b> / <b>ขอข้อมูลเพิ่มเติม</b>'; $tone = 'primary'; include __DIR__ . '/../../app/views/partials/callout.php'; ?>

    <p class="cp-section-note mt-4"><b>มุมมองที่ 2 (ไม่มีสิทธิ์อนุมัติ)</b>: <code>secondary_actions</code> เท่าเดิม [ไทม์ไลน์] [ส่งออก ▾] แต่ <code>overflow_actions</code>/<code>decision_actions</code> ว่างทั้งคู่ -- "อื่นๆ ▾" หายไปเอง (ว่าง = ไม่ render) ไม่มีปุ่มส้มเลยสักตัว <code>callout</code> บอกชื่อคนที่กำลังรออนุมัติจริง (ดึงจาก <code>run.approval_flow.approvers</code> ที่ <code>status==='pending'</code> เท่านั้น ไม่ใช่ mockup ข้อความลอยๆ).</p>
    <?php
    $title = 'รอบเงินเดือน มิถุนายน 2569';
    $secondary_actions = [
        ['label' => 'ไทม์ไลน์อนุมัติ', 'id' => 'cpPhbTimelineBtn', 'icon' => 'fa-solid fa-list-check'],
        ['label' => 'ส่งออก', 'icon' => 'fa-solid fa-file-export', 'items' => [
            ['label' => 'Excel', 'id' => 'cpPhbExportExcelBtn', 'icon' => 'fa-solid fa-file-excel file-icon-excel'],
            ['label' => 'PDF', 'id' => 'cpPhbExportPdfBtn', 'icon' => 'fa-solid fa-file-pdf file-icon-pdf'],
        ]],
    ];
    $overflow_actions = [];
    $decision_actions = [];
    $primary_action = null;
    $description = 'วันจ่าย 30/06/2569';
    $id_prefix = 'cpPhb';
    include __DIR__ . '/../../app/views/partials/page-header.php';
    ?>
    <?php $cpPendingNames = 'สมชาย ใจดี, สมหญิง รักงาน'; $text = 'รอการอนุมัติจาก ' . htmlspecialchars($cpPendingNames); $tone = 'primary'; include __DIR__ . '/../../app/views/partials/callout.php'; ?>
</div>

<!-- ==================== Page Header -- เมนู "อื่นๆ" divider fix (2026-09-13) ==================== -->
<div class="cp-section">
    <h2>Page Header — เมนู "อื่นๆ" (divider fix, 2026-09-13)</h2>
    <p class="cp-section-note">2 กรณีเทียบกัน, ทั้งคู่ใช้ <code>overflow_actions</code> 3 รายการ (พอที่จะเป็น dropdown จริง ไม่ใช่ 1 รายการที่กลายเป็นปุ่มธรรมดา) -- <b>ซ้าย</b>: [ปกติ][ปกติ][danger] -- มีรายการปกติอยู่เหนือ danger จริง ยังเห็น divider คั่นก่อนรายการ danger ตามเดิม (ไม่มีอะไรเปลี่ยนตรงนี้). <b>ขวา</b>: [danger][danger] ล้วน -- danger เริ่มที่ index 0 (ไม่มีรายการปกติอยู่เหนือเลย) <b>ไม่มี divider ให้เห็นอีกต่อไป</b> (ก่อนแก้ จะมี divider ว่างๆ ขึ้นก่อนรายการแรกสุดของเมนู ทั้งที่ไม่มีอะไรอยู่เหนือมันเลย) -- กดปุ่ม "อื่นๆ ▾" ทั้ง 2 ฝั่งเพื่อเทียบ.</p>
    <div class="d-flex gap-4">
        <div>
            <div class="small text-muted mb-1">มีรายการปกติอยู่เหนือ danger (ยังมี divider)</div>
            <?php
            $title = 'ตัวอย่าง';
            $breadcrumb = [];
            $secondary_actions = [];
            $primary_action = null;
            $decision_actions = [];
            $overflow_actions = [
                ['label' => 'ทำสำเนา', 'id' => 'cpPhcDuplicateBtn', 'icon' => 'fa-solid fa-copy'],
                ['label' => 'ดูประวัติ', 'id' => 'cpPhcHistoryBtn', 'icon' => 'fa-solid fa-clock-rotate-left'],
                ['label' => 'ลบ', 'id' => 'cpPhcDeleteBtn', 'icon' => 'fa-solid fa-trash', 'tone' => 'danger'],
            ];
            $description = null;
            $id_prefix = 'cpPhc';
            include __DIR__ . '/../../app/views/partials/page-header.php';
            ?>
        </div>
        <div>
            <div class="small text-muted mb-1">danger ล้วน เริ่มที่ index 0 (ไม่มี divider แล้ว)</div>
            <?php
            $overflow_actions = [
                ['label' => 'ยกเลิกรอบ', 'id' => 'cpPhdCancelBtn', 'icon' => 'fa-solid fa-ban', 'tone' => 'danger'],
                ['label' => 'ลบถาวร', 'id' => 'cpPhdDeleteBtn', 'icon' => 'fa-solid fa-trash', 'tone' => 'danger'],
            ];
            $id_prefix = 'cpPhd';
            include __DIR__ . '/../../app/views/partials/page-header.php';
            ?>
        </div>
    </div>
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
    ['label' => 'เงินเดือนรวม (บาท)', 'value' => fmtMoney(2456000), 'icon' => 'fa-solid fa-sack-dollar', 'sub' => 'เดือนนี้', 'badge' => null, 'link' => null],
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
    <p class="cp-section-note mt-3">§8 money-color system (item D, 2026-09-13) -- <code>value_class</code> ใหม่ (optional) ใส่ <code>.money-gross</code>/<code>.money-deduction</code>/<code>.money-net</code> ต่อจาก <code>.num</code> บนตัวเลขที่มีความหมายทางบัญชีจริง -- <b>ตัวเลขเท่านั้นที่เปลี่ยนสี</b> label/ไอคอน/การ์ดยังเทาปกติทุกใบ (เขียว/แดงเป็น token เดียวกับสถานะ ไม่มีเฉดแยก, สุทธิเป็นตัวหนา 600 <code>--c-text</code> ไม่มีสี -- ไม่ใช่ตัวเลข "ดี/ไม่ดี" แต่เป็นยอดสรุป). สลับ theme มุมขวาบนดู dark mode ด้วย.</p>
    <div class="row g-3">
        <div class="col-md-4">
            <?php $stat = ['label' => 'รายได้รวม', 'value' => fmtMoney(485000), 'icon' => 'fa-solid fa-sack-dollar', 'sub' => null, 'badge' => null, 'link' => null, 'value_class' => 'money-gross']; include __DIR__ . '/../../app/views/partials/stat-card.php'; ?>
        </div>
        <div class="col-md-4">
            <?php $stat = ['label' => 'รายการหัก', 'value' => fmtMoney(62500), 'icon' => 'fa-solid fa-minus', 'sub' => null, 'badge' => null, 'link' => null, 'value_class' => 'money-deduction']; include __DIR__ . '/../../app/views/partials/stat-card.php'; ?>
        </div>
        <div class="col-md-4">
            <?php $stat = ['label' => 'ยอดจ่ายสุทธิ', 'value' => fmtMoney(422500), 'icon' => 'fa-solid fa-hand-holding-dollar', 'sub' => null, 'badge' => null, 'link' => null, 'value_class' => 'money-net']; include __DIR__ . '/../../app/views/partials/stat-card.php'; ?>
        </div>
    </div>
</div>

<!-- ==================== Filter Bar (§6) ==================== -->
<div class="cp-section">
    <h2>Filter Bar (§6)</h2>
    <p class="cp-section-note"><code>app/views/partials/filter-bar.php</code> + JS <code>initFilterBar()</code> -- 6 ช่องตามหน้า Employee List จริง (ดู/สถานะ/แผนก/ทีม/ตำแหน่ง/สาขา) <code>col-sm-2</code> เท่ากันทุกช่อง เหมือน <code>.station-filter</code> เดิม, ไม่มีไอคอนหน้า label. <b>2026-09-13, restructured -- panel เหลือ 2 ส่วน (footer แยกถูกตัดออกแล้ว):</b> <b>หัว</b> (ป้าย "ตัวกรอง (N)" ซ้าย + chips/ปุ่ม "ล้างตัวกรอง"/วงกลม <code>.btn-icon</code> chevron ทางขวา ทั้งหมดแถวเดียว) และ <b>ตัว</b> (grid ฟิลด์ กางเมื่อกดวงกลม). <b>chips แสดงเฉพาะตอนยุบเท่านั้น</b> (ตอนกาง ฟิลด์เองมีขอบ <code>--c-border-strong</code> บอกว่ามีค่าแล้วแทน ไม่ต้องพึ่ง chips) ส่วนปุ่ม "ล้างตัวกรอง" อยู่ในหัวเสมอทั้ง 2 สถานะ. ตั้งค่าเริ่มต้นเป็น "สถานะ=ทำงานอยู่" + "แผนก=ไอที" (N=2, ยุบ) ไว้แล้วให้ทดสอบได้ทันที: <b>(1) ยุบ N=2</b> เห็น chips "สถานะ: ทำงานอยู่ ×"/"แผนก: ไอที ×" ในหัว (สถานะเริ่มต้นตอนนี้), <b>(2) กาง</b> (กดวงกลม chevron) -- chips หายไป เห็นขอบเข้มบน 2 ช่องที่มีค่าแทน, <b>(3) ล้างทั้งหมด</b> (กด × ที่ chip ตอนยุบ หรือปุ่ม "ล้างตัวกรอง" สถานะไหนก็ได้) -- N=0, ปุ่ม "ล้างตัวกรอง" หายไป, ขอบฟิลด์กลับปกติ. สถานะกาง/ยุบจำไว้ต่อ reload ผ่าน <code>pageKey</code> ที่ตั้งไว้ (ลอง reload หน้านี้หลังกางดู). ทุกช่องเป็น <code>select2-native</code> จริง (เหมือน Employee List จริงทุกช่องเป็น Select2) -- <code>initFilterBar()</code> อ่าน/ล้างค่าผ่าน <code>.val()</code>/<code>.val(x).trigger('change')</code> บน <code>&lt;select&gt;</code> เดิมที่ Select2 ครอบอยู่ ซึ่งคือ Select2 v4's เอง official API สำหรับตั้งค่าแบบ programmatic (v4 ไม่มี <code>.select2('val')</code> แยกต่างหากแบบ v3 แล้ว) -- ลอง "ล้างตัวกรอง"/กด × ที่ chip แล้วดู Select2 dropdown ที่ถูกครอบเปลี่ยนค่าตามจริง ไม่ใช่แค่ underlying select (ปุ่มทั้งคู่เป็น delegated binding บน panel เอง แก้บั๊กกดไม่ทำงานจากรอบก่อน). <b>2026-09-13, สืบต้นตอบั๊กใหม่ "กดติดบ้างไม่ติดบ้าง":</b> ไล่โค้ดจริงแล้วไม่พบ double-init บนหน้าจริง (payroll/detail.php มี once-guard ระดับโมดูลของตัวเองอยู่แล้ว, demo นี้เรียก <code>initFilterBar()</code> ครั้งเดียว) และปุ่ม toggle เดิมผูกกับวงกลม chevron เท่านั้น ไม่เคยครอบทั้งแถวหัว -- ไม่สามารถยืนยัน root cause เดียวที่จับได้คาหนังคาเขาได้ (ไม่มีสภาพแวดล้อม browser จริงให้ reproduce) จึงเพิ่ม defensive hardening ตามที่สั่งครบ 3 จุดแทนการปล่อยผ่าน: <b>(1)</b> เพิ่ม once-guard ระดับ element เองใน <code>initFilterBar()</code> (<code>$bar.data('filterBarInitialized')</code>) กัน double-bind ทุก handler หากมี caller ในอนาคตเรียกซ้ำโดยไม่ตั้งใจ (ไม่ใช่แค่พึ่ง once-guard ระดับหน้าเหมือนที่ payroll/detail.js ทำ) <b>(2)</b> zone สำหรับกาง/ยุบชัดเจนขึ้น (ไม่ใช่แค่วงกลม chevron อีกต่อไป แต่รวมป้าย "ตัวกรอง" ด้วย, cursor:pointer เป็นสัญญาณ) และ chips/ปุ่มล้างได้ <code>e.stopPropagation()</code> กันชนกับ zone นี้ (หรือ ancestor click zone ใดๆ ในอนาคต) แม้ปัจจุบันจะยังไม่เจอการชนจริงก็ตาม <b>(3)</b> ยืนยันแล้วว่าการยิง onChange/reload อยู่ครั้งเดียวต่อ action เสมออยู่แล้ว (debounce ผ่าน <code>setTimeout(0)</code> เดียวใน <code>scheduleNotify()</code>) -- เพิ่ม stress-test panel ข้างล่างนี้ให้ทดสอบ "กด × 10 ครั้งติดกันสลับกับกาง/ยุบ" ได้จริงด้วยตา ไม่ใช่แค่อ่านโค้ดแล้วเชื่อ. <b>2026-09-16, โครงหัวใหม่ (กฎเดียวทุกขนาดจอ):</b> หัวเป็น <b>2 แถวเสมอ</b> -- แถว 1 ป้าย "ตัวกรอง (N)" ซ้าย + [ล้างตัวกรอง][▾] ชิดขวา (ปุ่มล้างอยู่มุมขวาเสมอทั้งกาง/ยุบ, ซ่อนเมื่อ N=0, จอ &lt; <code>sm</code> เหลือไอคอนอย่างเดียว พร้อม title/aria-label), แถว 2 = <b>chips แถวเดียวไม่ wrap</b> เลื่อนแนวนอนได้ + fade ขอบขวา มีเฉพาะตอนยุบและ N &gt; 0 (ไม่มีปุ่มใดๆ ในแถว 2). <b>ลอง:</b> กด "ตั้งครบ 6 ตัวกรอง" แล้วยุบ -- chips ล้นขอบ เลื่อนซ้าย-ขวาได้ ขอบขวาจาง; กด "ล้างตัวกรอง" -> N=0 แถว 2 หายไปทั้งแถว; ย่อจอต่ำกว่า 576px -- ปุ่มล้างกลายเป็นไอคอน. <b>2026-09-13, บั๊กที่ 3 ในวันเดียวกัน -- ยืนยัน root cause จริงจาก repro ที่ผู้ใช้ให้มา (ไม่ใช่เดา):</b> <code>#cpFilterDept</code> เปลี่ยนเป็น <code>select2-remote</code> จริง (ชี้ <code>/api/department.get</code> เหมือน <code>#rdDepartmentFilter</code> ของหน้าจริงทุกประการ) แทน <code>select2-native</code> เดิม เพราะของเดิมไม่มีทาง repro บั๊กนี้ได้เลย -- <code>resetSelect()</code> เดิมใช้ <code>.find('option').first()</code> เป็น "ค่า default" เสมอ ซึ่งถูกสำหรับช่อง static/native (มี <code>&lt;option value="all"&gt;</code> เป็นตัวแรกจริงในมาร์กอัป) แต่ **ผิดสำหรับ select2-remote**: ช่องแบบ ajax ไม่มี option ใดๆ ในมาร์กอัปเลยตอนเริ่มต้น (<code>&lt;select&gt;&lt;/select&gt;</code> เปล่าล้วน) -- option เดียวที่เคยมีคือตัวที่ select2 เอง append ตอนผู้ใช้เลือกค่าจริง ดังนั้น <code>.find('option').first()</code> จึงเจอ**ตัวเดียวกับค่าที่กำลังจะล้าง**เสมอ แล้วตั้งค่ากลับไปที่ตัวมันเอง -- true no-op ตรงกับอาการที่รายงานทุกอย่าง (ช่องเดียวไม่ทำงานเลย, หลายช่องเฉพาะ static ทำงาน, "ล้างตัวกรอง" ล้างได้แค่ครึ่งเดียว). แก้ 3 จุดตามที่สั่ง: (1) <code>resetSelect()</code> แยก branch ตาม <code>.select2-remote</code> -- ลบ option ที่ค้างอยู่ทั้งหมดแล้วค่อย <code>.val(null).trigger('change')</code> คืนช่องกลับสู่สภาพเปล่าเป๊ะเหมือนตอนเริ่มต้น (2) <code>isActive()</code>/<code>resetSelect()</code> ทั้งคู่อ่าน sentinel ผ่าน <code>defaultValueFor()</code> ใหม่ -- อ่าน <code>data-filter-default</code> ถ้ามีระบุไว้ ไม่งั้น fallback ตาม type (<code>select2-remote</code> -&gt; <code>''</code>, อื่นๆ -&gt; <code>'all'</code>) ไม่ hardcode 'all' ทุกช่องอีกต่อไป (3) ปุ่ม "ล้างตัวกรอง" วนลูปบน snapshot ที่เก็บไว้ก่อน + <code>try/catch</code> ต่อช่อง กันช่องใดช่องหนึ่งพังแล้วช่องที่เหลือไม่ถูกล้างตาม -- ลองสร้าง N=2 ผ่านปุ่ม "ตั้งค่าตัวอย่างใหม่" แล้วกด × ที่ chip ของ "แผนก" (ตัวแรก, remote) ก่อนเป็นตัวอย่าง repro เดิม ควรหายไปทันทีเหมือนช่อง static.</p>
    <div class="cp-stress-test-panel" id="cpFilterBarStressPanel">
        <div class="cp-stress-test-row">
            <button type="button" class="btn btn-outline-secondary" id="cpFilterBarStressReset">ตั้งค่าตัวอย่างใหม่ (สถานะ=ทำงานอยู่, แผนก=ไอที)</button>
            <button type="button" class="btn btn-outline-secondary" id="cpFilterBarFillAll">ตั้งครบ 6 ตัวกรอง (chips ล้น)</button>
            <span class="cp-stress-test-counter">คลิก × / ล้างตัวกรอง ที่จับได้: <b id="cpFilterBarStressClickCount">0</b></span>
            <span class="cp-stress-test-counter">onChange ที่ยิงจริง: <b id="cpFilterBarStressChangeCount">0</b></span>
        </div>
        <p class="cp-stress-test-hint">วิธีทดสอบ: กด "ตั้งค่าตัวอย่างใหม่" แล้วกด × บน chip สลับกับกดวงกลม chevron กาง/ยุบ ทำซ้ำ 10 ครั้ง -- ตัวเลข "คลิกที่จับได้" ต้องขึ้นทุกครั้งที่กด × จริง (ไม่มีครั้งไหนกดแล้วเงียบ) ตั้งค่าตัวอย่างใหม่ได้เรื่อยๆ ไม่จำกัดจำนวนรอบ.</p>
    </div>
    <?php
    ob_start();
    ?>
    <div class="row g-3">
        <div class="col-sm-2">
            <!-- 2026-09-13, real bug repro fix: MUST be a genuine select2-remote (ajax) field, matching
                 payroll/detail.php's own #rdDepartmentFilter EXACTLY (same class, same data-api/data-type)
                 -- the native `select2-native` version this field used to be could never reproduce the
                 "× ไม่ทำงานเลย" bug at all, since a select2-remote's underlying <select> starts and
                 returns to being genuinely EMPTY (no baked-in <option> list to fall back to), which is
                 exactly what resetSelect() got wrong. Pre-seeded with ONE selected <option> (matches how
                 a real page pre-fills a remote field with an existing saved value) so the demo still
                 starts at N=2 active filters like before. Initialized by app.js's own app-wide
                 `initSelect2Remote('.select2-remote')` bootstrap -- no extra JS needed in this file. -->
            <label class="form-label small mb-1" for="cpFilterDept">แผนก</label>
            <select class="form-select form-select-sm select2-remote" id="cpFilterDept" data-api="/api/department.get" data-type="department">
                <option value="3" selected>ไอที</option>
            </select>
        </div>
        <div class="col-sm-2">
            <label class="form-label small mb-1">มุมมอง</label>
            <select class="form-select select2-native" id="cpFilterView">
                <option value="all">ทั้งหมด</option>
                <option value="active_only">เฉพาะที่ทำงานอยู่</option>
            </select>
        </div>
        <div class="col-sm-2">
            <label class="form-label small mb-1">สถานะ</label>
            <select class="form-select select2-native" id="cpFilterStatus">
                <option value="all">ทั้งหมด</option>
                <option value="active" selected>ทำงานอยู่</option>
                <option value="resigned">ลาออก</option>
            </select>
        </div>
        <div class="col-sm-2">
            <label class="form-label small mb-1">ทีม</label>
            <select class="form-select select2-native" id="cpFilterTeam">
                <option value="all">ทั้งหมด</option>
                <option value="1">ทีม A</option>
                <option value="2">ทีม B</option>
            </select>
        </div>
        <div class="col-sm-2">
            <label class="form-label small mb-1">ตำแหน่ง</label>
            <select class="form-select select2-native" id="cpFilterPosition">
                <option value="all">ทั้งหมด</option>
                <option value="1">เจ้าหน้าที่</option>
                <option value="2">หัวหน้างาน</option>
            </select>
        </div>
        <div class="col-sm-2">
            <label class="form-label small mb-1">สาขา</label>
            <select class="form-select select2-native" id="cpFilterBranch">
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
    <p class="cp-section-note">ของจริงมีอยู่แล้ว (<code>layout/header.php</code>'s bell + <code>public/js/notifications.js</code> + <code>NotificationModel</code>, ต่อ backend จริงครบ) -- <b>ไม่แตะรอบนี้</b> demo นี้คือ target design ใหม่ (แก้เป็นโครง "การ์ดต่อรายการ" ล่าสุด 2026-09-13) ที่ต่างจากของจริงจริงๆ 3 จุด (ไม่ใช่แค่สีที่ยังไม่ผ่าน token): <b>(1)</b> ไอคอนของจริงมีพื้นสีต่างกันตาม type (<code>.row-type-icon</code>, เหมือนหน้า Report) อันนี้พื้นสีเดียวกันทุก type ต่างแค่ตามอ่านแล้ว/ยังไม่อ่าน (เทา vs ส้มอ่อน) <b>(2)</b> จุดยังไม่อ่านของจริงอยู่ขวาของ item อันนี้ไม่มีจุดเลย (สัญญาณย้ายไปที่สี icon plate + น้ำหนัก title แทน) <b>(3)</b> ป้ายจำนวนของจริงโชว์ "99+" อันนี้โชว์ ">99" -- บันทึกไว้ใน rules.md §6 ให้รอบ 4 ตัดสินใจตอน migrate จริง ไม่ใช่เดาแทนตอนนี้. กระดิ่งเป็น <code>.btn-icon</code> วงกลมเดียวกับ row action (ของจริงเป็น <code>&lt;img&gt;</code> เปล่าไม่มีวงกลม) วางไว้ขวาสุดของ section นี้จำลองตำแหน่งจริงบน top bar (มุมขวาบน) -- dropdown เป็น Bootstrap dropdown จริง (<code>data-bs-toggle="dropdown"</code> + <code>dropdown-menu-end</code>, Popper คุมตำแหน่ง/พลิกด้านเองอัตโนมัติเมื่อชิดขอบจอ) กว้าง 380px สูงสุด 480px พื้น <code>--c-bg-subtle</code> radius <code>--radius-lg</code> (12px, surface ลอย ดู §1) เงานุ่ม <code>--shadow-soft</code> (ใหม่ 2026-09-13 -- กว้าง/จางกว่า <code>--shadow-modal</code>) <b>ไม่มีขอบเลยทั้งกล่อง</b> -- แต่ละรายการเป็นการ์ดจริง (พื้น <code>--c-bg</code> ล้วนๆ <b>ไม่มีขอบเช่นกัน</b>, radius <code>--radius-lg</code>) แยกจากพื้นกล่องด้วยสีพื้นต่างกันเท่านั้น เว้นช่องกันด้วย <code>gap</code> ไม่มีเส้นคั่นเลยทั้งบล็อก hover เปลี่ยนพื้นเป็น <code>--c-bg-hover</code> (ไม่มีขอบให้เปลี่ยนสีแล้ว) -- ส่วนรายการ scroll ด้วย <code>.scroll-thin</code> (class กลางใหม่, scrollbar บาง 6px ใช้ซ้ำได้ทั้งระบบ), badge เริ่มต้น = 3 (ตั้งตรงผ่าน <code>setNotificationCount()</code> ไม่ได้นับจาก list -- คนละ state กับของจริงที่ unread-count มาจาก endpoint แยกจาก dropdown เอง), list มี 5 รายการ (2 ยังไม่อ่าน) ผ่าน <code>renderNotifications()</code> -- กด "ทำเครื่องหมายว่าอ่านแล้วทั้งหมด" แล้วดู badge หาย + ไอคอนการ์ดทั้ง 5 ใบเปลี่ยนเป็นเทาปกติเหมือนกันหมด + title กลับเป็นน้ำหนักปกติ (re-render ผ่าน <code>renderNotifications()</code> เดิม, ไม่ใช่ฟังก์ชันแยก -- เมนูไม่ปิดตอนกดปุ่มนี้เพราะไม่ใช่ <code>.dropdown-item</code> และ Bootstrap ปิด dropdown แค่ตอนคลิกนอกเมนูหรือคลิก <code>.dropdown-item</code>). ทุกสีเป็น token ล้วน ลองสลับ theme มุมขวาบนดูด้วย.</p>
    <div class="d-flex justify-content-end">
        <div class="dropdown d-inline-block">
            <button type="button" class="btn-icon notif-bell-btn" id="cpNotifBellBtn" data-bs-toggle="dropdown" data-bs-display="dynamic" aria-expanded="false" aria-label="<?=htmlspecialchars(cpLangText('notifications', 'การแจ้งเตือน'))?>">
                <i class="fa-solid fa-bell"></i>
                <span class="notif-badge d-none" id="cpNotifBadge">0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end notif-dropdown" id="cpNotifDropdown">
                <div class="notif-dropdown-header">
                    <span data-i18n="notifications"><?=htmlspecialchars(cpLangText('notifications', 'การแจ้งเตือน'))?></span>
                    <button type="button" class="btn btn-link btn-sm" id="cpNotifMarkAllBtn" data-i18n="notif_mark_all_read"><?=htmlspecialchars(cpLangText('notif_mark_all_read', 'ทำเครื่องหมายว่าอ่านแล้วทั้งหมด'))?></button>
                </div>
                <div class="notif-list scroll-thin" id="cpNotifList"></div>
                <div class="notif-dropdown-footer">
                    <a href="#" class="btn btn-link btn-sm" data-i18n="notif_view_all"><?=htmlspecialchars(cpLangText('notif_view_all', 'ดูทั้งหมด'))?></a>
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
                <!-- design:ignore: text-primary deliberately kept HERE to prove the shared !important override neutralizes it -- removing it would defeat this exact demo's own point --><button type="button" class="btn btn-circle-action text-primary" title="ดู"><i class="fa-solid fa-eye"></i></button>
                <button type="button" class="btn btn-circle-action text-danger" title="ลบ"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
    </div>
</div>

<div class="cp-section">
    <h2>Badge / สถานะ (ข้อ 5)</h2>
    <p class="cp-section-note"><code>app/config/status_map.php</code> (data เดียวที่มา, ที่เดียวจริงๆ) + PHP <code>statusBadge($enum, $context)</code> (<code>app/helpers/helpers.php</code>) + JS <code>statusBadgeHtml(enum, context)</code> (<code>app.js</code>). <code>layout/header.php</code> (จุดเดียวกับที่ inject <code>BASE_URL</code>/<code>LANG_VERSION</code> อยู่แล้ว, ยกเว้นจากกฎ "ห้ามแตะหน้าจริง" เฉพาะบรรทัดนี้) ใส่ <code>window.STATUS_MAP = &lt;?=json_encode(loadStatusMap())?&gt;;</code> จาก PHP ตรงๆ ทุกหน้า -- <code>app.js</code> อ่านจาก <code>window.STATUS_MAP</code> เท่านั้น (ไม่มี copy ของตัวเองแล้ว ไม่มีความเสี่ยงเรื่อง drift อีกต่อไป) หน้านี้เองก็ใส่บรรทัดเดียวกันจาก <code>loadStatusMap()</code> จริงที่ require ไว้ตอนต้นไฟล์. ทุก context/enum ด้านล่าง render จริงผ่าน <code>statusBadgeHtml()</code> (ไม่ใช่ hardcode) -- enum ที่ไม่มีใน map จะเห็น badge เทา + label ดิบ + <code>console.warn()</code> (ลองเปิด console ดู "unmapped_demo" ท้ายสุด). <code>tone</code> ของ <code>run_state.approved</code> เป็น <code>warning</code> (ไม่ใช่ success) เพราะ "อนุมัติแล้ว" สำหรับคนทำเงินเดือนคือ "ต้องไปจ่ายต่อ" -- คนละความหมายกับ <code>approval_status.approved</code> ที่เป็น success (คำขอจบแล้ว) ตั้งใจให้ต่างกัน ไม่ใช่ bug. <code>data_source</code> ไม่ใช่สถานะจริง (§5) ใส่ไว้ชั่วคราวเป็น neutral ทั้งหมดเพื่อไม่พังตอน migrate รอบ 4.</p>
    <div id="cpBadgeShowcase"></div>
    <!-- 2026-09-15, Round 3 (comment-list restyle item 4) -- REPLACES this spot's own former
         outline-vs-ถม chip demo (.comment-tag-picker, deleted along with the chip row it documented).
         Rendered from JS because badgeDropdownHtml() is a JS-only helper (no PHP twin -- neither of
         its 2 real callers is server-rendered), see the demo script at the bottom of this file. -->
    <div class="mb-3">
        <div class="fw-semibold small text-uppercase text-muted mb-1">Badge dropdown (§5) — badge ที่เป็น dropdown toggle</div>
        <p class="cp-section-note"><code>badgeDropdownHtml(config)</code> + <code>initBadgeDropdown(scope, {onSelect})</code> (<code>app.js</code>) -- badge ที่กดได้ (มี ▾ จาก <code>.dropdown-toggle</code> ของ Bootstrap เอง) มี <b>2 โหมด</b>: <b>action menu</b> (<code>menuHtml</code> — caller ส่ง <code>&lt;li&gt;</code> มาเอง พร้อม handler ของตัวเอง — ของจริงคือ badge "ตรวจสอบแล้ว" ในตาราง Payroll Detail ที่เรียกผ่าน <code>statusBadgeHtml({menu})</code>) กับ <b>value picker</b> (<code>options</code> — แต่ละตัวเลือกเป็น statusBadge <b>outline</b> เสมอ, ตัวที่เลือกอยู่มี ✓ เทาท้ายบรรทัด, ค่าอยู่ใน <code>&lt;input type="hidden"&gt;</code> ให้ฟอร์ม/dirty-guard §9 อ่านได้ปกติ — ของจริงคือ tag picker ของ comment composer). คีย์บอร์ดมาจาก Bootstrap ตรงๆ (Esc ปิด, ↑/↓ เลื่อน, Enter เลือก) เพราะทุกแถวเป็น <code>&lt;button class="dropdown-item"&gt;</code> จริง — <b>ลองกดที่ badge ด้านล่างแล้วเลือกแท็กดู</b> (สลับ theme มุมขวาบนดู dark ด้วย).</p>
        <div id="cpBadgeDropdownShowcase" class="d-flex flex-wrap gap-4 align-items-center"></div>
    </div>
</div>

<div class="cp-section">
    <h2>Stepper (ข้อ 6)</h2>
    <p class="cp-section-note"><code>app/views/partials/status-stepper.php</code> + JS <code>renderStatusStepper(steps, current)</code> (<code>app.js</code>) -- <b>ย้าย Payroll Detail มาใช้จริงแล้ว 2026-09-13</b> (<code>payroll/detail.js</code>'s <code>renderProcessTimeline()</code>) -- โลจิกขั้น/branch ยังอยู่ที่ <code>runLifecycleSteps()</code>/<code>computeRunLifecycleProgress()</code> เดิมเป๊ะ ไม่แตะ, partial เองยัง "โง่" (ตัดสิน done/current/next จากตำแหน่งเทียบ <code>current</code> เท่านั้น ไม่มีปุ่ม action). <b>2026-09-13 same-day, "แยก 3 สถานะชัด" (§6 revised):</b> <b>เสร็จแล้ว</b> = วงกลม <code>--c-success-soft</code> + ✓ <code>--c-success</code>, label เต็มสี <code>--c-text</code> (ไม่จางแล้ว), เส้นเชื่อมช่วงที่อยู่หลังขั้นเสร็จ = <code>--c-success</code>; <b>ปัจจุบัน</b> = วงกลมตัน <code>--c-primary</code> + ไอคอนขาว 12px ของขั้นนั้น (แก้ ambiguity เดิมแล้ว, ดู item (5) ด้านล่าง), label <code>--c-text</code> หนา 600; <b>ยังมาไม่ถึง</b> = วงกลมว่างขอบ <code>--c-border-strong</code>, label <code>--c-text-faint</code> (จางกว่าขั้นเสร็จ), ไม่แสดงวันที่. รับเพิ่ม 3 อย่างต่อขั้น: <b>(1)</b> วันที่ (<code>{label, date}</code>, ใต้ label <code>--fs-xs</code> <code>--c-text-faint</code> ไม่มีไอคอนนาฬิกา) <b>(2)</b> <code>tone</code> ของขั้นปัจจุบันเท่านั้น (<code>{label, date, tone}</code>) ดึงจาก <code>statusMapEntry()</code>/<code>getStatusMapEntry(state, 'run_state')</code> จริงเสมอ ไม่ hardcode สี (ข้อ 5) <b>(3)</b> <code>final</code> (bool) บนขั้นที่เสร็จแล้วขั้นสุดท้ายเท่านั้น (<code>{label, date, final:true}</code>) -- ทำให้วงกลมนั้นตัน <code>--c-success</code> + ✓ สีขาว แทนที่ soft-done ปกติ (สำหรับรอบที่ "จบแล้วจริง" เช่น locked) <b>(4)</b> <code>live</code> (bool, ใหม่ 2026-09-13 item 3a "เก็บตก") บนขั้นปัจจุบันเท่านั้น (<code>{label, date, live:true}</code>) -- วงแหวน <code>--c-primary</code> ขยายออกเบาๆ ทุก 2.4s (วงกลมเองไม่กะพริบ, <code>prefers-reduced-motion</code> ปิดให้อัตโนมัติ) caller ต้องตัดสินเองว่า "ถึงตาผู้ใช้คนนี้" จริงไหม (มี decision/primary action ให้กด) ไม่ pulse ถ้าขั้นมี <code>tone</code> (branch state นิ่งอยู่แล้ว) <b>(5)</b> <code>icon</code> (string, ใหม่ 2026-09-13 item C ambiguity resolution) บนขั้นปัจจุบันเท่านั้น -- ไอคอนขาว 12px, bare Font Awesome class ไม่มี <code>fa-solid</code> prefix (partial/JS twin เติมให้เอง) -- <b>partial ไม่มี mapping ขั้น→ไอคอนของตัวเองเลย</b> caller ดึงจาก <code>runLifecycleSteps()</code>'s <code>step.icon</code> ซึ่ง sourced จาก <code>app.js</code>'s <code>RUN_LIFECYCLE_STEPS</code>/<code>RUN_LIFECYCLE_BRANCH_INFO</code> ที่เดียว (ตัวเดียวกับที่ Payroll Process List's mini-timeline ใช้อยู่แล้ว -- แก้ mapping ตรงนี้จึงกระทบทั้ง 2 หน้า ไม่ใช่แค่หน้านี้). ด้านล่างคือ 5 ขั้นจริงของรอบเงินเดือน (สร้างรายการ → ส่งอนุมัติ → อนุมัติ → จ่ายเงิน → ปิดรอบ) ที่ 5 กรณี: <b>draft</b> (ไอคอน <code>fa-calculator</code>)/<b>approved</b> (ปกติ ปัจจุบันสีส้มเหมือนเดิม), <b>pending_approval</b> (ใหม่ -- <code>live:true</code> เห็นวงแหวน pulse รอบขั้นปัจจุบัน), <b>locked</b> (ทุกขั้นเสร็จ ขั้นสุดท้าย <code>final:true</code> ตัน ✓ ขาว) และ <b>rejected</b> (ปัจจุบัน = "ไม่อนุมัติ / ส่งกลับแก้ไข" วงกลม <code>--c-danger</code> + ไอคอน <code>fa-xmark</code> ดึงจาก <code>statusMapEntry('rejected','run_state')</code>/<code>RUN_LIFECYCLE_BRANCH_INFO</code> จริง ไม่ใช่ mockup, <b>ไม่ pulse</b> แม้จะเป็นขั้นปัจจุบัน เพราะมี <code>tone</code>) -- ไม่มีกล่อง/การ์ดต่อขั้น (สลับ theme มุมขวาบนดู dark mode ด้วย).</p>
    <div id="cpStepperShowcase" class="d-flex flex-column gap-4"></div>
</div>

<!-- ==================== Callout (§15) ==================== -->
<?php
// Rendered through the REAL PHP partial (not just the JS twin) -- 5 tones, text taken verbatim from
// the real Payroll Detail i18n strings (next_step_draft/locked/need_info/rejected/cancelled) so this
// demo proves the actual copy/tone pairing used on the real page, not invented placeholder text.
$cpCallouts = [
    ['tone' => 'primary', 'text' => 'รอบนี้ยังเป็นแบบร่างอยู่ กด <b>คำนวณ</b> เพื่อคำนวณยอด แล้วจึง <b>ส่งอนุมัติ</b> เมื่อพร้อม'],
    ['tone' => 'success', 'text' => 'รอบนี้ถูกล็อกและปิดงานเรียบร้อยแล้ว ไม่ต้องดำเนินการเพิ่มเติม'],
    ['tone' => 'warning', 'text' => 'มีการขอข้อมูลเพิ่มเติม อ่านหมายเหตุด้านบน แล้วแก้ไขและส่งใหม่อีกครั้ง'],
    ['tone' => 'danger', 'text' => 'ถูกปฏิเสธ อ่านเหตุผลด้านบน รอการแก้ไขใหม่'],
    ['tone' => 'neutral', 'text' => 'รอบนี้ถูกยกเลิกแล้ว ไม่มีการดำเนินการต่อ'],
];
?>
<div class="cp-section">
    <h2>Callout (§15) — ใหม่</h2>
    <p class="cp-section-note"><code>app/views/partials/callout.php</code> + JS <code>calloutHtml(text, tone)</code> (<code>app.js</code>) -- แทน <code>.next-step-banner</code>/<code>.process-next-step</code> เดิมของ Payroll Detail (Round 3 item 3a follow-up). พื้น <code>--c-bg-subtle</code>, <code>--radius</code>, เส้นซ้าย 3px ตาม <code>tone</code> (<b>ข้อยกเว้นของ §3</b> -- การ์ด/stat ยังห้ามใช้สี tone บนขอบ/พื้นตัวเอง แต่ callout ใช้ได้เพราะนั่นคือหน้าที่ทั้งหมดของ component นี้), ตัวหนังสือ <code>--c-text</code> <code>--fs-base</code> เท่ากันทุก tone, <b>ไม่มีไอคอน</b> (เส้นซ้ายสีเดียวก็บอกความหมายพอแล้ว). <code>$text</code> เป็น HTML ดิบที่ caller เตรียมมาเอง (ไม่ escape ซ้ำ) เพื่อให้ตัวหนาคำ action ที่ตรงกับปุ่มจริงได้ (เช่น <code>&lt;b&gt;คำนวณ&lt;/b&gt;</code> ตรงกับปุ่ม "คำนวณ" ในตัวอย่าง <b>primary</b> ด้านล่าง) -- <code>.callout b/strong</code> คือ weight 600. 5 tone: <b>primary</b> (ขั้นต่อไป), <b>success</b> (จบแล้ว), <b>warning</b>, <b>danger</b>, <b>neutral</b> (<code>--c-border-strong</code>).</p>
    <div class="d-flex flex-column gap-2" style="max-width:640px;">
        <?php foreach ($cpCallouts as $co): $text = $co['text']; $tone = $co['tone']; ?>
        <div>
            <div class="small text-muted mb-1"><code>tone="<?=$tone?>"</code></div>
            <?php include __DIR__ . '/../../app/views/partials/callout.php'; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ==================== Setting row (§9/§11) ==================== -->
<div class="cp-section">
    <h2>Setting row (§9/§11) — ใหม่ 2026-09-14, 2 variant เพิ่ม 2026-09-14 (วันเดียวกัน)</h2>
    <p class="cp-section-note"><code>app/views/partials/setting-row.php</code> + JS <code>settingRowHtml({id,label,desc_on,desc_off,checked,variant})</code> (<code>app.js</code>) -- แถวตั้งค่า: label + คำอธิบายที่เปลี่ยนตามสถานะสวิตช์ได้ (ซ้าย) + switch. <b><code>variant</code> 2 แบบ ตามกฎ §9: 1-2 setting ในหน้า/section = <code>plain</code> (default), 3+ แถวซ้อนกัน = <code>card</code></b> (ทำให้เห็นว่า "กลุ่มนี้อยู่ด้วยกัน" เหมือนที่ <code>.filter-bar</code>/panel อื่นทำอยู่แล้ว -- แถว <code>plain</code> เดี่ยวๆ อ่านได้ปกติบนพื้นหน้าเปล่า แต่หลายแถว <code>plain</code> ซ้อนกันเริ่มอ่านเป็นข้อความหลวมๆ ไม่เป็นกลุ่ม). <b>คำอธิบายเปลี่ยนตามสถานะสวิตช์อัตโนมัติทั้ง 2 variant</b> ผ่าน <code>data-desc-on</code>/<code>data-desc-off</code> บน <code>.setting-row-desc</code> เอง -- delegated <code>change</code> handler กลางใน <code>app.js</code> (auto-wired ทั้งแอป ไม่ต้องเรียก init ใดๆ) สลับ <code>.html()</code> ให้เอง. เปลี่ยนที่มาจาก DOM programmatically (เช่น sync ค่าจาก server, revert ตอน save พลาด) ให้เรียก <code>syncSettingRowDesc($switchInput)</code> ตรงๆ แทน <code>.trigger('change')</code> -- กัน re-fire handler อื่นที่อาจผูกกับ switch ตัวเดียวกันอยู่แล้ว (เช่น save-on-toggle ของหน้าจริง) โดยไม่ตั้งใจ. ทั้ง 2 variant มี <code>margin-bottom: var(--sp-3)</code> ของตัวเอง (เหมือน <code>.filter-bar</code> มี <code>--sp-4</code> ของตัวเองอยู่แล้ว) ใช้จริงแล้วที่สวิตช์ "คำนวณอัตโนมัติ" ของ Payroll Detail (variant <code>plain</code>, แทน <code>#recalcReminderBanner</code> เดิมที่เคยลองมาแล้ว 2 รอบก่อนหน้า -- callout ก่อน แล้วข้อความเปล่าใต้สวิตช์).</p>
    <p class="cp-section-note mb-1"><b><code>plain</code> (default) -- ไม่มีกล่อง/พื้นเลย แถวเดียว <code>[switch] label · คำอธิบาย</code> สูง ~24px ชิดซ้าย (align กับ filter-bar ด้านล่างพอดี ไม่มี inset ของตัวเอง) -- ใช้จริงที่ Payroll Detail (1 setting เท่านั้น). Render จริงผ่าน PHP partial ตรงๆ, 2 สถานะเริ่มต้น -- สลับ theme มุมขวาบนดู light/dark ด้วย, ลองกดสวิตช์ดู:</b></p>
    <div class="d-flex flex-column mb-3" style="max-width:640px;">
        <?php
        $id = 'cpSettingRowPlainOn';
        $label = 'คำนวณอัตโนมัติทันทีหลังแก้ไขข้อมูล';
        $desc_on = 'ระบบจะคำนวณให้ทันทีเมื่อแก้ไขข้อมูล';
        $desc_off = 'หากแก้ไขข้อมูล ให้กด <b>คำนวณ</b> เองทุกครั้ง';
        $checked = true;
        // $variant omitted -- defaults to 'plain'.
        include __DIR__ . '/../../app/views/partials/setting-row.php';
        ?>
        <?php
        $id = 'cpSettingRowPlainOff';
        // $label/$desc_on/$desc_off already set above (same copy, reused -- only $checked differs).
        $checked = false;
        include __DIR__ . '/../../app/views/partials/setting-row.php';
        ?>
    </div>
    <p class="cp-section-note mb-1"><b>Render ผ่าน JS twin (<code>settingRowHtml()</code>), <code>plain</code>, byte-identical markup:</b></p>
    <div id="cpSettingRowJs" class="mb-4" style="max-width:640px;"></div>
    <p class="cp-section-note mb-1"><b><code>card</code> (<code>variant:'card'</code>) -- กล่องพื้น <code>--c-bg-subtle</code> เดิมจากรอบก่อน, label+คำอธิบายซ้อน 2 บรรทัดซ้าย/switch ขวา -- ใช้เมื่อมี 3+ setting ซ้อนกัน (จำลอง 3 แถวด้านล่าง):</b></p>
    <div style="max-width:640px;">
        <?php
        $id = 'cpSettingRowCard1';
        $label = 'คำนวณอัตโนมัติทันทีหลังแก้ไขข้อมูล';
        $desc_on = 'ระบบจะคำนวณให้ทันทีเมื่อแก้ไขข้อมูล';
        $desc_off = 'หากแก้ไขข้อมูล ให้กด <b>คำนวณ</b> เองทุกครั้ง';
        $checked = true;
        $variant = 'card';
        include __DIR__ . '/../../app/views/partials/setting-row.php';
        ?>
        <?php
        $id = 'cpSettingRowCard2';
        $label = 'แจ้งเตือนทางอีเมลเมื่อมีคำขออนุมัติใหม่';
        $desc_on = 'ส่งอีเมลแจ้งเตือนทันทีที่มีคำขอใหม่เข้ามา';
        $desc_off = 'จะไม่ได้รับอีเมลแจ้งเตือน ต้องเข้ามาดูเอง';
        $checked = false;
        include __DIR__ . '/../../app/views/partials/setting-row.php';
        ?>
        <?php
        $id = 'cpSettingRowCard3';
        $label = 'ล็อกรายการอัตโนมัติหลังจ่ายเงินแล้ว';
        $desc_on = 'ระบบจะล็อกรายการทันทีหลังจ่ายเงินสำเร็จ';
        $desc_off = 'ต้องกดล็อกรายการเองหลังจ่ายเงิน';
        $checked = true;
        include __DIR__ . '/../../app/views/partials/setting-row.php';
        ?>
    </div>
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
    <h2>Comment list + Composer (§6) — restyle 2026-09-15</h2>
    <p class="cp-section-note">JS <code>renderCommentList(items)</code> + <code>commentComposerHtml(config)</code> (<code>app.js</code>) -- component ของ <code>#employeeCommentModal</code> (Payroll Detail) <b>คนละหน้าที่กับ Timeline ด้านบนโดยเจตนา: Timeline ใช้กับ "ลำดับเหตุการณ์" เท่านั้น</b> (log ยาวไม่จำกัดของสิ่งที่เกิดขึ้นแล้ว เช่น audit log/ประวัติอนุมัติ — จุด+เส้นสื่อว่า "นี่คือขั้นหนึ่งในลำดับต่อเนื่อง") <b>ส่วน Comment list ใช้กับ "ใครพูดอะไร เมื่อไหร่"</b> (ความเห็นแต่ละอันเป็นหน่วยแยกจากกัน) — จึงไม่มีจุด/เส้นเชื่อม ไม่มีกรอบ/เส้นคั่นต่อรายการ ระยะห่างระหว่างรายการ <code>--sp-5</code>.</p>
    <p class="cp-section-note"><b>Composer (บนสุดเสมอ):</b> กล่องมีกรอบ <code>--c-border</code> <code>--radius-lg</code> — <b>โฟกัสในกล่อง (<code>:focus-within</code>) กรอบเปลี่ยนเป็นส้ม <code>--c-primary</code></b> (ไม่ใช่ฟ้า §3) ลองคลิกในช่องพิมพ์ด้านล่างดู; ภายใน: แถวบน avatar <b>28px</b> + ชื่อผู้เขียนตัวหนา <code>--fs-sm</code>, textarea <b>ไม่มีกรอบของตัวเอง</b> (กล่องคือกรอบ) ขยายอัตโนมัติตามเนื้อหา (auto-grow ของ <code>input.js</code> T002), เส้น <code>--c-border</code> 1 เส้นคั่น แล้วแถวล่าง: ซ้าย = <b>badge dropdown ตัวเดียวสำหรับแท็ก</b> (<code>badgeDropdownHtml()</code>, §5 — ดู section Badge ด้านบน; <b>ไม่มี label "แท็ก" นำหน้า</b>, ค่าเริ่มต้น "ไม่มีแท็ก" neutral outline), ขวา = ปุ่ม action ที่ caller ส่งมาเอง. ระยะจาก composer ลงไปรายการแรก <code>--sp-6</code> (กว้างกว่าระยะระหว่างรายการจริง ให้อ่านเป็นขอบเขต ไม่ใช่รายการถัดไป) — เป็น margin ของ component เอง ไม่ใช่ CSS ของหน้าที่เรียก.</p>
    <p class="cp-section-note"><b>1 รายการ = avatar เป็น gutter ซ้าย + คอลัมน์เนื้อหา 3 แถว:</b> avatar <b>28px</b> เป็นช่องซ้ายอย่างเดียว <b>ไม่มีอะไรอยู่ใต้มัน</b> — แถว 1 ชื่อ <code>--fs-sm</code> น้ำหนัก 600 + badge แท็ก (คงขนาดเดิม, ไม่มี badge เลยถ้าไม่มีแท็ก); แถว 2 ข้อความ <code>--fs-sm</code> <code>white-space:pre-line</code>; แถว 3 ซ้าย = เวลา relative <code>--fs-xs</code> เทา + tooltip เวลาเต็ม, ขวา = ปุ่มแก้ไข/ลบ <b>แสดงตลอด ไม่ต้อง hover</b> (ชิดขวาสุดของ container) — <b>ทั้ง 3 แถวเริ่มที่ขอบซ้ายเดียวกัน (ขวาของ avatar)</b>, ระยะชื่อ→ข้อความ <code>--sp-1</code> ข้อความ→เวลา <code>--sp-2</code>. <b>คั่นแต่ละรายการด้วยเส้น 1px <code>--c-border</code></b> (ยกเว้นรายการสุดท้าย) padding บน-ล่าง <code>--sp-4</code>. <b>รายการที่กำลังแก้</b> (composer ทั้งใบ) <b>เยื้องเข้ามาอยู่คอลัมน์เนื้อหาเดียวกัน และกว้างเต็มคอลัมน์</b>.</p>
    <p class="cp-section-note mb-1"><b>ว่าง (0 รายการ) — composer + empty state:</b></p>
    <div id="cpCommentEmptyShowcase" style="max-width:480px;"></div>
    <p class="cp-section-note mt-3 mb-1"><b>2 รายการปกติ + 1 รายการกำลังแก้ไข (inline edit = รายการนั้นกลายเป็น composer ทั้งใบ ผ่าน <code>item.bodyHtml</code>) -- สลับ theme มุมขวาบนดู light/dark:</b></p>
    <div id="cpCommentListShowcase" style="max-width:480px;"></div>
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
            <p class="cp-section-note mb-1"><b>Quick-view (§9):</b> modal ตัวอย่าง -- ตัดสินใจแล้ว: <code>modal-md</code> ไม่ทำ popover, หัวเป็น <code>.emp-header-card</code>, เนื้อหา 2 คอลัมน์เท่ากัน label/value, footer แค่ [ดูข้อมูลเต็ม] [ปิด] (primary ซ้าย, secondary ขวาสุด -- แก้ลำดับ 2026-09-14 Round 3 item 3c-1, ดู §4)</p>
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
        <button type="button" class="btn btn-outline-secondary" id="cpPageLoaderBtn">แสดง page-loader 2 วินาที</button>
    </div>
    <p class="cp-section-note mb-0"><code>showPageLoader()</code>/<code>hidePageLoader()</code> (<code>app.js</code>) + <code>page-loader.php</code> (<code>app/views/layout/</code>, ใหม่, §10/§11) -- overlay เต็มจอ ใช้เฉพาะโหลดหน้าครั้งแรก/เปลี่ยน route/ข้อมูลหลักยังไม่พร้อม เท่านั้น (ห้ามใช้กับ save/reload ตาราง/เปิด modal -- ใช้ปุ่ม spinner หรือ <code>.table-loading</code> แทน) -- ปรากฏหลัง delay 200ms กันกะพริบตอนโหลดเร็ว, fade-out 150ms ตอนปิด, backdrop <code>--c-bg</code> opacity .85 + blur 4px (ปรับตาม theme อัตโนมัติผ่าน <code>--c-bg-rgb</code> token ใหม่), วงแหวนเดียว <code>--c-primary</code> หมุน 1.2s -- ลองสลับ theme มุมขวาบนแล้วกดปุ่มซ้ำดู backdrop ปรับสีจริง</p>
    <p class="cp-section-note mb-0 mt-2"><code>data-dirty-guard</code> + <code>isFormDirty()</code>/<code>confirmIfDirtyThen()</code>/<code>refreshDirtyGuard()</code> (<code>app.js</code>, §9) -- opt-in ต่อ modal (ไม่ใช่ทุก <code>.modal</code> เหมือนกลไกที่เคยถูกสั่งปิดทั้งระบบไปเมื่อ 2026-09-09 เพราะสับสน -- ยืนยันกับผู้ใช้ตรงๆ ก่อนสร้างกลไกนี้กลับมาว่าออกแบบต่างจากเดิมจริง). ลอง 3 แบบ: <b>(1)</b> เปิดแล้ว<b>ปิดทันที</b>ไม่แตะอะไร -- ปิดได้เลยไม่ถาม. <b>(2)</b> เปิดแล้ว<b>พิมพ์อะไรสักอย่าง</b>ในช่องแล้วกด &times; หรือปุ่ม "ยกเลิก" (ปุ่ม "ยกเลิก" เป็นแค่ <code>data-bs-dismiss="modal"</code> ธรรมดา ไม่ได้เรียก <code>confirmIfDirtyThen()</code> เองเลยสักบรรทัด -- ผ่านกลไกเดียวกับ &times;/Esc/backdrop โดยอัตโนมัติ) -- ต้องเจอ dialog "มีข้อมูลที่ยังไม่ได้บันทึก" ก่อนปิดจริง. <b>(3)</b> พิมพ์อะไรสักอย่างแล้วกด "บันทึก (demo)" แล้วกด &times; อีกที -- ปิดได้เลยไม่ถาม (baseline ถูก refresh หลัง save).</p>
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

<!-- 2026-09-14, Phase Design Round 3 item 3c-1 follow-up -- real markup, not a mockup, but NOT the
     literal app/views/layout/page-loader.php include (that partial's own image src uses a short-echo
     PHP tag interpolating the BASE_URL CONSTANT, which this standalone dev page deliberately never
     defines, see this file's own docblock near the top on why -- same reason emp-header-card.php's
     demo further up passes plain arrays instead of real DB rows). Byte-identical class structure/ids
     otherwise, so the real CSS applies unmodified; the logo path is this file's own relative
     "../../public/..." convention, used everywhere else already (node_modules links above), not a
     real BASE_URL substitute.
     2026-09-14, real bug found and fixed (explicit report while testing the theme toggle further
     down in the body: clicking it did nothing at all) -- this comment used to spell out that PHP
     short-echo opening delimiter literally (backtick-wrapped, as if just prose). Per CLAUDE.md's own
     documented rule, PHP's lexer does not know it is "inside an HTML comment" -- it parses that exact
     2-character opening sequence anywhere in the raw file as the start of real code, regardless of
     context. Since this exact file never defines the BASE_URL constant, that literal opening fatally
     errored the moment the page was requested, truncating the ENTIRE rest of the page's output
     silently (curl showed a comment cut off mid-sentence, no visible error banner anywhere) -- which
     is exactly why nothing after this point in the file, including the real app.js include and the
     theme-toggle script further down, ever loaded at all. Rephrased below with no literal delimiter
     anywhere in this comment either (careful not to reintroduce the very bug being described -- the
     same trap CLAUDE.md notes hitting twice before while writing that rule's own explanation). Same
     class of fix CLAUDE.md's Code Convention section already documents once before in this same file
     (a cache-busting query-string example). -->
<div id="omPageLoader" class="om-page-loader d-none" aria-hidden="true">
    <div class="om-page-loader-stage">
        <div class="om-page-loader-ring"></div>
        <img class="om-page-loader-logo" src="../../public/images/origami_logo.png" alt="">
    </div>
    <div class="om-page-loader-text"><?=htmlspecialchars(cpLangText('processing', 'กำลังโหลด...'))?></div>
</div>

<div class="cp-section">
    <h2>Datepicker / Timepicker (§14, ข้อ 9)</h2>
    <p class="cp-section-note">Datepicker: migrate override เดิม (<code>style.css</code>'s "Bootstrap-datepicker" block) จาก token ชุดเก่า <code>--app-*</code> (T069) เป็นชุด <code>--c-*</code>/<code>--radius-lg</code>/<code>--shadow-modal</code> ของรอบนี้ -- วันนี้เป็นขอบ <code>--c-primary</code> (ไม่ใช่พื้นสี, แยกจากวันที่เลือกที่เป็นพื้นทึบ), วันปิด/นอกเดือน <code>--c-text-faint</code>, hover <code>--c-bg-hover</code>. Timepicker: <b>flatpickr time-only</b> (ตัดสินใจแล้ว -- native <code>&lt;input type="time"&gt;</code> ปรับสไตล์ popup ไม่ได้เลย) ผ่าน <code>initTimepicker()</code> (<code>input.js</code>, §11) <b>auto-init</b> ที่ class <code>.timepicker</code> ไม่ต้องเรียกเอง 24 ชม. ทีละ 5 นาที ค่า server เป็น <code>"HH:mm"</code> -- popup radius <code>--radius-lg</code> เงา <code>--shadow-soft</code> ตัวเลขที่กำลังตั้ง <code>--c-primary</code> hover <code>--c-bg-hover</code>.</p>
    <div class="d-flex flex-wrap gap-3">
        <input type="text" class="form-control datepicker" id="cpDatepickerDemo" style="max-width:220px;" placeholder="เลือกวันที่" autocomplete="off">
        <input type="text" class="form-control timepicker" id="cpTimepickerDemo" style="max-width:160px;" autocomplete="off">
    </div>
</div>

<div class="cp-section">
    <h2>Calendar widget (§14, ข้อ 9) -- component ใหม่ ยังไม่ต่อ dashboard จริง</h2>
    <p class="cp-section-note"><code>calendar-widget.php</code> + <code>renderCalendarWidget(el, {month, events, onSelect})</code> (<code>app.js</code>) -- โครงเดิมของ dashboard คงไว้ครบ (ตาราง 7 คอลัมน์/ปุ่มเลื่อนเดือน/dropdown เดือน/legend/ช่องรายละเอียด) class namespace ใหม่ทั้งหมด สีย้าย token ครบ 4 tone: holiday=<code>--c-danger</code>, ตัดรอบ=<code>--c-warning</code>, จ่ายเงิน=<code>--c-success</code>, สิ้นสุดทดลองงาน/ฝึกงาน=<code>--c-text-muted</code> (เทา ไม่ใช่ฟ้า -- ของจริงบน dashboard ตอนนี้เป็นสีครามซึ่งผิด §3, จดไว้ audit.md แล้ว). วันที่ 15 ของเดือนนี้ตั้งใจให้มี 2 event ซ้อนกันเพื่อโชว์ dots หลายจุด.</p>
    <div id="cpCalendarWidgetDemo" style="max-width:420px;"></div>
</div>

<div class="cp-section">
    <h2>Chart (§14, ข้อ 9) -- infra + demo เท่านั้น ยังไม่ migrate กราฟจริง</h2>
    <p class="cp-section-note">Token <code>--chart-1</code> (=<code>--c-primary</code>) ถึง <code>--chart-5</code> (เทาไล่ระดับ) + <code>--chart-grid</code> (=<code>--c-border</code>) และ helper <code>chartDefaults(overrides)</code>/<code>chartColors()</code> (<code>app.js</code>) ให้ทุกกราฟในอนาคตเรียกแทนตั้งสี/font/grid เอง. กฎใหม่: กราฟต้องมีข้อมูลเปรียบเทียบได้ (series เดียว &ge;6 จุด หรือหลาย series) &mdash; ค่าเดียว/แท่งเดียวใช้ stat card แทน; แท่งหลายแท่ง <em>series เดียวกัน</em> สีเดียว ยกเว้นแท่งที่เน้นสถานะจริง; legend ไม่มีสีรุ้ง. Bar 3 series ใช้ <code>--chart-1..3</code>, Line 2 series ใช้ <code>--chart-1</code>/<code>--chart-3</code> -- ลองสลับ theme มุมขวาบนดูสีปรับตามจริง.</p>
    <div class="row g-3">
        <div class="col-md-6">
            <div style="height:260px;"><canvas id="cpChartBarDemo"></canvas></div>
        </div>
        <div class="col-md-6">
            <div style="height:260px;"><canvas id="cpChartLineDemo"></canvas></div>
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
       (any host/port), not just this session's own dev server. COMPANY_CURRENCY_CODE gets a harmless
       static placeholder (this page never actually needs real currency data). LANG_VERSION gets a
       static placeholder too, but -- correction to this comment's own earlier claim, 2026-09-13 --
       real i18n DOES matter on this page now (Round 2's own DataTable/notification demos render
       real langData-driven chrome text): loadLang() (app.js) fetches `public/lang/{lang}.json`
       directly over HTTP, a plain static file needing no session/backend, confirmed working from
       this standalone page exactly like any real one. LANG_VERSION only feeds that fetch's cache-
       busting query string, so a static value is still fine (worst case, a stale cache on this one
       dev page -- never wrong content). -->
<script>
    var BASE_URL = window.location.origin + window.location.pathname.replace(/\/docs\/design\/components\.php$/, '');
    var LANG_VERSION = { th: 1, en: 1 };
    var COMPANY_CURRENCY_CODE = 'THB';
    // 2026-09-13, real bug found and fixed TWICE (a first attempt earlier the same day did not
    // actually resolve it -- explicit follow-up report + screenshot confirmed the notification
    // dropdown's chrome text was still rendering in the demo page's non-default language). Root cause
    // was NOT a wiring bug in this page's own i18n (public/lang/{th,en}.json both fetch fine, both
    // keys exist correctly in both files, confirmed directly) -- it's app.js's own real, app-wide
    // default: `currentLang = localStorage.getItem('preferred_language') || 'en'`. The first fix
    // attempt only seeded this value "if unset", which is correct behavior for a REAL page (never
    // clobber an actual logged-in user's saved choice) but not enough for THIS page: a leftover value
    // from earlier manual testing on this same browser origin (from before that seed existed, or a
    // real language-switcher click) survives an "if unset" guard forever, and a plain curl fetch can
    // never catch a client-side-only bug like this at all regardless. Fixed properly this time at 2
    // levels: (1) this page's own chrome text that used to be hardcoded Thai directly in the PHP (see
    // cpLangText()/$CP_LANG at the top of this file) now resolves via a real `?lang=` query param
    // instead, server-side, so its correctness is verifiable with a plain HTTP fetch + grep, no
    // browser/JS execution needed; (2) the seed below no longer checks "if unset" -- a dev-only tool
    // page has no real user preference worth preserving across visits, so always forcing it to match
    // this page's own resolved language is the right call, unlike the "if unset" guard app.js's own
    // REAL, session-driven default (used by every actual page) correctly still uses everywhere else.
    try {
        localStorage.setItem('preferred_language', <?=json_encode($CP_LANG)?>);
    } catch (e) {}
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
<!-- 2026-09-13, Round 2 item 9 -- this page's own datepicker/calendar/chart demo section needs
     bootstrap-datepicker's real JS (the CSS was already linked in <head> long before this, but the
     library script itself was never actually loaded here -- confirmed via grep, 0 hits before this)
     + Chart.js (loaded per-page in every real consumer, e.g. dashboard.php/employee/reports.php, not
     globally via footer.php -- same "load it on the page that needs it" pattern followed here). -->
<script src="../../node_modules/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js"></script>
<script src="../../node_modules/bootstrap-datepicker/dist/locales/bootstrap-datepicker.th.min.js"></script>
<script src="../../node_modules/flatpickr/dist/flatpickr.min.js"></script>
<script src="../../node_modules/chart.js/dist/chart.umd.min.js"></script>
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

    // 2026-09-13, "กดติดบ้างไม่ติดบ้าง" bug report -- manual stress-test panel wiring. 2 counters,
    // deliberately from 2 DIFFERENT sources so a real miss is visible as a mismatch, not just a low
    // number: cpFilterBarStressClickCount increments on every raw click the delegated listener below
    // actually receives (proves the click reached the handler at all); cpFilterBarStressChangeCount
    // increments once per debounced onChange (proves the whole pipeline -- select reset -> change
    // event -> refresh() -> onChange -- completed). The 2 numbers are NOT expected to always match 1:1
    // ("ล้างตัวกรอง" is 1 click but can reset 2 selects, still 1 onChange via the shared debounce; 2
    // rapid × clicks within the same tick coalesce into 1 onChange too, by design -- see
    // scheduleNotify()'s own comment above) -- what matters for THIS test is that the click counter
    // itself never stalls while repeatedly pressing × / Clear / the toggle in sequence.
    let cpStressClickCount = 0;
    let cpStressChangeCount = 0;
    $('#cpFilterBarDemo').on('click', '.filter-bar-chip-remove, .filter-bar-clear', function () {
        cpStressClickCount++;
        $('#cpFilterBarStressClickCount').text(cpStressClickCount);
    });
    $('#cpFilterBarFillAll').on('click', function () {
        $('#cpFilterView').val('active_only');
        $('#cpFilterStatus').val('active');
        $('#cpFilterTeam').val('1');
        $('#cpFilterPosition').val('2');
        $('#cpFilterBranch').val('1');
        $('#cpFilterDept').val(null).trigger('change');
        $('#cpFilterDept').append(new Option('ไอที', '3', true, true));
        $('#cpFilterView, #cpFilterStatus, #cpFilterTeam, #cpFilterPosition, #cpFilterBranch, #cpFilterDept').trigger('change');
        $('#cpFilterBarDemo').addClass('collapsed');
    });
    initFilterBar('#cpFilterBarDemo', {
        onChange: function () {
            cpStressChangeCount++;
            $('#cpFilterBarStressChangeCount').text(cpStressChangeCount);
            console.log('[filter-bar demo] onChange fired -- a real caller would reload its own table here.');
        },
    });
    // Re-populate both default filters so the tester can click ×/Clear, then reset, repeatedly --
    // without this there's nothing left to click after the first 1-2 removals.
    $('#cpFilterBarStressReset').on('click', function () {
        $('#cpFilterStatus').val('active').trigger('change');
        // 2026-09-13: #cpFilterDept is now a genuine select2-remote field (the repro fix below) -- a
        // static `.val('3').trigger('change')` would silently do nothing here, since a remote select
        // has no matching <option value="3"> to select unless one is actually appended first (the
        // SAME app-wide convention used to pre-fill any select2-remote field programmatically, e.g.
        // payroll/index.js's own #run_cycle_id populate call).
        $('#cpFilterDept').empty().append(new Option('ไอที', '3', true, true)).trigger('change');
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

    // countBadgeHtml() (§5) -- the plain number, and the `label` variant (2026-09-16) for a badge
    // that stands alone in a table cell where a bare number would not say what it counts.
    const $cpCountRow = $(
        '<div class="mb-3">' +
            '<div class="fw-semibold small text-uppercase text-muted mb-1">countBadgeHtml()</div>' +
            '<div class="d-flex flex-wrap gap-2 align-items-center"></div>' +
        '</div>'
    );
    [
        { label: 'ตัวเลขเปล่า (default, neutral)', html: countBadgeHtml(3) },
        { label: 'มี label + tone', html: countBadgeHtml(2, { tone: 'warning', label: getLangValue('calc_warning_count') || '{n} warnings' }) },
    ].forEach(function (item) {
        const $chip = $('<span class="d-inline-flex align-items-center gap-1 border rounded-2 px-2 py-1"></span>');
        $chip.append($('<code class="small text-muted"></code>').text(item.label));
        $chip.append(item.html);
        $cpCountRow.find('.d-flex').append($chip);
    });
    $cpBadgeShowcase.append($cpCountRow);

    // Badge dropdown (§5, 2026-09-15) -- BOTH modes of the same helper, side by side:
    //  (1) value picker: the exact config payroll/detail.js's own comment composer passes (4
    //      'employee_comment_tag' choices, 'none' marked `outline` so an untagged comment's toggle
    //      reads as an empty control) -- initBadgeDropdown() below makes it actually pick.
    //  (2) action menu: the shape Payroll Detail's verify badge uses, reached through
    //      statusBadgeHtml('verified','verify_status',{menu}) exactly as that page calls it, with a
    //      plain dropdown-item as the "action" (this demo has nothing to unverify, so the item is
    //      inert on purpose -- the point is the SHAPE, and that both callers go through one helper).
    const CP_TAG_OPTIONS = [
        { value: '', enum: 'none', outline: true },
        { value: 'in_progress', enum: 'in_progress' },
        { value: 'completed', enum: 'completed' },
        { value: 'error', enum: 'error' },
    ];
    $('#cpBadgeDropdownShowcase').html(
        '<div><div class="small text-muted mb-1">value picker (tag ของ comment composer)</div>'
        + badgeDropdownHtml({
            enum: 'none', context: 'employee_comment_tag', outline: true,
            options: CP_TAG_OPTIONS, value: '', name: 'cpDemoCommentTag',
        })
        + ' <span class="small text-muted ms-2" id="cpDemoTagValueOut"></span></div>'
        + '<div><div class="small text-muted mb-1">action menu (verify badge ของ Payroll Detail)</div>'
        + statusBadgeHtml('verified', 'verify_status', {
            menu: '<li><button type="button" class="dropdown-item"><i class="fa-solid fa-rotate-left text-secondary me-2"></i>'
                + escapeHtml(getLangValue('action_unverify') || 'Unverify') + '</button></li>',
        })
        + '</div>'
    );
    // Scope-level (delegated) init, the same way the real modal wires it -- one call covers every
    // badge dropdown inside, now and after any re-render.
    initBadgeDropdown('#cpBadgeDropdownShowcase', {
        onSelect: function (value) {
            $('#cpDemoTagValueOut').text('value = ' + JSON.stringify(value));
        },
    });

    // Stepper (ข้อ 6, ย้าย Payroll Detail มาใช้จริงแล้ว 2026-09-13) -- 5 ขั้นจริงของรอบเงินเดือน (ชื่อขั้น
    // ตรงกับ app.js's own RUN_LIFECYCLE_STEPS' doneKey labels: step_draft_done/step_submit_done/
    // state_approved/state_paid/state_locked) -- พิมพ์ตรงๆ ที่นี่แทนอ่านจาก langData เพื่อความง่าย
    // (เดโมอื่นในไฟล์นี้ก็ hardcode แบบนี้เป็นส่วนใหญ่) แม้หน้านี้จะมี i18n fetch จริงแล้วก็ตาม. `current`
    // ต่อ state ตามกฎเดียวกับ runLifecycleSteps() ของจริง (currentIndex = reachedIdx + 1): draft
    // (reachedIdx=0) -> current=1, approved (reachedIdx=2) -> current=3, paid (reachedIdx=3) ->
    // current=4 -- แต่ละขั้นที่ i<=current ได้วันที่ (feature ใหม่ 2026-09-13) เป็นตัวอย่างว่าเหมือน
    // payroll/detail.js's renderProcessTimeline() จริงเป๊ะ.
    const CP_STEPPER_LABELS = ['สร้างรายการ', 'ส่งอนุมัติ', 'อนุมัติ', 'จ่ายเงิน', 'ปิดรอบ'];
    // 2026-09-13, item C follow-up (icon resolution): SAME 5 icon values as the real
    // RUN_LIFECYCLE_STEPS (app.js), positional, printed directly here per this file's own
    // "พิมพ์ตรงๆ ที่นี่แทนอ่านจาก langData เพื่อความง่าย" convention -- not re-derived from the real
    // constant (which is tied to a real payroll run's own 5 stations, not a generic demo shape), but
    // kept byte-identical to it so this demo visually matches the real page exactly.
    const CP_STEPPER_ICONS = ['fa-calculator', 'fa-paper-plane', 'fa-list-check', 'fa-money-bill', 'fa-lock'];
    // 2026-09-13, item C follow-up: `markFinal` sets `final:true` on the LAST label only, for the
    // "locked" demo below (current pushed past the last index so every step, including the last,
    // renders --done -- the last one ALSO gets the final treatment: solid --c-success + white check).
    function cpStepperSteps(current, overrideAtCurrent, markFinal, markLive) {
        return CP_STEPPER_LABELS.map(function (label, i) {
            const step = { label: (overrideAtCurrent && i === current) ? overrideAtCurrent.label : label };
            if (i <= current) step.date = '13/09/2026';
            if (overrideAtCurrent && i === current && overrideAtCurrent.tone) step.tone = overrideAtCurrent.tone;
            if (markFinal && i === CP_STEPPER_LABELS.length - 1) step.final = true;
            // 2026-09-13, item 3a "เก็บตก" item 2: `markLive` sets `live:true` on the CURRENT step only
            // -- demo's own stand-in for "computeRunHeaderActions(run) returned a decision/primary
            // action for this viewer" (payroll/detail.js's real condition), no `tone` on this step so
            // the pulse actually renders (a toned/branch step never pulses per §6).
            if (markLive && i === current) step.live = true;
            // Icon (white, 12px) -- current step only, same "overrideAtCurrent supplies its own icon
            // when there's a branch" pattern the label/tone override already uses.
            if (i === current) {
                step.icon = (overrideAtCurrent && overrideAtCurrent.icon) ? overrideAtCurrent.icon : CP_STEPPER_ICONS[i];
            }
            return step;
        });
    }
    const $cpStepperShowcase = $('#cpStepperShowcase');
    // 2026-09-13, same-day follow-up: "rejected" ทดสอบผ่าน statusMapEntry(run_state) จริง (ไม่ hardcode
    // สี) -- ดึง tone/label ผ่าน getStatusMapEntry()/getLangValue() ตัวจริงเดียวกับที่
    // renderProcessTimeline() (payroll/detail.js) เรียกใช้ พิสูจน์ว่า wiring ทำงานจริง ไม่ใช่แค่ mockup.
    const cpRejectedEntry = getStatusMapEntry('rejected', 'run_state') || { tone: 'danger', label_key: null };
    const cpRejectedLabel = (cpRejectedEntry.label_key && getLangValue(cpRejectedEntry.label_key)) || 'ไม่อนุมัติ / ส่งกลับแก้ไข';
    // 2026-09-13, item C follow-up: icon pulled from the REAL RUN_LIFECYCLE_BRANCH_INFO (app.js, the
    // one place this mapping lives) -- not re-typed -- same "prove the wiring, not a mockup" pattern
    // the tone/label above already use.
    const cpRejectedIcon = (RUN_LIFECYCLE_BRANCH_INFO.rejected && RUN_LIFECYCLE_BRANCH_INFO.rejected.icon) || 'fa-xmark';
    [
        { title: 'draft (ไอคอนขั้นปัจจุบัน: fa-calculator)', current: 1 },
        { title: 'pending_approval (ถึงตาผู้ใช้คนนี้ -- live:true, วงแหวนเบาๆ รอบวงกลม)', current: 2, live: true },
        { title: 'approved', current: 3 },
        { title: 'locked (จบแล้ว -- ขั้นสุดท้าย final:true)', current: CP_STEPPER_LABELS.length, final: true },
        { title: 'rejected (tone + icon จาก RUN_LIFECYCLE_BRANCH_INFO/statusMapEntry จริง -- ไม่ pulse แม้จะมี tone)', current: 2, override: { label: cpRejectedLabel, tone: cpRejectedEntry.tone, icon: cpRejectedIcon } },
    ].forEach(function (demo) {
        const $block = $('<div></div>');
        $block.append($('<div class="fw-semibold small text-uppercase text-muted mb-2"></div>').text(demo.title));
        $block.append(renderStatusStepper(cpStepperSteps(demo.current, demo.override, demo.final, demo.live), demo.current));
        $cpStepperShowcase.append($block);
    });

    // Setting row (§9/§11) -- JS twin demo, byte-identical markup to the PHP partial rendered above.
    // Starts unchecked so clicking it demonstrates the on/off desc swap in the SAME direction as the
    // real Payroll Detail usage (a draft run's own switch starts off more often than on).
    $('#cpSettingRowJs').html(settingRowHtml({
        id: 'cpSettingRowJsSwitch',
        label: 'คำนวณอัตโนมัติทันทีหลังแก้ไขข้อมูล',
        desc_on: 'ระบบจะคำนวณให้ทันทีเมื่อแก้ไขข้อมูล',
        desc_off: 'หากแก้ไขข้อมูล ให้กด <b>คำนวณ</b> เองทุกครั้ง',
        checked: false,
    }));

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

    // Comment list + composer (§6) -- shape-for-shape the same calls the real modal makes
    // (payroll/detail.js's renderEmployeeCommentComposer()/employeeCommentInlineEditFormHtml()/
    // employeeCommentToListItem()), just with literal demo data instead of an AJAX payload and a
    // session user. Both demos render the SAME composer component -- the top-of-list compose box and
    // an item opened for inline edit are one component with different arguments, which is exactly
    // what this section is here to show.
    // Same 4 choices the real modal passes -- 'none' carries `outline` so an untagged composer's own
    // toggle reads as an empty control rather than a filled gray badge (badgeDropdownHtml(), §5).
    const CP_COMMENT_TAGS = [
        { value: '', enum: 'none', outline: true },
        { value: 'in_progress', enum: 'in_progress' },
        { value: 'completed', enum: 'completed' },
        { value: 'error', enum: 'error' },
    ];
    const CP_COMMENT_PLACEHOLDER = getLangValue('employee_comment_placeholder') || 'Write a comment...';
    function cpCommentItemActions() {
        return '<button type="button" class="btn-icon-ghost comment-item-icon-btn" title="' + (getLangValue('edit') || 'Edit') + '"><i class="fa-solid fa-pen"></i></button>'
            + '<button type="button" class="btn-icon-ghost comment-item-icon-btn comment-item-icon-btn-danger" title="' + (getLangValue('delete') || 'Delete') + '"><i class="fa-solid fa-trash-can"></i></button>';
    }
    // The compose box: 1 action button, nothing prefilled. `disabled` mirrors the real modal's own
    // starting state (nothing typed yet = nothing to save).
    const cpComposeBoxHtml = commentComposerHtml({
        idPrefix: 'cpCommentCompose',
        actor: { name: 'สมชาย ทำเงินเดือน' },
        placeholder: CP_COMMENT_PLACEHOLDER,
        tags: CP_COMMENT_TAGS,
        tagContext: 'employee_comment_tag',
        actions: `<button type="button" class="btn btn-primary" disabled>${getLangValue('save') || 'Save'}</button>`,
    });
    // Empty demo: composer + the SAME empty state renderCommentList() itself returns for 0 items
    // (caller-supplied copy, exactly like the real modal's own).
    $('#cpCommentEmptyShowcase').html(cpComposeBoxHtml + renderCommentList([], {
        emptyState: {
            icon: 'fa-solid fa-comments',
            title: getLangValue('employee_comment_timeline_empty') || 'No comments yet.',
        },
    }));
    // Inline edit: the whole item becomes a composer (prefilled text + tag, 2 buttons, the COMMENT'S
    // OWN author in the head row) -- passed through as `item.bodyHtml`, which replaces the entire
    // <li> rather than just its text row.
    const cpCommentInlineEditHtml = commentComposerHtml({
        idPrefix: 'cpCommentInlineEdit',
        actor: { name: 'สมชาย ทำเงินเดือน' },
        text: 'ขอเลื่อนตรวจสอบไปสัปดาห์หน้า เอกสารยังมาไม่ครบ',
        tag: 'in_progress',
        placeholder: CP_COMMENT_PLACEHOLDER,
        tags: CP_COMMENT_TAGS,
        tagContext: 'employee_comment_tag',
        actions: `<button type="button" class="btn btn-primary">${getLangValue('save') || 'Save'}</button>`
            + `<button type="button" class="btn btn-outline-secondary">${getLangValue('cancel') || 'Cancel'}</button>`,
    });
    const CP_COMMENT_ITEMS = [
        {
            time: '2026-09-14 15:40:00',
            actor: { name: 'สมชาย ทำเงินเดือน' },
            text: 'ตรวจสอบยอดโบนัสแล้ว ถูกต้องตามที่แจ้งไว้ ปิดรายการนี้ได้เลย',
            badge: { enum: 'completed', context: 'employee_comment_tag' },
            actions: cpCommentItemActions(),
        },
        {
            // Real edited-comment shape (timeSuffix, §6's own note on why this field exists at all) +
            // a genuine 2-line comment to show `white-space: pre-line` preserving the real newline.
            time: '2026-09-13 09:15:00',
            timeSuffix: `(${getLangValue('employee_comment_edited') || 'edited'})`,
            actor: { name: 'สมหญิง ฝ่ายบุคคล' },
            text: 'รอตรวจสอบเอกสารเพิ่มเติมจากพนักงาน\nจะอัปเดตอีกครั้งพรุ่งนี้',
            badge: { enum: 'in_progress', context: 'employee_comment_tag' },
            actions: cpCommentItemActions(),
        },
        {
            // Mid-inline-edit -- no time/actions/badge of its own: the composer that replaces this
            // whole item renders its own author row, its own tag chips and its own buttons.
            bodyHtml: cpCommentInlineEditHtml,
        },
    ];
    $('#cpCommentListShowcase').html(cpComposeBoxHtml + renderCommentList(CP_COMMENT_ITEMS));
    // Both comment showcases contain composers, and a composer's tag control is a badge dropdown --
    // same delegated init the real modal uses (one call per scope, survives re-renders).
    initBadgeDropdown('#cpCommentEmptyShowcase');
    initBadgeDropdown('#cpCommentListShowcase');

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
    // Page-loader (§10/§11) -- real showPageLoader()/hidePageLoader(), 2 real seconds so the
    // 200ms appear-delay and 150ms fade-out are both actually visible, not instant.
    $('#cpPageLoaderBtn').on('click', function () {
        showPageLoader();
        setTimeout(hidePageLoader, 2000);
    });

    // Datepicker (§14, ข้อ 9) -- real bootstrap-datepicker via the real initDatepicker(), proving the
    // token override above actually applies (not a mockup screenshot of one).
    initDatepicker('#cpDatepickerDemo');

    // Calendar widget (§14, ข้อ 9) -- component demo only, events hand-built for "this month" (not
    // fetched from any backend) so the demo always shows something regardless of when this page is
    // opened. Day 15 deliberately carries 2 events (cutoff + payment landing the same day, a real
    // combination this app's own payroll cycles can produce) to show the multi-dot case.
    (function () {
        const now = new Date();
        const y = now.getFullYear();
        const m = now.getMonth() + 1;
        const pad = function (n) { return String(n).padStart(2, '0'); };
        const events = [
            { date: y + '-' + pad(m) + '-05', tone: 'danger', label: 'วันหยุดชดเชย' },
            { date: y + '-' + pad(m) + '-15', tone: 'warning', label: 'วันตัดรอบเงินเดือน' },
            { date: y + '-' + pad(m) + '-15', tone: 'success', label: 'วันจ่ายเงินเดือน' },
            { date: y + '-' + pad(m) + '-25', tone: 'muted', label: 'สิ้นสุดทดลองงาน: สมชาย ใจดี' },
        ];
        renderCalendarWidget('#cpCalendarWidgetDemo', { month: { year: y, month: m }, events: events });
    })();

    // Chart (§14, ข้อ 9) -- infra demo only, chartDefaults()/chartColors() reused instead of each
    // chart setting its own colors (the pattern all 8 REAL charts in the app currently do -- see
    // docs/design/audit.md's 2026-09-13 addendum; real pages are NOT migrated here, that's round 4).
    // Bar = 3 genuinely different series (each internally one color, per §14's own rule -- not 1
    // series with a rainbow color per bar). Line = 2 series, same ramp.
    if (typeof Chart !== 'undefined') {
        const chartLabels = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.'];
        const colors = chartColors();
        new Chart(document.getElementById('cpChartBarDemo').getContext('2d'), {
            type: 'bar',
            data: {
                labels: chartLabels,
                datasets: [
                    { label: 'ต้นทุนเงินเดือน', data: [420, 435, 410, 460, 455, 470], backgroundColor: colors[0], borderRadius: 4 },
                    { label: 'สปส.', data: [28, 29, 27, 30, 30, 31], backgroundColor: colors[1], borderRadius: 4 },
                    { label: 'กยศ.', data: [12, 12, 11, 13, 12, 13], backgroundColor: colors[2], borderRadius: 4 },
                ],
            },
            options: chartDefaults(),
        });
        new Chart(document.getElementById('cpChartLineDemo').getContext('2d'), {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [
                    { label: 'พนักงานเข้าใหม่', data: [4, 6, 3, 7, 5, 8], borderColor: colors[0], backgroundColor: colors[0], tension: .3 },
                    { label: 'พนักงานลาออก', data: [2, 3, 4, 2, 3, 2], borderColor: colors[2], backgroundColor: colors[2], tension: .3 },
                ],
            },
            options: chartDefaults(),
        });
    }
    }); // end (window.langReady || Promise.resolve()).then(...)
});
</script>
<script>
// The payee-destination demo drives the REAL component (app.js) -- the only page-local part is the
// line that echoes what payeeDestinationType() would send, which a real form does at submit time.
$(function () {
    if (typeof initPayeeDestination !== 'function') return;
    initPayeeDestination('cpPayee', {
        employeeWrap: '#cpPayeeEmployeeWrapper',
        companyWrap: '#cpPayeeCompanyWrapper',
        externalWrap: '#cpPayeeExternalWrapper',
        onChange: function (payeeType) {
            $('#cpPayeeTypeOut').text(payeeType === 'none' ? 'payee_type: (ไม่ส่งคีย์นี้เลย = NULL)' : 'payee_type: ' + payeeType);
        },
    });
});
</script>
<script>
// 2026-09-14, real bug found and fixed -- see the toggle buttons' own HTML comment (above, near
// `.cp-theme-toggle`) for the full story. No theme logic of its own anymore: reads the LIVE
// data-bs-theme attribute for initial button state (the one true source, same attribute layout/
// header.php stamps server-side and app.js's applyTheme()/setTheme() manage from then on), and
// every click calls the SAME setTheme(theme) helper app.js's real Settings modal Save button calls
// -- exactly one mechanism can ever change the live theme now, not a 2nd separate one duplicating
// its logic under its own localStorage key.
(function () {
    function currentThemeFromDom() {
        var attr = document.documentElement.getAttribute('data-bs-theme');
        return attr === 'dark' ? 'dark' : (attr === 'light' ? 'light' : 'system');
    }
    function reflectActive(theme) {
        document.querySelectorAll('[data-cp-theme]').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-cp-theme') === theme);
        });
    }
    document.querySelectorAll('[data-cp-theme]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var theme = btn.getAttribute('data-cp-theme');
            reflectActive(theme);
            if (typeof setTheme === 'function') {
                setTheme(theme);
            } else {
                // app.js failed to load entirely (see #cpDepBanner further down) -- DOM-only
                // fallback so these buttons still do something instead of silently no-op'ing.
                if (theme === 'dark' || theme === 'light') {
                    document.documentElement.setAttribute('data-bs-theme', theme);
                } else {
                    document.documentElement.removeAttribute('data-bs-theme');
                }
            }
        });
    });
    // 2026-09-14, re-audit follow-up: no client-side theme seed here anymore -- the attribute this
    // reads is now stamped SERVER-SIDE (top of this file, same 3-way branch as layout/header.php's
    // own), before any CSS loads, so it's already correct at this point exactly like a real page.
    // "ทดสอบสลับ 3 โหมด...แล้ว reload ค่าคง" now holds for the real reason (server ui_theme persisted +
    // read back on the next request), not a client-side localStorage read standing in for it.
    reflectActive(currentThemeFromDom());
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
    if (typeof window.Chart === 'undefined') {
        missing.push('Chart.js (node_modules/chart.js/dist/chart.umd.min.js)');
    }
    if (typeof window.flatpickr === 'undefined') {
        missing.push('flatpickr (node_modules/flatpickr/dist/flatpickr.min.js)');
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
        'emptyStateHtml', 'dtRenderEmptyState', 'renderCalendarWidget', 'chartDefaults', 'chartColors',
        'showPageLoader', 'hidePageLoader',
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
