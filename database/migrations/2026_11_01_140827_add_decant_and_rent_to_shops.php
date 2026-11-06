<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->decimal('decant_price', 10, 2)->nullable()->after('phone');
            $table->decimal('min_decant_price', 10, 2)->nullable()->after('decant_price');
            $table->decimal('rent', 12, 2)->nullable()->after('min_decant_price');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['decant_price', 'min_decant_price', 'rent']);
        });
    }
};