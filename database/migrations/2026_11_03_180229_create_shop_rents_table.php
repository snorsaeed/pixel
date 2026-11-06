<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_rents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->date('effective_from'); // always the 1st of the month
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['shop_id', 'effective_from']);
            $table->index(['shop_id', 'effective_from']);
        });

        // Backfill from the existing shops.rent column.
        // Effective from the month the shop was created.
        DB::table('shops')
            ->whereNotNull('rent')
            ->orderBy('id')
            ->each(function ($shop) {
                $created = $shop->created_at
                    ? \Carbon\Carbon::parse($shop->created_at)->startOfMonth()
                    : now()->startOfMonth();

                DB::table('shop_rents')->insertOrIgnore([
                    'shop_id'        => $shop->id,
                    'effective_from' => $created->toDateString(),
                    'amount'         => $shop->rent,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_rents');
    }
};