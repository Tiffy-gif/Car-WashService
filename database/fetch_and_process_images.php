<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/ImageStorage.php';

$pdo = Database::getInstance();

$items = [
    // PRODUCTS
    [
        'type' => 'product',
        'id' => 2,
        'name' => 'Mobil 1 ESP 5W-30 Advanced Synthetic (4L)',
        'file_title' => 'File:Mobil 1 motor oil.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/b/bb/Mobil_1_motor_oil.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Mobil_1_motor_oil.jpg',
        'license' => 'Public domain',
        'author' => 'Interiot~commonswiki',
        'subfolder' => 'products',
        'prefix' => 'prod_2',
        'notes' => 'Mobil 1 motor oil bottle'
    ],
    [
        'type' => 'product',
        'id' => 3,
        'name' => 'Motul 10W-40 Semi-Synthetic Motorbike (1L)',
        'file_title' => 'File:Valvoline motor oil.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/b/b7/Valvoline_motor_oil.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Valvoline_motor_oil.jpg',
        'license' => 'CC BY 2.0',
        'author' => 'Frankieleon',
        'subfolder' => 'products',
        'prefix' => 'prod_3',
        'notes' => 'Generic 4-stroke engine oil bottle (No freely licensed Motul-specific photo on Wikimedia Commons)'
    ],
    [
        'type' => 'product',
        'id' => 4,
        'name' => 'Total Quartz 9000 5W-40 (4L)',
        'file_title' => 'File:Pennzoil oil bottles.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/7/7f/Pennzoil_oil_bottles.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Pennzoil_oil_bottles.jpg',
        'license' => 'CC BY 2.0',
        'author' => 'Mike Mozart',
        'subfolder' => 'products',
        'prefix' => 'prod_4',
        'notes' => 'Generic 5W-40 synthetic motor oil bottle (No freely licensed Total Quartz-specific photo on Wikimedia Commons)'
    ],
    [
        'type' => 'product',
        'id' => 6,
        'name' => 'Denso High-Performance Oil Filter (Ford/Lexus)',
        'file_title' => 'File:Oilfilter01.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/8/81/Oilfilter01.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Oilfilter01.jpg',
        'license' => 'CC BY-SA 3.0',
        'author' => 'Flominator',
        'subfolder' => 'products',
        'prefix' => 'prod_6',
        'notes' => 'Generic spin-on automotive oil filter canister (No freely licensed Denso-specific photo on Wikimedia Commons)'
    ],
    [
        'type' => 'product',
        'id' => 7,
        'name' => 'Honda Genuine Motorcycle Oil Filter',
        'file_title' => 'File:New motorcycle oil filter Hiflo Filtro.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/7/75/New_motorcycle_oil_filter_Hiflo_Filtro.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:New_motorcycle_oil_filter_Hiflo_Filtro.jpg',
        'license' => 'CC BY-SA 4.0',
        'author' => 'Petrb',
        'subfolder' => 'products',
        'prefix' => 'prod_7',
        'notes' => 'Generic motorcycle oil filter cartridge (No freely licensed Honda-specific motorcycle filter photo on Wikimedia Commons)'
    ],
    [
        'type' => 'product',
        'id' => 9,
        'name' => 'Toyota Super Long Life Coolant Pink (4L)',
        'file_title' => 'File:Antifreeze.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/b/bd/Antifreeze.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Antifreeze.jpg',
        'license' => 'CC BY 2.0',
        'author' => 'dno1967',
        'subfolder' => 'products',
        'prefix' => 'prod_9',
        'notes' => 'Generic automotive coolant / antifreeze bottle (No freely licensed Toyota SLLC-specific photo on Wikimedia Commons)'
    ],
    [
        'type' => 'product',
        'id' => 12,
        'name' => 'Ceramic Heavy-Duty Brake Pads (Hilux/Ranger/SUV)',
        'file_title' => 'File:Ece-r90-nummer bremsbelag.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/d/df/Ece-r90-nummer_bremsbelag.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Ece-r90-nummer_bremsbelag.jpg',
        'license' => 'CC BY-SA 4.0',
        'author' => 'Knaaken',
        'subfolder' => 'products',
        'prefix' => 'prod_12',
        'notes' => 'Heavy-duty automotive disc brake pads'
    ],
    [
        'type' => 'product',
        'id' => 13,
        'name' => 'Bosch Clear Advantage Wiper Blades 24"+18" (Pair)',
        'file_title' => 'File:Windshield Wipers 1.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/9/99/Windshield_Wipers_1.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Windshield_Wipers_1.jpg',
        'license' => 'Public domain',
        'author' => 'Wesha',
        'subfolder' => 'products',
        'prefix' => 'prod_13',
        'notes' => 'Automotive windshield wiper blades pair (No freely licensed Bosch-specific retail packaging photo on Wikimedia Commons)'
    ],
    [
        'type' => 'product',
        'id' => 14,
        'name' => 'GS Maintenance-Free Car Battery 12V 70Ah',
        'file_title' => 'File:Photo-CarBattery.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/e/ee/Photo-CarBattery.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Photo-CarBattery.jpg',
        'license' => 'Public domain',
        'author' => 'Shaddack',
        'subfolder' => 'products',
        'prefix' => 'prod_14',
        'notes' => 'Automotive 12V lead-acid car battery (No freely licensed GS Yuasa-specific photo on Wikimedia Commons)'
    ],
    [
        'type' => 'product',
        'id' => 15,
        'name' => 'Osram Night Breaker H4 Halogen Bulb 12V 60/55W',
        'file_title' => 'File:H4 halogen car lamp.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/9/95/H4_halogen_car_lamp.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:H4_halogen_car_lamp.jpg',
        'license' => 'CC BY-SA 4.0',
        'author' => 'K.Szonja',
        'subfolder' => 'products',
        'prefix' => 'prod_15',
        'notes' => 'Automotive H4 halogen bulb (No freely licensed Osram Night Breaker retail packaging photo on Wikimedia Commons)'
    ],
    [
        'type' => 'product',
        'id' => 16,
        'name' => 'Michelin Heavy-Duty Tubeless Tire Patch Kit',
        'file_title' => 'File:Puncture-repaire-kit.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/a/a7/Puncture-repaire-kit.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Puncture-repaire-kit.jpg',
        'license' => 'CC BY-SA 3.0',
        'author' => 'Björn Appel',
        'subfolder' => 'products',
        'prefix' => 'prod_16',
        'notes' => 'Tubeless tire repair / puncture patch kit (No freely licensed Michelin-branded kit photo on Wikimedia Commons)'
    ],
    [
        'type' => 'product',
        'id' => 17,
        'name' => 'Motorcycle Heavy-Duty Inner Tube 17"',
        'file_title' => 'File:Fahrradschlaeuche.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/5/5b/Fahrradschlaeuche.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Fahrradschlaeuche.jpg',
        'license' => 'Public domain',
        'author' => 'Baumfreund-FFM',
        'subfolder' => 'products',
        'prefix' => 'prod_17',
        'notes' => 'Heavy-duty rubber inner tube for spoked vehicle wheels'
    ],

    // SERVICES
    [
        'type' => 'service',
        'id' => 2,
        'name' => 'Interior Cleaning',
        'file_title' => 'File:Cleaning interior.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/e/ee/Cleaning_interior.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Cleaning_interior.jpg',
        'license' => 'CC BY-SA 4.0',
        'author' => 'Juanacosta84',
        'subfolder' => 'services',
        'prefix' => 'srv_2',
        'notes' => 'Car interior vacuum cleaning and detailing'
    ],
    [
        'type' => 'service',
        'id' => 4,
        'name' => 'Engine Bay Wash',
        'file_title' => 'File:A person cleans the engine.jpg',
        'url' => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/5/54/A_person_cleans_the_engine.jpg/1280px-A_person_cleans_the_engine.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:A_person_cleans_the_engine.jpg',
        'license' => 'CC BY 2.0',
        'author' => 'Shixart1985',
        'subfolder' => 'services',
        'prefix' => 'srv_4',
        'notes' => 'Engine bay degreasing and wash service'
    ],
    [
        'type' => 'service',
        'id' => 5,
        'name' => 'Hydrophobic Wax & Polish',
        'file_title' => 'File:Polishing of car after spraying 04.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/c/c7/Polishing_of_car_after_spraying_04.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Polishing_of_car_after_spraying_04.jpg',
        'license' => 'CC BY-SA 4.0',
        'author' => 'Wun-nam Rahimatu',
        'subfolder' => 'services',
        'prefix' => 'srv_5',
        'notes' => 'Machine paint polishing and hydrophobic wax application'
    ],
    [
        'type' => 'service',
        'id' => 7,
        'name' => 'Tire Change & Wheel Balance',
        'file_title' => 'File:Modern car wheel balancing machine tool at tire service.jpg',
        'url' => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/e/e2/Modern_car_wheel_balancing_machine_tool_at_tire_service.jpg/1280px-Modern_car_wheel_balancing_machine_tool_at_tire_service.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Modern_car_wheel_balancing_machine_tool_at_tire_service.jpg',
        'license' => 'CC BY 2.0',
        'author' => 'Shixart1985',
        'subfolder' => 'services',
        'prefix' => 'srv_7',
        'notes' => 'Dynamic wheel balancer and tire mounting machine at service bay'
    ],
    [
        'type' => 'service',
        'id' => 9,
        'name' => 'Brake Inspection & Adjustment',
        'file_title' => 'File:Disc brake car.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/5/53/Disc_brake_car.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Disc_brake_car.jpg',
        'license' => 'CC BY-SA 3.0',
        'author' => 'Irvin calicut',
        'subfolder' => 'services',
        'prefix' => 'srv_9',
        'notes' => 'Automotive disc brake caliper and rotor mechanical inspection'
    ],
    [
        'type' => 'service',
        'id' => 10,
        'name' => 'Battery Replacement & Electrical Test',
        'file_title' => 'File:Battery checker1245.JPG',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/0/0c/Battery_checker1245.JPG',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Battery_checker1245.JPG',
        'license' => 'CC BY-SA 4.0',
        'author' => 'Ahsanriaz6157',
        'subfolder' => 'services',
        'prefix' => 'srv_10',
        'notes' => 'Digital battery analyzer and electrical system test'
    ],
    [
        'type' => 'service',
        'id' => 11,
        'name' => 'Headlight / Tail Bulb Change',
        'file_title' => 'File:Headlamp.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/0/06/Headlamp.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Headlamp.jpg',
        'license' => 'CC0',
        'author' => 'HobbyAstronaut',
        'subfolder' => 'services',
        'prefix' => 'srv_11',
        'notes' => 'Automotive headlamp assembly inspection and bulb replacement'
    ],
    [
        'type' => 'service',
        'id' => 12,
        'name' => 'Wiper Blade Change (Labor)',
        'file_title' => 'File:Man holding a windscreen wiper.jpg',
        'url' => 'https://upload.wikimedia.org/wikipedia/commons/c/cb/Man_holding_a_windscreen_wiper.jpg',
        'page_url' => 'https://commons.wikimedia.org/wiki/File:Man_holding_a_windscreen_wiper.jpg',
        'license' => 'CC BY 4.0',
        'author' => 'ShiberuInupreza',
        'subfolder' => 'services',
        'prefix' => 'srv_12',
        'notes' => 'Technician installing windshield wiper blade'
    ]
];

if (php_sapi_name() !== 'cli') {
    return;
}

// Ensure docs directory exists
$docsDir = dirname(__DIR__) . '/docs';
if (!is_dir($docsDir)) {
    mkdir($docsDir, 0755, true);
}

$csvPath = $docsDir . '/image_credits.csv';
$csvHandle = fopen($csvPath, 'w');
fputcsv($csvHandle, ['Item Type', 'Item ID', 'Item Name', 'Image Path (600px)', 'Thumbnail Path (200px)', 'Source File Title', 'Source Page URL', 'Download URL', 'License', 'Author / Creator', 'Notes']);

$totalProcessed = 0;
$errors = [];

foreach ($items as $item) {
    echo "Processing {$item['type']} #{$item['id']}: {$item['name']}...\n";
    
    // Check if item already has an image in database from our previous step
    $table = ($item['type'] === 'product') ? 'products' : 'services';
    $checkStmt = $pdo->prepare("SELECT image_path, thumb_path FROM {$table} WHERE id = ?");
    $checkStmt->execute([$item['id']]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($existing['image_path']) && file_exists(dirname(__DIR__) . '/' . $existing['image_path'])) {
        $imagePath = $existing['image_path'];
        $thumbPath = $existing['thumb_path'];
        echo "  [EXISTS] Already processed -> {$imagePath}\n";
    } else {
        // Download image
        $tempPath = sys_get_temp_dir() . '/wm_download_' . uniqid() . '.jpg';
        $ch = curl_init($item['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CarWashProApp/1.0 (admin@carwashpro.local)');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $imgData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($imgData)) {
            echo "  [ERROR] Failed downloading {$item['url']} (HTTP {$httpCode})\n";
            $errors[] = "Failed download for #{$item['id']}";
            continue;
        }

        file_put_contents($tempPath, $imgData);
        $ext = pathinfo(parse_url($item['url'], PHP_URL_PATH), PATHINFO_EXTENSION);
        if (empty($ext)) $ext = 'jpg';

        $fakeFile = [
            'name' => basename($item['url']),
            'type' => 'image/jpeg',
            'tmp_name' => $tempPath,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempPath)
        ];

        $uploadRes = ImageStorage::processUpload($fakeFile, $item['subfolder'], $item['prefix']);
        @unlink($tempPath);

        if (empty($uploadRes['success'])) {
            echo "  [ERROR] ImageStorage error: " . ($uploadRes['error'] ?? 'Unknown error') . "\n";
            $errors[] = "ImageStorage error for #{$item['id']}: " . $uploadRes['error'];
            continue;
        }

        $imagePath = $uploadRes['image_path'];
        $thumbPath = $uploadRes['thumb_path'];
        $updatedAt = $uploadRes['image_updated_at'];

        // Update database row
        if ($item['type'] === 'product') {
            $stmt = $pdo->prepare("UPDATE products SET image_path = ?, thumb_path = ?, image_updated_at = ? WHERE id = ?");
            $stmt->execute([$imagePath, $thumbPath, $updatedAt, $item['id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE services SET image_path = ?, thumb_path = ?, image_updated_at = ? WHERE id = ?");
            $stmt->execute([$imagePath, $thumbPath, $updatedAt, $item['id']]);
        }
        echo "  [OK] Saved -> {$imagePath}\n";
    }

    // Write to CSV
    fputcsv($csvHandle, [
        $item['type'],
        $item['id'],
        $item['name'],
        $imagePath,
        $thumbPath,
        $item['file_title'],
        $item['page_url'],
        $item['url'],
        $item['license'],
        $item['author'],
        $item['notes']
    ]);

    $totalProcessed++;
    echo "  [OK] Saved -> {$imagePath}\n";
}

fclose($csvHandle);

echo "\n============================================\n";
echo "Completed processing {$totalProcessed} / " . count($items) . " items.\n";
if (!empty($errors)) {
    echo "Errors:\n" . implode("\n", $errors) . "\n";
} else {
    echo "All items processed successfully with pure white backgrounds!\n";
    echo "Credits saved to: docs/image_credits.csv\n";
}
