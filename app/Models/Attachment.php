<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'attachment_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'attachable_id',
        'attachable_type',
        'file_path',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
