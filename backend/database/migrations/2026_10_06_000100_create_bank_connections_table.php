<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('external_id');
            $table->string('status', 20)->default('pending_link');
            $table->string('institution_name', 120)->nullable();
            $table->string('institution_logo_url', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('settings')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['status', 'last_synced_at']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('connection_id')->nullable()->constrained('bank_connections')->nullOnDelete();
            $table->string('external_id')->nullable();
            $table->bigInteger('provider_balance')->nullable();
            $table->timestamp('provider_synced_at')->nullable();
            $table->unique(['connection_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['connection_id', 'external_id']);
            $table->dropColumn(['external_id', 'provider_balance', 'provider_synced_at']);
            $table->dropConstrainedForeignId('connection_id');
        });

        Schema::dropIfExists('bank_connections');
    }
};
