<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // sale_items — the item-level money
        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table) {
                if (Schema::hasColumn('sale_items', 'price')) {
                    $table->decimal('price', 12, 4)->nullable()->change();
                }
                if (Schema::hasColumn('sale_items', 'amount_paid')) {
                    $table->decimal('amount_paid', 12, 4)->default(0)->change();
                }
                if (Schema::hasColumn('sale_items', 'amount_due')) {
                    $table->decimal('amount_due', 12, 4)->default(0)->change();
                }
                if (Schema::hasColumn('sale_items', 'paid_before_close')) {
                    $table->decimal('paid_before_close', 12, 4)->default(0)->change();
                }
            });
        }

        // sales — the sale-level totals
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (Schema::hasColumn('sales', 'total')) {
                    $table->decimal('total', 12, 4)->default(0)->change();
                }
                if (Schema::hasColumn('sales', 'iqd_total')) {
                    $table->decimal('iqd_total', 14, 4)->default(0)->change();
                }
                if (Schema::hasColumn('sales', 'discount')) {
                    $table->decimal('discount', 8, 4)->default(0)->change();
                }
            });
        }
    }

    public function down(): void
    {
        // Never narrow back — would corrupt money.
    }
};