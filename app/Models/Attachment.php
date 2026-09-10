<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = "attachment_id";

    protected $keyType = "string";

    public $incrementing = false;

    protected $fillable = [
        "attachable_id",
        "attachable_type",
        "file_path",
        "disk",
        "mime_type",
        "size",
        "width",
        "height",
    ];

    protected $visible = [
        "attachment_id",
        "url",
        "mime_type",
        "size",
        "width",
        "height",
    ];

    protected $appends = ["url"];

    public function getUrlAttribute(): ?string
    {
        $resource = match ($this->attachable_type) {
            (new Income())->getMorphClass() => "incomes",
            (new Expense())->getMorphClass() => "expenses",
            (new Transfer())->getMorphClass() => "transfers",
            default => null,
        };

        return $resource &&
            $this->disk === "attachments" &&
            $this->mime_type === "image/avif"
            ? "/api/" .
                    $resource .
                    "/" .
                    $this->attachable_id .
                    "/attachments/" .
                    $this->getKey()
            : null;
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
