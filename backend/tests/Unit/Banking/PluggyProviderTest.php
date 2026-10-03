<?php

use App\Domain\Banking\Errors\ProviderRequestFailed;
use App\Domain\Banking\Errors\ProviderUnavailable;
use App\Domain\Banking\Providers\Pluggy\PluggyProvider;
use App\Domain\Transactions\Enums\Direction;
use Carbon\CarbonImmutable;
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

it('ignora conta com subtype desconhecido e registra em log', function () {
    Log::spy();

    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/accounts*' => Http::response([
            'results' => [[
                'id' => 'acc-unknown',
                'type' => 'BANK',
                'subtype' => 'INVESTMENT_ACCOUNT',
                'name' => 'Investimento',
                'balance' => 100.0,
                'currencyCode' => 'BRL',
            ]],
        ]),
    ]);

    $accounts = $this->provider->accounts('item-1');

    expect($accounts)->toBe([]);

    Log::shouldHaveReceived('warning')->once();
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

    $transactions = iterator_to_array($this->provider->transactions($accountId, $dateFrom, null));

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
    expect($transactionRequests[1][0]->url())->toContain('after=00000000-0000-0000-0000-0000000000b2');
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

    $transactions = iterator_to_array($this->provider->transactions('acc-1', null, null));

    expect($transactions[0]->installment)->toBeNull();
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

it('converte 503 e 429 em ProviderUnavailable', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/items/*' => Http::response(['message' => 'down'], 503),
    ]);

    expect(fn () => $this->provider->item('item-1'))->toThrow(ProviderUnavailable::class);

    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/items/*' => Http::response(['message' => 'slow down'], 429),
    ]);

    expect(fn () => $this->provider->item('item-1'))->toThrow(ProviderUnavailable::class);
});

it('converte um 400 inesperado em ProviderRequestFailed', function () {
    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        'api.pluggy.ai/items/*' => Http::response(['message' => 'bad request'], 400),
    ]);

    expect(fn () => $this->provider->item('item-1'))->toThrow(ProviderRequestFailed::class);
});
