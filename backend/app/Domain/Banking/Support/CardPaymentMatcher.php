<?php

namespace App\Domain\Banking\Support;

use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderBillPayment;
use Carbon\CarbonImmutable;

/**
 * Puro, sem banco: reconhece créditos do cartão como pagamento de fatura e
 * deduplica os que representam o mesmo pagamento.
 *
 * Reconhecimento:
 *
 * - É perna de transferência (já marcada por App\Domain\Cards\Actions\AssignStatement
 *   antes de chegar aqui) ou está travada (isLocked — o usuário decidiu o
 *   estado dela à mão) — sempre reconhecido, nunca é duplicata, sempre
 *   prioridade máxima para reivindicar a vaga de um pagamento do banco.
 * - Casa com um pagamento informado por alguma fatura do banco (mesmo valor,
 *   data a ±WINDOW_DAYS): cada pagamento do banco é consumido por no máximo
 *   um lançamento local, o de data mais próxima — entre candidatos de
 *   prioridade igual (ver assignSlots()), não só por proximidade de data.
 * - Sem pagamento do banco disponível, a descrição bate com
 *   App\Domain\Banking\Support\CardPaymentDescriptionPatterns — só então um
 *   lançamento vindo do banco (não manual/CSV/OFX) pode ser candidato a
 *   duplicata. Um candidato assim só se torna uma âncora NOVA (sem casar
 *   com nenhuma já existente — ver nearestAnchor()) quando nenhum payments[]
 *   do lote cobre a data dele (ver hasReportedPaymentNear()): com algum
 *   payments[] por perto, mas de outro valor, o banco já tem dado
 *   específico sobre o período — melhor deixar sem decisão do que confiar
 *   só na descrição contra um sinal conflitante.
 *
 * Deduplicação: o excedente de lançamentos reconhecidos pelo padrão de
 * descrição, além do que já foi consumido por um pagamento do banco, é
 * comparado contra uma "âncora" fixa (o próprio pagamento já reconhecido, ou
 * o excedente anterior mais próximo que não achou âncora) — nunca contra uma
 * janela encadeada, que confundiria pagamentos de meses diferentes só porque
 * ficam a poucos dias um do outro em sequência. Perna de transferência,
 * travada e lançamento manual/CSV/OFX nunca são marcados como duplicata.
 */
final class CardPaymentMatcher
{
    /** Também usada por App\Domain\Banking\Actions\ReconcileCardPayments::earliestBillClosing(). */
    public const WINDOW_DAYS = 3;

    /**
     * @param  list<CardPaymentCandidate>  $candidates
     * @param  list<ProviderBill>  $bills
     * @return list<CardPaymentDecision>
     */
    public static function match(array $candidates, array $bills): array
    {
        return self::matchDetailed($candidates, $bills)->decisions;
    }

    /**
     * Como match(), mas também devolve os candidatos travados por
     * hasReportedPaymentNear() (ver a classe) — reconhecidos pelo padrão de
     * descrição, sem vaga/âncora do banco, mas com algum payments[] do lote
     * cobrindo a mesma data, de outro valor. Usada por
     * App\Domain\Banking\Actions\ReconcileCardPayments, que precisa saber
     * quais ids ficaram deliberadamente sem decisão (para nunca resetá-los —
     * eles não são "não reconhecidos", são "sem dado novo suficiente para
     * decidir agora") — match() sozinho não distingue um candidato assim de
     * um que realmente não bateu com nada.
     *
     * @param  list<CardPaymentCandidate>  $candidates
     * @param  list<ProviderBill>  $bills
     */
    public static function matchDetailed(array $candidates, array $bills): CardPaymentMatchResult
    {
        /** @var array<int, CardPaymentCandidate> $byId */
        $byId = [];
        foreach ($candidates as $candidate) {
            $byId[$candidate->id] = $candidate;
        }

        $slots = self::bankPaymentSlots($bills);
        [$slotToCandidate, $candidateToSlot] = self::assignSlots($slots, $candidates);

        /** @var array<int, string|null> $anchorBillId  candidateId => fatura do banco (ou null) — cada chave é uma âncora: nunca duplicata */
        $anchorBillId = [];

        foreach ($slotToCandidate as $slotIndex => $candidateId) {
            $anchorBillId[$candidateId] = $slots[$slotIndex]['billId'];
        }

        // Referência fixa (perna de transferência ou travada) que não
        // reivindicou nenhum pagamento do banco ainda é uma âncora própria
        // — nunca vira duplicata de nada.
        foreach ($candidates as $candidate) {
            if (self::isFixedReference($candidate) && ! isset($candidateToSlot[$candidate->id])) {
                $anchorBillId[$candidate->id] ??= null;
            }
        }

        /** @var list<CardPaymentCandidate> $leftovers não reivindicados por nenhum pagamento do banco, nem referência fixa */
        $leftovers = array_values(array_filter(
            $candidates,
            fn (CardPaymentCandidate $c) => ! self::isFixedReference($c) && ! isset($candidateToSlot[$c->id]),
        ));

        $patternLeftovers = array_values(array_filter(
            $leftovers,
            fn (CardPaymentCandidate $c) => CardPaymentDescriptionPatterns::matches($c->description),
        ));

        // Lançamento manual/CSV/OFX reconhecido pelo padrão nunca é
        // duplicata: é sempre a própria âncora, mesmo perto de outra.
        foreach ($patternLeftovers as $candidate) {
            if (! $candidate->isProviderSourced) {
                $anchorBillId[$candidate->id] ??= null;
            }
        }

        /** @var list<CardPaymentCandidate> $providerPatternLeftovers */
        $providerPatternLeftovers = array_values(array_filter(
            $patternLeftovers,
            fn (CardPaymentCandidate $c) => $c->isProviderSourced,
        ));

        usort($providerPatternLeftovers, fn (CardPaymentCandidate $a, CardPaymentCandidate $b): int => strcmp($a->date, $b->date) ?: $a->id <=> $b->id);

        /** @var array<int, true> $duplicateIds */
        $duplicateIds = [];

        /** @var array<int, true> $gatedIds */
        $gatedIds = [];

        foreach ($providerPatternLeftovers as $candidate) {
            $anchorId = self::nearestAnchor($candidate, $byId, array_keys($anchorBillId));

            if ($anchorId !== null) {
                $duplicateIds[$candidate->id] = true;
            } elseif (self::hasReportedPaymentNear($bills, $candidate->date)) {
                // Sem âncora e com algum payments[] cobrindo esta data (mas
                // de outro valor, já que senão teria virado uma vaga mais
                // acima): o banco já tem dado específico sobre o período —
                // mais seguro deixar este candidato sem decisão do que
                // confiar só na descrição contra um sinal conflitante.
                $gatedIds[$candidate->id] = true;
            } else {
                // Sem âncora e sem nenhum payments[] cobrindo esta data: o
                // padrão de descrição é a única pista que existe, e vale
                // como uma nova âncora.
                $anchorBillId[$candidate->id] = null;
            }
        }

        $decisions = [];

        foreach ($anchorBillId as $candidateId => $billExternalId) {
            $decisions[] = new CardPaymentDecision($candidateId, isDuplicate: false, billExternalId: $billExternalId);
        }

        foreach (array_keys($duplicateIds) as $candidateId) {
            $decisions[] = new CardPaymentDecision($candidateId, isDuplicate: true, billExternalId: null);
        }

        return new CardPaymentMatchResult($decisions, $gatedIds);
    }

    /**
     * Um pagamento do banco é uma vaga (slot) por `id` — nunca por (valor,
     * data): dois pagamentos de ids diferentes, mesmo com o mesmo valor no
     * mesmo dia e na mesma fatura, são vagas distintas (podem ser dois
     * pagamentos de verdade, ex.: duas parcelas iguais pagas juntas). Só
     * colapsa numa vaga só quando é literalmente o mesmo pagamento (mesmo
     * id) repetido em mais de uma fatura — nesse caso, entre as faturas que
     * o repetem, prefere a de fechamento mais recente que ainda seja igual
     * ou anterior à data do pagamento (a fatura que ele de fato quita), e só
     * sem nenhuma candidata assim cai para a de fechamento mais próximo.
     *
     * @param  list<ProviderBill>  $bills
     * @return list<array{billId: string, amountCents: int, date: string}>
     */
    private static function bankPaymentSlots(array $bills): array
    {
        return array_map(self::preferredEntry(...), array_values(self::groupPaymentsById($bills)));
    }

    /**
     * A mesma fatura preferida que bankPaymentSlots() usa internamente
     * (preferredEntry()), exposta para quem precisa só da atribuição
     * id-de-pagamento → fatura — hoje, App\Domain\Banking\Actions\SyncBills,
     * para nunca somar o mesmo pagamento do banco (mesmo `payments[].id`
     * repetido em mais de uma fatura — dado real da Pluggy) em reported_paid
     * de duas faturas diferentes.
     *
     * @param  list<ProviderBill>  $bills
     * @return array<string, string> id do pagamento => id da fatura preferida
     */
    public static function preferredBillForPayment(array $bills): array
    {
        $preferred = [];

        foreach (self::groupPaymentsById($bills) as $paymentId => $entries) {
            $preferred[$paymentId] = self::preferredEntry($entries)['billId'];
        }

        return $preferred;
    }

    /**
     * Todas as ocorrências de payments[] de todas as faturas, agrupadas por
     * `id` do pagamento — base de bankPaymentSlots() e preferredBillForPayment().
     *
     * @param  list<ProviderBill>  $bills
     * @return array<string, list<array{billId: string, closingDate: ?string, amountCents: int, date: string}>>
     */
    private static function groupPaymentsById(array $bills): array
    {
        $groups = [];

        foreach ($bills as $bill) {
            foreach ($bill->payments as $payment) {
                if (! self::isUsablePayment($payment)) {
                    continue;
                }

                $groups[$payment->id][] = [
                    'billId' => $bill->id,
                    'closingDate' => $bill->closingDate,
                    'amountCents' => $payment->amountCents,
                    'date' => $payment->date,
                ];
            }
        }

        return $groups;
    }

    /**
     * Existe algum payments[], de qualquer fatura deste lote, com data a
     * ±WINDOW_DAYS de $date — não importa o valor (um valor igual já teria
     * virado vaga antes de chegar aqui). Usada só para decidir se um
     * candidato reconhecido apenas pelo padrão de descrição (sem vaga do
     * banco) pode se tornar uma âncora nova — ver match().
     *
     * @param  list<ProviderBill>  $bills
     */
    private static function hasReportedPaymentNear(array $bills, string $date): bool
    {
        foreach ($bills as $bill) {
            foreach ($bill->payments as $payment) {
                if (! self::isUsablePayment($payment)) {
                    continue;
                }

                if (abs(self::diffDays($date, $payment->date)) <= self::WINDOW_DAYS) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isUsablePayment(ProviderBillPayment $payment): bool
    {
        return $payment->id !== '' && $payment->date !== '';
    }

    /**
     * @param  list<array{billId: string, closingDate: ?string, amountCents: int, date: string}>  $entries
     * @return array{billId: string, amountCents: int, date: string}
     */
    private static function preferredEntry(array $entries): array
    {
        $date = $entries[0]['date'];

        $eligible = array_values(array_filter(
            $entries,
            fn (array $e) => $e['closingDate'] !== null && $e['closingDate'] <= $date,
        ));

        $ranked = $eligible !== [] ? $eligible : $entries;

        usort($ranked, function (array $a, array $b): int {
            if ($a['closingDate'] === $b['closingDate']) {
                return 0;
            }
            if ($a['closingDate'] === null) {
                return 1;
            }
            if ($b['closingDate'] === null) {
                return -1;
            }

            return strcmp($b['closingDate'], $a['closingDate']);
        });

        return ['billId' => $ranked[0]['billId'], 'amountCents' => $ranked[0]['amountCents'], 'date' => $ranked[0]['date']];
    }

    /**
     * Liga cada pagamento do banco a no máximo um lançamento local, em três
     * rodadas de prioridade — a distância de data só decide dentro de cada
     * rodada, nunca entre elas:
     *
     * 1. Referência fixa (perna de transferência ou travada) — mesmo quando
     *    outro candidato está nominalmente mais perto da data, uma
     *    transferência já confirmada (ou uma decisão já travada pelo
     *    usuário) é mais confiável que um crédito ainda não ligado.
     * 2. Descrição batendo com App\Domain\Banking\Support\CardPaymentDescriptionPatterns
     *    — um crédito que parece mesmo um pagamento (ex.: "pagamento
     *    recebido") reivindica antes de um que não tem nenhuma cara de
     *    pagamento (ex.: um reembolso coincidentemente mais próximo em
     *    data), mesmo este estando mais perto da data do banco.
     * 3. Todo o restante.
     *
     * Dentro de cada rodada: do mais próximo em data para o mais distante;
     * lançado (posted) é preferido a pendente em caso de empate.
     *
     * @param  list<array{billId: string, amountCents: int, date: string}>  $slots
     * @param  list<CardPaymentCandidate>  $candidates
     * @return array{0: array<int, int>, 1: array<int, int>} slot => candidateId, candidateId => slot
     */
    private static function assignSlots(array $slots, array $candidates): array
    {
        $slotToCandidate = [];
        $candidateToSlot = [];

        foreach ([0, 1, 2] as $tier) {
            $pairs = [];

            foreach ($slots as $slotIndex => $slot) {
                if (isset($slotToCandidate[$slotIndex])) {
                    continue;
                }

                foreach ($candidates as $candidate) {
                    if (isset($candidateToSlot[$candidate->id])) {
                        continue;
                    }
                    if (self::priorityTier($candidate) !== $tier) {
                        continue;
                    }
                    if ($candidate->amountCents !== $slot['amountCents']) {
                        continue;
                    }

                    $diff = abs(self::diffDays($candidate->date, $slot['date']));

                    if ($diff > self::WINDOW_DAYS) {
                        continue;
                    }

                    $pairs[] = ['slot' => $slotIndex, 'candidateId' => $candidate->id, 'diff' => $diff, 'isPending' => $candidate->isPending];
                }
            }

            usort($pairs, function (array $a, array $b): int {
                if ($a['diff'] !== $b['diff']) {
                    return $a['diff'] <=> $b['diff'];
                }
                if ($a['isPending'] !== $b['isPending']) {
                    return $a['isPending'] ? 1 : -1;
                }

                return $a['candidateId'] <=> $b['candidateId'];
            });

            foreach ($pairs as $pair) {
                if (isset($slotToCandidate[$pair['slot']]) || isset($candidateToSlot[$pair['candidateId']])) {
                    continue;
                }

                $slotToCandidate[$pair['slot']] = $pair['candidateId'];
                $candidateToSlot[$pair['candidateId']] = $pair['slot'];
            }
        }

        return [$slotToCandidate, $candidateToSlot];
    }

    private static function priorityTier(CardPaymentCandidate $candidate): int
    {
        if (self::isFixedReference($candidate)) {
            return 0;
        }

        if (CardPaymentDescriptionPatterns::matches($candidate->description)) {
            return 1;
        }

        return 2;
    }

    private static function isFixedReference(CardPaymentCandidate $candidate): bool
    {
        return $candidate->isTransferLeg || $candidate->isLocked;
    }

    /**
     * Âncora mais próxima (mesmo valor, data a ±WINDOW_DAYS) entre as já
     * decididas — nunca uma cadeia: cada excedente compara só contra âncoras
     * já fixadas, nunca contra outro excedente ainda não resolvido.
     *
     * @param  array<int, CardPaymentCandidate>  $byId
     * @param  list<int>  $anchorIds
     */
    private static function nearestAnchor(CardPaymentCandidate $candidate, array $byId, array $anchorIds): ?int
    {
        $best = null;
        $bestDiff = null;

        foreach ($anchorIds as $anchorId) {
            $anchor = $byId[$anchorId];

            if ($anchor->amountCents !== $candidate->amountCents) {
                continue;
            }

            $diff = abs(self::diffDays($candidate->date, $anchor->date));

            if ($diff > self::WINDOW_DAYS) {
                continue;
            }

            if ($bestDiff === null || $diff < $bestDiff) {
                $best = $anchorId;
                $bestDiff = $diff;
            }
        }

        return $best;
    }

    private static function diffDays(string $dateA, string $dateB): int
    {
        return (int) CarbonImmutable::parse($dateA)->diffInDays(CarbonImmutable::parse($dateB), false);
    }
}
