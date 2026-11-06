<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopEmployee extends Model
{
    protected $fillable = [
        'shop_id',
        'name',
        'phone',
        'role',
        'salary',
        'hire_date',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'salary'    => 'float',
        'hire_date' => 'date',
        'is_active' => 'bool',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}