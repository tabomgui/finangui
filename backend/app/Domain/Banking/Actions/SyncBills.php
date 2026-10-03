<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderBill;
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
 * já existente, ela fica com as datas antigas (só `external_id`/`reported_total`
 * são gravados); numa fatura nova, adota a fatura local mais próxima em vez
 * de inserir fora de ordem, ou, sem nenhuma candidata, ignora com log — nunca
 * insere uma fatura que quebre a ordenação do cartão.
 */
final class SyncBills
{
    private const ADOPTION_WINDOW_DAYS = 7;

    private const MAX_FALLBACK_DISTANCE_DAYS = 45;

    /**
     * @param  list<ProviderBill>  $bills
     *
     * @throws NotACreditCard
     */
    public function handle(Account $card, array $bills): void
    {
        if (! $card->isCreditCard() || $card->closing_day === null || $card->due_day === null) {
            throw new NotACreditCard;
        }

        foreach ($bills as $bill) {
            $this->applyBill($card, $bill);
        }
    }

    private function applyBill(Account $card, ProviderBill $bill): void
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
            $this->createOrAdopt($card, $bill, $due, $closing);

            return;
        }

        $this->applyToExisting($statement, $bill, $due, $closing);
    }

    private function createOrAdopt(Account $card, ProviderBill $bill, CarbonImmutable $due, CarbonImmutable $closing): void
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
            ]);

            return;
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
            $this->applyToExisting($nearest, $bill, $nearest->due_date, $nearest->closing_date);

            return;
        }

        Log::warning('Pluggy: fatura nova do banco não cabe na ordem das faturas locais e nenhuma fatura próxima pôde ser adotada; ignorando.', [
            'account_id' => $card->id,
            'bill_id' => $bill->id,
            'due_date' => $due->toDateString(),
            'closing_date' => $closing->toDateString(),
        ]);
    }

    private function applyToExisting(CardStatement $statement, ProviderBill $bill, CarbonImmutable $due, CarbonImmutable $closing): void
    {
        $datesChanged = ! $statement->due_date->equalTo($due) || ! $statement->closing_date->equalTo($closing);

        if ($datesChanged) {
            ['previous' => $previous, 'next' => $next] = StatementOrdering::neighbors($statement);

            if (StatementOrdering::fits($previous, $next, $closing, $due)) {
                $statement->closing_date = $closing;
                $statement->due_date = $due;
            }
        }

        $statement->fill(['external_id' => $bill->id, 'reported_total' => $bill->totalCents]);
        $statement->save();
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
