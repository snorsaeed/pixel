<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_employees', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')
                  ->constrained('shops')
                  ->cascadeOnDelete();

            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('role')->nullable();
            $table->decimal('salary', 12, 2)->nullable();

            $table->date('hire_date')->nullable();
            $table->boolean('is_active')->nullable()->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_employees');
    }
};