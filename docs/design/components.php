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

<!-- ==================== Form control (§9) ==================== -->
<div class="cp-section">
    <h2>Form control (§9)</h2>
    <p class="cp-section-note">Focus ring ส้ม (§3) คลิกเข้าช่องด้านล่างเพื่อดูจริง (:focus ทำ mockup ไม่ได้) -- ช่องที่ 3 คือ invalid state.</p>
    <div class="cp-row">
        <div>
            <label class="form-label">ปกติ</label>
            <input type="text" class="form-control" placeholder="พิมพ์เพื่อดู focus ring">
        </div>
        <div>
            <label class="form-label">Select</label>
            <select class="form-select">
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
        ธรรมดา -- ยังไม่ผ่าน <code>statusBadge()</code> เพราะข้อ 5 ยังไม่ทำ) / action (<code>.col-actions</code>).
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
                    <button type="button" class="btn btn-link btn-sm p-0 me-2" title="ดู"><i class="fa-solid fa-eye"></i></button>
                    <button type="button" class="btn btn-link btn-sm p-0 text-danger" title="ลบ"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>
</div>

<!-- ==================== ยังไม่ทำ -- placeholder สำหรับข้อ 4-7 ==================== -->
<div class="cp-section">
    <h2>Page Header / Stat Card / Filter Bar (ข้อ 4)</h2>
    <p class="cp-section-note"><code>page-header.php</code>, <code>stat-card.php</code> (class <code>.stat</code>), <code>filter-bar.php</code> (§2, §6)</p>
    <div class="cp-empty">ยังไม่ทำ -- รอข้อ 4</div>
</div>

<div class="cp-section">
    <h2>Badge / สถานะ (ข้อ 5)</h2>
    <p class="cp-section-note"><code>status_map.php</code>, PHP <code>statusBadge()</code> / JS <code>statusBadgeHtml()</code> (§5)</p>
    <div class="cp-empty">ยังไม่ทำ -- รอข้อ 5</div>
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
<script src="../../public/js/table-column-filter.js"></script>
<script src="../../public/js/sticky-table-columns.js"></script>
<!-- Loads the REAL app.js (not a copy) so initSharedDataTable() below is always byte-identical to
     production, zero drift risk -- deliberately NOT reimplemented/copy-pasted here. app.js assumes
     a full app page shell for a few unrelated things it does on $(document).ready() (i18n fetch via
     BASE_URL, which this standalone page never defines; theme/font-size sync; login-timezone
     recording) -- those specific bits will harmlessly fail/no-op here (console warnings at most, not
     thrown synchronously into anything else's path) since this page never calls into them; every
     DOM-dependent initializer elsewhere in app.js already guards on `$('#selector').length` per this
     project's own established convention, so nothing here throws just because the normal navbar/
     sidebar/etc. markup doesn't exist. initSharedDataTable() itself has no dependency on any of that
     -- confirmed by reading its own function body. -->
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
