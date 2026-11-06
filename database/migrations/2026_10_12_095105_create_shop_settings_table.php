<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained('shops')->cascadeOnDelete();

            // IQT
            $table->decimal('iqd_rate', 8, 2)->default(0);

            // Discount
            $table->string('discount_code')->nullable();
            $table->decimal('discount_min_spend', 10, 2)->default(0);
            $table->decimal('discount_value', 10, 2)->default(0);
            $table->enum('discount_type', ['percentage', 'fixed'])->default('percentage');

            // Website / hero
            $table->string('website_url')->nullable();
            $table->string('website_img')->nullable();
            $table->string('hero_url')->nullable();
            $table->text('hero_text')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};