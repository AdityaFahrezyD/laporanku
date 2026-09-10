<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AvifProcessor
{
    public function process(UploadedFile $file): array
    {
        $invalid = fn() => throw ValidationException::withMessages([
            "image" =>
                "Gambar harus JPEG, PNG, WebP, atau AVIF statis yang valid dan tidak melebihi batas piksel.",
        ]);
        if (
            !$file->isValid() ||
            $file->getSize() > config("attachments.max_kb") * 1024
        ) {
            $invalid();
        }
        $info = @getimagesize($file->getPathname());
        if (
            !$info ||
            !in_array(
                $info["mime"],
                ["image/jpeg", "image/png", "image/webp", "image/avif"],
                true
            ) ||
            $info[0] < 1 ||
            $info[1] < 1 ||
            config("attachments.max_pixels") < $info[0] * $info[1]
        ) {
            $invalid();
        }
        $bytes = file_get_contents($file->getPathname());
        if ($this->animated($bytes, $info["mime"])) {
            $invalid();
        }
        if (
            !function_exists("imageavif") ||
            !(gd_info()["AVIF Support"] ?? false)
        ) {
            throw new RuntimeException("GD AVIF is unavailable.");
        }
        $image = @imagecreatefromstring($bytes);
        unset($bytes);
        if (!$image) {
            $invalid();
        }
        if ($info["mime"] === "image/jpeg") {
            if (!function_exists("exif_read_data")) {
                throw new RuntimeException(
                    "EXIF extension is required for JPEG orientation."
                );
            }
            $orientation =
                @exif_read_data($file->getPathname())["Orientation"] ?? 1;
            if (in_array($orientation, [2, 5, 7], true)) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            } elseif ($orientation === 4) {
                imageflip($image, IMG_FLIP_VERTICAL);
            }
            $angle = match ($orientation) {
                3 => 180,
                5, 8 => 90,
                6, 7 => -90,
                default => 0,
            };
            if ($angle) {
                $image = imagerotate($image, $angle, 0);
            }
        }
        $scale = min(
            1,
            max(1, config("attachments.max_dimension")) /
                max(imagesx($image), imagesy($image))
        );
        $width = max(1, (int) floor(imagesx($image) * $scale));
        $height = max(1, (int) floor(imagesy($image) * $scale));
        $output = imagecreatetruecolor($width, $height);
        imagealphablending($output, false);
        imagesavealpha($output, true);
        imagecopyresampled(
            $output,
            $image,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($image),
            imagesy($image)
        );
        unset($image);
        ob_start();
        try {
            imageavif(
                $output,
                null,
                config("attachments.quality"),
                config("attachments.speed")
            );
            $encoded = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($output);
        }
        $check = @imagecreatefromstring($encoded);
        if (
            !$check ||
            imagesx($check) !== $width ||
            imagesy($check) !== $height ||
            (@getimagesizefromstring($encoded)["mime"] ?? null) !== "image/avif"
        ) {
            throw new RuntimeException("AVIF output validation failed.");
        }
        unset($check);

        return [
            "bytes" => $encoded,
            "mime_type" => "image/avif",
            "size" => strlen($encoded),
            "width" => $width,
            "height" => $height,
        ];
    }

    private function animated(string $bytes, string $mime): bool
    {
        // Inspect container chunks, not arbitrary matches inside compressed pixels.
        $offset = match ($mime) {
            "image/png" => 8,
            "image/webp" => 12,
            default => 0,
        };
        while ($offset + 8 <= strlen($bytes) && $mime !== "image/jpeg") {
            $length = unpack(
                $mime === "image/webp" ? "V" : "N",
                substr($bytes, $offset + ($mime === "image/webp" ? 4 : 0), 4)
            )[1];
            $type = substr(
                $bytes,
                $offset + ($mime === "image/webp" ? 0 : 4),
                4
            );
            if (
                ($mime === "image/png" && $type === "acTL") ||
                ($mime === "image/webp" &&
                    in_array($type, ["ANIM", "ANMF"], true))
            ) {
                return true;
            }
            if ($mime === "image/avif" && $type === "ftyp") {
                $brands = str_split(
                    substr($bytes, $offset + 8, max(0, $length - 8)),
                    4
                );

                return in_array("avis", $brands, true) ||
                    in_array("msf1", $brands, true);
            }
            $step = match ($mime) {
                "image/png" => $length + 12,
                "image/webp" => $length + 8 + ($length % 2),
                default => $length,
            };
            if ($step < 8 || $offset + $step > strlen($bytes)) {
                break;
            }
            $offset += $step;
        }

        return false;
    }
}
