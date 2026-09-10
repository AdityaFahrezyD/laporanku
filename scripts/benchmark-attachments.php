<?php

use App\Services\AvifProcessor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;

// Run: php -d memory_limit=128M scripts/benchmark-attachments.php
require __DIR__ . "/../vendor/autoload.php";
$app = require __DIR__ . "/../bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();
$path = tempnam(sys_get_temp_dir(), "avif-bench-");
try {
    $image = imagecreatetruecolor(2000, 2000);
    for ($y = 0; $y < 2000; $y++) {
        for ($x = 0; $x < 2000; $x++) {
            imagesetpixel(
                $image,
                $x,
                $y,
                (($x * 17 + $y * 3) % 256 << 16) |
                    (($x + $y * 11) % 256 << 8) |
                    ($x * 7 + $y) % 256
            );
        }
    }
    imagejpeg($image, $path, 85);
    unset($image);
    $start = microtime(true);
    $result = app(AvifProcessor::class)->process(
        new UploadedFile($path, "benchmark.jpg", "image/jpeg", null, true)
    );
    echo json_encode(
        [
            "input_pixels" => 4000000,
            "input_bytes" => filesize($path),
            "output_bytes" => $result["size"],
            "width" => $result["width"],
            "height" => $result["height"],
            "seconds" => round(microtime(true) - $start, 3),
            "php_peak_mb" => round(memory_get_peak_usage(true) / 1048576, 2),
            "memory_limit" => ini_get("memory_limit"),
            "note" =>
                "Synthetic image; PHP memory counters may omit native codec allocations. Repeat on hosting PHP web runtime.",
        ],
        JSON_PRETTY_PRINT
    ) . PHP_EOL;
} finally {
    @unlink($path);
}
