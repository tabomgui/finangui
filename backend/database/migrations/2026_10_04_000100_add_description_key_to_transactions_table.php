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
            $table->index(['user_id', 'description_key']);
        });

        DB::table('transactions')->select(['id', 'description'])->orderBy('id')->chunkById(1000, function ($rows) {
            foreach ($rows as $row) {
                DB::table('transactions')->where('id', $row->id)->update(['description_key' => TextNormalizer::key($row->description)]);
            }
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
