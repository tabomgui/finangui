<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // cascade no banco para a exclusão do usuário funcionar; a API bloqueia
            // excluir categoria com filhas (DeleteCategory).
            $table->foreignId('parent_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('kind', 10);
            $table->string('icon', 50)->nullable();
            $table->string('color', 7)->nullable();
            $table->boolean('is_transfer')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'parent_id', 'name'])->nullsNotDistinct();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
