<?php

use App\Services\AttachmentFiles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command("attachments:cleanup", function () {
    $failed = 0;
    // Grace period protects uploads in progress and permits recovery after a crash.
    DB::table("attachment_file_cleanup")
        ->where("created_at", "<=", now()->subHour())
        ->orderBy("id")
        ->chunkById(100, function ($rows) use (&$failed) {
            foreach ($rows as $row) {
                if (!app(AttachmentFiles::class)->cleanup($row->id)) {
                    $failed++;
                }
            }
        });
    $this->info("Cleanup selesai; tertunda/gagal: " . $failed);

    return $failed ? 1 : 0;
})->purpose("Retry pending attachment file cleanup older than one hour");

Artisan::command("inspire", function () {
    $this->comment(Inspiring::quote());
})->purpose("Display an inspiring quote");
