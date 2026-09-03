<?php
declare(strict_types=1);

/**
 * Platform Hardening Phase 5B: GD-based thumbnail generation for the 4 upload types confirmed in
 * scope (Image Library grids for Employment Certificate / Payslip Template, employee profile
 * photos, and image-mime employee_documents rows) -- no external dependency, `gd`+`fileinfo` are
 * already installed in this environment. SVG is deliberately skipped (GD cannot rasterize it, and
 * SVG is a valid company-logo/profile-photo upload option elsewhere in this app) -- callers should
 * simply not call generate() for an SVG source and leave thumbnail_path NULL.
 *
 * A thumbnail failure (corrupt image, GD error) must never fail the upload itself -- generate()
 * catches everything internally and returns false; callers leave thumbnail_path NULL on false,
 * same "best-effort, never let a non-critical side effect fail the primary action" precedent as
 * PayslipDeliveryService.
 */
class ThumbnailGenerator {
    private const SUPPORTED_MIMES = ['image/jpeg', 'image/png'];

    public static function isSupportedMime(?string $mime): bool {
        return $mime !== null && in_array($mime, self::SUPPORTED_MIMES, true);
    }

    /** @param string $sourcePath absolute path to the already-uploaded source image.
     *  @param string $destPath absolute path to write the thumbnail to (directory must already exist).
     *  @param int $maxDim thumbnail fits within maxDim x maxDim, aspect ratio preserved. */
    public static function generate(string $sourcePath, string $destPath, int $maxDim = 200): bool {
        try {
            if (!is_file($sourcePath)) {
                return false;
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($sourcePath);
            if (!self::isSupportedMime($mime)) {
                return false;
            }
            $source = $mime === 'image/png' ? @imagecreatefrompng($sourcePath) : @imagecreatefromjpeg($sourcePath);
            if ($source === false) {
                return false;
            }
            $srcWidth = imagesx($source);
            $srcHeight = imagesy($source);
            if ($srcWidth <= 0 || $srcHeight <= 0) {
                imagedestroy($source);
                return false;
            }
            $scale = min($maxDim / $srcWidth, $maxDim / $srcHeight, 1.0);
            $dstWidth = max(1, (int)round($srcWidth * $scale));
            $dstHeight = max(1, (int)round($srcHeight * $scale));

            $thumb = imagecreatetruecolor($dstWidth, $dstHeight);
            if ($mime === 'image/png') {
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
                imagefilledrectangle($thumb, 0, 0, $dstWidth, $dstHeight, $transparent);
            }
            imagecopyresampled($thumb, $source, 0, 0, 0, 0, $dstWidth, $dstHeight, $srcWidth, $srcHeight);

            $ok = $mime === 'image/png' ? imagepng($thumb, $destPath) : imagejpeg($thumb, $destPath, 85);

            imagedestroy($source);
            imagedestroy($thumb);
            return (bool)$ok;
        } catch (Throwable $e) {
            return false;
        }
    }
}
