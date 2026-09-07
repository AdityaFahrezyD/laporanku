<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasUuids;

    protected $primaryKey = 'category_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['name', 'type'];

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class, 'category_id', 'category_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id', 'category_id');
    }
}
