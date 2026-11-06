<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopRent extends Model
{
    protected $fillable = ['shop_id', 'effective_from', 'amount'];

    protected $casts = [
        'effective_from' => 'date',
        'amount'         => 'decimal:2',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}