<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

/**
 * ImageStorage
 * Centralized service for validating, resizing to padded squares (600px & 200px),
 * compressing, storing, deleting, and generating URLs for product and service pictures.
 * Cloud-ready: upload destination directory and base URL can be modified in config/app.php.
 */
class ImageStorage {

    /**
     * Validate an uploaded file for image format and file size.
     * Rejects SVG, XML, and files exceeding size limit.
     */
    public static function validate(array $file): ?string {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return 'No picture was uploaded.';
        }

        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $maxMb = round(MAX_IMAGE_UPLOAD_BYTES / (1024 * 1024));
            return "The picture is too large. The maximum allowed file size is {$maxMb} MB.";
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return 'Upload failed with error code ' . $file['error'] . '. Please try again.';
        }

        // Check file size
        if (($file['size'] ?? 0) > MAX_IMAGE_UPLOAD_BYTES) {
            $mb = round($file['size'] / (1024 * 1024), 2);
            $maxMb = round(MAX_IMAGE_UPLOAD_BYTES / (1024 * 1024));
            return "The selected picture is {$mb} MB. Maximum allowed size is {$maxMb} MB.";
        }

        // Check extension
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            return 'SVG vector files are not allowed. Please upload a real JPG, PNG, or WebP picture.';
        }

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedExts, true)) {
            return "Invalid file extension (.{$ext}). Allowed picture formats are JPG, PNG, and WebP.";
        }

        // Prevent SVG / XML masquerading
        $tmp = $file['tmp_name'] ?? '';
        if (!is_file($tmp) || !is_readable($tmp)) {
            return 'Temporary upload file could not be read.';
        }

        $header = (string)@file_get_contents($tmp, false, null, 0, 512);
        if (stripos($header, '<svg') !== false || stripos($header, '<?xml') !== false) {
            return 'SVG files and XML documents are not allowed. Please choose a real JPG, PNG, or WebP picture.';
        }

        // Verify with getimagesize
        $info = @getimagesize($tmp);
        if ($info === false) {
            return 'The selected file is not a valid picture or is corrupted.';
        }

        $mime = strtolower((string)($info['mime'] ?? ''));
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            return "Unsupported picture format ({$mime}). Only real JPG, PNG, and WebP images are allowed.";
        }

        return null; // Valid
    }

    /**
     * Process an uploaded file: resize to 600x600 padded square and 200x200 thumbnail,
     * compress for fast loading, and save to the storage directory.
     *
     * @param array $file $_FILES['...']
     * @param string $subfolder Directory under public/uploads/ (e.g. 'products', 'services')
     * @param string $prefix Filename prefix (e.g. 'prod', 'srv')
     * @return array ['success' => bool, 'image_path' => string, 'thumb_path' => string, 'image_updated_at' => string, 'error' => ?string]
     */
    public static function processUpload(array $file, string $subfolder = 'products', string $prefix = 'item'): array {
        $validationError = self::validate($file);
        if ($validationError !== null) {
            return ['success' => false, 'error' => $validationError];
        }

        $tmp = $file['tmp_name'];
        $info = @getimagesize($tmp);
        if (!$info) {
            return ['success' => false, 'error' => 'Could not determine picture dimensions.'];
        }

        $mime = strtolower((string)$info['mime']);
        $srcImg = null;

        if ($mime === 'image/jpeg') {
            $srcImg = @imagecreatefromjpeg($tmp);
            // Handle EXIF orientation if available
            if ($srcImg && function_exists('exif_read_data')) {
                try {
                    $exif = @exif_read_data($tmp);
                    if (!empty($exif['Orientation'])) {
                        switch ($exif['Orientation']) {
                            case 3: $srcImg = imagerotate($srcImg, 180, 0); break;
                            case 6: $srcImg = imagerotate($srcImg, -90, 0); break;
                            case 8: $srcImg = imagerotate($srcImg, 90, 0); break;
                        }
                    }
                } catch (\Throwable $e) {}
            }
        } elseif ($mime === 'image/png') {
            $srcImg = @imagecreatefrompng($tmp);
        } elseif ($mime === 'image/webp') {
            if (function_exists('imagecreatefromwebp')) {
                $srcImg = @imagecreatefromwebp($tmp);
            }
        }

        if (!$srcImg) {
            return ['success' => false, 'error' => 'Unable to read and decode image data.'];
        }

        // Ensure target directory exists
        $uploadBase = rtrim(STORAGE_UPLOAD_DIR, '/\\');
        $targetDir = $uploadBase . '/' . trim($subfolder, '/\\');
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $unique = bin2hex(random_bytes(6)) . '_' . time();
        $preferWebp = function_exists('imagewebp');
        $ext = $preferWebp ? 'webp' : 'jpg';

        $file600 = "{$prefix}_{$unique}_600.{$ext}";
        $file200 = "{$prefix}_{$unique}_200.{$ext}";

        $dest600 = $targetDir . '/' . $file600;
        $dest200 = $targetDir . '/' . $file200;

        // 1. Generate 600px square center-cropped image (fills edge-to-edge, no white padding)
        $canvas600 = self::createCroppedSquare($srcImg, 600);
        // 2. Generate 200px square center-cropped thumbnail
        $canvas200 = self::createCroppedSquare($srcImg, 200);

        // Compress and save (both WebP and JPEG now have guaranteed solid pure white background)
        if ($preferWebp) {
            imagewebp($canvas600, $dest600, 85);
            imagewebp($canvas200, $dest200, 85);
        } else {
            imagejpeg($canvas600, $dest600, 85);
            imagejpeg($canvas200, $dest200, 85);
        }

        imagedestroy($srcImg);
        imagedestroy($canvas600);
        imagedestroy($canvas200);

        $now = date('Y-m-d H:i:s');
        return [
            'success' => true,
            'image_path' => 'public/uploads/' . trim($subfolder, '/') . '/' . $file600,
            'thumb_path' => 'public/uploads/' . trim($subfolder, '/') . '/' . $file200,
            'image_updated_at' => $now,
            'error' => null
        ];
    }

    /**
     * Resizes the image into a targetSize x targetSize square canvas using center crop,
     * filling the square edge-to-edge without white padding or distortion.
     */
    private static function createCroppedSquare(\GdImage $src, int $targetSize): \GdImage {
        $origW = imagesx($src);
        $origH = imagesy($src);

        // Crop the largest centered square from the source image
        $minDim = min($origW, $origH);
        $srcX = (int)round(($origW - $minDim) / 2);
        $srcY = (int)round(($origH - $minDim) / 2);

        $canvas = imagecreatetruecolor($targetSize, $targetSize);

        // Alpha blending and transparency support
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefilledrectangle($canvas, 0, 0, $targetSize, $targetSize, $transparent);
        imagealphablending($canvas, true);

        // Resample the center-cropped square
        imagecopyresampled($canvas, $src, 0, 0, $srcX, $srcY, $targetSize, $targetSize, $minDim, $minDim);

        return $canvas;
    }

    /**
     * Legacy padded square helper.
     */
    private static function createPaddedSquare(\GdImage $src, int $targetSize): \GdImage {
        return self::createCroppedSquare($src, $targetSize);
    }

    /**
     * Delete existing image files (600px and 200px) when replaced or removed.
     */
    public static function deleteFiles(?string $imagePath, ?string $thumbPath): void {
        $baseDir = realpath(dirname(__DIR__));
        if (!$baseDir) return;

        foreach ([$imagePath, $thumbPath] as $p) {
            if (empty($p)) continue;
            // Never delete default sample assets or outside uploads
            if (strpos($p, 'public/uploads/') !== 0) continue;

            $full = $baseDir . '/' . ltrim($p, '/\\');
            $real = realpath($full);
            if ($real && is_file($real)) {
                // Ensure the file is inside public/uploads
                $uploadsDir = realpath($baseDir . '/public/uploads');
                if ($uploadsDir && strpos($real, $uploadsDir) === 0) {
                    @unlink($real);
                }
            }
        }
    }

    /**
     * Get the full absolute URL for a picture (for POS API and web clients).
     */
    public static function getUrl(?string $relativePath): ?string {
        if (empty($relativePath)) {
            return null;
        }
        if (strpos($relativePath, 'http://') === 0 || strpos($relativePath, 'https://') === 0) {
            return $relativePath;
        }
        return API_BASE_URL . '/' . ltrim($relativePath, '/');
    }
}
