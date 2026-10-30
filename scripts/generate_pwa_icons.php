<?php
declare(strict_types=1);

$logoPath = dirname(__DIR__) . '/public/uploads/logo_1792207707_9fdb4a82.png';
$iconsDir = dirname(__DIR__) . '/pos/icons';

if (!is_dir($iconsDir)) {
    mkdir($iconsDir, 0755, true);
}

if (!file_exists($logoPath)) {
    fwrite(STDERR, "Logo file not found: {$logoPath}\n");
    exit(1);
}

$srcImg = imagecreatefrompng($logoPath);
if (!$srcImg) {
    fwrite(STDERR, "Failed to load PNG logo\n");
    exit(1);
}

imagealphablending($srcImg, true);
imagesavealpha($srcImg, true);

$srcW = imagesx($srcImg);
$srcH = imagesy($srcImg);

function resizeAndSave(
    GdImage $src,
    int $srcW,
    int $srcH,
    int $targetSize,
    string $outPath,
    bool $isMaskable = false
): void {
    $dst = imagecreatetruecolor($targetSize, $targetSize);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);

    if ($isMaskable) {
        // Maskable icon: Solid background with safe area padding (70-75%)
        $bg = imagecolorallocate($dst, 2, 132, 199); // #0284c7 Primary Brand Blue
        imagefilledrectangle($dst, 0, 0, $targetSize, $targetSize, $bg);
        imagealphablending($dst, true);

        $maxDim = (int)round($targetSize * 0.75);
    } else {
        // Standard transparent icon
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $targetSize, $targetSize, $transparent);
        imagealphablending($dst, true);

        $maxDim = (int)round($targetSize * 0.90);
    }

    // Preserve aspect ratio
    $ratio = min($maxDim / $srcW, $maxDim / $srcH);
    $dstW = (int)round($srcW * $ratio);
    $dstH = (int)round($srcH * $ratio);
    $dstX = (int)round(($targetSize - $dstW) / 2);
    $dstY = (int)round(($targetSize - $dstH) / 2);

    imagecopyresampled($dst, $src, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
    imagepng($dst, $outPath, 9);
    imagedestroy($dst);
}

resizeAndSave($srcImg, $srcW, $srcH, 192, $iconsDir . '/icon-192.png', false);
resizeAndSave($srcImg, $srcW, $srcH, 512, $iconsDir . '/icon-512.png', false);
resizeAndSave($srcImg, $srcW, $srcH, 192, $iconsDir . '/icon-maskable-192.png', true);
resizeAndSave($srcImg, $srcW, $srcH, 512, $iconsDir . '/icon-maskable-512.png', true);

imagedestroy($srcImg);
echo "Successfully generated PWA icons in pos/icons/:\n";
echo "  - icon-192.png\n";
echo "  - icon-512.png\n";
echo "  - icon-maskable-192.png\n";
echo "  - icon-maskable-512.png\n";
