<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Transfer extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'transfer_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['from_wallet_id', 'to_wallet_id', 'amount', 'description', 'transaction_date'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'transaction_date' => 'datetime'];
    }

    public function transferFrom(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'from_wallet_id', 'wallet_id');
    }

    public function transferTo(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'to_wallet_id', 'wallet_id');
    }

    public function transferAttachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function attachments(): MorphMany
    {
        return $this->transferAttachments();
    }
}
