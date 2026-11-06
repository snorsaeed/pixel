<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websales', function (Blueprint $table) {
            $table->decimal('delivery_price', 10, 2)->default(0)->after('total');
            $table->decimal('iqd_rate', 10, 2)->default(0)->after('delivery_price');
            $table->decimal('iqd_total', 12, 2)->default(0)->after('iqd_rate');
        });
    }

    public function down(): void
    {
        Schema::table('websales', function (Blueprint $table) {
            $table->dropColumn(['delivery_price', 'iqd_rate', 'iqd_total',]);
        });
    }
};