<?php

use App\Domain\Rules\Support\TextNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('description_key')->default('');
        });

        // Uma atualização por descrição distinta, não por linha: muito mais
        // rápido quando a mesma descrição se repete em muitas transações.
        DB::table('transactions')->select('description')->distinct()->orderBy('description')->cursor()
            ->each(function ($row) {
                $key = TextNormalizer::key($row->description);

                $row->description === null
                    ? DB::table('transactions')->whereNull('description')->update(['description_key' => $key])
                    : DB::table('transactions')->where('description', $row->description)->update(['description_key' => $key]);
            });

        // O índice só depois do backfill: evita manter a árvore do índice
        // atualizada a cada um dos updates acima.
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'description_key']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'description_key']);
            $table->dropColumn('description_key');
        });
    }
};
