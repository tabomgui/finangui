<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_sync_runs', function (Blueprint $table) {
            $table->id();
            // Mesmo dono da conexão — BelongsToUser exige a coluna; nunca
            // gravado separado do user_id de $connection.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('bank_connections')->cascadeOnDelete();
            // scheduled | manual | connect | credentials — ver App\Domain\Banking\Enums\SyncTrigger.
            $table->string('trigger', 20);
            // uuid do job de fila desta tentativa (ver App\Domain\Banking\Jobs\SyncConnection)
            // — o mesmo em todo retry da mesma execução lógica (release() ou
            // retry automático), diferente em qualquer dispatch novo. Decide
            // se uma run `running` encontrada é "esta mesma tentativa de
            // novo" (reaproveita) ou uma run presa de uma execução anterior
            // (fecha como erro) — nunca reaproveita só por estar `running`.
            $table->uuid('job_uuid')->nullable();
            // running | success | partial | error — ver App\Domain\Banking\Enums\SyncRunStatus.
            $table->string('status', 20)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->boolean('refresh_requested')->default(false);
            // lastUpdatedAt do item (no provedor) no fim desta execução.
            $table->timestamp('provider_updated_at')->nullable();
            $table->unsignedInteger('added_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('bills_count')->default(0);
            // Lista curta de avisos voltados ao usuário (ex.: falha ao pedir
            // atualização ao banco, pagamentos duplicados ignorados).
            $table->jsonb('warnings')->nullable();
            // Contadores da reconciliação de pagamentos de fatura (ver
            // App\Domain\Banking\Actions\ReconcileCardPayments) desta execução,
            // quando não entraram em warnings.
            $table->jsonb('stats')->nullable();
            // Mensagem voltada ao usuário, sem segredos/stack — ver
            // App\Domain\Banking\Jobs\SyncConnection.
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['connection_id', 'id']);
            $table->index(['connection_id', 'status']);
        });

        Schema::create('bank_sync_run_items', function (Blueprint $table) {
            $table->id();
            // Mesmo dono da run — BelongsToUser exige a coluna.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('bank_sync_runs')->cascadeOnDelete();
            // nullOnDelete: a transação pode ser excluída depois (ex.: revert
            // de um lote, ou exclusão manual) — o snapshot abaixo mantém o
            // item legível mesmo assim.
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('account_name', 120);
            $table->date('date');
            $table->string('description', 255);
            $table->bigInteger('amount');
            $table->string('direction', 10);
            $table->timestamps();

            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_sync_run_items');
        Schema::dropIfExists('bank_sync_runs');
    }
};
