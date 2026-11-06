<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class sales extends Model
{
    protected $fillable = [
        'total',
        'iqd_total',
        'shop_id',
        'discount',
        'name',
        'phone',
        
    ];

public function shop(){
    return $this->belongsTo(Shop::class, 'shop_id');
}
// sales.php
public function sale_items(){
    return $this->hasMany(sale_items::class, 'sales_id');  // was 'sale_id'
}
}
