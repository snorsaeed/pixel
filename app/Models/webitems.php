<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class webitems extends Model
{
    protected $fillable = [
        'websales_id',
        'products_id',
        'qyt',
        'price',
    ];

    public function sale()
    {
        return $this->belongsTo(websales::class, 'websales_id');
    }

    public function product()
    {
        return $this->belongsTo(webproducts::class, 'products_id');
    }
}