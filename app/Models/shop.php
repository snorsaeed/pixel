<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    protected $fillable = [
        'shop_name',
        'phone',
        'expires_at',
        'decant_price',
        'min_decant_price',
        'rent',
    ];

    protected $casts = [
        'expires_at'       => 'datetime',
        // Money fields: 4 decimals so USD↔IQD round-trips exactly
        // (600000 IQD → 363.6364 USD → 600000 IQD, not 600006)
        'decant_price'     => 'decimal:4',
        'min_decant_price' => 'decimal:4',
        'rent'             => 'decimal:4',
    ];

    // ─────────────────────────────────────────────────────────────
    // Relations
    // ─────────────────────────────────────────────────────────────

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function products()
    {
        return $this->hasMany(products::class, 'shop_id');
    }

    public function webproducts()
    {
        return $this->hasMany(webproducts::class, 'shop_id');
    }

    public function sale()
    {
        return $this->hasMany(sales::class, 'shop_id');
    }

    public function setting()
    {
        return $this->hasOne(ShopSetting::class, 'shop_id');
    }

    public function employees()
    {
        return $this->hasMany(ShopEmployee::class, 'shop_id');
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class, 'shop_id');
    }

    // ─────────────────────────────────────────────────────────────
    // Rent history
    // ─────────────────────────────────────────────────────────────

    public function rents()
    {
        return $this->hasMany(ShopRent::class)->orderBy('effective_from');
    }

    /**
     * Latest rent row whose effective_from <= current month.
     */
    public function currentRent(): ?ShopRent
    {
        return $this->rents()
            ->where('effective_from', '<=', now()->startOfMonth()->toDateString())
            ->latest('effective_from')
            ->first();
    }

    /**
     * Rent row that applied in a specific month.
     */
    public function rentForMonth(Carbon $month): ?ShopRent
    {
        return $this->rents()
            ->where('effective_from', '<=', $month->copy()->startOfMonth()->toDateString())
            ->latest('effective_from')
            ->first();
    }

    /**
     * Rent amount (float) for a given month, or null if none.
     */
    public function rentAmountForMonth(Carbon $month): ?float
    {
        $row = $this->rentForMonth($month);
        return $row ? (float) $row->amount : null;
    }

    /**
     * Rent amount currently in effect (falls back to the cached column).
     */
    public function currentRentAmount(): ?float
    {
        $row = $this->currentRent();
        return $row ? (float) $row->amount : ($this->rent !== null ? (float) $this->rent : null);
    }
}