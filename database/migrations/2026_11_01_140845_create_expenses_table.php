<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')
                  ->constrained('shops')
                  ->cascadeOnDelete();

            $table->string('title')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('category')->nullable()->index();
            $table->date('expense_date')->nullable()->index();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            $table->index(['shop_id', 'expense_date']);
            $table->index(['shop_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};