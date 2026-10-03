<?php

namespace App\Domain\Banking\Providers\Pluggy;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderCategory;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Data\ProviderTransaction;
use App\Domain\Banking\Errors\ProviderAuthFailed;
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
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * Cliente da API da Pluggy (https://docs.pluggy.ai). Único lugar do app que
 * fala HTTP com o provedor; o resto do domínio Banking conhece só o
 * contrato {@see BankProvider}.
 */
final class PluggyProvider implements BankProvider
{
    private const API_KEY_CACHE_KEY = 'pluggy.api_key';

    private const CATEGORIES_CACHE_KEY = 'pluggy.categories';

    private const MAX_TRANSACTION_PAGES = 200;

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
        $accessToken = $response->json('accessToken');

        if (! is_string($accessToken) || $accessToken === '') {
            // Resposta 2xx sem accessToken não deveria acontecer; mais seguro
            // tratar como indisponibilidade transitória do que confiar num
            // token vazio/ausente.
            throw new ProviderUnavailable('Pluggy não devolveu accessToken em POST /connect_token.');
        }

        return $accessToken;
    }

    public function item(string $itemId): ProviderItem
    {
        $response = $this->request('GET', '/items/'.rawurlencode($itemId));

        /** @var array<string, mixed> $data */
        $data = $response->json();

        return $this->itemFromArray($data);
    }

    public function refreshItem(string $itemId): void
    {
        // PATCH /items/{id} com corpo "{}" (objeto vazio) dispara a
        // atualização do item sem esperar o resultado. stdClass em vez de
        // [] para o json_encode virar "{}", não "[]".
        $this->request('PATCH', '/items/'.rawurlencode($itemId), ['json' => new stdClass]);
    }

    public function deleteItem(string $itemId): void
    {
        $this->request('DELETE', '/items/'.rawurlencode($itemId));
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

    /**
     * @throws InvalidArgumentException
     */
    public function transactions(string $accountId, bool $creditCard, ?CarbonImmutable $dateFrom, ?CarbonImmutable $createdAtFrom): iterable
    {
        $this->assertExactlyOneDateFilter($dateFrom, $createdAtFrom);

        return $this->transactionsGenerator($accountId, $creditCard, $dateFrom, $createdAtFrom);
    }

    private function assertExactlyOneDateFilter(?CarbonImmutable $dateFrom, ?CarbonImmutable $createdAtFrom): void
    {
        if (($dateFrom === null) === ($createdAtFrom === null)) {
            throw new InvalidArgumentException(
                'Informe exatamente um entre $dateFrom (primeiro sync) e $createdAtFrom (syncs seguintes).'
            );
        }
    }

    /**
     * @return Generator<int, ProviderTransaction>
     */
    private function transactionsGenerator(string $accountId, bool $creditCard, ?CarbonImmutable $dateFrom, ?CarbonImmutable $createdAtFrom): Generator
    {
        $query = ['accountId' => $accountId];

        if ($dateFrom !== null) {
            $query['dateFrom'] = $dateFrom->toDateString();
        }

        if ($createdAtFrom !== null) {
            // GET /v2/transactions — createdAtFrom é um timestamp completo
            // (não só a data), em UTC, no formato ISO 8601 com milissegundos.
            $query['createdAtFrom'] = $createdAtFrom->clone()->setTimezone('UTC')->format('Y-m-d\TH:i:s.v\Z');
        }

        $next = null;
        $seenCursors = [];
        $page = 0;

        do {
            $requestQuery = $query;

            if ($next !== null) {
                $after = $this->extractAfterCursor($next);

                if ($after === null || $after === '' || isset($seenCursors[$after])) {
                    throw new ProviderUnavailable('Paginação da Pluggy presa (cursor vazio ou repetido) em GET /v2/transactions.');
                }

                $seenCursors[$after] = true;
                $requestQuery['after'] = $after;
            }

            if (++$page > self::MAX_TRANSACTION_PAGES) {
                throw new ProviderUnavailable('Paginação da Pluggy excedeu '.self::MAX_TRANSACTION_PAGES.' páginas em GET /v2/transactions.');
            }

            // Sempre reenvia os filtros originais ($requestQuery) em vez do
            // "next" devolvido: o "next" da doc vem com um "?" na frente
            // (ex.: "?accountId=...&after=..."), e reenviá-lo colado depois
            // de "/v2/transactions?" duplicaria o "?". Extraímos só o
            // cursor "after" (ver extractAfterCursor()) e montamos a query
            // nós mesmos.
            $response = $this->request('GET', '/v2/transactions', ['query' => $requestQuery]);

            /** @var array<string, mixed> $raw */
            foreach ((array) $response->json('results', []) as $raw) {
                yield $this->transactionFromArray($raw, $creditCard);
            }

            /** @var string|null $next */
            $next = $response->json('next');
        } while ($next !== null && $next !== '');
    }

    /**
     * Extrai o cursor "after" de um "next" da Pluggy, que pode vir como
     * "?accountId=...&after=X" (o formato documentado), "accountId=...
     * &after=X" (sem o "?", defensivo) ou, em tese, uma URL absoluta.
     * `rawurldecode`, não `urldecode`/`parse_str`: o cursor pode conter "+"
     * de verdade (ex.: base64), e `urldecode`/`parse_str` o decodificariam
     * como espaço.
     */
    private function extractAfterCursor(string $next): ?string
    {
        $next = ltrim($next, '?');

        if (str_contains($next, '://')) {
            $next = (string) parse_url($next, PHP_URL_QUERY);
        }

        foreach (explode('&', $next) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if ($key === 'after') {
                return rawurldecode($value);
            }
        }

        return null;
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

    public function categories(): array
    {
        try {
            // Cacheado por 1 dia: a árvore de categorias da Pluggy não muda
            // de uma sincronização para outra, e isso evita uma chamada a
            // mais por conta em todo sync. Se a busca falhar, não cacheia o
            // vazio — a próxima chamada tenta de novo, em vez de ficar sem
            // categorização por nome até o cache vencer.
            $rows = Cache::remember(self::CATEGORIES_CACHE_KEY, now()->addDay(), fn () => $this->fetchAllCategories());
        } catch (Throwable $e) {
            Log::warning('Pluggy: falha ao buscar categorias; categorização por nome fica desligada por ora.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        return array_map(
            fn (array $row) => new ProviderCategory(id: $row['id'], name: $row['name'], parentId: $row['parentId']),
            $rows,
        );
    }

    /**
     * @return list<array{id: string, name: string, parentId: ?string}>
     */
    private function fetchAllCategories(): array
    {
        $rows = [];
        $page = 1;
        $totalPages = 1;

        // GET /categories — paginada: {page, total, totalPages, results}.
        do {
            $response = $this->request('GET', '/categories', ['query' => ['page' => $page]]);

            /** @var array<string, mixed> $data */
            $data = $response->json();

            /** @var array<string, mixed> $raw */
            foreach ((array) ($data['results'] ?? []) as $raw) {
                $rows[] = [
                    'id' => (string) $raw['id'],
                    // "descriptionTranslated" é o nome em pt-BR; "description"
                    // (inglês) só entra se a tradução não vier.
                    'name' => (string) ($raw['descriptionTranslated'] ?? $raw['description']),
                    'parentId' => isset($raw['parentId']) ? (string) $raw['parentId'] : null,
                ];
            }

            $totalPages = max(1, (int) ($data['totalPages'] ?? 1));
            $page++;
        } while ($page <= $totalPages);

        return $rows;
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
            balanceCents: $this->toCents((float) $data['balance']),
            creditLimitCents: isset($creditData['creditLimit']) ? $this->toCents((float) $creditData['creditLimit']) : null,
            closingDay: isset($creditData['balanceCloseDate']) ? $this->dayOf((string) $creditData['balanceCloseDate']) : null,
            dueDay: isset($creditData['balanceDueDate']) ? $this->dayOf((string) $creditData['balanceDueDate']) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function transactionFromArray(array $data, bool $creditCard): ProviderTransaction
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
            date: $this->calendarDate((string) $data['date']),
            amountCents: abs($this->toCents($amount)),
            direction: $this->directionOf($data['type'] ?? null, $amount, $creditCard),
            description: $description,
            pending: ($data['status'] ?? null) === 'PENDING',
            categoryId: isset($data['categoryId']) ? (string) $data['categoryId'] : null,
            installment: $installment,
            purchaseDate: isset($metadata['purchaseDate']) ? $this->calendarDate((string) $metadata['purchaseDate']) : null,
            billId: isset($metadata['billId']) ? (string) $metadata['billId'] : null,
        );
    }

    /**
     * A direção vem de "type" (DEBIT/CREDIT) — nunca do sinal de "amount",
     * que inverte em cartão de crédito. "type" ausente não deveria
     * acontecer (a doc o documenta como sempre presente); defensivamente,
     * cai para o sinal do valor, com o mesmo cuidado de inversão em cartão.
     */
    private function directionOf(mixed $type, float $amount, bool $creditCard): Direction
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
    private function billFromArray(array $data): ProviderBill
    {
        return new ProviderBill(
            id: (string) $data['id'],
            dueDate: $this->calendarDate((string) $data['dueDate']),
            closingDate: isset($data['billClosingDate']) ? $this->calendarDate((string) $data['billClosingDate']) : null,
            totalCents: $this->toCents((float) $data['totalAmount']),
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
    private function calendarDate(string $iso): string
    {
        $utc = CarbonImmutable::parse($iso, 'UTC');

        if ($utc->isStartOfDay()) {
            return $utc->toDateString();
        }

        return $utc->setTimezone(config('app.timezone'))->toDateString();
    }

    private function dayOf(string $iso): int
    {
        return (int) CarbonImmutable::parse($this->calendarDate($iso))->day;
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

            if ($response->status() === 401) {
                // Renovou a key e o 401 continua: não é key velha, é
                // credencial errada de verdade (ou revogada de vez).
                Log::warning('Pluggy: 401 mesmo depois de renovar a API key.', ['method' => $method, 'uri' => $uri]);

                throw new ProviderAuthFailed;
            }
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

        if ($response->status() === 429) {
            throw new ProviderUnavailable(
                "Pluggy respondeu HTTP 429 em {$method} {$uri}.",
                retryAfter: $this->retryAfterSeconds($response),
            );
        }

        if ($response->serverError()) {
            throw new ProviderUnavailable("Pluggy respondeu HTTP {$response->status()} em {$method} {$uri}.");
        }

        Log::warning('Pluggy: requisição recusada.', [
            'method' => $method,
            'uri' => $uri,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $providerCode = $response->json('code');

        throw new ProviderRequestFailed($response->status(), is_string($providerCode) ? $providerCode : null);
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? (int) $header : null;
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

            if (in_array($response->status(), [400, 401, 403], true)) {
                // Credenciais recusadas pela própria Pluggy: client_id/secret
                // errados, não uma instabilidade do lado deles.
                Log::warning('Pluggy: autenticação recusada (credenciais inválidas).', ['status' => $response->status()]);

                throw new ProviderAuthFailed;
            }

            if (! $response->successful()) {
                throw new ProviderUnavailable("Falha ao autenticar na Pluggy (POST /auth): HTTP {$response->status()}.");
            }

            return (string) $response->json('apiKey');
        });

        return $apiKey;
    }
}
