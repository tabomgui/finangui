<?php

namespace App\Domain\Banking\Providers\Pluggy;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderCategory;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Data\ProviderTransaction;
use App\Domain\Banking\Errors\ProviderAuthFailed;
use App\Domain\Banking\Errors\ProviderRequestFailed;
use App\Domain\Banking\Errors\ProviderUnavailable;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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

        return PluggyPayloadMapper::item($data);
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
            $account = PluggyPayloadMapper::account($raw);

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
                yield PluggyPayloadMapper::transaction($raw, $creditCard);
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
            $bills[] = PluggyPayloadMapper::bill($raw);
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
            'body' => $this->truncatedBody($response),
        ]);

        $providerCode = $response->json('code');

        throw new ProviderRequestFailed($response->status(), is_string($providerCode) ? $providerCode : null);
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? (int) $header : null;
    }

    /**
     * Corpo cru da resposta, cortado em 1000 caracteres: só para o log de
     * uma recusa (debug), nunca pensado para guardar um corpo inteiro
     * (pode ser grande e não acrescenta nada depois de um certo tamanho).
     */
    private function truncatedBody(Response $response): string
    {
        return mb_substr($response->body(), 0, 1000);
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

    /**
     * A key fica cacheada criptografada (Crypt::encryptString): o cache
     * "database"/"file" do Laravel não é um cofre — nada nele precisa
     * continuar lendo texto puro só porque é "só um cache".
     */
    private function apiKey(): string
    {
        /** @var string $encrypted */
        $encrypted = Cache::remember(self::API_KEY_CACHE_KEY, now()->addMinutes(110), function (): string {
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

            $apiKey = $response->json('apiKey');

            if (! is_string($apiKey) || $apiKey === '') {
                // 2xx sem apiKey não deveria acontecer; mais seguro tratar
                // como indisponibilidade transitória do que cachear uma key
                // vazia (toda chamada seguinte tentaria autenticar com "").
                throw new ProviderUnavailable('Pluggy não devolveu apiKey em POST /auth.');
            }

            return Crypt::encryptString($apiKey);
        });

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            // Cache de antes desta mudança (texto puro) ou corrompido: força
            // reautenticar, em vez de devolver lixo como se fosse a key.
            Cache::forget(self::API_KEY_CACHE_KEY);

            return $this->apiKey();
        }
    }
}
