<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_items', 'amount_paid')) {
                $table->decimal('amount_paid', 12, 2)->default(0)->after('qyt');
            }
            if (!Schema::hasColumn('sale_items', 'amount_due')) {
                $table->decimal('amount_due', 12, 2)->default(0)->after('amount_paid');
            }
        });

        // Backfill: every existing item gets amount_due = price * qyt
        // and amount_paid = 0, UNLESS it's already marked 'sold' (then it's fully paid)
        DB::table('sale_items')->update([
            'amount_paid' => DB::raw("CASE
                WHEN status = 'sold'     THEN price * qyt
                WHEN status = 'returned' THEN 0
                ELSE 0
            END"),
            'amount_due' => DB::raw("CASE
                WHEN status = 'pending'  THEN price * qyt
                ELSE 0
            END"),
        ]);
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('sale_items', 'amount_paid')) {
                $table->dropColumn('amount_paid');
            }
            if (Schema::hasColumn('sale_items', 'amount_due')) {
                $table->dropColumn('amount_due');
            }
        });
    }
};