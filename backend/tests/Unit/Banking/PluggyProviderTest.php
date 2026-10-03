<?php

use App\Domain\Banking\Errors\ProviderAuthFailed;
use App\Domain\Banking\Errors\ProviderRequestFailed;
use App\Domain\Banking\Errors\ProviderUnavailable;
use App\Domain\Banking\Providers\Pluggy\PluggyProvider;
use App\Domain\Transactions\Enums\Direction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'services.pluggy.client_id' => 'test-client-id',
        'services.pluggy.client_secret' => 'test-client-secret',
        'services.pluggy.base_url' => 'https://api.pluggy.ai',
    ]);

    $this->provider = new PluggyProvider;
});

it('está desligado sem client_id/client_secret', function () {
    config(['services.pluggy.client_id' => null]);

    expect($this->provider->enabled())->toBeFalse();

    config(['services.pluggy.client_id' => 'test-client-id']);

    expect($this->provider->enabled())->toBeTrue();
});

it('autentica uma única vez e reaproveita a key entre chamadas', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/items/*' => Http::response(pluggyFixture('item-updated.json')),
        'api.pluggy.ai/accounts*' => Http::response(['results' => []]),
    ]);

    $this->provider->item('00000000-0000-0000-0000-000000000001');
    $this->provider->accounts('00000000-0000-0000-0000-000000000001');

    $authCalls = Http::recorded(fn ($request) => str_contains($request->url(), '/auth'));

    expect($authCalls)->toHaveCount(1);

    Http::assertSent(fn ($request) => $request->hasHeader('X-API-KEY', 'key-1'));
});

it('renova a key uma vez quando uma chamada volta 401', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::sequence()
            ->push(['apiKey' => 'key-1'])
            ->push(['apiKey' => 'key-2']),
        'api.pluggy.ai/items/*' => Http::sequence()
            ->push(null, 401)
            ->push(pluggyFixture('item-updated.json'), 200),
    ]);

    $item = $this->provider->item('00000000-0000-0000-0000-000000000001');

    expect($item->id)->toBe('00000000-0000-0000-0000-000000000001');

    expect(Http::recorded(fn ($request) => str_contains($request->url(), '/auth')))->toHaveCount(2);
    $itemRequests = Http::recorded(fn ($request) => str_contains($request->url(), '/items/'))->values();
    expect($itemRequests)->toHaveCount(2);

    [$first] = $itemRequests[0];
    [$second] = $itemRequests[1];
    expect($first->header('X-API-KEY'))->toBe(['key-1']);
    expect($second->header('X-API-KEY'))->toBe(['key-2']);
});

it('um segundo 401 mesmo depois de renovar a key vira ProviderAuthFailed', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::sequence()
            ->push(['apiKey' => 'key-1'])
            ->push(['apiKey' => 'key-2']),
        'api.pluggy.ai/items/*' => Http::response(null, 401),
    ]);

    expect(fn () => $this->provider->item('item-1'))->toThrow(ProviderAuthFailed::class);
});

it('/auth respondendo 400, 401 ou 403 vira ProviderAuthFailed, não ProviderUnavailable', function (int $status) {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['message' => 'invalid credentials'], $status),
    ]);

    expect(fn () => $this->provider->item('item-1'))->toThrow(ProviderAuthFailed::class);
})->with([400, 401, 403]);

it('/auth respondendo 503 continua ProviderUnavailable (transitório, não é credencial errada)', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['message' => 'down'], 503),
    ]);

    expect(fn () => $this->provider->item('item-1'))->toThrow(ProviderUnavailable::class);
});

it('mapeia o item com needsReauth/isUpdating', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/items/*' => Http::response(pluggyFixture('item-login-error.json')),
    ]);

    $item = $this->provider->item('00000000-0000-0000-0000-000000000002');

    expect($item->status)->toBe('LOGIN_ERROR')
        ->and($item->needsReauth())->toBeTrue()
        ->and($item->isUpdating())->toBeFalse()
        ->and($item->institutionName)->toBe('Pluggy Bank')
        ->and($item->errorMessage)->toBe('Usuário ou senha inválidos.')
        ->and($item->lastUpdatedAt)->toBeInstanceOf(CarbonImmutable::class);
});

it('manda clientUserId dentro de options no connect_token, e itemId só na raiz quando informado', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/connect_token' => Http::response(['accessToken' => 'token-abc']),
    ]);

    $token = $this->provider->connectToken('user:1');

    expect($token)->toBe('token-abc');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/connect_token')) {
            return false;
        }

        $body = $request->data();

        return $body['options']['clientUserId'] === 'user:1'
            && $body['options']['avoidDuplicates'] === true
            && ! isset($body['clientUserId'])
            && ! isset($body['itemId']);
    });

    $this->provider->connectToken('user:1', 'item-999');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/connect_token')) {
            return false;
        }

        $body = $request->data();

        return ($body['itemId'] ?? null) === 'item-999'
            && $body['options']['clientUserId'] === 'user:1';
    });
});

it('connect_token sem accessToken na resposta vira ProviderUnavailable, não TypeError', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/connect_token' => Http::response(['accessToken' => null]),
    ]);

    expect(fn () => $this->provider->connectToken('user:1'))->toThrow(ProviderUnavailable::class);
});

it('pede atualização com PATCH {} e deleta o item', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/items/*' => Http::response([], 200),
    ]);

    $this->provider->refreshItem('item-1');
    $this->provider->deleteItem('item-1');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request->body() === '{}');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

it('mapeia contas (checking, savings e cartão com creditData)', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/accounts*' => Http::response(pluggyFixture('accounts.json')),
    ]);

    $accounts = $this->provider->accounts('00000000-0000-0000-0000-000000000001');

    expect($accounts)->toHaveCount(3);

    [$checking, $savings, $creditCard] = $accounts;

    expect($checking->kind)->toBe('checking')
        ->and($checking->currency)->toBe('BRL')
        ->and($checking->balanceCents)->toBe(123456)
        ->and($checking->number)->toBe('0001/1234567-8');

    expect($savings->kind)->toBe('savings')
        ->and($savings->balanceCents)->toBe(50000);

    expect($creditCard->kind)->toBe('credit_card')
        ->and($creditCard->balanceCents)->toBe(98765)
        ->and($creditCard->creditLimitCents)->toBe(500000)
        ->and($creditCard->closingDay)->toBe(25)
        ->and($creditCard->dueDay)->toBe(5);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'itemId=00000000-0000-0000-0000-000000000001'));
});

it('subtype e type desconhecidos: conta ignorada e registrada em log', function () {
    Log::spy();

    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/accounts*' => Http::response([
            'results' => [[
                'id' => 'acc-unknown',
                'type' => 'INVESTMENT',
                'subtype' => 'INVESTMENT_ACCOUNT',
                'name' => 'Investimento',
                'balance' => 100.0,
                'currencyCode' => 'BRL',
            ]],
        ]),
    ]);

    $accounts = $this->provider->accounts('item-1');

    expect($accounts)->toBe([]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Pluggy: conta ignorada por type/subtype desconhecidos.', Mockery::any());
});

it('subtype desconhecido mas type reconhecido: cai no fallback por type e registra em log', function () {
    Log::spy();

    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/accounts*' => Http::response([
            'results' => [[
                'id' => 'acc-fallback',
                'type' => 'BANK',
                'subtype' => 'SOME_NEW_SUBTYPE',
                'name' => 'Conta nova',
                'balance' => 100.0,
                'currencyCode' => 'BRL',
            ]],
        ]),
    ]);

    $accounts = $this->provider->accounts('item-1');

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]->kind)->toBe('checking');

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Pluggy: subtype desconhecido, conta mapeada pelo type.', Mockery::any());
});

it('converte transações com direção por type, data em São Paulo, pendência e parcela, e segue a paginação pelo next', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::sequence()
            ->push(pluggyFixture('transactions-page-1.json'))
            ->push(pluggyFixture('transactions-page-2.json')),
    ]);

    $accountId = '00000000-0000-0000-0000-0000000000a1';
    $dateFrom = CarbonImmutable::parse('2025-10-03');

    $transactions = iterator_to_array($this->provider->transactions($accountId, true, $dateFrom, null));

    expect($transactions)->toHaveCount(3);

    [$debit, $pendingCredit, $installmentDebit] = $transactions;

    // 2026-09-20T02:00:00Z em America/Sao_Paulo (UTC-3) é 2026-09-19 23:00.
    expect($debit->date)->toBe('2026-09-19')
        ->and($debit->direction)->toBe(Direction::Out)
        ->and($debit->amountCents)->toBe(15032)
        ->and($debit->pending)->toBeFalse()
        ->and($debit->categoryId)->toBe('16000000');

    expect($pendingCredit->direction)->toBe(Direction::In)
        ->and($pendingCredit->pending)->toBeTrue()
        ->and($pendingCredit->amountCents)->toBe(500000);

    // amount vem negativo (-299.90) por ser cartão, mas a direção é definida
    // pelo "type" (DEBIT), não pelo sinal.
    expect($installmentDebit->direction)->toBe(Direction::Out)
        ->and($installmentDebit->amountCents)->toBe(29990)
        ->and($installmentDebit->installment)->toBe(['number' => 2, 'total' => 10])
        ->and($installmentDebit->purchaseDate)->toBe('2026-08-22')
        ->and($installmentDebit->billId)->toBe('00000000-0000-0000-0000-0000000000c1');

    Http::assertSent(function ($request) use ($accountId) {
        return str_contains($request->url(), '/v2/transactions')
            && str_contains($request->url(), "accountId={$accountId}")
            && str_contains($request->url(), 'dateFrom=2025-10-03');
    });

    $transactionRequests = Http::recorded(fn ($request) => str_contains($request->url(), '/v2/transactions'))->values();
    expect($transactionRequests)->toHaveCount(2);
    expect($transactionRequests[1][0]->url())
        ->toContain('after=00000000-0000-0000-0000-0000000000b2')
        ->toContain("accountId={$accountId}")
        // Sem "?" duplicado — o "next" da página 1 já vem com "?" na frente
        // (ver fixture), e a chamada seguinte reconstrói a query do zero.
        ->not->toContain('??');
});

it('exige exatamente um entre dateFrom e createdAtFrom', function () {
    expect(fn () => $this->provider->transactions('acc-1', false, null, null))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->provider->transactions('acc-1', false, CarbonImmutable::now(), CarbonImmutable::now()))
        ->toThrow(InvalidArgumentException::class);
});

it('createdAtFrom vai formatado em UTC com milissegundos', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::response(['results' => [], 'next' => null]),
    ]);

    $createdAtFrom = CarbonImmutable::parse('2026-03-07 10:00:00', 'America/Sao_Paulo');

    iterator_to_array($this->provider->transactions('acc-1', false, null, $createdAtFrom));

    // 10:00 em São Paulo (UTC-3) é 13:00 UTC.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'createdAtFrom=2026-03-07T13%3A00%3A00.000Z'));
});

it('paginação: cursor repetido (next preso em loop) vira ProviderUnavailable', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::response([
            'results' => [],
            'next' => '?accountId=acc-1&after=cursor-x',
        ]),
    ]);

    expect(function () {
        iterator_to_array($this->provider->transactions('acc-1', false, CarbonImmutable::parse('2025-01-01'), null));
    })->toThrow(ProviderUnavailable::class);
});

it('paginação: next como URL absoluta também funciona (extrai só a query)', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::sequence()
            ->push(['results' => [], 'next' => 'https://api.pluggy.ai/v2/transactions?accountId=acc-1&after=cursor-abs'])
            ->push(['results' => [], 'next' => null]),
    ]);

    iterator_to_array($this->provider->transactions('acc-1', false, CarbonImmutable::parse('2025-01-01'), null));

    $requests = Http::recorded(fn ($request) => str_contains($request->url(), '/v2/transactions'))->values();
    expect($requests)->toHaveCount(2);
    expect($requests[1][0]->url())->toContain('after=cursor-abs')->not->toContain('??');
});

it('paginação: cursor com "+" literal não é decodificado como espaço', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::sequence()
            ->push(['results' => [], 'next' => '?accountId=acc-1&after=AbC+123%3D'])
            ->push(['results' => [], 'next' => null]),
    ]);

    iterator_to_array($this->provider->transactions('acc-1', false, CarbonImmutable::parse('2025-01-01'), null));

    $requests = Http::recorded(fn ($request) => str_contains($request->url(), '/v2/transactions'))->values();
    // A query do segundo request é recodificada pelo client HTTP ao montar
    // a URL: o "+" original (preservado por rawurldecode) vira "%2B" de
    // novo nessa saída, nunca um espaço ("%20"/" ") — o que provaria que
    // ele teria sido decodificado incorretamente antes de ser reenviado.
    expect($requests[1][0]->url())->toContain('after=AbC%2B123%3D');
});

it('não conta parcela quando totalInstallments é menor que 2', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::response([
            'results' => [[
                'id' => 'tx-single',
                'date' => '2026-09-20T12:00:00.000Z',
                'description' => 'Compra à vista',
                'descriptionRaw' => 'COMPRA A VISTA',
                'amount' => -50.0,
                'currencyCode' => 'BRL',
                'status' => 'POSTED',
                'type' => 'DEBIT',
                'categoryId' => null,
                'category' => null,
                'creditCardMetadata' => [
                    'installmentNumber' => 1,
                    'totalInstallments' => 1,
                    'totalAmount' => 50.0,
                    'purchaseDate' => '2026-09-20',
                    'billId' => 'bill-1',
                ],
            ]],
            'next' => null,
        ]),
    ]);

    $transactions = iterator_to_array($this->provider->transactions('acc-1', true, null, CarbonImmutable::now()));

    expect($transactions[0]->installment)->toBeNull();
});

it('parcela com número fora do total (dado corrompido) é tratada como sem parcela', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::response([
            'results' => [[
                'id' => 'tx-corrupt',
                'date' => '2026-09-20T12:00:00.000Z',
                'description' => 'Compra',
                'amount' => -50.0,
                'currencyCode' => 'BRL',
                'status' => 'POSTED',
                'type' => 'DEBIT',
                'creditCardMetadata' => [
                    'installmentNumber' => 11,
                    'totalInstallments' => 10,
                    'totalAmount' => 500.0,
                    'purchaseDate' => '2026-08-20',
                    'billId' => 'bill-1',
                ],
            ]],
            'next' => null,
        ]),
    ]);

    $transactions = iterator_to_array($this->provider->transactions('acc-1', true, null, CarbonImmutable::now()));

    expect($transactions[0]->installment)->toBeNull();
});

it('prefere amountInAccountCurrency quando presente (compra em moeda estrangeira)', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::response([
            'results' => [[
                'id' => 'tx-foreign',
                'date' => '2026-09-20T12:00:00.000Z',
                'description' => 'Compra no exterior',
                'amount' => -10.0,
                'amountInAccountCurrency' => -52.37,
                'currencyCode' => 'USD',
                'status' => 'POSTED',
                'type' => 'DEBIT',
            ]],
            'next' => null,
        ]),
    ]);

    $transactions = iterator_to_array($this->provider->transactions('acc-1', false, null, CarbonImmutable::now()));

    expect($transactions[0]->amountCents)->toBe(5237);
});

it('sem amountInAccountCurrency, usa amount', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::response([
            'results' => [[
                'id' => 'tx-local',
                'date' => '2026-09-20T12:00:00.000Z',
                'description' => 'Compra local',
                'amount' => -19.99,
                'currencyCode' => 'BRL',
                'status' => 'POSTED',
                'type' => 'DEBIT',
            ]],
            'next' => null,
        ]),
    ]);

    $transactions = iterator_to_array($this->provider->transactions('acc-1', false, null, CarbonImmutable::now()));

    expect($transactions[0]->amountCents)->toBe(1999);
});

it('converte valores decimais para centavos sem erro de arredondamento nos casos de borda', function (float $value, int $expectedCents) {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::response([
            'results' => [[
                'id' => 'tx-edge',
                'date' => '2026-09-20T12:00:00.000Z',
                'description' => 'Valor',
                'amount' => $value,
                'currencyCode' => 'BRL',
                'status' => 'POSTED',
                'type' => $value < 0 ? 'DEBIT' : 'CREDIT',
            ]],
            'next' => null,
        ]),
    ]);

    $transactions = iterator_to_array($this->provider->transactions('acc-1', false, null, CarbonImmutable::now()));

    expect($transactions[0]->amountCents)->toBe($expectedCents);
})->with([
    [19.99, 1999],
    [1234.5, 123450],
    [-0.01, 1],
]);

it('type ausente: usa o sinal do valor, invertido em cartão', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::response([
            'results' => [
                ['id' => 'tx-neg-nocard', 'date' => '2026-09-20T12:00:00.000Z', 'description' => 'x', 'amount' => -50.0, 'currencyCode' => 'BRL', 'status' => 'POSTED'],
                ['id' => 'tx-neg-card', 'date' => '2026-09-20T12:00:00.000Z', 'description' => 'x', 'amount' => -50.0, 'currencyCode' => 'BRL', 'status' => 'POSTED'],
            ],
            'next' => null,
        ]),
    ]);

    $notCard = iterator_to_array($this->provider->transactions('acc-1', false, null, CarbonImmutable::now()));
    $card = iterator_to_array($this->provider->transactions('acc-1', true, null, CarbonImmutable::now()));

    expect($notCard[1]->direction)->toBe(Direction::Out)
        ->and($card[1]->direction)->toBe(Direction::In);
});

it('mapeia faturas com closingDate opcional', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/bills*' => Http::response(pluggyFixture('bills.json')),
    ]);

    $bills = $this->provider->bills('00000000-0000-0000-0000-0000000000a3');

    expect($bills)->toHaveCount(2);

    [$withClosing, $withoutClosing] = $bills;

    expect($withClosing->dueDate)->toBe('2026-10-05')
        ->and($withClosing->closingDate)->toBe('2026-09-25')
        ->and($withClosing->totalCents)->toBe(123456);

    expect($withoutClosing->closingDate)->toBeNull()
        ->and($withoutClosing->totalCents)->toBe(98765);
});

it('calendarDate: hora UTC exatamente meia-noite usa a mesma data, sem converter de fuso', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::response([
            'results' => [[
                'id' => 'tx-midnight',
                'date' => '2026-09-20T00:00:00.000Z',
                'description' => 'x',
                'amount' => 10.0,
                'currencyCode' => 'BRL',
                'status' => 'POSTED',
                'type' => 'CREDIT',
            ]],
            'next' => null,
        ]),
    ]);

    $transactions = iterator_to_array($this->provider->transactions('acc-1', false, null, CarbonImmutable::now()));

    expect($transactions[0]->date)->toBe('2026-09-20');
});

it('calendarDate: hora UTC real (não meia-noite) converte para o fuso do app e pode virar o dia anterior', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::response([
            'results' => [[
                'id' => 'tx-early',
                'date' => '2026-09-20T02:00:00.000Z',
                'description' => 'x',
                'amount' => 10.0,
                'currencyCode' => 'BRL',
                'status' => 'POSTED',
                'type' => 'CREDIT',
            ]],
            'next' => null,
        ]),
    ]);

    $transactions = iterator_to_array($this->provider->transactions('acc-1', false, null, CarbonImmutable::now()));

    // 2026-09-20T02:00:00Z em America/Sao_Paulo (UTC-3) é 2026-09-19 23:00.
    expect($transactions[0]->date)->toBe('2026-09-19');
});

it('calendarDate também normaliza purchaseDate (meia-noite UTC não volta um dia)', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/v2/transactions*' => Http::response([
            'results' => [[
                'id' => 'tx-purchase',
                'date' => '2026-09-20T12:00:00.000Z',
                'description' => 'x',
                'amount' => -50.0,
                'currencyCode' => 'BRL',
                'status' => 'POSTED',
                'type' => 'DEBIT',
                'creditCardMetadata' => [
                    'installmentNumber' => 1,
                    'totalInstallments' => 3,
                    'totalAmount' => 150.0,
                    'purchaseDate' => '2026-08-22T00:00:00.000Z',
                    'billId' => 'bill-1',
                ],
            ]],
            'next' => null,
        ]),
    ]);

    $transactions = iterator_to_array($this->provider->transactions('acc-1', true, null, CarbonImmutable::now()));

    expect($transactions[0]->purchaseDate)->toBe('2026-08-22');
});

it('busca e mapeia categorias (uma página)', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/categories*' => Http::response(pluggyFixture('categories.json')),
    ]);

    $categories = $this->provider->categories();

    expect($categories)->toHaveCount(7);

    $salary = collect($categories)->firstWhere('id', '0101');
    expect($salary->name)->toBe('Salário')
        ->and($salary->parentId)->toBe('0100');

    $income = collect($categories)->firstWhere('id', '0100');
    expect($income->parentId)->toBeNull();
});

it('busca categorias paginadas e as cacheia por 1 dia', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/categories*' => Http::sequence()
            ->push(pluggyFixture('categories-page-1.json'))
            ->push(pluggyFixture('categories-page-2.json')),
    ]);

    $categories = $this->provider->categories();

    expect($categories)->toHaveCount(2);

    // Segunda chamada não bate na rede: veio do cache.
    $this->provider->categories();

    $categoryRequests = Http::recorded(fn ($request) => str_contains($request->url(), '/categories'));
    expect($categoryRequests)->toHaveCount(2);
});

it('categorias: falha na busca devolve lista vazia e registra em log, sem propagar a exceção', function () {
    Log::spy();

    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/categories*' => Http::response(['message' => 'down'], 503),
    ]);

    $categories = $this->provider->categories();

    expect($categories)->toBe([]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Pluggy: falha ao buscar categorias; categorização por nome fica desligada por ora.', Mockery::any());
});

it('erro de conexão (timeout/DNS) vira ProviderUnavailable', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection timed out');
    });

    expect(fn () => $this->provider->item('item-1'))->toThrow(ProviderUnavailable::class);
});

it('converte 503 em ProviderUnavailable', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/items/*' => Http::response(['message' => 'down'], 503),
    ]);

    expect(fn () => $this->provider->item('item-1'))->toThrow(ProviderUnavailable::class);
});

it('converte 429 em ProviderUnavailable e carrega o Retry-After', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/items/*' => Http::response(['message' => 'slow down'], 429, ['Retry-After' => '30']),
    ]);

    try {
        $this->provider->item('item-1');
        $this->fail('deveria ter lançado ProviderUnavailable');
    } catch (ProviderUnavailable $e) {
        expect($e->retryAfter)->toBe(30);
    }
});

it('converte um 400 inesperado em ProviderRequestFailed, com status e providerCode', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/items/*' => Http::response(['code' => 'INVALID_ITEM', 'message' => 'bad request'], 400),
    ]);

    try {
        $this->provider->item('item-1');
        $this->fail('deveria ter lançado ProviderRequestFailed');
    } catch (ProviderRequestFailed $e) {
        expect($e->status)->toBe(400)
            ->and($e->providerCode)->toBe('INVALID_ITEM');
    }
});
