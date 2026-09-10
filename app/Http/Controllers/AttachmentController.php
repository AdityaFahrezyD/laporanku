<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Transfer;
use App\Services\AttachmentFiles;
use App\Services\AvifProcessor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AttachmentController extends Controller
{
    private function model(string $resource): string
    {
        return match ($resource) {
            "incomes" => Income::class,
            "expenses" => Expense::class,
            "transfers" => Transfer::class,
            default => abort(404),
        };
    }

    public function store(
        Request $request,
        string $resource,
        string $id,
        AvifProcessor $processor,
        AttachmentFiles $files
    ) {
        $model = $this->model($resource);
        $model::findOrFail($id);
        $request->validate([
            "image" => [
                "required",
                "file",
                "max:" . config("attachments.max_kb"),
            ],
        ]);
        $ticket = null;
        try {
            $result = $processor->process($request->file("image"));
            $path = $resource . "/" . $id . "/" . Str::uuid() . ".avif";
            $ticket = $files->track("attachments", $path);
            if (!Storage::disk("attachments")->put($path, $result["bytes"])) {
                throw new \RuntimeException("Attachment write failed.");
            }
            unset($result["bytes"]);
            $attachment = DB::transaction(function () use (
                $model,
                $id,
                $path,
                $result,
                $ticket
            ) {
                // Coordinate attachment publishing with the cleanup command.
                abort_unless(
                    DB::table("attachment_file_cleanup")
                        ->lockForUpdate()
                        ->find($ticket),
                    409
                );
                $record = $model::lockForUpdate()->findOrFail($id);
                $attachment = $record
                    ->attachments()
                    ->create([
                        "disk" => "attachments",
                        "file_path" => $path,
                        ...$result,
                    ]);
                DB::table("attachment_file_cleanup")
                    ->where("id", $ticket)
                    ->delete();

                return $attachment;
            });

            return response()->json(
                [
                    "message" => "Attachment berhasil disimpan.",
                    "data" => $attachment,
                ],
                201
            );
        } catch (Throwable $error) {
            if ($ticket !== null) {
                $files->cleanup($ticket);
            }
            if (
                $error instanceof ValidationException ||
                $error instanceof HttpExceptionInterface ||
                $error instanceof ModelNotFoundException
            ) {
                throw $error;
            }
            report($error);

            return response()->json(
                [
                    "message" =>
                        "Gambar gagal diproses atau disimpan. Silakan coba kembali.",
                ],
                500
            );
        }
    }

    public function show(string $resource, string $id, string $attachmentId)
    {
        $record = $this->model($resource)::findOrFail($id);
        $attachment = $record->attachments()->findOrFail($attachmentId);
        // Only publish files created and verified by this image pipeline.
        abort_unless(
            $attachment->disk === "attachments" &&
                $attachment->mime_type === "image/avif",
            404
        );
        $disk = Storage::disk("attachments");
        abort_unless($disk->exists($attachment->file_path), 404);

        return $disk->response(
            $attachment->file_path,
            $attachment->getKey() . ".avif",
            [
                "Content-Type" => "image/avif",
                "X-Content-Type-Options" => "nosniff",
                "Cache-Control" => "no-store",
            ],
            "inline"
        );
    }

    public function destroy(
        string $resource,
        string $id,
        string $attachmentId,
        AttachmentFiles $files
    ) {
        DB::transaction(function () use (
            $resource,
            $id,
            $attachmentId,
            $files
        ) {
            $record = $this->model($resource)
                ::lockForUpdate()
                ->findOrFail($id);
            $attachment = $record->attachments()->findOrFail($attachmentId);
            $files->removeAfterCommit($attachment);
            $attachment->delete();
        });

        return response()->noContent();
    }
}
