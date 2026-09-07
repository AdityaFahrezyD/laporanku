<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'wallet_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['name', 'type', 'balance', 'is_active'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function walletIncomes(): HasMany
    {
        return $this->hasMany(Income::class, 'wallet_id', 'wallet_id');
    }

    public function walletExpenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'wallet_id', 'wallet_id');
    }

    public function walletTransfersFrom(): HasMany
    {
        return $this->hasMany(Transfer::class, 'from_wallet_id', 'wallet_id');
    }

    public function walletTransfersTo(): HasMany
    {
        return $this->hasMany(Transfer::class, 'to_wallet_id', 'wallet_id');
    }
}
