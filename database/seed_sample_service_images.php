<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();

$servicesToSeed = [
    [
        'id' => 1,
        'title' => 'Exterior Wash',
        'sub' => 'High Pressure Rinse & Foam',
        'theme' => [14, 165, 233], // Sky blue
        'icon_type' => 'car_wash'
    ],
    [
        'id' => 3,
        'title' => 'Full Wash Pro',
        'sub' => 'Exterior Foam + Interior Detailing',
        'theme' => [2, 132, 199], // Deep blue
        'icon_type' => 'full_wash'
    ],
    [
        'id' => 6,
        'title' => 'Tire Patch & Repair',
        'sub' => 'Puncture Seal & Pressure Balance',
        'theme' => [217, 119, 6], // Amber
        'icon_type' => 'tire'
    ],
    [
        'id' => 8,
        'title' => 'Brake Pad Service',
        'sub' => 'Ceramic Brake Pad Labor & Test',
        'theme' => [225, 29, 72], // Rose red
        'icon_type' => 'brake'
    ],
];

$svcDir = dirname(__DIR__) . '/public/uploads/services';
if (!is_dir($svcDir)) {
    mkdir($svcDir, 0755, true);
}

function createServiceArtwork(int $id, string $title, string $sub, array $theme, string $iconType, int $size): string
{
    $img = imagecreatetruecolor($size, $size);
    imagealphablending($img, true);
    imagesavealpha($img, true);

    // Soft rounded background
    $bgCol = imagecolorallocate($img, 248, 250, 252);
    imagefilledrectangle($img, 0, 0, $size, $size, $bgCol);

    // Accent header circle
    $accent = imagecolorallocate($img, $theme[0], $theme[1], $theme[2]);
    $accentLight = imagecolorallocatealpha($img, $theme[0], $theme[1], $theme[2], 100);

    $cx = (int)($size / 2);
    $cy = (int)($size * 0.45);
    $r = (int)($size * 0.28);
    imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $accentLight);
    imageellipse($img, $cx, $cy, $r * 2, $r * 2, $accent);

    // Inner icon symbol
    $white = imagecolorallocate($img, 255, 255, 255);
    $dark = imagecolorallocate($img, 30, 41, 59);
    $gray = imagecolorallocate($img, 100, 116, 139);

    if ($iconType === 'car_wash' || $iconType === 'full_wash') {
        // Car silhouette
        imagefilledrectangle($img, $cx - (int)($size * 0.15), $cy - (int)($size * 0.05), $cx + (int)($size * 0.15), $cy + (int)($size * 0.1), $accent);
        imagefilledrectangle($img, $cx - (int)($size * 0.1), $cy - (int)($size * 0.12), $cx + (int)($size * 0.1), $cy - (int)($size * 0.05), $accent);
        // Wheels
        imagefilledellipse($img, $cx - (int)($size * 0.09), $cy + (int)($size * 0.1), (int)($size * 0.08), (int)($size * 0.08), $dark);
        imagefilledellipse($img, $cx + (int)($size * 0.09), $cy + (int)($size * 0.1), (int)($size * 0.08), (int)($size * 0.08), $dark);
        // Water drops
        imagefilledellipse($img, $cx, $cy - (int)($size * 0.18), (int)($size * 0.04), (int)($size * 0.06), $accent);
        imagefilledellipse($img, $cx - (int)($size * 0.08), $cy - (int)($size * 0.16), (int)($size * 0.035), (int)($size * 0.05), $accent);
        imagefilledellipse($img, $cx + (int)($size * 0.08), $cy - (int)($size * 0.16), (int)($size * 0.035), (int)($size * 0.05), $accent);
    } elseif ($iconType === 'tire') {
        // Outer wheel
        imagefilledellipse($img, $cx, $cy, (int)($size * 0.32), (int)($size * 0.32), $dark);
        imagefilledellipse($img, $cx, $cy, (int)($size * 0.20), (int)($size * 0.20), $white);
        imagefilledellipse($img, $cx, $cy, (int)($size * 0.08), (int)($size * 0.08), $accent);
    } elseif ($iconType === 'brake') {
        // Brake disc & caliper
        imagefilledellipse($img, $cx, $cy, (int)($size * 0.32), (int)($size * 0.32), $gray);
        imagefilledellipse($img, $cx, $cy, (int)($size * 0.14), (int)($size * 0.14), $bgCol);
        imagefilledrectangle($img, $cx + (int)($size * 0.05), $cy - (int)($size * 0.16), $cx + (int)($size * 0.16), $cy + (int)($size * 0.02), $accent);
    }

    // Border line around whole artwork
    $borderCol = imagecolorallocate($img, 226, 232, 240);
    imagerectangle($img, 0, 0, $size - 1, $size - 1, $borderCol);

    // Text labels if size is large (600px)
    if ($size >= 400) {
        $fontFile = null;
        // Check Windows standard fonts
        $candidates = ['C:/Windows/Fonts/arialbd.ttf', 'C:/Windows/Fonts/segoeui.ttf', 'C:/Windows/Fonts/tahoma.ttf'];
        foreach ($candidates as $cand) {
            if (file_exists($cand)) {
                $fontFile = $cand;
                break;
            }
        }
        if ($fontFile && function_exists('imagettftext')) {
            $bbox = imagettfbbox(18, 0, $fontFile, $title);
            $tw = abs($bbox[4] - $bbox[0]);
            imagettftext($img, 18, 0, (int)(($size - $tw) / 2), (int)($size * 0.82), $dark, $fontFile, $title);

            $sbox = imagettfbbox(12, 0, $fontFile, $sub);
            $sw = abs($sbox[4] - $sbox[0]);
            imagettftext($img, 12, 0, (int)(($size - $sw) / 2), (int)($size * 0.88), $gray, $fontFile, $sub);
        } else {
            imagestring($img, 4, $cx - (int)(strlen($title) * 4), (int)($size * 0.80), $title, $dark);
            imagestring($img, 2, $cx - (int)(strlen($sub) * 3), (int)($size * 0.86), $sub, $gray);
        }
    }

    $filename = "svc_{$id}_sample_{$size}.webp";
    $filepath = dirname(__DIR__) . "/public/uploads/services/{$filename}";
    imagewebp($img, $filepath, 85);
    imagedestroy($img);

    return "public/uploads/services/{$filename}";
}

if (php_sapi_name() === 'cli') {
    $now = date('Y-m-d H:i:s');
    foreach ($servicesToSeed as $svc) {
        $mainRel = createServiceArtwork($svc['id'], $svc['title'], $svc['sub'], $svc['theme'], $svc['icon_type'], 600);
        $thumbRel = createServiceArtwork($svc['id'], $svc['title'], $svc['sub'], $svc['theme'], $svc['icon_type'], 200);

        $stmt = $pdo->prepare("UPDATE services SET image_path = ?, thumb_path = ?, image_updated_at = ? WHERE id = ?");
        $stmt->execute([$mainRel, $thumbRel, $now, $svc['id']]);
        echo "Service ID {$svc['id']} ({$svc['title']}) seeded with sample image.\n";
    }

    echo "All sample services successfully seeded!\n";
}
