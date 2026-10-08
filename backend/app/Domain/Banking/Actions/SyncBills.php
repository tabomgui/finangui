<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderBillPayment;
use App\Domain\Banking\Support\CardPaymentMatcher;
use App\Domain\Cards\Errors\NotACreditCard;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Support\InvoiceCycle;
use App\Domain\Cards\Support\StatementOrdering;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Segundo passo do sync periódico de um cartão (App\Domain\Banking\Jobs\SyncConnection):
 * upsert de cada fatura informada pelo banco (só Open Finance) por
 * `external_id`. Uma fatura local sem `external_id`, cujo vencimento fique a
 * até 7 dias do informado pelo banco (a mais próxima) ou cujo fechamento
 * calculado seja exatamente igual, é adotada (ganha o id) em vez de criar
 * uma duplicata — nunca adota uma fatura que já tenha um `external_id`
 * diferente (log e segue sem adotar nada, nunca sobrescrevendo o vínculo de
 * outra fatura do banco). Datas novas só são aplicadas (numa fatura já
 * existente) ou a fatura só é criada do zero quando mantêm a ordem com as
 * vizinhas e o vão de até 40 dias entre fechamento e vencimento — a mesma
 * validação de App\Domain\Cards\Actions\UpdateStatement, extraída para
 * App\Domain\Cards\Support\StatementOrdering. Quando não cabem: numa fatura
 * já existente, ela fica com as datas antigas (só `external_id`/`reported_total`/
 * `reported_paid` são gravados); numa fatura nova, adota a fatura local mais
 * próxima em vez de inserir fora de ordem, ou, sem nenhuma candidata, ignora
 * com log — nunca insere uma fatura que quebre a ordenação do cartão.
 *
 * `billClosingDate` ausente (comum em faturas antigas, de antes do cartão ter
 * os dias atuais) é diferente de "não cabe na ordem": numa fatura já
 * existente, o fechamento gravado NUNCA muda nesse caso (só o vencimento,
 * quando ainda cabe — ver applyDueDateOnly()), mesmo que closing_day/due_day
 * do cartão já tenham mudado desde que ela foi gravada — sem isso, um sync
 * seguinte recalcularia o fechamento com os dias de HOJE e reescreveria uma
 * fatura antiga já fechada, violando "nunca reescreve faturas já gravadas".
 * Numa fatura nova, sem closing_date gravado para proteger, o calculado a
 * partir dos dias atuais do cartão é o único palpite disponível mesmo.
 *
 * reported_paid grava a soma de payments[] da fatura do banco (null sem
 * nenhum) — ver App\Domain\Cards\Models\CardStatement::paid().
 *
 * Por fim, a fatura mais recente deste lote (maior fechamento) define
 * closing_day/due_day do cartão para os próximos ciclos ainda não criados
 * (ver syncCardDays()) — nunca reescreve uma fatura já gravada.
 */
final class SyncBills
{
    private const ADOPTION_WINDOW_DAYS = 7;

    private const MAX_FALLBACK_DISTANCE_DAYS = 45;

    /**
     * @param  list<ProviderBill>  $bills
     * @return int quantas faturas foram de fato gravadas (criadas ou atualizadas) — para App\Domain\Banking\Jobs\SyncConnection alimentar `bills_count` do histórico de sincronização (App\Domain\Banking\Models\BankSyncRun); uma fatura ignorada por não caber na ordem local (ver createOrAdopt()) não conta
     *
     * @throws NotACreditCard
     */
    public function handle(Account $card, array $bills): int
    {
        if (! $card->isCreditCard() || $card->closing_day === null || $card->due_day === null) {
            throw new NotACreditCard;
        }

        // Atribui cada id de pagamento do banco à fatura preferida ANTES de
        // gravar qualquer uma delas (mesma regra de App\Domain\Banking\Support\CardPaymentMatcher::preferredBillForPayment()):
        // sem isso, um `payments[].id` repetido em mais de uma fatura deste
        // lote (dado real da Pluggy) somaria o mesmo pagamento em
        // reported_paid de duas faturas diferentes.
        $preferredBillByPaymentId = CardPaymentMatcher::preferredBillForPayment($bills);

        $applied = 0;

        foreach ($bills as $bill) {
            if ($this->applyBill($card, $bill, $preferredBillByPaymentId)) {
                $applied++;
            }
        }

        $this->syncCardDays($card, $bills);

        return $applied;
    }

    /**
     * As até 3 faturas mais recentes informadas pelo banco (maior fechamento,
     * calculado como em applyBill()) definem os dias usados para os ciclos
     * futuros do cartão (closing_day/due_day) — nunca reescreve faturas já
     * gravadas, só o dia usado por App\Domain\Cards\Support\StatementResolver/InvoiceCycle
     * para resolver a próxima fatura ainda não criada. Usa closing_day/due_day
     * atuais (lidos antes de qualquer atualização aqui) para calcular o
     * fechamento de uma fatura sem billClosingDate, igual a applyBill().
     *
     * O dia usado é o mais comum entre essas faturas (ver mostCommonDay()),
     * não só o da mais recente: um fechamento/vencimento que cai num fim de
     * semana ou feriado às vezes antecipa ou atrasa um dia só naquele ciclo
     * — usar a maioria recente evita o cartão "piscar" o dia por causa de um
     * desvio pontual desses.
     *
     * @param  list<ProviderBill>  $bills
     */
    private function syncCardDays(Account $card, array $bills): void
    {
        if ($bills === []) {
            return;
        }

        /** @var list<array{closing: CarbonImmutable, due: CarbonImmutable}> $cycles */
        $cycles = array_map(function (ProviderBill $bill) use ($card): array {
            $due = CarbonImmutable::parse($bill->dueDate)->startOfDay();
            $closing = $bill->closingDate !== null
                ? CarbonImmutable::parse($bill->closingDate)->startOfDay()
                : InvoiceCycle::closingForDueDate($due, (int) $card->closing_day, (int) $card->due_day);

            return ['closing' => $closing, 'due' => $due];
        }, $bills);

        usort($cycles, fn (array $a, array $b): int => $b['closing'] <=> $a['closing']);
        $recent = array_slice($cycles, 0, 3);

        $newClosingDay = $this->mostCommonDay($recent, 'closing', (int) $card->closing_day);
        $newDueDay = $this->mostCommonDay($recent, 'due', (int) $card->due_day);

        if ($newClosingDay !== (int) $card->closing_day || $newDueDay !== (int) $card->due_day) {
            $card->update(['closing_day' => $newClosingDay, 'due_day' => $newDueDay]);
        }
    }

    /**
     * Dia mais comum entre as faturas recentes para 'closing' ou 'due';
     * empate decidido pela ocorrência mais recente (a lista já vem ordenada
     * da mais nova para a mais antiga). Ignora uma data que cai exatamente
     * no último dia de um mês curto quando o dia atual do cartão é maior —
     * isso é só o mês não ter esse dia (ex.: fechamento nominal 31, fatura de
     * fevereiro fecha em 28), não uma mudança real do dia de fechamento.
     *
     * @param  list<array{closing: CarbonImmutable, due: CarbonImmutable}>  $recent
     */
    private function mostCommonDay(array $recent, string $key, int $currentDay): int
    {
        /** @var array<int, int> $counts */
        $counts = [];
        /** @var array<int, int> $firstIndex */
        $firstIndex = [];

        foreach ($recent as $index => $cycle) {
            $date = $cycle[$key];

            if ($date->day === $date->daysInMonth && $currentDay > $date->day) {
                continue;
            }

            $counts[$date->day] = ($counts[$date->day] ?? 0) + 1;
            $firstIndex[$date->day] ??= $index;
        }

        if ($counts === []) {
            return $currentDay;
        }

        $bestDay = $currentDay;
        $bestCount = -1;
        $bestIndex = PHP_INT_MAX;

        foreach ($counts as $day => $count) {
            $index = $firstIndex[$day];

            if ($count > $bestCount || ($count === $bestCount && $index < $bestIndex)) {
                $bestDay = $day;
                $bestCount = $count;
                $bestIndex = $index;
            }
        }

        return $bestDay;
    }

    /**
     * @param  array<string, string>  $preferredBillByPaymentId  ver CardPaymentMatcher::preferredBillForPayment()
     */
    private function applyBill(Account $card, ProviderBill $bill, array $preferredBillByPaymentId): bool
    {
        $due = CarbonImmutable::parse($bill->dueDate)->startOfDay();
        $closing = $bill->closingDate !== null
            ? CarbonImmutable::parse($bill->closingDate)->startOfDay()
            : InvoiceCycle::closingForDueDate($due, (int) $card->closing_day, (int) $card->due_day);

        $statement = CardStatement::query()
            ->where('account_id', $card->id)
            ->where('external_id', $bill->id)
            ->first();

        $statement ??= $this->findAdoptionCandidate($card, $due, $closing, $bill->id);

        if ($statement === null) {
            return $this->createOrAdopt($card, $bill, $due, $closing, $preferredBillByPaymentId);
        }

        $this->applyToExisting($statement, $bill, $due, $closing, $preferredBillByPaymentId);

        return true;
    }

    /**
     * @param  array<string, string>  $preferredBillByPaymentId
     */
    private function createOrAdopt(Account $card, ProviderBill $bill, CarbonImmutable $due, CarbonImmutable $closing, array $preferredBillByPaymentId): bool
    {
        ['previous' => $previous, 'next' => $next] = StatementOrdering::neighborsForAccount($card->id, $closing);

        if (StatementOrdering::fits($previous, $next, $closing, $due)) {
            CardStatement::create([
                'user_id' => $card->user_id,
                'account_id' => $card->id,
                'closing_date' => $closing,
                'due_date' => $due,
                'external_id' => $bill->id,
                'reported_total' => $bill->totalCents,
                'reported_paid' => self::reportedPaidCents($bill, $preferredBillByPaymentId),
            ]);

            return true;
        }

        // Não cabe na ordem — uma fatura local vizinha está no caminho (ex.:
        // fechamento do banco adiantado em relação ao nosso). findAdoptionCandidate()
        // já não achou nada dentro da janela de 7 dias (senão nem teria
        // chegado até aqui — applyBill() já teria adotado antes de tentar
        // criar); aqui a busca é sem janela, porque sabemos que há uma
        // vizinha no caminho e perder os dados da fatura é pior do que
        // adotar uma mais distante. Sem nenhuma candidata (todas as
        // vizinhas já pertencem a outra fatura do banco), ignora com log.
        $nearest = $this->nearestAdoptable($card, $due, $bill->id);

        if ($nearest !== null) {
            $this->applyToExisting($nearest, $bill, $nearest->due_date, $nearest->closing_date, $preferredBillByPaymentId);

            return true;
        }

        Log::warning('Pluggy: fatura nova do banco não cabe na ordem das faturas locais e nenhuma fatura próxima pôde ser adotada; ignorando.', [
            'account_id' => $card->id,
            'bill_id' => $bill->id,
            'due_date' => $due->toDateString(),
            'closing_date' => $closing->toDateString(),
        ]);

        return false;
    }

    /**
     * @param  array<string, string>  $preferredBillByPaymentId
     */
    private function applyToExisting(CardStatement $statement, ProviderBill $bill, CarbonImmutable $due, CarbonImmutable $closing, array $preferredBillByPaymentId): void
    {
        if ($bill->closingDate === null) {
            // Banco não informa o fechamento desta fatura (comum em faturas
            // antigas, de antes do cartão ter os dias atuais): $closing acima
            // é só um palpite calculado com closing_day/due_day de HOJE, que
            // pode já ter mudado desde que esta fatura foi gravada — nunca
            // usado para reescrever o fechamento já gravado (violaria "nunca
            // reescreve faturas já gravadas", ver a classe). Só o vencimento
            // pode mudar, e só quando ainda cabe com o fechamento atual (sem
            // mudar) e as vizinhas.
            $this->applyDueDateOnly($statement, $due);
        } else {
            $datesChanged = ! $statement->due_date->equalTo($due) || ! $statement->closing_date->equalTo($closing);

            if ($datesChanged) {
                ['previous' => $previous, 'next' => $next] = StatementOrdering::neighbors($statement);

                if (StatementOrdering::fits($previous, $next, $closing, $due)) {
                    $statement->closing_date = $closing;
                    $statement->due_date = $due;
                }
            }
        }

        $statement->fill([
            'external_id' => $bill->id,
            'reported_total' => $bill->totalCents,
            'reported_paid' => self::reportedPaidCents($bill, $preferredBillByPaymentId),
        ]);
        $statement->save();
    }

    /**
     * Vencimento de uma fatura já existente cujo banco não informa o
     * fechamento: só muda quando ainda fica depois do fechamento já gravado
     * (que nunca muda aqui) e continua cabendo entre as vizinhas e dentro da
     * janela de 40 dias — mesmas regras de App\Domain\Cards\Support\StatementOrdering::fits(),
     * sem a parte de closingFitsNeighbors() (o fechamento não está mudando).
     */
    private function applyDueDateOnly(CardStatement $statement, CarbonImmutable $due): void
    {
        if ($statement->due_date->equalTo($due)) {
            return;
        }

        ['previous' => $previous, 'next' => $next] = StatementOrdering::neighbors($statement);

        if (StatementOrdering::dueAfterClosing($statement->closing_date, $due)
            && StatementOrdering::dueFitsNeighbors($previous, $next, $due)
            && StatementOrdering::withinMaxSpan($statement->closing_date, $due)) {
            $statement->due_date = $due;
        }
    }

    /**
     * Soma de payments[] da fatura do banco, em centavos — só os pagamentos
     * cuja fatura preferida (ver CardPaymentMatcher::preferredBillForPayment(),
     * calculado uma vez para todo o lote em handle()) é ESTA fatura: um
     * `payments[].id` repetido em mais de uma fatura deste lote (dado real
     * da Pluggy — o mesmo pagamento aparece na fatura que ele quita e na
     * seguinte) nunca pode contar duas vezes. Um pagamento sem entrada no
     * mapa (id/data inválidos, descartados por CardPaymentMatcher) conta
     * normalmente nesta fatura — mesmo comportamento de antes para esse caso
     * raro. Null sem nenhum pagamento reconhecido para esta fatura (não
     * zero: "o banco não disse nada" é diferente de "o banco disse que não
     * há pagamento nenhum", embora hoje os dois se comportem igual em
     * CardStatement::paid()). Gravado em reported_paid.
     *
     * @param  array<string, string>  $preferredBillByPaymentId
     */
    private static function reportedPaidCents(ProviderBill $bill, array $preferredBillByPaymentId): ?int
    {
        $claimed = array_filter(
            $bill->payments,
            fn (ProviderBillPayment $payment) => ($preferredBillByPaymentId[$payment->id] ?? $bill->id) === $bill->id,
        );

        if ($claimed === []) {
            return null;
        }

        return (int) array_sum(array_map(fn (ProviderBillPayment $payment) => $payment->amountCents, $claimed));
    }

    /**
     * Fatura local candidata a adoção: sem external_id, com vencimento a
     * até ADOPTION_WINDOW_DAYS do informado pelo banco (a mais próxima) ou
     * cujo fechamento seja exatamente o calculado. Uma fatura próxima que já
     * tem um external_id diferente nunca é adotada — só loga o conflito e
     * segue como se não tivesse achado nada (nunca lança, nunca sobrescreve
     * o vínculo de outra fatura do banco).
     */
    private function findAdoptionCandidate(Account $card, CarbonImmutable $due, CarbonImmutable $closing, string $billId): ?CardStatement
    {
        $nearby = CardStatement::query()
            ->where('account_id', $card->id)
            ->where(fn ($q) => $q
                ->whereBetween('due_date', [$due->subDays(self::ADOPTION_WINDOW_DAYS)->toDateString(), $due->addDays(self::ADOPTION_WINDOW_DAYS)->toDateString()])
                ->orWhere('closing_date', $closing->toDateString()))
            ->get();

        if ($nearby->isEmpty()) {
            return null;
        }

        /** @var Collection<int, CardStatement> $adoptable */
        $adoptable = $nearby->whereNull('external_id');

        if ($adoptable->isEmpty()) {
            Log::warning('Pluggy: fatura(s) local(is) próxima(s) deste vencimento já pertencem a outra fatura do banco; ignorando a adoção.', [
                'account_id' => $card->id,
                'bill_id' => $billId,
                'due_date' => $due->toDateString(),
            ]);

            return null;
        }

        return $adoptable->sortBy(fn (CardStatement $s) => abs($s->due_date->diffInDays($due, false)))->first();
    }

    /**
     * Última tentativa do caminho de criação (createOrAdopt()), quando a
     * fatura nova não cabe na ordem: a vizinha mais próxima do cartão (sem
     * a janela de ADOPTION_WINDOW_DAYS — já sabemos que há uma vizinha no
     * caminho; é ela ou uma perto dela que está causando o conflito), que
     * ainda não pertence a outra fatura do banco — contanto que a distância
     * até o vencimento informado não passe de MAX_FALLBACK_DISTANCE_DAYS.
     * Mais do que isso não é mais "a vizinha do conflito", é só a fatura
     * mais próxima que existe: melhor ignorar com log do que adotar algo
     * longe demais do que o banco informou.
     */
    private function nearestAdoptable(Account $card, CarbonImmutable $due, string $billId): ?CardStatement
    {
        /** @var Collection<int, CardStatement> $adoptable */
        $adoptable = CardStatement::query()
            ->where('account_id', $card->id)
            ->whereNull('external_id')
            ->get();

        if ($adoptable->isEmpty()) {
            Log::warning('Pluggy: nenhuma fatura local disponível para adotar; ignorando a fatura nova do banco.', [
                'account_id' => $card->id,
                'bill_id' => $billId,
            ]);

            return null;
        }

        $nearest = $adoptable->sortBy(fn (CardStatement $s) => abs($s->due_date->diffInDays($due, false)))->first();

        if (abs($nearest->due_date->diffInDays($due, false)) > self::MAX_FALLBACK_DISTANCE_DAYS) {
            Log::warning('Pluggy: a fatura local mais próxima está longe demais do vencimento informado pelo banco; ignorando a fatura nova.', [
                'account_id' => $card->id,
                'bill_id' => $billId,
                'nearest_statement_id' => $nearest->id,
            ]);

            return null;
        }

        return $nearest;
    }
}
