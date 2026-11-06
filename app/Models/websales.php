<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class websales extends Model
{
protected $fillable = [
    'shop_id',
    'name',
    'city',
    'address',
    'code',
    'status',           // ← add
    'total',
    'delivery_price',
    'iqd_rate',
    'iqd_total',
];

    public function items()
    {
        return $this->hasMany(webitems::class, 'websales_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }
}