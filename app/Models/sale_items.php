<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class sale_items extends Model
{
    use MassPrunable;

    protected $table = 'sale_items';

    protected $fillable = [
        'sales_id',
        'products_id',
        'price',
        'qyt',
        'status',
        'is_tester',
        'amount_paid',
        'amount_due',
        'paid_before_close',
    ];

    protected $casts = [
        // Money — 4 decimals so USD↔IQD round-trips exactly
        'price'             => 'decimal:4',
        'amount_paid'       => 'decimal:4',
        'amount_due'        => 'decimal:4',
        'paid_before_close' => 'decimal:4',

        // Flags / ints
        'is_tester'         => 'boolean',
        'qyt'               => 'integer',

        // Timestamps
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────
    // Relations
    // ─────────────────────────────────────────────────────────

    public function products()
    {
        return $this->belongsTo(products::class, 'products_id');
    }

    public function sale()
    {
        return $this->belongsTo(sales::class, 'sales_id');
    }

    public function lineRevenue(): float
    {
        if ($this->is_tester) return 0.0;
        return round((float) $this->price * (int) $this->qyt, 4);
    }

    public function lineProfit(): float
    {
        if ($this->is_tester) return 0.0;
        $buy = (float) ($this->products->buy_price ?? 0);
        return round(((float) $this->price - $buy) * (int) $this->qyt, 4);
    }

    // ─────────────────────────────────────────────────────────
    // Prune: delete RETURNED items older than 6 months.
    // Sold / pending items are never touched.
    // ─────────────────────────────────────────────────────────
    public function prunable(): Builder
    {
        return static::query()
            ->where('status', 'returned')
            ->where('created_at', '<', now()->subMonths(6));
    }
}