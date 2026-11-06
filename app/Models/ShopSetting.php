<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopSetting extends Model
{
    protected $fillable = [
        'shop_id',
        'iqd_rate',
        'discount_code',
        'discount_min_spend',
        'discount_value',
        'discount_type',
        'rent',
        'decant_price',
        'min_decant_price',
        'printer_id',
        'printer_api_key',
        'website_url',
        'website_img',
        'hero_url',
        'hero_text',
    ];

    protected function casts(): array
    {
        return [
            // iqd_rate: 2 decimals is fine — it's a rate, not a converted amount
            'iqd_rate'           => 'decimal:2',

            // Money fields: keep 4 decimals so USD↔IQD round-trips exactly
            'discount_min_spend' => 'decimal:4',
            'discount_value'     => 'decimal:4',
            'rent'               => 'decimal:4',
            'decant_price'       => 'decimal:4',
            'min_decant_price'   => 'decimal:4',
        ];
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function applyDiscount(string $code, float $subtotal): float
    {
        $storedCode = (string) $this->discount_code;
        $minSpend   = (float) ($this->discount_min_spend ?? 0);
        $value      = (float) ($this->discount_value ?? 0);

        if (
            ! $this->discount_code ||
            strtoupper($code) !== strtoupper($storedCode) ||
            $subtotal < $minSpend ||
            $value <= 0
        ) {
            return 0;
        }

        return $this->discount_type === 'percentage'
            ? $subtotal * ($value / 100)
            : min($value, $subtotal);
    }
}