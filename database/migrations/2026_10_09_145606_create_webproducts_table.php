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
        Schema::create('webproducts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('products_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->timestamps();
            $table->string('img')->nullable();
            $table->string('brand');
            $table->string('model');
            $table->string('barcode');
            $table->text('disc')->nullable();
            $table->string('gender')->nullable();
            $table->integer('size')->nullable();
            $table->float('discount')->default(0);
            $table->string('notes')->nullable();
            $table->float('price');
            $table->integer('stock');
            $table->string('avb')->default('yes');


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webproducts');
    }
};
