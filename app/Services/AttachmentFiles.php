<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AttachmentFiles
{
    public function track(string $disk, string $path): int
    {
        return DB::table("attachment_file_cleanup")->insertGetId([
            "disk" => $disk,
            "file_path" => $path,
            "created_at" => now(),
        ]);
    }

    public function removeAfterCommit(Attachment $attachment): void
    {
        if (!$attachment->disk) {
            return; // Legacy files have no known storage location.
        }
        $id = $this->track($attachment->disk, $attachment->file_path);
        DB::afterCommit(fn() => $this->cleanup($id));
    }

    public function cleanup(int $id): bool
    {
        try {
            return DB::transaction(function () use ($id) {
                $row = DB::table("attachment_file_cleanup")
                    ->lockForUpdate()
                    ->find($id);
                if (!$row) {
                    return true;
                }
                if (
                    Attachment::where("disk", $row->disk)
                        ->where("file_path", $row->file_path)
                        ->exists()
                ) {
                    return false;
                }
                $disk = Storage::disk($row->disk);
                if (
                    $disk->exists($row->file_path) &&
                    !$disk->delete($row->file_path)
                ) {
                    throw new RuntimeException(
                        "Attachment file deletion failed."
                    );
                }
                DB::table("attachment_file_cleanup")
                    ->where("id", $id)
                    ->delete();

                return true;
            });
        } catch (Throwable $error) {
            report($error);

            return false;
        }
    }
}
