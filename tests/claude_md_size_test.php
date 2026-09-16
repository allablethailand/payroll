<?php
/**
 * Guards CLAUDE.md's own size (2026-09-14 -- it hit 226,514 chars, past Claude Code's 150k warning
 * threshold, almost entirely from per-round decision history/bug narratives piling up inside
 * project-guide sections over many sessions). That history was moved to `docs/decisions/*.md`
 * (copied whole, not trimmed) and each source section replaced with a short current-rules summary
 * + a pointer line -- see CLAUDE.md's own Process section for the standing rule this test enforces:
 * "ห้ามเพิ่มประวัติ/เหตุผลยาวใน CLAUDE.md ให้ไป docs/decisions/".
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. No DB access needed.
 * Run with: php tests/claude_md_size_test.php
 */
declare(strict_types=1);

const MAX_CHARS = 80000;

$passes = 0;
$failures = 0;

$path = __DIR__ . '/../CLAUDE.md';
$content = file_get_contents($path);

if ($content === false) {
    $failures++;
    echo "  FAIL  could not read $path\n";
} else {
    $len = mb_strlen($content, 'UTF-8');
    if ($len > MAX_CHARS) {
        $failures++;
        echo "  FAIL  CLAUDE.md is $len chars, over the " . MAX_CHARS . "-char limit. Move round-by-round " .
            "history/decision narrative into docs/decisions/<หัวข้อ>.md (copy whole, don't trim it) and " .
            "leave only a short current-rules summary + pointer line in CLAUDE.md.\n";
    } else {
        $passes++;
        echo "  PASS  CLAUDE.md is $len chars (limit " . MAX_CHARS . ")\n";
    }
}

echo "\nPassed: {$passes}, Failed: {$failures}\n";
exit($failures > 0 ? 1 : 0);
