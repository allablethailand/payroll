/**
 * 2026-09-11, Batch 3C item 2 -- lightweight PASS/FAIL script for public/js/app.js's
 * parseUserAgent()/formatUserAgentSummary(), matching this project's own tests/*.php convention
 * (no test framework, plain PASS/FAIL lines, nonzero exit code on any failure).
 *
 * app.js as a whole can't just be require()'d in plain Node -- it's full of top-level jQuery/DOM
 * calls ($(document).on(...), window.langReady = loadLang(...), etc.) that assume a browser. Rather
 * than hand-copy parseUserAgent()/formatUserAgentSummary() into this file (a real drift risk -- this
 * test would keep silently passing against a STALE copy if app.js's real functions ever changed),
 * this extracts their REAL source text straight out of app.js by balanced-brace scanning from each
 * `function name(` marker, then evaluates just that extracted text -- so this test always exercises
 * the actual shipped implementation, not a copy of it. Safe here specifically because neither
 * function's body contains a regex/string literal with a literal '{' or '}' character (confirmed by
 * reading both bodies) -- a naive brace-count would mis-scan if one did.
 *
 * The Chrome/Windows, Edge/Windows, iPhone Safari, and Android Chrome UA strings below are the
 * EXACT same fixtures tests/employee_login_log_test.php already uses against
 * EmployeeLoginLogModel::parseUserAgent() (the PHP twin this JS version is deliberately kept in
 * lockstep with) -- reused rather than inventing new ones, so both implementations are proven
 * against identical real-world input. The Firefox fixture is new (the PHP test file has none).
 */
const fs = require('fs');
const path = require('path');

const appJsPath = path.join(__dirname, '..', 'public', 'js', 'app.js');
const source = fs.readFileSync(appJsPath, 'utf8');

function extractFunctionSource(fileText, fnName) {
    const marker = `function ${fnName}(`;
    const startIdx = fileText.indexOf(marker);
    if (startIdx === -1) {
        throw new Error(`${fnName}() not found in app.js -- has it been renamed/removed?`);
    }
    const braceStart = fileText.indexOf('{', startIdx);
    let depth = 0;
    let i = braceStart;
    for (; i < fileText.length; i++) {
        if (fileText[i] === '{') depth++;
        else if (fileText[i] === '}') {
            depth--;
            if (depth === 0) break;
        }
    }
    if (depth !== 0) {
        throw new Error(`${fnName}() -- could not find a matching closing brace (naive scan failed)`);
    }
    return fileText.slice(startIdx, i + 1);
}

const extracted = extractFunctionSource(source, 'parseUserAgent') + '\n' +
    extractFunctionSource(source, 'formatUserAgentSummary') + '\n' +
    'module.exports = { parseUserAgent, formatUserAgentSummary };';

const Module = require('module');
const m = new Module(appJsPath);
m._compile(extracted, appJsPath);
const { parseUserAgent, formatUserAgentSummary } = m.exports;

let passed = 0;
let failed = 0;
function check(label, actual, expected) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    if (ok) {
        passed++;
        console.log(`  PASS  ${label}`);
    } else {
        failed++;
        console.log(`  FAIL  ${label} -- expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
    }
}
function checkTrue(label, actual) {
    check(label, !!actual, true);
}

console.log('=== parseUserAgent()/formatUserAgentSummary() -- 5 real-world UA fixtures ===');

// Same fixture as tests/employee_login_log_test.php's own $chromeWin.
const chromeWin = parseUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.6613.120 Safari/537.36');
check('Chrome/Windows: os_name', chromeWin.os_name, 'Windows');
check('Chrome/Windows: os_version', chromeWin.os_version, '10/11');
check('Chrome/Windows: browser_name', chromeWin.browser_name, 'Chrome');
check('Chrome/Windows: browser_version', chromeWin.browser_version, '128.0.6613.120');
check('Chrome/Windows: summary uses major version only', formatUserAgentSummary('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.6613.120 Safari/537.36'), 'Windows 10/11 · Chrome 128');

// Same fixture as tests/employee_login_log_test.php's own $edgeWin -- also carries a Chrome/ token,
// proving Edge is checked BEFORE Chrome.
const edgeWinUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.2739.79';
const edgeWin = parseUserAgent(edgeWinUa);
check('Edge/Windows (also carries a Chrome/ token): browser_name is Edge, not Chrome', edgeWin.browser_name, 'Edge');
check('Edge/Windows: browser_version reads the Edg/ segment, not the Chrome/ one', edgeWin.browser_version, '128.0.2739.79');
check('Edge/Windows: summary', formatUserAgentSummary(edgeWinUa), 'Windows 10/11 · Edge 128');

// Same fixture as tests/employee_login_log_test.php's own $iphoneSafari.
const iphoneSafariUa = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const iphoneSafari = parseUserAgent(iphoneSafariUa);
check('iPhone Safari: os_name', iphoneSafari.os_name, 'iOS');
check('iPhone Safari: os_version (underscores converted to dots)', iphoneSafari.os_version, '17.5.1');
check('iPhone Safari: browser_name', iphoneSafari.browser_name, 'Safari');
check('iPhone Safari: browser_version reads the Version/ segment, not the Safari/ build number', iphoneSafari.browser_version, '17.5');
check('iPhone Safari: summary', formatUserAgentSummary(iphoneSafariUa), 'iOS 17.5.1 · Safari 17');

// New fixture (no PHP-side equivalent yet) -- Firefox also carries no Chrome/Safari token at all,
// the simplest case, but still worth a real assertion rather than assuming it works.
const firefoxUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:129.0) Gecko/20100101 Firefox/129.0';
const firefox = parseUserAgent(firefoxUa);
check('Firefox/Windows: os_name', firefox.os_name, 'Windows');
check('Firefox/Windows: browser_name', firefox.browser_name, 'Firefox');
check('Firefox/Windows: browser_version', firefox.browser_version, '129.0');
check('Firefox/Windows: summary', formatUserAgentSummary(firefoxUa), 'Windows 10/11 · Firefox 129');

// Same fixture as tests/employee_login_log_test.php's own $androidChrome.
const androidChromeUa = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36';
const androidChrome = parseUserAgent(androidChromeUa);
check('Android Chrome: os_name', androidChrome.os_name, 'Android');
check('Android Chrome: os_version', androidChrome.os_version, '14');
check('Android Chrome: browser_name', androidChrome.browser_name, 'Chrome');
check('Android Chrome: summary', formatUserAgentSummary(androidChromeUa), 'Android 14 · Chrome 128');

console.log('=== Edge cases ===');
checkTrue('an empty UA string parses without throwing', (() => { try { parseUserAgent(''); return true; } catch (e) { return false; } })());
check('an empty UA string: os_name stays null', parseUserAgent('').os_name, null);
check('formatUserAgentSummary() on an empty UA returns an empty string, not "undefined · undefined"', formatUserAgentSummary(''), '');
check('formatUserAgentSummary() on an unrecognized UA falls back gracefully', formatUserAgentSummary('SomeCustomThing/1.0'), '');

console.log('\n' + '-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
} else {
    console.log('ALL TESTS PASSED');
}
