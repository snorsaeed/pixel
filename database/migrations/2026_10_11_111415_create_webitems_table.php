<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webitems', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
    $table->foreignId('websales_id')->constrained('websales')->cascadeOnDelete();
    $table->foreignId('products_id')->constrained('webproducts')->cascadeOnDelete();
    $table->integer('qyt');
    $table->float('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webitems');
    }
};
