<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class webproducts extends Model
{
    use MassPrunable;

    protected $fillable = [
        'img',
        'size',
        'products_id',
        'shop_id',
        'price',
        'notes',
        'disc',
        'gender',
        'discount',
        'barcode',
        'brand',
        'model',
        'stock',
        'avb',
    ];

    protected $casts = [
        'price'      => 'decimal:4',
        'discount'   => 'decimal:4',
        'disc'       => 'decimal:4',
        'stock'      => 'integer',
        'avb'        => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function products()
    {
        return $this->belongsTo(products::class, 'products_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function webitems()
    {
        return $this->hasMany(webitems::class, 'products_id');
    }

    public function prunable(): Builder
    {
        return static::query()
            ->where('stock', '<=', 0)
            ->where('updated_at', '<', now()->subYear())
            ->whereDoesntHave('products');
    }
}