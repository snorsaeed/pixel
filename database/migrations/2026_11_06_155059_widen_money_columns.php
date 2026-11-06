<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── shop_settings ──
        if (Schema::hasTable('shop_settings')) {
            Schema::table('shop_settings', function (Blueprint $table) {
                // iqd_rate stays as decimal(8,2) — it's a rate, not a money amount
                if (Schema::hasColumn('shop_settings', 'discount_min_spend')) {
                    $table->decimal('discount_min_spend', 12, 4)->default(0)->change();
                }
                if (Schema::hasColumn('shop_settings', 'discount_value')) {
                    $table->decimal('discount_value', 12, 4)->default(0)->change();
                }
            });
        }

        // ── shops ──
        if (Schema::hasTable('shops')) {
            Schema::table('shops', function (Blueprint $table) {
                if (Schema::hasColumn('shops', 'rent')) {
                    $table->decimal('rent', 12, 4)->nullable()->change();
                }
                if (Schema::hasColumn('shops', 'decant_price')) {
                    $table->decimal('decant_price', 12, 4)->nullable()->change();
                }
                if (Schema::hasColumn('shops', 'min_decant_price')) {
                    $table->decimal('min_decant_price', 12, 4)->nullable()->change();
                }
            });
        }

        // ── shop_rents ──
        if (Schema::hasTable('shop_rents')) {
            Schema::table('shop_rents', function (Blueprint $table) {
                if (Schema::hasColumn('shop_rents', 'amount')) {
                    $table->decimal('amount', 12, 4)->nullable()->change();
                }
            });
        }

        // ── shop_employees ──
        if (Schema::hasTable('shop_employees')) {
            Schema::table('shop_employees', function (Blueprint $table) {
                if (Schema::hasColumn('shop_employees', 'salary')) {
                    $table->decimal('salary', 12, 4)->nullable()->change();
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally left empty: narrowing money columns back to (…,2)
        // would round-trip loss (600000 IQD → 363.64 USD → 600006 IQD).
        // Never roll this migration back.
    }
};