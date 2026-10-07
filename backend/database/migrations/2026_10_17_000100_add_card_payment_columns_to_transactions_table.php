<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guardado por hasColumn: permite rodar de novo (ex.: para reaplicar
        // o backfill abaixo) sem falhar por coluna já existente.
        if (! Schema::hasColumn('transactions', 'card_payment_statement_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                // Marca explícita de pagamento de fatura reconhecido (ver
                // App\Domain\Cards\Actions\AssignStatement e
                // App\Domain\Banking\Actions\ReconcileCardPayments): quando
                // preenchida, esta transação (sempre direction=in num cartão)
                // é um pagamento, não uma cobrança/estorno, e o valor é
                // sempre igual a statement_id — nunca um valor diferente, o
                // CHECK abaixo garante isso. App\Domain\Cards\Models\CardStatement::scopeWithTotals
                // usa esta coluna (não mais transfer_id) para separar
                // pagamento de estorno.
                $table->foreignId('card_payment_statement_id')->nullable()->after('statement_id')
                    ->constrained('card_statements')->nullOnDelete();
                $table->index('card_payment_statement_id');
            });
        }

        // O código nunca grava os dois campos separadamente: toda escrita
        // passa por App\Domain\Cards\Actions\AssignStatement (que sempre
        // grava statement_id e card_payment_statement_id juntos a partir do
        // mesmo $statement) ou por um dos poucos pontos que os espelham
        // explicitamente (App\Domain\Banking\Actions\ReconcileCardPayments,
        // App\Domain\Imports\Actions\RevertImportBatch) — o CHECK é a rede
        // de segurança contra os dois saírem de sincronia por acidente.
        if (! $this->constraintExists('transactions_card_payment_statement_id_matches_statement')) {
            DB::statement(
                'ALTER TABLE transactions ADD CONSTRAINT transactions_card_payment_statement_id_matches_statement '
                .'CHECK (card_payment_statement_id IS NULL OR card_payment_statement_id = statement_id)'
            );
        }

        if (! Schema::hasColumn('transactions', 'ignored_reason')) {
            Schema::table('transactions', function (Blueprint $table) {
                // Motivo visível quando is_ignored foi marcado automaticamente
                // (ex.: pagamento duplicado do mesmo cartão) — null num
                // lançamento ignorado à mão pelo usuário (ou cujo is_ignored
                // o usuário editou depois: ver App\Domain\Transactions\Actions\UpdateTransaction).
                $table->string('ignored_reason')->nullable()->after('is_ignored');
            });
        }

        if (! Schema::hasColumn('transactions', 'card_payment_locked')) {
            Schema::table('transactions', function (Blueprint $table) {
                // true assim que o usuário edita is_ignored à mão (ver
                // UpdateTransaction) — App\Domain\Banking\Actions\ReconcileCardPayments
                // nunca mais toca is_ignored/ignored_reason/card_payment_statement_id
                // desta transação depois disso, mesmo rodando de novo.
                $table->boolean('card_payment_locked')->default(false)->after('ignored_reason');
            });
        }

        // Uma perna de transferência já gravada antes desta coluna existir é,
        // por definição, um pagamento reconhecido (é perna de transferência
        // para dentro do cartão) — o marcador sempre igual a statement_id.
        // Escopado a cartões porque statement_id só existe em contas de
        // cartão; whereNull evita regravar quem já foi marcado (reaplicar
        // esta migration não desfaz nada).
        $creditCardAccountIds = DB::table('accounts')->where('type', 'credit_card')->pluck('id');

        if ($creditCardAccountIds->isNotEmpty()) {
            DB::table('transactions')
                ->whereIn('account_id', $creditCardAccountIds)
                ->where('direction', 'in')
                ->whereNotNull('transfer_id')
                ->whereNotNull('statement_id')
                ->whereNull('card_payment_statement_id')
                ->update(['card_payment_statement_id' => DB::raw('statement_id')]);
        }
    }

    private function constraintExists(string $name): bool
    {
        return DB::select('SELECT 1 FROM pg_constraint WHERE conname = ?', [$name]) !== [];
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_card_payment_statement_id_matches_statement');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('card_payment_statement_id');
            $table->dropColumn(['ignored_reason', 'card_payment_locked']);
        });
    }
};
