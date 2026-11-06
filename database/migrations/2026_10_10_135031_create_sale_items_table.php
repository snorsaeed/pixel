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
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('products_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_id')->constrained()->cascadeOnDelete();
            $table->float('price');
            $table->integer('qyt');
            $table->string('status');
            

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
