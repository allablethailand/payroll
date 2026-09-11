<?php
/**
 * Batch 4 item 1: detect duplicate keys within each of public/lang/th.json and en.json, plus keys
 * present in one file but missing from the other.
 *
 * json_decode() silently keeps only the LAST occurrence of a duplicate object key (verified: PHP
 * and every mainstream JS JSON.parse behave the same way) -- so a duplicate is otherwise invisible
 * to the app itself, and the FIRST occurrence's value is simply dead weight nobody ever sees. This
 * file parses the raw JSON text itself (a small hand-rolled tokenizer/parser, not json_decode) so
 * every occurrence of a duplicate key -- not just the one that survives -- is visible, with its own
 * line number and value.
 *
 * The actual check logic lives in checkLangFiles() below so tests/lang_check_test.php can reuse it
 * verbatim (require this file, call the function, assert on the result) instead of duplicating the
 * parser -- per this project's own "generalize, don't mirror-copy" rule. Only the block at the
 * bottom of this file, guarded to run when this file is executed DIRECTLY (not when required by
 * the test), prints the human-readable report and sets the process exit code.
 *
 * Usage: php scripts/check-lang.php
 * Exit code: 0 if nothing found, 1 if any duplicate or cross-file key mismatch was found.
 */

declare(strict_types=1);

function tokenizeJson(string $src): array {
    $tokens = [];
    $len = strlen($src);
    $i = 0;
    $line = 1;
    while ($i < $len) {
        $ch = $src[$i];
        if ($ch === "\n") { $line++; $i++; continue; }
        if ($ch === ' ' || $ch === "\t" || $ch === "\r") { $i++; continue; }
        $startLine = $line;
        if ($ch === '{') { $tokens[] = ['type' => '{', 'line' => $startLine]; $i++; continue; }
        if ($ch === '}') { $tokens[] = ['type' => '}', 'line' => $startLine]; $i++; continue; }
        if ($ch === '[') { $tokens[] = ['type' => '[', 'line' => $startLine]; $i++; continue; }
        if ($ch === ']') { $tokens[] = ['type' => ']', 'line' => $startLine]; $i++; continue; }
        if ($ch === ':') { $tokens[] = ['type' => ':', 'line' => $startLine]; $i++; continue; }
        if ($ch === ',') { $tokens[] = ['type' => ',', 'line' => $startLine]; $i++; continue; }
        if ($ch === '"') {
            $raw = '"';
            $i++;
            while ($i < $len && $src[$i] !== '"') {
                if ($src[$i] === '\\' && $i + 1 < $len) {
                    $raw .= $src[$i] . $src[$i + 1];
                    if ($src[$i + 1] === "\n") { $line++; }
                    $i += 2;
                } else {
                    if ($src[$i] === "\n") { $line++; }
                    $raw .= $src[$i];
                    $i++;
                }
            }
            $raw .= '"';
            $i++;
            $decoded = json_decode($raw);
            if ($decoded === null && $raw !== '"null"') {
                throw new RuntimeException("Invalid JSON string literal at line $startLine: $raw");
            }
            $tokens[] = ['type' => 'string', 'value' => $decoded, 'line' => $startLine];
            continue;
        }
        if (preg_match('/\G-?\d+(\.\d+)?([eE][+-]?\d+)?/', $src, $m, 0, $i)) {
            $tokens[] = ['type' => 'number', 'value' => (float)$m[0], 'line' => $startLine];
            $i += strlen($m[0]);
            continue;
        }
        if (substr($src, $i, 4) === 'true') { $tokens[] = ['type' => 'bool', 'value' => true, 'line' => $startLine]; $i += 4; continue; }
        if (substr($src, $i, 5) === 'false') { $tokens[] = ['type' => 'bool', 'value' => false, 'line' => $startLine]; $i += 5; continue; }
        if (substr($src, $i, 4) === 'null') { $tokens[] = ['type' => 'null', 'value' => null, 'line' => $startLine]; $i += 4; continue; }
        throw new RuntimeException("Unexpected character '{$ch}' at line $startLine");
    }
    $tokens[] = ['type' => 'eof', 'line' => $line];
    return $tokens;
}

/**
 * Recursive-descent parser. Returns [value, duplicates] where `value` mirrors what json_decode()
 * itself would produce (last-occurrence-wins, same as the real app), and `duplicates` is a flat
 * list of every object-scope key that appeared more than once, each entry:
 * {path, key, occurrences: [{value, line}, ...]} (occurrences in file order, ALL of them, not just
 * the 2nd+).
 */
function parseJsonTracked(array $tokens): array {
    $pos = 0;
    $duplicates = [];

    $peek = function () use (&$tokens, &$pos) { return $tokens[$pos]; };
    $advance = function () use (&$tokens, &$pos) { return $tokens[$pos++]; };
    $expect = function (string $type) use (&$peek, &$advance) {
        $t = $peek();
        if ($t['type'] !== $type) {
            throw new RuntimeException("Expected '{$type}' but found '{$t['type']}' at line {$t['line']}");
        }
        return $advance();
    };

    $parseValue = function (string $path) use (&$parseValue, &$peek, &$advance, &$expect, &$duplicates) {
        $t = $peek();
        switch ($t['type']) {
            case '{':
                $advance();
                $seen = []; // key => list of {value, line}
                $result = [];
                if ($peek()['type'] !== '}') {
                    while (true) {
                        $keyTok = $expect('string');
                        $key = $keyTok['value'];
                        $expect(':');
                        $childPath = $path === '' ? $key : "$path.$key";
                        $val = $parseValue($childPath);
                        $seen[$key][] = ['value' => $val, 'line' => $keyTok['line']];
                        $result[$key] = $val; // last occurrence wins, same as json_decode()
                        if ($peek()['type'] === ',') { $advance(); continue; }
                        break;
                    }
                }
                $expect('}');
                foreach ($seen as $k => $occ) {
                    if (count($occ) > 1) {
                        $duplicates[] = ['path' => $path, 'key' => $k, 'occurrences' => $occ];
                    }
                }
                return $result;
            case '[':
                $advance();
                $result = [];
                $idx = 0;
                if ($peek()['type'] !== ']') {
                    while (true) {
                        $result[] = $parseValue("$path[$idx]");
                        $idx++;
                        if ($peek()['type'] === ',') { $advance(); continue; }
                        break;
                    }
                }
                $expect(']');
                return $result;
            case 'string':
            case 'number':
            case 'bool':
            case 'null':
                return $advance()['value'];
            default:
                throw new RuntimeException("Unexpected token '{$t['type']}' at line {$t['line']}");
        }
    };

    $value = $parseValue('');
    $expect('eof');
    return [$value, $duplicates];
}

function flattenKeys(array $data, string $prefix = ''): array {
    $out = [];
    foreach ($data as $k => $v) {
        $path = $prefix === '' ? (string)$k : "$prefix.$k";
        if (is_array($v)) {
            $out = array_merge($out, flattenKeys($v, $path));
        } else {
            $out[$path] = $v;
        }
    }
    return $out;
}

function valueToDisplay($v): string {
    if (is_string($v)) return $v;
    if (is_bool($v)) return $v ? 'true' : 'false';
    if ($v === null) return 'null';
    return (string)$v;
}

// Find real usage (langData['key'] / langData["key"] / langData.key / data-i18n="key" /
// getLangValue('key')) across the codebase for a duplicated key, so the report can say whether the
// key is even referenced anywhere -- doesn't prove which VALUE is displayed (that's always the last
// occurrence, per json_decode()'s own documented-here behavior), just whether the key is dead
// entirely. Scans files directly in PHP rather than shelling out to `grep -e` -- escapeshellarg()
// on Windows PHP silently strips embedded double-quote characters from an argument (confirmed while
// building this: every double-quote-containing pattern silently became unmatchable), so a
// grep-based version would have falsely reported several genuinely-used keys as unreferenced.
function projectSourceFiles(string $root): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $skipDirs = ['.git', 'node_modules', 'vendor', 'storage', 'public/uploads'];
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        $path = $fileInfo->getPathname();
        $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
        $skip = false;
        foreach ($skipDirs as $d) {
            if (strpos($relative, "$d/") === 0) { $skip = true; break; }
        }
        if ($skip) continue;
        $ext = $fileInfo->getExtension();
        if ($ext === 'php' || $ext === 'js') $files[] = $path;
    }
    $cache = $files;
    return $cache;
}

function isKeyReferenced(string $key, string $projectRoot): bool {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $escaped = preg_quote($key, '/');
    $patterns = [
        "/langData\\['$escaped'\\]/",
        '/langData\["' . $escaped . '"\]/',
        "/langData\\.$escaped\\b/",
        '/data-i18n="' . $escaped . '"/',
        "/getLangValue\\('$escaped'\\)/",
    ];
    foreach (projectSourceFiles($projectRoot) as $file) {
        $content = file_get_contents($file);
        if ($content === false) continue;
        foreach ($patterns as $p) {
            if (preg_match($p, $content) === 1) {
                $cache[$key] = true;
                return true;
            }
        }
    }
    $cache[$key] = false;
    return false;
}

/**
 * Runs the full check against the given {lang => path} file map and returns a structured result
 * (no output, no exit) -- the one function both the CLI report below and
 * tests/lang_check_test.php call, so the parser/detection logic exists in exactly one place.
 *
 * Return shape:
 * {
 *   parsed: {lang => {value, duplicates}},   // duplicates: see parseJsonTracked()'s own docblock
 *   onlyIn: {lang => [dot-path keys present in this lang's file but missing from every other]},
 *   hasProblem: bool
 * }
 */
function checkLangFiles(array $files, string $projectRoot): array {
    $parsed = [];
    foreach ($files as $lang => $path) {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read $path");
        }
        [$value, $duplicates] = parseJsonTracked(tokenizeJson($raw));
        $parsed[$lang] = ['value' => $value, 'duplicates' => $duplicates];
    }

    $flatByLang = [];
    foreach ($parsed as $lang => $info) {
        $flatByLang[$lang] = flattenKeys($info['value']);
    }

    $onlyIn = [];
    $langs = array_keys($flatByLang);
    foreach ($langs as $lang) {
        $otherKeys = [];
        foreach ($langs as $other) {
            if ($other === $lang) continue;
            $otherKeys = array_merge($otherKeys, array_keys($flatByLang[$other]));
        }
        $missing = array_diff(array_keys($flatByLang[$lang]), $otherKeys);
        sort($missing);
        $onlyIn[$lang] = $missing;
    }

    $hasProblem = false;
    foreach ($parsed as $info) {
        if (!empty($info['duplicates'])) { $hasProblem = true; break; }
    }
    if (!$hasProblem) {
        foreach ($onlyIn as $missing) {
            if (!empty($missing)) { $hasProblem = true; break; }
        }
    }

    return ['parsed' => $parsed, 'onlyIn' => $onlyIn, 'hasProblem' => $hasProblem, 'flatByLang' => $flatByLang];
}

function printLangCheckReport(array $result, string $projectRoot): void {
    echo "=== Duplicate keys within each file ===\n\n";
    foreach ($result['parsed'] as $lang => $info) {
        if (empty($info['duplicates'])) {
            echo "[$lang.json] No duplicate keys found.\n\n";
            continue;
        }
        echo "[$lang.json] " . count($info['duplicates']) . " duplicate key(s):\n";
        foreach ($info['duplicates'] as $dup) {
            $fullKey = $dup['path'] === '' ? $dup['key'] : $dup['path'] . '.' . $dup['key'];
            echo "  - \"$fullKey\" (" . count($dup['occurrences']) . " occurrences):\n";
            $lastIdx = count($dup['occurrences']) - 1;
            foreach ($dup['occurrences'] as $idx => $occ) {
                $marker = ($idx === $lastIdx) ? '  <-- currently in effect (last occurrence wins)' : '';
                $valDisplay = is_array($occ['value']) ? '(object)' : valueToDisplay($occ['value']);
                echo "      line {$occ['line']}: \"$valDisplay\"$marker\n";
            }
            $referenced = isKeyReferenced($fullKey, $projectRoot);
            echo "      referenced in code: " . ($referenced ? 'yes' : 'NO (appears unused)') . "\n";
        }
        echo "\n";
    }

    echo "=== Keys present in one file but missing from the other(s) ===\n\n";
    $anyMissing = false;
    foreach ($result['onlyIn'] as $lang => $missing) {
        if (empty($missing)) continue;
        $anyMissing = true;
        echo "In $lang.json but missing elsewhere (" . count($missing) . "):\n";
        foreach ($missing as $k) {
            echo "  - $k = \"" . valueToDisplay($result['flatByLang'][$lang][$k]) . "\"\n";
        }
        echo "\n";
    }
    if (!$anyMissing) {
        echo "None -- all files have exactly the same key set.\n";
    }

    echo "=== Summary ===\n";
    echo $result['hasProblem'] ? "FOUND issues -- see above.\n" : "No issues found.\n";
}

if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $projectRoot = dirname(__DIR__);
    $files = [
        'th' => $projectRoot . '/public/lang/th.json',
        'en' => $projectRoot . '/public/lang/en.json',
    ];
    $result = checkLangFiles($files, $projectRoot);
    printLangCheckReport($result, $projectRoot);
    exit($result['hasProblem'] ? 1 : 0);
}
