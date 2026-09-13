<?php
/**
 * scripts/check-design.php -- docs/design/rules.md §12 lint, Round 2 item 8 (the last item of round 2).
 *
 * Implements all 8 rules from §12 verbatim:
 *   1. hex/rgb/hsl in CSS outside tokens.css, and in style="" / JS string literals
 *      (exempt: `--bs-*-rgb:` lines in style.css's Bootstrap-override section -- a mechanical
 *      RGB-triplet decomposition of an EXISTING token, not a new color)
 *   2. inline `style="` in a view/JS (exempt: `display:none` in JS, a table column's own `width`)
 *   3. forbidden classes: btn-success/info/warning/light/dark, btn-outline-(?!secondary),
 *      bg-primary/info/success, text-primary/info/success, border-primary, rounded-circle, btn-circle
 *   4. `<i class="fa` inside a `.nav-link` (a tab icon)
 *   5. `.DataTable(`/`.dataTable(` outside initSharedDataTable() (app.js's own definition is exempt)
 *   6. `Swal.fire(` outside app.js/alert.js
 *   7. `number_format(` in a view / `.toLocaleString(` in JS (format-helpers.js's own fmtNum() is exempt)
 *   8. `<span class="badge` with no `data-badge="status"` marker on the SAME tag
 *
 * "โหมดผ่อน" (lenient mode, per §12's own words): every file gets COUNTED, but only a file that has
 * opted in via a `design:clean` marker near its own top (`// design:clean` as the first line for
 * CSS/JS, `<?php // design:clean` for PHP -- detected loosely, as a substring anywhere in the first
 * 10 lines, not a rigid single-line format, so a real docblock-style file can still carry it) FAILS
 * the run if it has any hits at all. A file with no marker never fails, no matter how many hits it
 * has -- it just gets reported, so round 4 can pick it up later without this script blocking
 * unrelated work in the meantime.
 *
 * A SEPARATE, line-level escape hatch (`design:ignore` anywhere on the line, comment syntax doesn't
 * matter -- plain substring match) exists for the rare, deliberate case where a line intentionally
 * needs to contain something that LOOKS like a violation but genuinely isn't one for THIS specific
 * line -- e.g. components.php's own "before" demo showing `.text-danger`/`.text-primary` stacked on
 * `.btn-circle-action` to PROVE the shared `!important` override neutralizes them (removing those
 * classes would defeat the demo's whole point). Used sparingly, each use commented with why.
 *
 * Attribute/token-aware matching, not naive whole-line regex, for rules 1 (view-type only)/2/3/4/8 --
 * an earlier version of this script scanned whole lines for these and produced real false positives
 * on this exact file (components.php is FULL of prose discussing class names inside `<code>` tags as
 * documentation, e.g. "`.btn-outline-brand` ไม่แสดงแยกที่นี่..." -- a plain substring match would
 * wrongly flag that sentence as if it were a real `class="btn-outline-brand"` usage) and on
 * hyphenated-compound class names (`.notif-badge`/`.btn-circle-action` both contain "badge"/"circle"
 * as a SUBSTRING with a `\b` word boundary on both sides, since `-` counts as a non-word character --
 * `\bbadge\b` matches inside "notif-badge" the exact same way it matches the real Bootstrap `.badge`
 * utility class). Fixed by extracting the actual `class="..."`/`style="..."` attribute VALUE first,
 * then testing token-by-token (exact-match or `badge-*`/`btn-outline-*` prefix, never a bare
 * substring) against that extracted value alone.
 *
 * Scan targets, per §12's own list, PLUS one deliberate widening for this round specifically:
 *   - app/views/**\/*.php (views, including every app/views/partials/*.php)
 *   - public/js/**\/*.js (no vendor/min files exist under public/js today -- this app's own JS is
 *     100% first-party, vendor code lives in node_modules -- so nothing is actually excluded in
 *     practice, but the exclusion check itself is still coded so a future vendor drop-in doesn't
 *     silently get scanned)
 *   - public/css/*.css, EXCEPT tokens.css is skipped from rule #1 specifically (it's the canonical
 *     SOURCE of the hex values, that's the entire point of it existing) -- it's still collected and
 *     still checked against rules #2-8, which trivially pass on a pure custom-property file, and
 *     still expected to carry its own `design:clean` marker (explicit instruction this round) so the
 *     file list below doesn't silently omit it from "reviewed and clean" bookkeeping.
 *   - docs/design/components.php -- NOT part of §12's own literal `app/views/**` scan target (it
 *     lives under docs/, not app/views/), but explicitly added to the scan list THIS round on
 *     instruction ("components.php...ต้อง mark design:clean และผ่าน lint จริง") since it's the one
 *     real page-shaped PHP file this whole Round 2 initiative has been writing to since item 2.
 *
 * Every rule function below is a pure function (lines in, an array of hits out) so
 * tests/design_lint_test.php can require this file and call them directly, exactly like
 * scripts/check-lang.php's own checkLangFiles() is already reused by tests/lang_check_test.php --
 * only the block at the very bottom, guarded to run when this file is executed DIRECTLY (not when
 * required), prints the human-readable report and sets the process exit code.
 *
 * Usage: php scripts/check-design.php
 * Exit code: 0 if no `design:clean`-marked file has any hit, 1 if any does.
 */

declare(strict_types=1);

const DESIGN_RULE_LABELS = [
    1 => 'hex/rgb/hsl outside tokens.css / in style="" / JS string',
    2 => 'inline style="',
    3 => 'forbidden Bootstrap classes',
    4 => '<i class="fa" inside .nav-link',
    5 => '.DataTable(/.dataTable( outside initSharedDataTable()',
    6 => 'Swal.fire( outside app.js/alert.js',
    7 => 'number_format(/.toLocaleString( outside fmtNum()',
    8 => '<span class="badge" without data-badge="status"',
];

/** Recursively lists files under $dir with extension $ext (no leading dot), sorted for stable output. */
function designLintGlobRecursive(string $dir, string $ext): array {
    if (!is_dir($dir)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if (strtolower($file->getExtension()) === strtolower($ext)) {
            $out[] = $file->getPathname();
        }
    }
    sort($out);
    return $out;
}

/**
 * Collects every file to lint, grouped by type ('view'|'js'|'css') -- see this file's own top
 * docblock for exactly what's included/excluded and why.
 */
function designLintCollectFiles(string $root): array {
    $views = designLintGlobRecursive($root . '/app/views', 'php');
    // Deliberate widening this round -- see top docblock.
    $componentsPhp = $root . '/docs/design/components.php';
    if (is_file($componentsPhp)) {
        $views[] = $componentsPhp;
    }
    $js = array_values(array_filter(
        designLintGlobRecursive($root . '/public/js', 'js'),
        function (string $f): bool {
            // No vendor/min files exist under public/js today (confirmed before writing this) --
            // this guard exists so a future vendor drop-in doesn't silently get scanned/flagged.
            return strpos($f, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) === false
                && substr($f, -7) !== '.min.js';
        }
    ));
    $css = [];
    foreach (['style.css', 'tokens.css'] as $name) {
        $path = $root . '/public/css/' . $name;
        if (is_file($path)) $css[] = $path;
    }
    sort($views);
    return ['view' => $views, 'js' => $js, 'css' => $css];
}

/** Loose file-level marker detection -- see top docblock for why this isn't a rigid single-line format. */
function designLintHasCleanMarker(string $file): bool {
    $fh = @fopen($file, 'r');
    if (!$fh) return false;
    $head = '';
    for ($i = 0; $i < 10 && !feof($fh); $i++) {
        $head .= (string) fgets($fh);
    }
    fclose($fh);
    return strpos($head, 'design:clean') !== false;
}

/** Line-level escape hatch -- see top docblock. Deliberately not comment-syntax-aware (plain
 * substring), same "loose on purpose" reasoning as the file-level marker above. */
function designLintLineIgnored(string $line): bool {
    return strpos($line, 'design:ignore') !== false;
}

/** Extracts every `attr="value"`/`attr='value'` match for the given attribute name on one line. */
function designLintExtractAttr(string $line, string $attr): array {
    $values = [];
    if (preg_match_all('/\b' . $attr . '\s*=\s*(["\'])(.*?)\1/i', $line, $m)) {
        $values = $m[2];
    }
    return $values;
}

/** Rule 1 -- hex/rgb/hsl. CSS files: whole-line (it's styling code, not prose) with the one stated
 * `--bs-*-rgb:` exemption. View/JS files: restricted to the VALUE of a `style=""` attribute (views)
 * or a quoted string literal (JS) -- NOT a naive whole-line scan, which produced real false positives
 * on this exact file: components.php's own token-reference swatch table stores each token's hex
 * value in a plain PHP array (`['--c-primary', '#FF9900', ...]`, for the swatch demo to render) and
 * its own prose mentions Bootstrap's hardcoded `#0d6efd` in a sentence explaining WHY an override was
 * needed -- neither is a real color literal introduced outside the token system, both are legitimate
 * documentation content, and a naive whole-line scan can't tell the difference. */
function designLintRule1(array $lines, string $type): array {
    $hits = [];
    $hexRe = '/#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{4}|[0-9a-fA-F]{3})\b/';
    $funcRe = '/\b(rgba?|hsla?)\s*\(/i';
    foreach ($lines as $i => $line) {
        if (designLintLineIgnored($line)) continue;
        if ($type === 'css') {
            if (preg_match('/--bs-[a-z0-9-]*-rgb\s*:/i', $line)) continue;
            if (preg_match($hexRe, $line) || preg_match($funcRe, $line)) {
                $hits[] = ['rule' => 1, 'line' => $i + 1, 'text' => trim($line)];
            }
            continue;
        }
        if ($type === 'view') {
            foreach (designLintExtractAttr($line, 'style') as $value) {
                if (preg_match($hexRe, $value) || preg_match($funcRe, $value)) {
                    $hits[] = ['rule' => 1, 'line' => $i + 1, 'text' => trim($line)];
                    break;
                }
            }
            continue;
        }
        // JS: restrict to quoted string literals (single/double/backtick) -- a hex/rgb mention in a
        // // or /* */ comment is not a real color literal in the running code.
        if (preg_match_all('/(["\'`])((?:\\\\.|(?!\1).)*)\1/', $line, $m)) {
            foreach ($m[2] as $strContent) {
                if (preg_match($hexRe, $strContent) || preg_match($funcRe, $strContent)) {
                    $hits[] = ['rule' => 1, 'line' => $i + 1, 'text' => trim($line)];
                    break;
                }
            }
        }
    }
    return $hits;
}

/** True if a style="" VALUE is one of the 2 documented exemptions, or one of 2 pragmatic extensions
 * of the same underlying principle ("a per-instance numeric dimension, not a hardcoded decorative
 * value") -- documented individually below, each traced to a REAL, already-shipped, already-approved
 * pattern this round found while writing the lint, not invented speculatively:
 *   (a) `display:none` -- JS only, §12.2's own stated exemption (a temporary toggle).
 *   (b) a lone `width:` declaration -- §12.2's own stated exemption (a table column's own width).
 *   (c) EVERY declaration in the value is a pure dimension/layout property (width, height, min-width,
 *       max-width, font-size, border-radius, object-fit, object-position -- no color anywhere) --
 *       this is
 *       `apvAvatarHtml()`'s (app.js, §11's own shared avatar helper) and its PHP twin
 *       (emp-header-card.php, item 6c) OWN established output shape: an avatar's SIZE is a per-call
 *       parameter, not a fixed set of classes, so `style="width:40px;height:40px;min-width:40px;
 *       border-radius:50%;object-fit:cover;"` is exactly how this ALREADY-SHIPPED, §11-listed
 *       component has always rendered -- flagging it would mean the shared avatar helper itself can
 *       never be marked design:clean, which is clearly not what rule 2 is trying to catch (a
 *       genuinely hardcoded, should-be-a-CSS-class decorative style). Widens exemption (b)'s own
 *       "a dimension, not a decoration" principle rather than special-casing this one helper by name.
 *   (d) every declaration's VALUE is a pure `var(--token)` reference (any property) -- by definition
 *       this cannot hardcode a new raw value, it's a token lookup; components.php's own token-swatch
 *       grid needs exactly this (`style="background:var(--c-primary)"`, looped over ~20 token names
 *       to render each one's live preview -- there is no sane fixed-class alternative for "show me
 *       this token's actual current color" across an arbitrary token list).
 */
function designLintStyleValueExempt(string $value, string $type): bool {
    $value = trim($value);
    if ($value === '') return true;
    if ($type === 'js' && preg_match('/^display\s*:\s*none\s*;?$/i', $value)) return true;
    $dimensionProps = [
        'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height',
        'font-size', 'border-radius', 'object-fit', 'object-position',
    ];
    $decls = array_filter(array_map('trim', explode(';', $value)));
    if (!$decls) return true;
    foreach ($decls as $decl) {
        if (!preg_match('/^([a-z-]+)\s*:\s*(.+)$/i', $decl, $m)) return false;
        $prop = strtolower(trim($m[1]));
        $val = trim($m[2]);
        if (in_array($prop, $dimensionProps, true)) continue;
        if (preg_match('/^var\(\s*--[a-z0-9-]+\s*(,\s*[^)]+)?\)$/i', $val)) continue;
        return false;
    }
    return true;
}

/** Rule 2 -- inline style="", exemptions checked against the ATTRIBUTE VALUE itself (see
 * designLintStyleValueExempt() above), not the whole line, so a line with multiple style="" (or
 * other content) isn't wrongly exempted just because ONE of them qualifies. */
function designLintRule2(array $lines, string $type): array {
    $hits = [];
    foreach ($lines as $i => $line) {
        if (designLintLineIgnored($line)) continue;
        foreach (designLintExtractAttr($line, 'style') as $value) {
            if (!designLintStyleValueExempt($value, $type)) {
                $hits[] = ['rule' => 2, 'line' => $i + 1, 'text' => trim($line)];
                break;
            }
        }
    }
    return $hits;
}

/** Rule 3 -- forbidden classes, matched TOKEN-BY-TOKEN against extracted class="" values (both plain
 * views and JS-built markup use class="..." the same way, including inside a backtick template
 * literal -- the double/single-quoted class="..." substring inside it extracts identically). Exact
 * token match or an explicit prefix check (`btn-outline-*`, never a bare substring) -- this is what
 * keeps `.btn-circle-action` (contains "circle" but is not `.btn-circle`) and prose mentioning a
 * class name inside a `<code>` tag (no real class="" attribute at all) from false-triggering.
 *
 * `.btn-decision-success`/`.btn-decision-warning`/`.btn-decision-danger` (2026-09-13, decision-set
 * follow-up, rules.md §4's documented exception) are DELIBERATELY not in `$exactForbidden` below and
 * need no explicit allowlist entry either -- they already pass through untouched, since exact-token
 * matching means a whole different string (`btn-decision-success`) never equals a forbidden one
 * (`btn-success`), and the `btn-outline-*` prefix check only matches THAT prefix, not `btn-decision-*`.
 * Documented here so a future tightening of this rule (e.g. switching to a substring/prefix check)
 * doesn't accidentally sweep these 3 up without someone reading this comment first. */
function designLintRule3(array $lines): array {
    $hits = [];
    $exactForbidden = [
        'btn-success', 'btn-info', 'btn-warning', 'btn-light', 'btn-dark',
        'bg-primary', 'bg-info', 'bg-success',
        'text-primary', 'text-info', 'text-success',
        'border-primary', 'rounded-circle', 'btn-circle',
    ];
    foreach ($lines as $i => $line) {
        if (designLintLineIgnored($line)) continue;
        $hit = false;
        $matchedToken = null;
        foreach (designLintExtractAttr($line, 'class') as $classValue) {
            $tokens = preg_split('/\s+/', trim($classValue), -1, PREG_SPLIT_NO_EMPTY);
            $lowerTokens = array_map('strtolower', $tokens);
            foreach ($lowerTokens as $t) {
                if (in_array($t, $exactForbidden, true)) { $hit = true; $matchedToken = $t; break; }
                if (preg_match('/^btn-outline-(?!secondary$)[a-z]+$/', $t)) { $hit = true; $matchedToken = $t; break; }
            }
            // §8/item D, 2026-09-13: `text-danger` alone stays legal (destructive dropdown items etc.
            // still use it plainly) -- but combined with `.num` on the SAME element it's an ad-hoc
            // money color bypassing the shared `.money-gross`/`.money-deduction`/`.money-net` classes
            // (§8's own money-color system), so THAT specific pairing is forbidden. `text-success` on
            // a `.num` element is already caught by the blanket `text-success` ban above -- no
            // separate pairing check needed for it.
            if (!$hit && in_array('num', $lowerTokens, true) && in_array('text-danger', $lowerTokens, true)) {
                $hit = true; $matchedToken = 'text-danger (on .num)';
            }
            if ($hit) break;
        }
        if ($hit) {
            $hits[] = ['rule' => 3, 'line' => $i + 1, 'text' => trim($line), 'match' => $matchedToken];
        }
    }
    return $hits;
}

/** Rule 4 -- `<i class="fa` inside a `.nav-link`. The "nav-link" trigger requires an ACTUAL
 * `class="...nav-link..."` attribute (token-matched, same reasoning as rule 3), not a bare substring
 * anywhere on the line -- an earlier version triggered on this file's OWN prose discussing the
 * `.nav-link` class as documentation. Real markup spans multiple lines (an opening
 * `<button class="nav-link ...">`, then the icon a line or few below it) -- audit.md's own SC9 count
 * used the same loose "nearby lines" proximity, not a full DOM parse, so the WINDOW itself (5 lines
 * forward) still mirrors that methodology rather than introducing a stricter one that would silently
 * disagree with the round-1 baseline for no real reason. */
function designLintRule4(array $lines): array {
    $hits = [];
    $windowEnd = -1;
    foreach ($lines as $i => $line) {
        foreach (designLintExtractAttr($line, 'class') as $classValue) {
            $tokens = preg_split('/\s+/', trim($classValue), -1, PREG_SPLIT_NO_EMPTY);
            if (in_array('nav-link', array_map('strtolower', $tokens), true)) {
                $windowEnd = $i + 5;
                break;
            }
        }
        if ($i <= $windowEnd && !designLintLineIgnored($line)
            && preg_match('/<i\b[^>]*class\s*=\s*["\'][^"\']*\bfa[a-z-]*\b/i', $line)) {
            $hits[] = ['rule' => 4, 'line' => $i + 1, 'text' => trim($line)];
        }
    }
    return $hits;
}

/** Rule 5 -- .DataTable(/.dataTable( outside initSharedDataTable(). app.js is exempt wholesale (the
 * caller passes the exempt filename in, see the dispatcher below) since it's the ONE file allowed to
 * call this directly, inside initSharedDataTable()'s own body. */
function designLintRule5(array $lines): array {
    $hits = [];
    foreach ($lines as $i => $line) {
        if (designLintLineIgnored($line)) continue;
        if (preg_match('/\.(DataTable|dataTable)\s*\(/', $line)) {
            $hits[] = ['rule' => 5, 'line' => $i + 1, 'text' => trim($line)];
        }
    }
    return $hits;
}

/** Rule 6 -- Swal.fire( outside app.js/alert.js (both exempt, passed in by the caller). */
function designLintRule6(array $lines): array {
    $hits = [];
    foreach ($lines as $i => $line) {
        if (designLintLineIgnored($line)) continue;
        if (preg_match('/\bSwal\.fire\s*\(/', $line)) {
            $hits[] = ['rule' => 6, 'line' => $i + 1, 'text' => trim($line)];
        }
    }
    return $hits;
}

/** Rule 7 -- number_format( in a view, .toLocaleString( in JS. format-helpers.js's own fmtNum()
 * definition is exempt (the caller skips calling this for that one file). */
function designLintRule7(array $lines, string $type): array {
    $hits = [];
    $re = $type === 'view' ? '/\bnumber_format\s*\(/' : '/\.toLocaleString\s*\(/';
    foreach ($lines as $i => $line) {
        if (designLintLineIgnored($line)) continue;
        if (preg_match($re, $line)) {
            $hits[] = ['rule' => 7, 'line' => $i + 1, 'text' => trim($line)];
        }
    }
    return $hits;
}

/** Rule 8 -- <span class="badge" without a data-badge="status" marker on the SAME tag. Inspects each
 * <span ...> tag's own attributes (not the whole line), and matches "badge" as an EXACT class token
 * or a `badge-*` prefix -- never a bare substring, which would otherwise also match unrelated
 * hyphenated classes like `.notif-badge` (the notification-count pill, a completely different
 * component, item 6d) simply because "badge" appears inside the compound name with a word boundary
 * on each side. Single-line tags only (matches this project's own established markup style,
 * confirmed by reading every real statusBadgeHtml()/badge call site during item 5 -- none split a
 * <span>'s own attributes across lines). */
function designLintRule8(array $lines): array {
    $hits = [];
    foreach ($lines as $i => $line) {
        if (designLintLineIgnored($line)) continue;
        if (!preg_match_all('/<span\b([^>]*)>/i', $line, $m, PREG_SET_ORDER)) continue;
        foreach ($m as $match) {
            $attrs = $match[1];
            // Extract class="" specifically out of this tag's own attribute string.
            $classValues = [];
            if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $attrs, $cm)) {
                $classValues[] = $cm[2];
            }
            $hasBadge = false;
            foreach ($classValues as $classValue) {
                foreach (preg_split('/\s+/', trim($classValue), -1, PREG_SPLIT_NO_EMPTY) as $t) {
                    $t = strtolower($t);
                    if ($t === 'badge' || preg_match('/^badge-[a-z0-9_-]+$/', $t)) { $hasBadge = true; break 2; }
                }
            }
            if (!$hasBadge) continue;
            if (preg_match('/data-badge\s*=\s*["\']status["\']/i', $attrs)) continue;
            $hits[] = ['rule' => 8, 'line' => $i + 1, 'text' => trim($line)];
        }
    }
    return $hits;
}

/**
 * Runs every applicable rule against one file, given its already-classified $type ('view'|'js'|'css')
 * and basename-based exemptions. Returns a flat array of hits (see the rule functions' own shapes).
 */
function designLintCheckFile(string $path, string $type): array {
    $content = (string) file_get_contents($path);
    $lines = explode("\n", $content);
    $base = basename($path);
    $hits = [];

    if ($type === 'css') {
        // tokens.css is the canonical hex source -- rule 1 does not apply to it at all (§12's own
        // "ยกเว้น tokens.css"). Every other rule trivially finds nothing in a pure custom-property
        // file, so they still run (cheap, and correct if that ever changes).
        if ($base !== 'tokens.css') {
            $hits = array_merge($hits, designLintRule1($lines, 'css'));
        }
        $hits = array_merge($hits, designLintRule2($lines, 'css'));
        $hits = array_merge($hits, designLintRule3($lines));
        $hits = array_merge($hits, designLintRule8($lines));
        return $hits;
    }

    // view or js from here down.
    $hits = array_merge($hits, designLintRule1($lines, $type));
    $hits = array_merge($hits, designLintRule2($lines, $type));
    $hits = array_merge($hits, designLintRule3($lines));
    $hits = array_merge($hits, designLintRule4($lines));
    if ($type === 'js' && $base !== 'app.js') {
        $hits = array_merge($hits, designLintRule5($lines));
    }
    if ($type === 'js' && $base !== 'app.js' && $base !== 'alert.js') {
        $hits = array_merge($hits, designLintRule6($lines));
    }
    if (!($type === 'js' && $base === 'format-helpers.js')) {
        $hits = array_merge($hits, designLintRule7($lines, $type));
    }
    $hits = array_merge($hits, designLintRule8($lines));
    return $hits;
}

/**
 * Runs the full lint across the whole app. Returns:
 *   'files' => [ path => ['type'=>.., 'clean'=>bool, 'hits'=>[...], 'countsByRule'=>[1=>n,...]] ]
 *   'totalsByRule' => [1=>n, ..., 8=>n] (app-wide, every file, regardless of marker)
 *   'failed' => [path, ...] (design:clean-marked files that still have >=1 hit)
 */
function designLintRun(string $root): array {
    $files = designLintCollectFiles($root);
    $result = ['files' => [], 'totalsByRule' => array_fill(1, 8, 0), 'failed' => []];
    foreach ($files as $type => $paths) {
        foreach ($paths as $path) {
            $hits = designLintCheckFile($path, $type);
            $countsByRule = array_fill(1, 8, 0);
            foreach ($hits as $h) {
                $countsByRule[$h['rule']]++;
                $result['totalsByRule'][$h['rule']]++;
            }
            $clean = designLintHasCleanMarker($path);
            $result['files'][$path] = [
                'type' => $type,
                'clean' => $clean,
                'hits' => $hits,
                'countsByRule' => $countsByRule,
            ];
            if ($clean && count($hits) > 0) {
                $result['failed'][] = $path;
            }
        }
    }
    return $result;
}

if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $root = dirname(__DIR__);
    $result = designLintRun($root);

    echo "=== docs/design/rules.md §12 lint (lenient mode) ===\n\n";

    $markedFiles = array_filter($result['files'], fn($f) => $f['clean']);
    $unmarkedFiles = array_filter($result['files'], fn($f) => !$f['clean']);

    echo "-- design:clean files (" . count($markedFiles) . ") --\n";
    ksort($markedFiles);
    foreach ($markedFiles as $path => $info) {
        $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
        $rel = str_replace('\\', '/', $rel);
        $total = array_sum($info['countsByRule']);
        $status = $total === 0 ? 'PASS' : 'FAIL';
        echo "  [$status] $rel";
        if ($total > 0) {
            $parts = [];
            foreach ($info['countsByRule'] as $rule => $n) {
                if ($n > 0) $parts[] = "#$rule=$n";
            }
            echo ' (' . implode(', ', $parts) . ')';
        }
        echo "\n";
    }

    echo "\n-- unmarked files with hits (reported, not failed -- top 20 by total hit count) --\n";
    $unmarkedTotals = [];
    foreach ($unmarkedFiles as $path => $info) {
        $total = array_sum($info['countsByRule']);
        if ($total > 0) $unmarkedTotals[$path] = $total;
    }
    arsort($unmarkedTotals);
    $shown = 0;
    foreach ($unmarkedTotals as $path => $total) {
        if ($shown++ >= 20) break;
        $rel = str_replace('\\', '/', str_replace($root . DIRECTORY_SEPARATOR, '', $path));
        $info = $result['files'][$path];
        $parts = [];
        foreach ($info['countsByRule'] as $rule => $n) {
            if ($n > 0) $parts[] = "#$rule=$n";
        }
        echo "  $rel: $total (" . implode(', ', $parts) . ")\n";
    }
    $remaining = count($unmarkedTotals) - $shown;
    echo $remaining > 0 ? "  ... $remaining more file(s) with hits not shown\n" : "  (all shown)\n";

    echo "\n-- app-wide totals per rule --\n";
    foreach (DESIGN_RULE_LABELS as $rule => $label) {
        echo "  §12.$rule ({$label}): {$result['totalsByRule'][$rule]}\n";
    }

    echo "\n=== Summary ===\n";
    echo 'Files scanned: ' . count($result['files']) . ' (' . count($markedFiles) . " marked design:clean)\n";
    if ($result['failed']) {
        echo "FAILED -- " . count($result['failed']) . " design:clean file(s) still have lint hits:\n";
        foreach ($result['failed'] as $path) {
            echo '  - ' . str_replace('\\', '/', str_replace($root . DIRECTORY_SEPARATOR, '', $path)) . "\n";
        }
        exit(1);
    }
    echo "PASSED -- every design:clean file has 0 hits.\n";
    exit(0);
}
