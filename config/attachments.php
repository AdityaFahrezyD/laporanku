<?php

return [
    'max_kb' => (int) env('ATTACHMENT_MAX_KB', 5120),
    'max_pixels' => (int) env('ATTACHMENT_MAX_PIXELS', 4000000),
    'max_dimension' => (int) env('ATTACHMENT_MAX_DIMENSION', 1920),
    'quality' => (int) env('ATTACHMENT_AVIF_QUALITY', 60),
    'speed' => (int) env('ATTACHMENT_AVIF_SPEED', 8),
];
