<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Income extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'income_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['wallet_id', 'amount', 'category_id', 'description', 'transaction_date'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'transaction_date' => 'datetime'];
    }

    public function incomeWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'wallet_id');
    }

    public function incomeAttachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function attachments(): MorphMany
    {
        return $this->incomeAttachments();
    }
}
