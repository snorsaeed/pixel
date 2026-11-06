<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class products extends Model
{
    /** @use HasFactory<\Database\Factories\ProductsFactory> */
    use HasFactory;
    use MassPrunable;

    protected $fillable = [
        'brand',
        'model',
        'barcode',
        'buy_price',
        'sale_price',
        'qyt',
        'shop_id',
    ];

    public function shop()
    {
        return $this->belongsTo(shop::class, 'shop_id');
    }

    public function webproduct()
    {
        return $this->hasOne(webproducts::class, 'products_id');
    }

    public function sale_item()
    {
        return $this->hasMany(sale_items::class, 'products_id');
    }

    public function prunable(): Builder
    {
        return static::query()
            ->where('qyt', '<=', 0)
            ->where('updated_at', '<', now()->subYear())
            ->whereDoesntHave('sale_item')
            ->whereDoesntHave('webproduct');
    }
}