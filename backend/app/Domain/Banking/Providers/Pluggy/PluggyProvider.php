<?php

namespace App\Domain\Banking\Providers\Pluggy;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Data\ProviderTransaction;
use App\Domain\Banking\Errors\ProviderRequestFailed;
use App\Domain\Banking\Errors\ProviderUnavailable;
use App\Domain\Transactions\Enums\Direction;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * Cliente da API da Pluggy (https://docs.pluggy.ai). Único lugar do app que
 * fala HTTP com o provedor; o resto do domínio Banking conhece só o
 * contrato {@see BankProvider}.
 */
final class PluggyProvider implements BankProvider
{
    private const API_KEY_CACHE_KEY = 'pluggy.api_key';

    public function enabled(): bool
    {
        return filled(config('services.pluggy.client_id')) && filled(config('services.pluggy.client_secret'));
    }

    public function connectToken(string $clientUserId, ?string $itemId = null): string
    {
        // POST /connect_token — clientUserId precisa ir dentro de "options":
        // na raiz do payload a Pluggy aceita a chamada mas descarta o valor
        // em silêncio (o item nasceria sem clientUserId, e não daria pra
        // conferir depois que ele pertence a este usuário).
        $payload = [
            'options' => [
                'clientUserId' => $clientUserId,
                'avoidDuplicates' => true,
            ],
        ];

        if ($itemId !== null) {
            $payload['itemId'] = $itemId;
        }

        $response = $this->request('POST', '/connect_token', ['json' => $payload]);

        /** @var string $accessToken */
        $accessToken = $response->json('accessToken');

        return $accessToken;
    }

    public function item(string $itemId): ProviderItem
    {
        $response = $this->request('GET', "/items/{$itemId}");

        /** @var array<string, mixed> $data */
        $data = $response->json();

        return $this->itemFromArray($data);
    }

    public function refreshItem(string $itemId): void
    {
        // PATCH /items/{id} com corpo "{}" (objeto vazio) dispara a
        // atualização do item sem esperar o resultado. stdClass em vez de
        // [] para o json_encode virar "{}", não "[]".
        $this->request('PATCH', "/items/{$itemId}", ['json' => new stdClass]);
    }

    public function deleteItem(string $itemId): void
    {
        $this->request('DELETE', "/items/{$itemId}");
    }

    public function accounts(string $itemId): array
    {
        $response = $this->request('GET', '/accounts', ['query' => ['itemId' => $itemId]]);

        $accounts = [];

        /** @var array<string, mixed> $raw */
        foreach ((array) $response->json('results', []) as $raw) {
            $account = $this->accountFromArray($raw);

            if ($account !== null) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    public function transactions(string $accountId, ?CarbonImmutable $dateFrom, ?CarbonImmutable $createdAtFrom): iterable
    {
        return $this->transactionsGenerator($accountId, $dateFrom, $createdAtFrom);
    }

    /**
     * @return Generator<int, ProviderTransaction>
     */
    private function transactionsGenerator(string $accountId, ?CarbonImmutable $dateFrom, ?CarbonImmutable $createdAtFrom): Generator
    {
        $query = ['accountId' => $accountId];

        if ($dateFrom !== null) {
            $query['dateFrom'] = $dateFrom->toDateString();
        }

        if ($createdAtFrom !== null) {
            $query['createdAtFrom'] = $createdAtFrom->toDateString();
        }

        // GET /v2/transactions — paginada por cursor: "next" (quando não
        // nulo) já é a query string completa da próxima chamada, substitui
        // a anterior por inteiro (não concatena com $query).
        $next = null;

        do {
            $response = $next === null
                ? $this->request('GET', '/v2/transactions', ['query' => $query])
                : $this->request('GET', '/v2/transactions?'.$next);

            /** @var array<string, mixed> $raw */
            foreach ((array) $response->json('results', []) as $raw) {
                yield $this->transactionFromArray($raw);
            }

            /** @var string|null $next */
            $next = $response->json('next');
        } while ($next !== null);
    }

    public function bills(string $accountId): array
    {
        $response = $this->request('GET', '/bills', ['query' => ['accountId' => $accountId]]);

        $bills = [];

        /** @var array<string, mixed> $raw */
        foreach ((array) $response->json('results', []) as $raw) {
            $bills[] = $this->billFromArray($raw);
        }

        return $bills;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function itemFromArray(array $data): ProviderItem
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
    private function accountFromArray(array $data): ?ProviderAccount
    {
        $subtype = $data['subtype'] ?? null;

        $kind = match ($subtype) {
            'CHECKING_ACCOUNT' => 'checking',
            'SAVINGS_ACCOUNT' => 'savings',
            'CREDIT_CARD' => 'credit_card',
            default => null,
        };

        if ($kind === null) {
            // Subtipo que ainda não sabemos mapear (ex.: investimento):
            // ignora a conta em vez de adivinhar o tipo, e registra pra
            // decidirmos depois se vale a pena suportar.
            Log::warning('Pluggy: conta ignorada por subtype desconhecido.', [
                'account_id' => $data['id'] ?? null,
                'subtype' => $subtype,
            ]);

            return null;
        }

        /** @var array<string, mixed>|null $creditData */
        $creditData = $data['creditData'] ?? null;

        return new ProviderAccount(
            id: (string) $data['id'],
            kind: $kind,
            name: (string) ($data['marketingName'] ?? $data['name']),
            number: isset($data['number']) ? (string) $data['number'] : null,
            currency: (string) $data['currencyCode'],
            balanceCents: $this->toCents((float) $data['balance']),
            creditLimitCents: isset($creditData['creditLimit']) ? $this->toCents((float) $creditData['creditLimit']) : null,
            closingDay: isset($creditData['balanceCloseDate']) ? CarbonImmutable::parse($creditData['balanceCloseDate'])->day : null,
            dueDay: isset($creditData['balanceDueDate']) ? CarbonImmutable::parse($creditData['balanceDueDate'])->day : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function transactionFromArray(array $data): ProviderTransaction
    {
        /** @var array<string, mixed>|null $metadata */
        $metadata = $data['creditCardMetadata'] ?? null;

        $installment = null;

        if (
            $metadata !== null
            && isset($metadata['installmentNumber'], $metadata['totalInstallments'])
            && (int) $metadata['totalInstallments'] >= 2
        ) {
            $installment = [
                'number' => (int) $metadata['installmentNumber'],
                'total' => (int) $metadata['totalInstallments'],
            ];
        }

        $description = $data['description'] ?? null;
        $description = filled($description) ? (string) $description : (string) ($data['descriptionRaw'] ?? '');

        return new ProviderTransaction(
            id: (string) $data['id'],
            // "date" vem em UTC; a data do app é a de São Paulo (pode virar
            // o dia anterior perto da meia-noite UTC).
            date: CarbonImmutable::parse($data['date'])->setTimezone(config('app.timezone'))->toDateString(),
            amountCents: abs($this->toCents((float) $data['amount'])),
            // A direção vem de "type", não do sinal de "amount" (que inverte
            // em cartão de crédito).
            direction: ($data['type'] ?? null) === 'CREDIT' ? Direction::In : Direction::Out,
            description: $description,
            pending: ($data['status'] ?? null) === 'PENDING',
            categoryId: isset($data['categoryId']) ? (string) $data['categoryId'] : null,
            installment: $installment,
            purchaseDate: isset($metadata['purchaseDate']) ? (string) $metadata['purchaseDate'] : null,
            billId: isset($metadata['billId']) ? (string) $metadata['billId'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function billFromArray(array $data): ProviderBill
    {
        return new ProviderBill(
            id: (string) $data['id'],
            dueDate: CarbonImmutable::parse($data['dueDate'])->toDateString(),
            closingDate: isset($data['billClosingDate']) ? CarbonImmutable::parse($data['billClosingDate'])->toDateString() : null,
            totalCents: $this->toCents((float) $data['totalAmount']),
        );
    }

    /**
     * O JSON da Pluggy traz valores monetários decimais (float); a
     * conversão para centavos inteiros só acontece aqui, no limite com o
     * provedor — o resto do domínio nunca vê float de dinheiro.
     */
    private function toCents(float $value): int
    {
        return (int) round($value * 100);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function request(string $method, string $uri, array $options = []): Response
    {
        $response = $this->send($method, $uri, $options);

        if ($response->status() === 401) {
            // A key cacheada localmente por ~1h50 pode já ter sido revogada
            // do lado da Pluggy antes disso (TTL real é 2h): esquece e tenta
            // de novo uma única vez com uma key nova.
            Cache::forget(self::API_KEY_CACHE_KEY);
            $response = $this->send($method, $uri, $options);
        }

        return $this->ensureSuccessful($response, $method, $uri);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function send(string $method, string $uri, array $options): Response
    {
        try {
            return $this->client()->send($method, $uri, $options);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable("Falha de rede ao chamar {$method} {$uri} na Pluggy.", previous: $e);
        }
    }

    private function ensureSuccessful(Response $response, string $method, string $uri): Response
    {
        if ($response->successful()) {
            return $response;
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new ProviderUnavailable("Pluggy respondeu HTTP {$response->status()} em {$method} {$uri}.");
        }

        throw new ProviderRequestFailed;
    }

    private function client(): PendingRequest
    {
        /** @var string $baseUrl */
        $baseUrl = config('services.pluggy.base_url');

        return Http::baseUrl($baseUrl)
            ->withHeaders(['X-API-KEY' => $this->apiKey()])
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(5);
    }

    private function apiKey(): string
    {
        /** @var string $apiKey */
        $apiKey = Cache::remember(self::API_KEY_CACHE_KEY, now()->addMinutes(110), function (): string {
            /** @var string $baseUrl */
            $baseUrl = config('services.pluggy.base_url');

            try {
                // POST /auth — troca client_id/client_secret por uma API key
                // válida por 2h; cacheamos por ~1h50 pra sempre renovar antes
                // do vencimento real.
                $response = Http::baseUrl($baseUrl)
                    ->acceptJson()
                    ->timeout(20)
                    ->connectTimeout(5)
                    ->post('/auth', [
                        'clientId' => config('services.pluggy.client_id'),
                        'clientSecret' => config('services.pluggy.client_secret'),
                    ]);
            } catch (ConnectionException $e) {
                throw new ProviderUnavailable('Falha de rede ao autenticar na Pluggy.', previous: $e);
            }

            if (! $response->successful()) {
                throw new ProviderUnavailable("Falha ao autenticar na Pluggy (POST /auth): HTTP {$response->status()}.");
            }

            return (string) $response->json('apiKey');
        });

        return $apiKey;
    }
}
