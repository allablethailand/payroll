<?php
/**
 * Lightweight verification script for Platform Hardening Phase 5A/5B/5C (upload hardening):
 * ThumbnailGenerator (GD-based thumbnail generation, the 4 confirmed-in-scope upload types) and
 * SyncBatchModel::start()'s new original_file_path/name/size columns (Phase 5C import retention --
 * ImportService::commit()/ManualEntryController::importPreview()/importCommit() only ever forward
 * this same array through to start(), so this is the one real integration point worth testing
 * directly; the controller-level file-copy/token-resolution logic itself was verified by reading
 * the code, not exercised here, since it needs a real $_FILES superglobal this CLI test has no way
 * to simulate).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Uses a freshly-created company (own INSERT, no shared
 * comp_id=1 dependency) for the sync_batches fixture.
 *
 * Run with: php tests/upload_hardening_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/ThumbnailGenerator.php';
require_once __DIR__ . '/../app/models/SyncBatchModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    if ($actual === $expected) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

function makeCompany(PDO $pdo, string $countryCode): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

$tmpFiles = [];
function makeTmpFilePath(string $ext): string {
    global $tmpFiles;
    $path = sys_get_temp_dir() . '/audit_thumb_test_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $tmpFiles[] = $path;
    return $path;
}

try {
    $compId = makeCompany($pdo, 'TH');

    // ================= ThumbnailGenerator =================
    echo "=== ThumbnailGenerator::isSupportedMime() ===\n";
    checkTrue('image/jpeg is supported', ThumbnailGenerator::isSupportedMime('image/jpeg'));
    checkTrue('image/png is supported', ThumbnailGenerator::isSupportedMime('image/png'));
    checkFalse('image/svg+xml is NOT supported (GD cannot rasterize SVG)', ThumbnailGenerator::isSupportedMime('image/svg+xml'));
    checkFalse('application/pdf is NOT supported', ThumbnailGenerator::isSupportedMime('application/pdf'));
    checkFalse('null is NOT supported', ThumbnailGenerator::isSupportedMime(null));

    echo "\n=== ThumbnailGenerator::generate() -- real images ===\n";
    // A real 400x300 JPEG, larger than the default 200px maxDim -- forces actual downscaling.
    $jpegSource = makeTmpFilePath('jpg');
    $jpegImg = imagecreatetruecolor(400, 300);
    imagefill($jpegImg, 0, 0, imagecolorallocate($jpegImg, 100, 150, 200));
    imagejpeg($jpegImg, $jpegSource, 90);
    imagedestroy($jpegImg);

    $jpegThumb = makeTmpFilePath('jpg');
    $jpegOk = ThumbnailGenerator::generate($jpegSource, $jpegThumb);
    checkTrue('JPEG: generate() returns true', $jpegOk);
    checkTrue('JPEG: thumbnail file was actually written', is_file($jpegThumb));
    if (is_file($jpegThumb)) {
        $dims = getimagesize($jpegThumb);
        checkTrue('JPEG: thumbnail width fits within 200px maxDim', $dims[0] <= 200);
        checkTrue('JPEG: thumbnail height fits within 200px maxDim', $dims[1] <= 200);
        // 400x300 source, aspect ratio 4:3 -- scaled to fit 200x200 means width becomes the
        // constraining dimension (200), height should be 150 to preserve aspect ratio.
        check('JPEG: aspect ratio preserved (200x150 for a 400x300 4:3 source)', [$dims[0], $dims[1]], [200, 150]);
    }

    // A real 100x100 PNG WITH alpha transparency, smaller than maxDim -- should still copy through
    // (scale factor capped at 1.0, never upscaled) and preserve the PNG format for its alpha channel.
    $pngSource = makeTmpFilePath('png');
    $pngImg = imagecreatetruecolor(100, 100);
    imagealphablending($pngImg, false);
    imagesavealpha($pngImg, true);
    $transparent = imagecolorallocatealpha($pngImg, 0, 0, 0, 127);
    imagefilledrectangle($pngImg, 0, 0, 100, 100, $transparent);
    imagepng($pngImg, $pngSource);
    imagedestroy($pngImg);

    $pngThumb = makeTmpFilePath('png');
    $pngOk = ThumbnailGenerator::generate($pngSource, $pngThumb);
    checkTrue('PNG: generate() returns true', $pngOk);
    if (is_file($pngThumb)) {
        $dims = getimagesize($pngThumb);
        check('PNG: a source already smaller than maxDim is never upscaled', [$dims[0], $dims[1]], [100, 100]);
        check('PNG: output format stays image/png (alpha preserved)', $dims['mime'], 'image/png');
    }

    echo "\n=== ThumbnailGenerator::generate() -- failure paths never throw ===\n";
    $missingSource = makeTmpFilePath('jpg'); // never actually created
    checkFalse('missing source file returns false, not an exception', ThumbnailGenerator::generate($missingSource, makeTmpFilePath('jpg')));

    $corruptSource = makeTmpFilePath('jpg');
    file_put_contents($corruptSource, 'this is not a real jpeg, just plain text');
    checkFalse('a corrupt/non-image file returns false, not an exception', ThumbnailGenerator::generate($corruptSource, makeTmpFilePath('jpg')));

    // ================= Phase 5C: SyncBatchModel::start() original-file columns =================
    echo "\n=== SyncBatchModel::start() -- original_file_path/name/size (Phase 5C) ===\n";
    $batchModel = new SyncBatchModel($pdo);

    $batchIdWithFile = $batchModel->start($compId, 'attendance', 'import', 'manual', 1, null, null, '127.0.0.1', 'TestAgent/1.0', [
        'path' => 'storage/uploads/import_originals/' . $compId . '/deadbeef.xlsx',
        'name' => 'my_attendance_import.xlsx',
        'size' => 54321,
    ]);
    $rowWithFile = $batchModel->get($batchIdWithFile, $compId);
    checkTrue('batch with an original file: row exists', $rowWithFile !== null);
    if ($rowWithFile !== null) {
        check('original_file_path stored correctly', $rowWithFile['original_file_path'], 'storage/uploads/import_originals/' . $compId . '/deadbeef.xlsx');
        check('original_file_name stored correctly', $rowWithFile['original_file_name'], 'my_attendance_import.xlsx');
        check('original_file_size stored correctly', (int)$rowWithFile['original_file_size'], 54321);
    }

    // A sync-engine call site (not import) never passes $originalFile -- confirms the 3 new columns
    // default to NULL and don't silently break every OTHER, unrelated caller of start().
    $batchIdSync = $batchModel->start($compId, 'employee', 'sync', 'manual', 1);
    $rowSync = $batchModel->get($batchIdSync, $compId);
    checkTrue('a sync batch (no originalFile arg) still creates a row', $rowSync !== null);
    if ($rowSync !== null) {
        check('original_file_path is NULL for a sync batch', $rowSync['original_file_path'], null);
        check('original_file_name is NULL for a sync batch', $rowSync['original_file_name'], null);
        check('original_file_size is NULL for a sync batch', $rowSync['original_file_size'], null);
    }

    echo "\n=== SUMMARY: {$passes} passed, {$failures} failed ===\n";
} finally {
    $pdo->rollBack();
    foreach ($tmpFiles as $f) {
        if (is_file($f)) { @unlink($f); }
    }
}

exit($failures > 0 ? 1 : 0);
