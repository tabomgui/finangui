<?php

use Illuminate\Support\Str;

it('exporta documento OpenAPI cobrindo a API v1', function () {
    $path = storage_path('framework/testing/openapi.json');

    $this->artisan('scramble:export', ['--path' => $path])->assertSuccessful();

    $document = json_decode((string) file_get_contents($path), true);

    expect($document['paths'])->toHaveKeys([
        '/accounts', '/categories', '/tags', '/transactions', '/transfers', '/dashboard', '/me', '/auth/login',
    ]);
});

it('documenta updates como PATCH, nunca PUT', function () {
    $document = exportOpenApiDocument();

    foreach (['/accounts/{account}', '/categories/{category}', '/tags/{tag}', '/transactions/{transaction}'] as $path) {
        expect($document['paths'][$path])->toHaveKey('patch');
        expect($document['paths'][$path])->not->toHaveKey('put');
    }
});

it('documenta 409 de DomainError com {code, message} nos endpoints que podem lançar', function () {
    $document = exportOpenApiDocument();

    $cases = [
        ['delete', '/accounts/{account}', 'account_has_transactions'],
        ['delete', '/categories/{category}', 'category_has_children'],
    ];

    foreach ($cases as [$method, $path, $code]) {
        $response = $document['paths'][$path][$method]['responses']['409'] ?? null;
        expect($response)->not->toBeNull();

        $schema = $response['content']['application/json']['schema'];
        expect($schema['properties']['code']['enum'] ?? null)->toBe([$code]);
        expect($schema['required'])->toBe(['code', 'message']);
    }

    // Mais de um DomainError possível na mesma rota: vira anyOf com um enum por código.
    $transferPost = $document['paths']['/transfers']['post']['responses']['409']['content']['application/json']['schema'];
    $codes = collect($transferPost['anyOf'])->map(fn ($s) => $s['properties']['code']['enum'][0])->sort()->values()->all();
    expect($codes)->toBe(['transfer_currency_mismatch', 'transfer_same_account']);
});

it('documenta 404 em GET, PATCH e DELETE /transfers/{transfer}', function () {
    $document = exportOpenApiDocument();

    foreach (['get', 'patch', 'delete'] as $method) {
        expect($document['paths']['/transfers/{transfer}'][$method]['responses'])->toHaveKey('404');
    }
});

it('documenta 201 nos endpoints de criação', function () {
    $document = exportOpenApiDocument();

    $creates = [
        ['/accounts', 'post'],
        ['/categories', 'post'],
        ['/tags', 'post'],
        ['/transactions', 'post'],
        ['/transfers', 'post'],
        ['/auth/register', 'post'],
    ];

    foreach ($creates as [$path, $method]) {
        $responses = $document['paths'][$path][$method]['responses'];
        expect($responses)->toHaveKey('201');
        expect($responses)->not->toHaveKey('200');
    }
});

it('documenta registration_closed (403) e session_required (400) em auth/register', function () {
    $document = exportOpenApiDocument();

    $responses = $document['paths']['/auth/register']['post']['responses'];
    expect($responses)->toHaveKeys(['403', '400']);

    $forbidden = $responses['403']['content']['application/json']['schema'];
    expect($forbidden['properties']['code']['const'] ?? null)->toBe('registration_closed');
});

it('documenta session_required (400) em auth/login', function () {
    $document = exportOpenApiDocument();

    $schema = $document['paths']['/auth/login']['post']['responses']['400']['content']['application/json']['schema'];
    expect($schema['properties']['code']['const'] ?? null)->toBe('session_required');
});

it('documenta include_archived como query param booleano em GET /accounts e /categories', function () {
    $document = exportOpenApiDocument();

    foreach (['/accounts', '/categories'] as $path) {
        $params = collect($document['paths'][$path]['get']['parameters'] ?? []);
        $param = $params->firstWhere('name', 'include_archived');

        expect($param)->not->toBeNull();
        expect($param['in'])->toBe('query');
        expect($param['schema']['type'])->toBe('boolean');
    }
});

it('documenta top_categories[].category_id como integer|null no dashboard', function () {
    $document = exportOpenApiDocument();

    $categoryId = $document['paths']['/dashboard']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['properties']['top_categories']['items']['properties']['category_id'];

    expect($categoryId['type'])->toContain('integer');
    expect($categoryId['type'])->toContain('null');
    expect($categoryId['type'])->not->toContain('string');
});

it('documenta balance como obrigatório em AccountResource', function () {
    $document = exportOpenApiDocument();

    $accountResource = $document['components']['schemas']['AccountResource'];

    expect($accountResource['required'])->toContain('balance');
    expect($accountResource['properties']['balance']['type'])->toBe('integer');
});

it('documenta is_transfer_effective como booleano', function () {
    $document = exportOpenApiDocument();

    expect($document['components']['schemas']['CategoryResource']['properties']['is_transfer_effective']['type'])
        ->toBe('boolean');
});

it('documenta os campos de cartão de UpdateAccountRequest com o tipo real, não string', function () {
    $document = exportOpenApiDocument();

    $properties = $document['components']['schemas']['UpdateAccountRequest']['properties'];

    expect($properties['credit_limit']['type'])->toBe('integer');
    expect($properties['closing_day']['type'])->toBe('integer');
    expect($properties['due_day']['type'])->toBe('integer');
    expect($properties['last_four']['type'])->toContain('string');
});

/**
 * @return array<string, mixed>
 */
function exportOpenApiDocument(): array
{
    $path = storage_path('framework/testing/openapi-'.Str::random(8).'.json');

    test()->artisan('scramble:export', ['--path' => $path])->assertSuccessful();

    /** @var array<string, mixed> $document */
    $document = json_decode((string) file_get_contents($path), true);

    return $document;
}
