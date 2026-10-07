<?php

namespace App\Domain\Banking\Providers\Pluggy;

use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderBillPayment;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Data\ProviderTransaction;
use App\Domain\Transactions\Enums\Direction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Converte o JSON cru da Pluggy nos DTOs do domínio (App\Domain\Banking\Data).
 * Puro: nenhum método aqui faz uma chamada HTTP — isso é
 * App\Domain\Banking\Providers\Pluggy\PluggyProvider, que usa esta classe só
 * para a tradução.
 */
final class PluggyPayloadMapper
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function item(array $data): ProviderItem
    {
        /** @var array<string, mixed>|null $connector */
        $connector = $data['connector'] ?? null;
        /** @var array<string, mixed>|null $error */
        $error = $data['error'] ?? null;

        return new ProviderItem(
            id: (string) $data['id'],
            status: (string) $data['status'],
            clientUserId: isset($data['clientUserId']) ? (string) $data['clientUserId'] : null,
            lastUpdatedAt: isset($data['lastUpdatedAt']) ? CarbonImmutable::parse($data['lastUpdatedAt']) : null,
            institutionName: isset($connector['name']) ? (string) $connector['name'] : null,
            institutionLogoUrl: isset($connector['imageUrl']) ? (string) $connector['imageUrl'] : null,
            errorMessage: isset($error['message']) ? (string) $error['message'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function account(array $data): ?ProviderAccount
    {
        $subtype = $data['subtype'] ?? null;
        $type = $data['type'] ?? null;

        $kind = match ($subtype) {
            'CHECKING_ACCOUNT' => 'checking',
            'SAVINGS_ACCOUNT' => 'savings',
            'CREDIT_CARD' => 'credit_card',
            // Subtipo que ainda não sabemos mapear (ex.: investimento):
            // cai para um tipo genérico pelo "type" — perde só a distinção
            // checking/savings dentro de BANK — em vez de descartar a
            // conta inteira.
            default => match ($type) {
                'BANK' => 'checking',
                'CREDIT' => 'credit_card',
                default => null,
            },
        };

        if ($kind === null) {
            // "type" também não ajudou: não dá pra adivinhar. Ignora a
            // conta e registra pra decidirmos depois se vale a pena suportar.
            Log::warning('Pluggy: conta ignorada por type/subtype desconhecidos.', [
                'account_id' => $data['id'] ?? null,
                'type' => $type,
                'subtype' => $subtype,
            ]);

            return null;
        }

        if (! in_array($subtype, ['CHECKING_ACCOUNT', 'SAVINGS_ACCOUNT', 'CREDIT_CARD'], true)) {
            Log::warning('Pluggy: subtype desconhecido, conta mapeada pelo type.', [
                'account_id' => $data['id'] ?? null,
                'type' => $type,
                'subtype' => $subtype,
            ]);
        }

        /** @var array<string, mixed>|null $creditData */
        $creditData = $data['creditData'] ?? null;

        return new ProviderAccount(
            id: (string) $data['id'],
            kind: $kind,
            name: (string) ($data['marketingName'] ?? $data['name']),
            number: isset($data['number']) ? (string) $data['number'] : null,
            currency: (string) $data['currencyCode'],
            balanceCents: self::toCents((float) $data['balance']),
            creditLimitCents: isset($creditData['creditLimit']) ? self::toCents((float) $creditData['creditLimit']) : null,
            availableCreditLimitCents: isset($creditData['availableCreditLimit']) ? self::toCents((float) $creditData['availableCreditLimit']) : null,
            closingDay: isset($creditData['balanceCloseDate']) ? self::dayOf((string) $creditData['balanceCloseDate']) : null,
            dueDay: isset($creditData['balanceDueDate']) ? self::dayOf((string) $creditData['balanceDueDate']) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function transaction(array $data, bool $creditCard): ProviderTransaction
    {
        /** @var array<string, mixed>|null $metadata */
        $metadata = $data['creditCardMetadata'] ?? null;

        $installment = null;

        if (
            $metadata !== null
            && isset($metadata['installmentNumber'], $metadata['totalInstallments'])
            && (int) $metadata['totalInstallments'] >= 2
            // Sanidade: número da parcela tem que caber no total — um valor
            // fora disso é dado corrompido, melhor tratar como "sem parcela"
            // do que mandar um número de parcela sem sentido pra frente.
            && (int) $metadata['installmentNumber'] >= 1
            && (int) $metadata['installmentNumber'] <= (int) $metadata['totalInstallments']
        ) {
            $installment = [
                'number' => (int) $metadata['installmentNumber'],
                'total' => (int) $metadata['totalInstallments'],
            ];
        }

        $description = $data['description'] ?? null;
        $description = filled($description) ? (string) $description : (string) ($data['descriptionRaw'] ?? '');

        // "amountInAccountCurrency" (quando vem) já está na moeda da conta;
        // "amount" pode estar na moeda original de uma compra no exterior.
        // O app só lida com uma moeda por conta, então prefere o primeiro.
        $amount = (float) ($data['amountInAccountCurrency'] ?? $data['amount']);

        return new ProviderTransaction(
            id: (string) $data['id'],
            date: self::calendarDate((string) $data['date']),
            amountCents: abs(self::toCents($amount)),
            direction: self::directionOf($data['type'] ?? null, $amount, $creditCard),
            description: $description,
            pending: ($data['status'] ?? null) === 'PENDING',
            categoryId: isset($data['categoryId']) ? (string) $data['categoryId'] : null,
            installment: $installment,
            purchaseDate: isset($metadata['purchaseDate']) ? self::calendarDate((string) $metadata['purchaseDate']) : null,
            billId: isset($metadata['billId']) ? (string) $metadata['billId'] : null,
        );
    }

    /**
     * A direção vem de "type" (DEBIT/CREDIT) — nunca do sinal de "amount",
     * que inverte em cartão de crédito. "type" ausente não deveria
     * acontecer (a doc o documenta como sempre presente); defensivamente,
     * cai para o sinal do valor, com o mesmo cuidado de inversão em cartão.
     */
    private static function directionOf(mixed $type, float $amount, bool $creditCard): Direction
    {
        if ($type === 'CREDIT') {
            return Direction::In;
        }

        if ($type === 'DEBIT') {
            return Direction::Out;
        }

        $negative = $amount < 0;

        return match (true) {
            $creditCard && $negative => Direction::In,
            $creditCard => Direction::Out,
            $negative => Direction::Out,
            default => Direction::In,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function bill(array $data): ProviderBill
    {
        /** @var list<array<string, mixed>> $payments */
        $payments = (array) ($data['payments'] ?? []);

        return new ProviderBill(
            id: (string) $data['id'],
            dueDate: self::calendarDate((string) $data['dueDate']),
            closingDate: isset($data['billClosingDate']) ? self::calendarDate((string) $data['billClosingDate']) : null,
            totalCents: self::toCents((float) $data['totalAmount']),
            payments: array_values(array_filter(array_map(self::billPayment(...), $payments))),
        );
    }

    /**
     * Sem `id` ou `paymentDate` o pagamento é descartado (null) em vez de
     * mapeado com dado incompleto — dado corrompido/parcial não deveria
     * acontecer, mas é melhor ignorar este pagamento do que deixar
     * App\Domain\Banking\Support\CardPaymentMatcher casar por um id vazio.
     *
     * @param  array<string, mixed>  $data
     */
    private static function billPayment(array $data): ?ProviderBillPayment
    {
        $id = $data['id'] ?? null;
        $paymentDate = $data['paymentDate'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        if (! is_string($paymentDate) || $paymentDate === '') {
            return null;
        }

        return new ProviderBillPayment(
            id: (string) $id,
            date: self::calendarDate($paymentDate),
            amountCents: self::toCents((float) ($data['amount'] ?? 0)),
        );
    }

    /**
     * Data "de calendário" a partir de um timestamp ISO em UTC. Quando a
     * hora UTC é exatamente meia-noite, a Pluggy está mandando só uma data
     * (sem hora real — ex.: dueDate, purchaseDate, balanceCloseDate), e
     * converter para o fuso do app voltaria um dia (meia-noite UTC é 21h do
     * dia anterior em São Paulo). Com hora real (ex.: o horário de uma
     * transação), a conversão para o fuso do app é o comportamento certo.
     */
    public static function calendarDate(string $iso): string
    {
        $utc = CarbonImmutable::parse($iso, 'UTC');

        if ($utc->isStartOfDay()) {
            return $utc->toDateString();
        }

        return $utc->setTimezone(config('app.timezone'))->toDateString();
    }

    private static function dayOf(string $iso): int
    {
        return (int) CarbonImmutable::parse(self::calendarDate($iso))->day;
    }

    /**
     * O JSON da Pluggy traz valores monetários decimais (float); a
     * conversão para centavos inteiros só acontece aqui, no limite com o
     * provedor — o resto do domínio nunca vê float de dinheiro.
     */
    public static function toCents(float $value): int
    {
        return (int) round($value * 100);
    }
}
