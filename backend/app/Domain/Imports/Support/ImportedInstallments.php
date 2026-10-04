<?php

namespace App\Domain\Imports\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\Direction;

/**
 * Regras puras sobre linhas de parcela, compartilhadas pelo IngestionPlanner
 * (prévia e decisão) e por IngestTransactions (execução): o que conta como
 * linha de parcela, e como agrupar as parcelas novas de uma mesma compra
 * dentro de um único arquivo.
 */
final class ImportedInstallments
{
    /**
     * Só conta credit_card e só saída: o banco nunca manda "parcela" numa
     * entrada (estorno, pagamento), e parcelamento só existe em cartão.
     */
    public static function isInstallmentRow(Account $account, ParsedRow $row): bool
    {
        return $row->installment !== null && $account->isCreditCard() && $row->direction === Direction::Out;
    }

    /**
     * Entre as linhas de parcela nova de um arquivo (nenhuma bateu com nada
     * já gravado no banco), decide qual "semeia" cada compra — vira o plano
     * — e qual é absorvida por uma semente já vista: processa em ordem
     * crescente de número da parcela, então a semente de cada compra é
     * sempre a de menor número (quem cria o plano decide a `purchase_date`
     * estimada e tudo que a parcela 1..N-1, se existirem, nunca chegaram a
     * entrar no arquivo). Uma semente só absorve números maiores que ainda
     * não tem; duas linhas com o mesmo número (ex.: duas "Parcela 1/3"
     * iguais) nunca são a mesma compra — a segunda semeia a sua própria.
     *
     * @param  list<int>  $indexes  índices em $rows das linhas candidatas (mesmo índice que RowDecision usa)
     * @param  list<ParsedRow>  $rows
     * @return array<int, int> índice da linha => índice da semente da compra (igual a si mesma quando a própria linha semeia)
     */
    public static function classify(array $indexes, array $rows): array
    {
        if ($indexes === []) {
            return [];
        }

        $sorted = $indexes;
        usort($sorted, fn (int $a, int $b) => $rows[$a]->installment['number'] <=> $rows[$b]->installment['number']);

        /** @var list<array{index: int, total: int, descriptionKey: string, amount: int, claimed: array<int, true>}> $seeds */
        $seeds = [];
        $seedOf = [];

        foreach ($sorted as $index) {
            $row = $rows[$index];
            /** @var array{number: int, total: int} $installment */
            $installment = $row->installment;
            $descriptionKey = TextNormalizer::key($row->description);

            $matchedKey = self::findSeed($seeds, $descriptionKey, $installment, $row->amount);

            if ($matchedKey !== null) {
                $seeds[$matchedKey]['claimed'][$installment['number']] = true;
                $seedOf[$index] = $seeds[$matchedKey]['index'];

                continue;
            }

            $seeds[] = [
                'index' => $index,
                'total' => $installment['total'],
                'descriptionKey' => $descriptionKey,
                'amount' => $row->amount,
                'claimed' => [$installment['number'] => true],
            ];
            $seedOf[$index] = $index;
        }

        return $seedOf;
    }

    /**
     * Mesma tolerância de IngestionPlanner::matchInstallment(): valor com
     * diferença menor que o total de parcelas (o resto da divisão nunca
     * passa disso). Uma semente que já reivindicou este número não serve —
     * essa linha é de outra compra, mesmo com total/descrição iguais.
     *
     * @param  list<array{index: int, total: int, descriptionKey: string, amount: int, claimed: array<int, true>}>  $seeds
     * @param  array{number: int, total: int}  $installment
     */
    private static function findSeed(array $seeds, string $descriptionKey, array $installment, int $amount): ?int
    {
        foreach ($seeds as $key => $seed) {
            if ($seed['total'] !== $installment['total'] || $seed['descriptionKey'] !== $descriptionKey) {
                continue;
            }

            if (isset($seed['claimed'][$installment['number']])) {
                continue;
            }

            if (abs($seed['amount'] - $amount) < $seed['total']) {
                return $key;
            }
        }

        return null;
    }
}
