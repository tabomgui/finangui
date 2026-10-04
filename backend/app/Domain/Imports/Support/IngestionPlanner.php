<?php

namespace App\Domain\Imports\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Enums\RowOutcome;
use App\Domain\Recurrences\Support\RecurrenceMatcher;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Decide o destino de cada linha sem gravar nada: duplicada/atualizada por
 * external_id, substituição de parcela projetada ou já lançada (no banco ou
 * só dentro do próprio arquivo, ver ImportedInstallments), adoção de
 * lançamento manual (ou de pagamento de fatura) e troca de id de uma
 * pendente — na ordem em que a primeira regra que casar vence (ver
 * DedupMatchers para as comparações candidata-a-candidata). Usado tanto
 * pela prévia quanto pela confirmação, para garantir que as duas vejam os
 * mesmos números: as duas usam o mesmo planner, e a confirmação roda de
 * novo, sob a trava da conta, porque o estado pode ter mudado entre a
 * prévia e a confirmação. Consulta só a conta de destino e janelas de
 * datas em volta das linhas do arquivo.
 */
final class IngestionPlanner
{
    public function __construct(
        private readonly DedupMatchers $matchers = new DedupMatchers,
        private readonly RecurrenceMatcher $recurrenceMatcher = new RecurrenceMatcher,
    ) {}

    /**
     * @param  list<ParsedRow>  $rows
     * @param  ImportFormat|null  $format  formato do lote (ver Actions/IngestTransactions e Queries/ImportPreview); format Pluggy amplia a adoção (ver adoptionPool())
     * @return list<RowDecision>
     */
    public function plan(Account $account, array $rows, ?ImportFormat $format = null): array
    {
        if ($rows === []) {
            return [];
        }

        $candidates = $this->loadCandidates($account, $rows);
        $externalIds = array_values(array_unique(array_map(fn (ParsedRow $row) => $row->externalId, $rows)));
        $externalIdsInFile = array_fill_keys($externalIds, true);

        $byExternalId = $candidates->whereNotNull('external_id')->keyBy('external_id');
        $installmentPool = $candidates->whereNull('external_id')->whereNotNull('installment_plan_id')->values();
        $adoptionPool = $this->adoptionPool($candidates, $format);
        $recurrencePool = $this->recurrencePool($candidates);
        $swapPool = $candidates->filter(
            fn (Transaction $t) => $t->external_id !== null && $this->isStillOpen($t)
        )->values();

        /** @var array<int, RowDecision> $decisions */
        $decisions = [];
        $usedIds = [];
        $seenExternalIds = [];
        // Linhas de parcela nova (nenhum casamento no banco): só decididas
        // depois, juntas, em ImportedInstallments::classify() — precisa ver
        // o arquivo inteiro para saber qual linha semeia cada compra.
        $pendingInstallmentIndexes = [];

        foreach ($rows as $index => $row) {
            if (isset($seenExternalIds[$row->externalId])) {
                $decisions[$index] = new RowDecision($row, RowOutcome::Duplicate);

                continue;
            }
            $seenExternalIds[$row->externalId] = true;

            $existing = $byExternalId->get($row->externalId);

            if ($existing !== null) {
                $usedIds[$existing->id] = true;
                $decisions[$index] = $this->isStillOpen($existing) && ! $row->pending
                    ? new RowDecision($row, RowOutcome::Update, $existing->id, $existing)
                    : new RowDecision($row, RowOutcome::Duplicate, $existing->id, $existing);

                continue;
            }

            if (ImportedInstallments::isInstallmentRow($account, $row)) {
                $match = $this->matchers->matchInstallment($installmentPool, $usedIds, $row);

                if ($match !== null) {
                    $usedIds[$match->id] = true;
                    $decisions[$index] = new RowDecision($row, RowOutcome::ReplaceInstallment, $match->id, $match);

                    continue;
                }
            }

            if ($account->isCreditCard() && $row->direction === Direction::In) {
                $paid = $this->matchers->matchCardPayment($adoptionPool, $usedIds, $row);

                if ($paid !== null) {
                    $usedIds[$paid->id] = true;
                    $decisions[$index] = new RowDecision($row, RowOutcome::Adopt, $paid->id, $paid);

                    continue;
                }
            }

            $recurrenceMatch = $this->recurrenceMatcher->bestMatch($recurrencePool, $usedIds, $row->amount, $row->direction, $row->date, $row->description);

            if ($recurrenceMatch !== null) {
                $usedIds[$recurrenceMatch->id] = true;
                $decisions[$index] = new RowDecision($row, RowOutcome::Adopt, $recurrenceMatch->id, $recurrenceMatch, matchedByRecurrence: true);

                continue;
            }

            $adopted = $this->matchers->matchAdoption($adoptionPool, $usedIds, $row);

            if ($adopted !== null) {
                $usedIds[$adopted->id] = true;
                $decisions[$index] = new RowDecision($row, RowOutcome::Adopt, $adopted->id, $adopted);

                continue;
            }

            $swapped = $this->matchers->matchSwap($swapPool, $usedIds, $externalIdsInFile, $row);

            if ($swapped !== null) {
                $usedIds[$swapped->id] = true;
                $decisions[$index] = new RowDecision($row, RowOutcome::SwapPending, $swapped->id, $swapped);

                continue;
            }

            if (ImportedInstallments::isInstallmentRow($account, $row)) {
                $pendingInstallmentIndexes[] = $index;

                continue;
            }

            $decisions[$index] = new RowDecision($row, RowOutcome::New);
        }

        foreach (ImportedInstallments::classify($pendingInstallmentIndexes, $rows) as $index => $seedIndex) {
            $decisions[$index] = $index === $seedIndex
                ? new RowDecision($rows[$index], RowOutcome::New)
                : new RowDecision($rows[$index], RowOutcome::ReplaceInstallment, seedIndex: $seedIndex);
        }

        ksort($decisions);

        return array_values($decisions);
    }

    /**
     * Parcelas não são lançamentos manuais livres: têm seu próprio
     * casamento (replace_installment) e nunca entram aqui, mesmo sem
     * external_id. Uma prevista de recorrência também nunca entra aqui:
     * RecurrenceMatcher (tentado antes, ver recurrencePool()) é o único
     * caminho até ela — senão a tolerância de descrição genérica daqui
     * (sem olhar match_pattern) poderia confirmá-la por trás da regra mais
     * estrita que o usuário configurou. Sem external_id, qualquer outro
     * lançamento é candidato. Com external_id, só entra quando o lote é da
     * sincronização bancária (Pluggy) e o lançamento já existente não veio
     * do próprio banco — um lançamento manual, de CSV ou de OFX ainda não
     * confirmado, com o seu próprio id sintético, que o banco agora está
     * relatando com um id dele: mesmos limiares de valor/data/descrição de
     * matchAdoption() (e matchCardPayment(), que usa o mesmo pool) decidem
     * se de fato bate.
     *
     * @param  Collection<int, Transaction>  $candidates
     * @return Collection<int, Transaction>
     */
    private function adoptionPool(Collection $candidates, ?ImportFormat $format): Collection
    {
        return $candidates->filter(function (Transaction $t) use ($format) {
            if ($t->installment_plan_id !== null || $t->isUnconfirmedOccurrence()) {
                return false;
            }

            if ($t->external_id === null) {
                return true;
            }

            return $format === ImportFormat::Pluggy && $t->source !== TransactionSource::Pluggy;
        })->values();
    }

    /**
     * Previstas de recorrência ainda não confirmadas: candidatas ao
     * casamento de RecurrenceMatcher::bestMatch(), tentado antes da adoção
     * de manuais comuns (ver matchAdoption() acima).
     *
     * @param  Collection<int, Transaction>  $candidates
     * @return Collection<int, Transaction>
     */
    private function recurrencePool(Collection $candidates): Collection
    {
        return $candidates->filter(fn (Transaction $t) => $t->isUnconfirmedOccurrence())->values();
    }

    /**
     * Ainda pode virar `posted` de verdade: pendente (de qualquer fonte) ou
     * projetada por data futura que não é parcela (`installment_plan_id`
     * nulo) — uma parcela projetada tem seu próprio casamento
     * (replace_installment) e nunca deve trocar de external_id ou virar
     * "atualizada" por aqui.
     */
    private function isStillOpen(Transaction $t): bool
    {
        return $t->status === TransactionStatus::Pending
            || ($t->status === TransactionStatus::Projected && $t->installment_plan_id === null);
    }

    /**
     * Todo candidato possível a alguma das cascatas: pela janela de datas
     * ou pelo external_id exato (pode estar fora da janela), e parcelas de
     * plano sem external_id numa janela mais larga (35 dias, ou perto da
     * data de compra do plano). installmentPlan/tags vêm sempre
     * carregados, sem N+1: RowDecision leva o model junto da decisão (ver
     * DedupMatchers e os `new RowDecision(..., $existing)` acima), então
     * quem consumir a decisão mais tarde não dispara outra consulta ao
     * tocar numa relação já carregada aqui.
     *
     * @param  list<ParsedRow>  $rows
     * @return Collection<int, Transaction>
     */
    private function loadCandidates(Account $account, array $rows): Collection
    {
        $dates = array_map(fn (ParsedRow $row) => $row->date, $rows);
        $minDate = CarbonImmutable::parse(min($dates));
        $maxDate = CarbonImmutable::parse(max($dates));
        $externalIds = array_values(array_unique(array_map(fn (ParsedRow $row) => $row->externalId, $rows)));

        $windowed = Transaction::query()
            ->where('account_id', $account->id)
            ->where(function (Builder $query) use ($minDate, $maxDate, $externalIds) {
                $query->whereBetween('date', [$minDate->subDays(7)->toDateString(), $maxDate->addDays(7)->toDateString()])
                    ->orWhereIn('external_id', $externalIds);
            })
            ->get();

        // Parcela de plano do cartão sem external_id, em qualquer status: a
        // parcela 1 de uma compra manual nasce lançada e o job diário lança
        // as demais, então tanto posted quanto projected podem ser a mesma
        // parcela que o banco está relatando agora.
        $installmentCandidates = Transaction::query()
            ->where('account_id', $account->id)
            ->whereNull('external_id')
            ->whereNotNull('installment_plan_id')
            ->where(function (Builder $query) use ($minDate, $maxDate) {
                $query->whereBetween('date', [$minDate->subDays(35)->toDateString(), $maxDate->addDays(35)->toDateString()])
                    ->orWhereHas('installmentPlan', fn (Builder $plan) => $plan->whereBetween(
                        'purchase_date',
                        [$minDate->subDays(5)->toDateString(), $maxDate->addDays(5)->toDateString()],
                    ));
            })
            ->get();

        $candidates = $windowed->concat($installmentCandidates)->unique('id')->values();
        $candidates->load(['installmentPlan', 'tags', 'recurrence']);

        return $candidates;
    }
}
