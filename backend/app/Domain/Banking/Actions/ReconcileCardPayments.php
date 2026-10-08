<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Support\CardPaymentCandidate;
use App\Domain\Banking\Support\CardPaymentDryRunAborted;
use App\Domain\Banking\Support\CardPaymentMatcher;
use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Support\StatementOrdering;
use App\Domain\Cards\Support\StatementResolver;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Actions\LinkTransfer;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Errors\TransferLinkInvalid;
use App\Domain\Transfers\Models\TransferSuggestion;
use App\Domain\Transfers\Queries\TransferCandidates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Reconcilia os créditos de um cartão com os pagamentos de fatura: reconhece
 * (App\Domain\Banking\Support\CardPaymentMatcher), deduplica, quita a fatura
 * certa (marca card_payment_statement_id/statement_id) e tenta ligar à
 * transferência correspondente numa conta não-cartão do usuário — sem
 * exigir a evidência de descrição que App\Domain\Transfers\Support\TransferMatcher
 * pede para ligar sozinho, porque aqui o crédito já foi reconhecido como
 * pagamento por outra via (fatura do banco ou padrão de descrição).
 *
 * Roda a cada sync bancário (App\Domain\Banking\Jobs\SyncConnection, com os
 * bills já buscados para este cartão, restrito à janela recém-sincronizada
 * via $windowFrom) e também pelo comando `cards:reconcile-payments` (mesma
 * ideia de janela: ver earliestBillClosing()). Dentro da janela (ou em todo
 * o histórico, sem janela), uma transação que não foi reconhecida nesta
 * passagem, mas estava marcada como pagamento ou ignorada por uma
 * reconciliação anterior, volta ao estado neutro (ver resetUnrecognized()) —
 * fora da janela, nada é tocado. Uma transação com card_payment_locked (o
 * usuário editou is_ignored à mão) nunca é alterada, mas, se hoje vale como
 * pagamento, ainda entra na combinação como referência fixa — ver pool().
 * `statement_locked` (o usuário escolheu a fatura à mão) é mais restrita:
 * ainda reconhece o crédito como pagamento normalmente (sai de is_ignored,
 * conta em paid()), mas nunca move statement_id — nem para a fatura que o
 * próprio banco relata (ver statementFor()) nem ao perder o reconhecimento
 * (ver resetUnrecognized()).
 */
final class ReconcileCardPayments
{
    // NUNCA mude este texto: é comparado literalmente em queries (ver o `where('ignored_reason',
    // self::DUPLICATE_REASON)` abaixo) para reconhecer uma duplicata já marcada por uma
    // reconciliação anterior — mudar o texto faria toda duplicata já gravada parecer uma
    // ignorada à mão (nunca mais reconsiderada) na próxima passagem. Um código estável
    // (ex.: enum) mapeado para o texto na borda (JsonResource) resolveria isso sem esse risco,
    // mas junto de outro motivo de ignorar que precise do mesmo tratamento — hoje só este existe.
    public const DUPLICATE_REASON = 'Pagamento duplicado: já contabilizado em outro lançamento do cartão.';

    private const TRANSFER_LINK_MAX_DAYS = 3;

    public function __construct(
        private readonly StatementResolver $resolver,
        private readonly AssignStatement $assignStatement,
        private readonly LinkTransfer $linkTransfer,
    ) {}

    /**
     * @param  list<ProviderBill>  $bills  faturas do banco já sincronizadas deste cartão (com payments[]) — [] quando não há (cartão manual, ou sem credenciais no comando de reconciliação)
     * @param  bool  $dryRun  calcula tudo normalmente, mas termina desfazendo a transação em vez de confirmar — ver App\Domain\Banking\Support\CardPaymentDryRunAborted, lançada com os contadores já prontos para quem chama capturar
     * @return array{payments: int, duplicates: int, transfers_linked: int}
     *
     * @throws CardPaymentDryRunAborted quando $dryRun é true
     */
    public function handle(Account $card, array $bills, ?CarbonImmutable $windowFrom = null, bool $dryRun = false): array
    {
        return DB::transaction(function () use ($card, $bills, $windowFrom, $dryRun) {
            // Serializa reconciliações concorrentes do mesmo cartão (sync
            // manual e agendado podem se cruzar).
            $card = Account::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();

            $counts = ['payments' => 0, 'duplicates' => 0, 'transfers_linked' => 0];

            $pool = $this->pool($card, $windowFrom);

            if ($pool->isEmpty()) {
                return $this->finish($counts, $dryRun);
            }

            $candidates = $pool->map(fn (Transaction $t) => new CardPaymentCandidate(
                id: $t->id,
                amountCents: $t->amount->cents,
                date: $t->date->toDateString(),
                description: $t->description,
                isTransferLeg: $t->isTransferLeg(),
                isProviderSourced: $t->source === TransactionSource::Pluggy,
                isPending: $t->status === TransactionStatus::Pending,
                isLocked: $t->card_payment_locked,
            ))->all();

            $decisions = CardPaymentMatcher::match($candidates, $bills);

            /** @var array<int, true> $decidedIds */
            $decidedIds = [];

            foreach ($decisions as $decision) {
                $decidedIds[$decision->transactionId] = true;
                $transaction = $pool->get($decision->transactionId);

                // Travada: entra na combinação só como referência fixa (já
                // reivindicou sua vaga em CardPaymentMatcher), nunca é
                // alterada — o usuário decidiu o estado dela à mão.
                if ($transaction === null || $transaction->card_payment_locked) {
                    continue;
                }

                if ($decision->isDuplicate) {
                    $counts['duplicates'] += $this->markDuplicate($transaction) ? 1 : 0;

                    continue;
                }

                $counts['payments'] += $this->markChosen($card, $transaction, $decision->billExternalId) ? 1 : 0;

                if (! $transaction->isTransferLeg()) {
                    $counts['transfers_linked'] += $this->tryLinkTransfer($transaction);
                }
            }

            foreach ($pool as $transaction) {
                if (! $transaction->card_payment_locked && ! isset($decidedIds[$transaction->id])) {
                    $this->resetUnrecognized($card, $transaction);
                }
            }

            return $this->finish($counts, $dryRun);
        });
    }

    /**
     * @param  array{payments: int, duplicates: int, transfers_linked: int}  $counts
     * @return array{payments: int, duplicates: int, transfers_linked: int}
     *
     * @throws CardPaymentDryRunAborted quando $dryRun é true
     */
    private function finish(array $counts, bool $dryRun): array
    {
        if ($dryRun) {
            throw new CardPaymentDryRunAborted($counts);
        }

        return $counts;
    }

    /**
     * Referência mais antiga entre as faturas informadas, ou null sem
     * nenhuma — usada tanto por App\Domain\Banking\Jobs\SyncConnection
     * quanto pelo comando `cards:reconcile-payments` para nunca reconsiderar
     * (nem resetar) nada fora do período que as próprias faturas buscadas
     * agora cobrem.
     *
     * Para cada fatura, a referência é o menor entre dois pisos:
     *
     * - o fechamento, quando o banco o informa; sem isso (comum em faturas
     *   antigas, de antes do cartão ter os dias atuais — ver
     *   App\Domain\Banking\Actions\SyncBills), o próprio vencimento menos 40
     *   dias (o vão máximo entre fechamento e vencimento — ver
     *   App\Domain\Cards\Support\StatementOrdering::MAX_SPAN_DAYS —, a
     *   estimativa mais cedo possível sem nenhum fechamento real);
     * - cada data de payments[] desta fatura menos WINDOW_DAYS (a mesma
     *   janela de App\Domain\Banking\Support\CardPaymentMatcher): um
     *   pagamento informado antes mesmo do piso acima (não deveria
     *   acontecer, mas o banco já mandou payments[] com datas estranhas)
     *   não pode ficar fora da janela reconsiderada.
     *
     * NUNCA pula uma fatura por falta de fechamento: pular faria a janela
     * calculada aqui começar tarde demais sempre que houver, entre as
     * faturas buscadas, uma mais antiga sem fechamento e outra mais recente
     * com fechamento — excluindo do pool() de handle() créditos de pagamento
     * de faturas antigas que, de outra forma, casariam normalmente com os
     * `payments[]` dessas mesmas faturas (ou com o padrão de descrição).
     *
     * @param  list<ProviderBill>  $bills
     */
    public static function earliestBillClosing(array $bills): ?CarbonImmutable
    {
        $earliest = null;

        foreach ($bills as $bill) {
            $reference = $bill->closingDate !== null
                ? CarbonImmutable::parse($bill->closingDate)
                : CarbonImmutable::parse($bill->dueDate)->subDays(StatementOrdering::MAX_SPAN_DAYS);

            foreach ($bill->payments as $payment) {
                if ($payment->date === '') {
                    continue;
                }

                $paymentFloor = CarbonImmutable::parse($payment->date)->subDays(CardPaymentMatcher::WINDOW_DAYS);

                if ($paymentFloor->lessThan($reference)) {
                    $reference = $paymentFloor;
                }
            }

            if ($earliest === null || $reference->lessThan($earliest)) {
                $earliest = $reference;
            }
        }

        return $earliest;
    }

    /**
     * Entradas do cartão candidatas: não travadas e (não ignoradas, ou
     * ignoradas por uma reconciliação anterior desta mesma rotina — para
     * acompanhar uma duplicata que só apareceu depois, ou reconsiderar se o
     * papel de escolhido/duplicata mudou); mais as travadas (card_payment_locked
     * — o usuário editou is_ignored à mão) que hoje valem como pagamento
     * (is_ignored false): entram como referência fixa para
     * App\Domain\Banking\Support\CardPaymentMatcher reconhecer a vaga já
     * ocupada por elas (nunca são alteradas — ver o filtro em handle()).
     * Uma travada ignorada (o usuário decidiu que não é pagamento) não
     * participa de nada, nem como referência. $windowFrom, quando
     * informado, restringe à janela recém-sincronizada — fora dela, nada é
     * reconsiderado nem usado como referência.
     *
     * @return Collection<int, Transaction>
     */
    private function pool(Account $card, ?CarbonImmutable $windowFrom): Collection
    {
        return Transaction::query()
            ->where('account_id', $card->id)
            ->where('direction', Direction::In->value)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q2) => $q2->where('card_payment_locked', false)
                    ->where(fn (Builder $q3) => $q3->where('is_ignored', false)->orWhere('ignored_reason', self::DUPLICATE_REASON)))
                ->orWhere(fn (Builder $q2) => $q2->where('card_payment_locked', true)->where('is_ignored', false)))
            ->when($windowFrom !== null, fn (Builder $q) => $q->where('date', '>=', $windowFrom->toDateString()))
            ->get()
            ->keyBy('id');
    }

    /**
     * Nunca chega aqui com uma perna de transferência ou uma travada: as
     * duas são sempre âncoras em App\Domain\Banking\Support\CardPaymentMatcher
     * (nunca "isDuplicate"), então este método só trata créditos comuns
     * vindos do banco.
     *
     * @return bool true quando algo de fato mudou
     */
    private function markDuplicate(Transaction $transaction): bool
    {
        if ($transaction->is_ignored && $transaction->ignored_reason === self::DUPLICATE_REASON && $transaction->card_payment_statement_id === null) {
            return false;
        }

        $transaction->update([
            'is_ignored' => true,
            'ignored_reason' => self::DUPLICATE_REASON,
            'card_payment_statement_id' => null,
        ]);

        return true;
    }

    /**
     * Perna de transferência: por padrão nunca recalcula nem move
     * statement_id (isso é decisão de App\Domain\Cards\Actions\AssignStatement,
     * chamada na criação/edição da transferência) — só espelha o marcador
     * para o valor que já está lá. A única exceção é quando uma fatura do
     * banco casa com este crédito (billExternalId) e ele não veio de
     * App\Domain\Cards\Actions\PayStatement (sempre source=manual): um
     * crédito vindo do banco (source=pluggy) que só se tornou transferência
     * por DetectTransfers, uma sugestão aceita ou "juntar à mão" pode ter
     * ganhado sua fatura por StatementResolver::forPayment(), uma
     * estimativa pela data — a fatura que o próprio banco relatou é mais
     * confiável e corrige isso, movendo pela via normal (AssignStatement).
     *
     * Crédito sem transferência: usa a fatura do banco quando há uma nesta
     * passagem; sem isso, mantém a fatura já escolhida numa reconciliação
     * anterior (nenhum dado novo do banco para justificar mudar); só na
     * primeira vez que este crédito é reconhecido é que cai para
     * StatementResolver::forPayment(). Trava em `statement_locked` (o
     * usuário escolheu a fatura à mão, ver
     * App\Domain\Transactions\Actions\UpdateTransaction): a fatura nunca
     * muda, nem para a do banco — statementFor() cai direto para
     * CardStatement::query()->find($transaction->statement_id), ignorando
     * $bankStatement.
     *
     * @return bool true quando algo de fato mudou
     */
    private function markChosen(Account $card, Transaction $transaction, ?string $billExternalId): bool
    {
        if ($transaction->isTransferLeg()) {
            return $this->markChosenTransferLeg($card, $transaction, $billExternalId);
        }

        $statement = $this->statementFor($card, $transaction, $billExternalId);

        $needsUpdate = $transaction->is_ignored
            || $transaction->ignored_reason !== null
            || $transaction->statement_id !== $statement->id
            || $transaction->card_payment_statement_id !== $statement->id;

        if (! $needsUpdate) {
            return false;
        }

        $transaction->update([
            'is_ignored' => false,
            'ignored_reason' => null,
            'statement_id' => $statement->id,
            'card_payment_statement_id' => $statement->id,
        ]);

        return true;
    }

    /**
     * Fatura a quitar por este crédito (não perna de transferência): nunca
     * chamada com statement_locked, ou chamada e a fatura já escolhida pelo
     * usuário é respeitada acima de qualquer coisa — nem a fatura do banco
     * move statement_id longe dela (o CHECK do banco exige
     * card_payment_statement_id = statement_id, então "reconhecer sem
     * mover" só é possível alinhando os dois na fatura já travada).
     */
    private function statementFor(Account $card, Transaction $transaction, ?string $billExternalId): CardStatement
    {
        if ($transaction->statement_locked) {
            return CardStatement::query()->find($transaction->statement_id) ?? $this->resolver->forPayment($card, $transaction->date);
        }

        $bankStatement = $this->bankStatementFor($card, $billExternalId);
        $alreadyRecognized = $transaction->card_payment_statement_id !== null;

        return match (true) {
            $bankStatement !== null => $bankStatement,
            $alreadyRecognized => CardStatement::query()->find($transaction->statement_id) ?? $this->resolver->forPayment($card, $transaction->date),
            default => $this->resolver->forPayment($card, $transaction->date),
        };
    }

    /**
     * Crédito já em perna de transferência: nunca recalcula statement_id por
     * conta própria — só espelha o marcador, ou, quando uma fatura do banco
     * casa com este crédito e ele não veio de App\Domain\Cards\Actions\PayStatement
     * (sinal: source pluggy — um crédito criado por PayStatement é sempre
     * source manual, com a fatura já escolhida de propósito) nem está
     * `statement_locked` (o usuário escolheu a fatura à mão — nem a fatura
     * do banco a move), move para a fatura do banco através de
     * AssignStatement — a mesma via que qualquer outra mudança de fatura
     * usa, para statement_id e card_payment_statement_id nunca saírem de
     * sincronia.
     */
    private function markChosenTransferLeg(Account $card, Transaction $transaction, ?string $billExternalId): bool
    {
        $bankStatement = $this->bankStatementFor($card, $billExternalId);

        if ($bankStatement !== null
            && $transaction->source === TransactionSource::Pluggy
            && ! $transaction->statement_locked
            && $bankStatement->id !== $transaction->statement_id) {
            $this->assignStatement->handle($transaction, $bankStatement->id);
            $transaction->save();

            return true;
        }

        if (! $transaction->is_ignored && $transaction->ignored_reason === null && $transaction->card_payment_statement_id === $transaction->statement_id) {
            return false;
        }

        $transaction->update([
            'is_ignored' => false,
            'ignored_reason' => null,
            'card_payment_statement_id' => $transaction->statement_id,
        ]);

        return true;
    }

    private function bankStatementFor(Account $card, ?string $billExternalId): ?CardStatement
    {
        if ($billExternalId === null) {
            return null;
        }

        return CardStatement::query()->where('account_id', $card->id)->where('external_id', $billExternalId)->first();
    }

    /**
     * Uma transação do pool que não foi reconhecida nesta passagem: se ela
     * tinha marca de pagamento ou estava ignorada por esta mesma rotina,
     * volta a ser um lançamento comum (fatura pela data, como qualquer
     * entrada sem pagamento reconhecido) — nunca por outro motivo de
     * is_ignored, que não é nosso para desfazer. `statement_locked` é mais
     * restrita, igual a markChosen()/statementFor(): só o marcador de
     * pagamento (e o ignorado de duplicata) voltam ao neutro —
     * `statement_id` nunca é recalculado por forDate(), a fatura que o
     * usuário escolheu à mão não é nossa para mudar mesmo quando o crédito
     * deixa de ser reconhecido como pagamento.
     */
    private function resetUnrecognized(Account $card, Transaction $transaction): void
    {
        $wasDuplicateIgnore = $transaction->ignored_reason === self::DUPLICATE_REASON;

        if ($transaction->card_payment_statement_id === null && ! $wasDuplicateIgnore) {
            return;
        }

        $attributes = [
            'is_ignored' => $wasDuplicateIgnore ? false : $transaction->is_ignored,
            'ignored_reason' => $wasDuplicateIgnore ? null : $transaction->ignored_reason,
            'card_payment_statement_id' => null,
        ];

        if (! $transaction->statement_locked) {
            $attributes['statement_id'] = $this->resolver->forDate($card, $transaction->date)->id;
        }

        $transaction->update($attributes);
    }

    /**
     * Procura, numa conta não-cartão do usuário, a saída que corresponde a
     * este pagamento: mesmo valor e moeda, data a ±3 dias, lançada (não
     * pendente/projetada/ignorada/parcela), ainda sem transferência, e sem
     * um par já descartado pelo usuário (App\Domain\Transfers\Queries\TransferCandidates::dismissedPairs).
     * Liga de verdade só quando há exatamente uma candidata; com mais de
     * uma, grava uma sugestão pendente para cada uma em vez de arriscar —
     * mesma estrutura de App\Domain\Transfers\Actions\DetectTransfers. Sem
     * nenhuma (pagou com conta de outro banco, ou a conta não é
     * sincronizada), o pagamento continua valendo como pagamento mesmo assim.
     *
     * @return int 1 quando ligou de verdade, 0 nos outros casos
     */
    private function tryLinkTransfer(Transaction $in): int
    {
        $candidates = Transaction::query()
            ->whereHas('account', fn (Builder $q) => $q->where('type', '!=', AccountType::CreditCard->value))
            ->where('direction', Direction::Out->value)
            ->where('currency', $in->currency)
            ->where('amount', $in->amount->cents)
            ->where('status', TransactionStatus::Posted->value)
            ->where('is_ignored', false)
            ->whereNull('transfer_id')
            ->whereNull('installment_plan_id')
            ->whereBetween('date', [
                $in->date->subDays(self::TRANSFER_LINK_MAX_DAYS)->toDateString(),
                $in->date->addDays(self::TRANSFER_LINK_MAX_DAYS)->toDateString(),
            ])
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $dismissed = TransferCandidates::dismissedPairs([$in->id, ...$candidates->pluck('id')->all()]);

        $eligible = $candidates->reject(fn (Transaction $out) => isset($dismissed["{$out->id}:{$in->id}"]));

        if ($eligible->isEmpty()) {
            return 0;
        }

        if ($eligible->count() > 1) {
            $this->suggestPending($eligible, $in);

            return 0;
        }

        try {
            $this->linkTransfer->handle($eligible->first(), $in, self::TRANSFER_LINK_MAX_DAYS);
        } catch (TransferLinkInvalid|ModelNotFoundException) {
            return 0;
        }

        return 1;
    }

    /**
     * @param  Collection<int, Transaction>  $outs
     */
    private function suggestPending(Collection $outs, Transaction $in): void
    {
        $now = CarbonImmutable::now();

        $rows = $outs->map(fn (Transaction $out) => [
            'user_id' => $in->user_id,
            'out_transaction_id' => $out->id,
            'in_transaction_id' => $in->id,
            // Não vem de App\Domain\Transfers\Support\TransferMatcher (esta
            // sugestão nasce de um pagamento já reconhecido, não de
            // pontuação por pista de descrição) — pontuação neutra, só para
            // ordenar a lista de sugestões.
            'score' => 0.5,
            'status' => TransferSuggestionStatus::Pending->value,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        TransferSuggestion::query()->insertOrIgnore($rows);
    }
}
