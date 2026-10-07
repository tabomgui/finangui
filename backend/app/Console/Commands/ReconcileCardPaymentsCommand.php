<?php

namespace App\Console\Commands;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Actions\ReconcileCardPayments;
use App\Domain\Banking\Contracts\BankProviderFactory;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Errors\BankingDisabled;
use App\Domain\Banking\Support\CardPaymentDryRunAborted;
use App\Models\User;
use App\Support\UserContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Aplica a reconciliação de pagamentos de fatura (App\Domain\Banking\Actions\ReconcileCardPayments)
 * em todo o histórico, não só na janela de um sync — para cartões conectados
 * a um banco, busca de novo as faturas (com `payments[]`) do provedor;
 * cartões sem conexão (ou sem credenciais no momento) são reconciliados só
 * pelas regras que não dependem disso (transferência já ligada, padrão de
 * descrição). `--dry-run` mostra o que mudaria sem gravar nada: a
 * reconciliação de cada cartão roda normalmente dentro de uma transação
 * própria, só que a transação é desfeita no final em vez de confirmada.
 */
final class ReconcileCardPaymentsCommand extends Command
{
    protected $signature = 'cards:reconcile-payments
        {--user= : Id ou e-mail de um usuário específico}
        {--dry-run : Mostra o que mudaria sem gravar nada}';

    protected $description = 'Reconcilia pagamentos de fatura de cartão em todo o histórico';

    public function __construct(
        private readonly BankProviderFactory $providerFactory,
        private readonly ReconcileCardPayments $reconcile,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $users = $this->users();

        if ($users->isEmpty()) {
            $this->error('Nenhum usuário encontrado.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $hadFailure = false;

        foreach ($users as $user) {
            UserContext::run($user, function () use ($user, $dryRun, &$hadFailure) {
                if (! $this->reconcileForUser($user, $dryRun)) {
                    $hadFailure = true;
                }
            });
        }

        return $hadFailure ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, User>
     */
    private function users(): Collection
    {
        $option = $this->option('user');

        if ($option === null) {
            return User::query()->get();
        }

        $option = (string) $option;

        return User::query()
            ->where(function ($q) use ($option) {
                if (ctype_digit($option)) {
                    $q->orWhere('id', (int) $option);
                }
                $q->orWhere('email', mb_strtolower($option));
            })
            ->get();
    }

    /**
     * @return bool false quando algum cartão deste usuário falhou
     */
    private function reconcileForUser(User $user, bool $dryRun): bool
    {
        $cards = Account::query()->where('type', AccountType::CreditCard->value)->get();

        if ($cards->isEmpty()) {
            return true;
        }

        $provider = null;

        try {
            $provider = $this->providerFactory->for($user);
        } catch (BankingDisabled) {
            // Sem credenciais verificadas: segue sem faturas do banco — a
            // reconciliação ainda roda pelas regras que não dependem delas.
        }

        $ok = true;

        foreach ($cards as $card) {
            if ($provider !== null && $card->connection_id !== null && $card->external_id !== null) {
                try {
                    $bills = $provider->bills($card->external_id);
                } catch (Throwable $e) {
                    // Sem saber o estado real das faturas agora, reconciliar
                    // só pelas regras que não dependem do banco arriscaria
                    // perder o rastro de um pagamento que só a fatura do
                    // banco identifica — melhor pular este cartão nesta
                    // rodada do que reconciliar com dados incompletos.
                    $this->error("Cartão {$card->id} ({$card->name}): falha ao buscar faturas do banco ({$e->getMessage()}); pulando.");
                    $ok = false;

                    continue;
                }
            } else {
                $bills = [];
            }

            try {
                $counts = $this->reconcileCard($card, $bills, $dryRun);
            } catch (Throwable $e) {
                $this->error("Cartão {$card->id} ({$card->name}): falha ao reconciliar ({$e->getMessage()}).");
                $ok = false;

                continue;
            }

            $prefix = $dryRun ? '[dry-run] ' : '';
            $this->info(sprintf(
                '%sCartão %d (%s): %d pagamento(s) marcado(s), %d duplicata(s) ignorada(s), %d transferência(s) ligada(s).',
                $prefix,
                $card->id,
                $card->name,
                $counts['payments'],
                $counts['duplicates'],
                $counts['transfers_linked'],
            ));
        }

        return $ok;
    }

    /**
     * @param  list<ProviderBill>  $bills
     * @return array{payments: int, duplicates: int, transfers_linked: int}
     */
    private function reconcileCard(Account $card, array $bills, bool $dryRun): array
    {
        // Mesma ideia de janela de App\Domain\Banking\Jobs\SyncConnection:
        // sem bills (cartão manual, ou sem credenciais), sem restrição —
        // cobre todo o histórico. Com bills, nunca reconsidera (nem reseta)
        // nada anterior ao fechamento da fatura mais antiga buscada agora —
        // esta busca pode não cobrir o histórico inteiro do cartão.
        $windowFrom = ReconcileCardPayments::earliestBillClosing($bills);

        try {
            return $this->reconcile->handle($card, $bills, $windowFrom, dryRun: $dryRun);
        } catch (CardPaymentDryRunAborted $e) {
            // Esperado em modo dry-run: a transação já foi desfeita dentro
            // de ReconcileCardPayments::handle() — os contadores vêm com a
            // exceção porque o valor de retorno normal nunca chega aqui.
            return $e->counts;
        }
    }
}
