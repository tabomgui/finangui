<?php

use App\Domain\Rules\Support\RuleDefinitionValidator;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rulePayload(array $overrides = []): array
{
    return array_merge([
        'match' => 'all',
        'conditions' => [
            ['field' => 'description', 'op' => 'contains', 'value' => 'uber'],
        ],
        'actions' => [
            ['type' => 'ignore'],
        ],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, string>
 */
function ruleErrors(array $overrides = []): array
{
    return RuleDefinitionValidator::errors(rulePayload($overrides));
}

it('aceita uma regra completa: texto, amount, direction, account_id, date, grupo e os 5 tipos de ação', function () {
    $errors = RuleDefinitionValidator::errors([
        'match' => 'all',
        'conditions' => [
            ['field' => 'description', 'op' => 'contains', 'value' => 'uber'],
            ['field' => 'amount', 'op' => 'gt', 'value' => 1000],
            ['field' => 'direction', 'op' => 'equals', 'value' => 'out'],
            ['field' => 'account_id', 'op' => 'equals', 'value' => 1],
            ['field' => 'date', 'op' => 'gte', 'value' => '2026-01-01'],
            ['match' => 'any', 'conditions' => [
                ['field' => 'description', 'op' => 'contains', 'value' => 'mercado'],
                ['field' => 'description', 'op' => 'contains', 'value' => 'padaria'],
            ]],
        ],
        'actions' => [
            ['type' => 'set_category', 'category_id' => 10],
            ['type' => 'set_description', 'value' => 'Uber'],
            ['type' => 'set_payee', 'value' => 'Uber'],
            ['type' => 'add_tag', 'tag_id' => 1],
            ['type' => 'ignore'],
        ],
    ]);

    expect($errors)->toBe([]);
});

it('match fora de all|any', function () {
    expect(ruleErrors(['match' => 'maybe']))->toHaveKey('match');
});

it('conditions vazio e actions vazio', function () {
    expect(ruleErrors(['conditions' => []]))->toHaveKey('conditions')
        ->and(ruleErrors(['actions' => []]))->toHaveKey('actions');
});

it('campo desconhecido e operador não permitido para o campo', function () {
    expect(ruleErrors(['conditions' => [
        ['field' => 'bogus', 'op' => 'equals', 'value' => 'x'],
    ]]))->toHaveKey('conditions.0.field')
        ->and(ruleErrors(['conditions' => [
            ['field' => 'amount', 'op' => 'contains', 'value' => 100],
        ]]))->toHaveKey('conditions.0.op');
});

it('texto vazio, só espaços ou maior que 200 caracteres', function () {
    expect(ruleErrors(['conditions' => [
        ['field' => 'description', 'op' => 'contains', 'value' => ''],
    ]]))->toHaveKey('conditions.0.value')
        ->and(ruleErrors(['conditions' => [
            ['field' => 'description', 'op' => 'contains', 'value' => '   '],
        ]]))->toHaveKey('conditions.0.value')
        ->and(ruleErrors(['conditions' => [
            ['field' => 'description', 'op' => 'contains', 'value' => str_repeat('a', 201)],
        ]]))->toHaveKey('conditions.0.value');
});

it('regex inválida é rejeitada com mensagem específica', function () {
    $errors = ruleErrors(['conditions' => [
        ['field' => 'description', 'op' => 'regex', 'value' => '(['],
    ]]);

    expect($errors)->toHaveKey('conditions.0.value')
        ->and($errors['conditions.0.value'])->toBe('Expressão regular inválida.');
});

it('regex que casa string vazia é rejeitada com mensagem específica', function () {
    foreach (['.*', 'a?'] as $pattern) {
        $errors = ruleErrors(['conditions' => [
            ['field' => 'description', 'op' => 'regex', 'value' => $pattern],
        ]]);

        expect($errors)->toHaveKey('conditions.0.value')
            ->and($errors['conditions.0.value'])->toBe('A expressão regular não pode casar texto vazio.');
    }
});

it('amount negativo ou não inteiro', function () {
    expect(ruleErrors(['conditions' => [
        ['field' => 'amount', 'op' => 'equals', 'value' => -5],
    ]]))->toHaveKey('conditions.0.value')
        ->and(ruleErrors(['conditions' => [
            ['field' => 'amount', 'op' => 'equals', 'value' => 12.5],
        ]]))->toHaveKey('conditions.0.value');
});

it('direction fora de in|out', function () {
    expect(ruleErrors(['conditions' => [
        ['field' => 'direction', 'op' => 'equals', 'value' => 'maybe'],
    ]]))->toHaveKey('conditions.0.value');
});

it('account_id não inteiro', function () {
    expect(ruleErrors(['conditions' => [
        ['field' => 'account_id', 'op' => 'equals', 'value' => 'abc'],
    ]]))->toHaveKey('conditions.0.value');
});

it('date fora do formato Y-m-d ou data inexistente', function () {
    expect(ruleErrors(['conditions' => [
        ['field' => 'date', 'op' => 'equals', 'value' => '01/01/2026'],
    ]]))->toHaveKey('conditions.0.value')
        ->and(ruleErrors(['conditions' => [
            ['field' => 'date', 'op' => 'equals', 'value' => '2026-02-30'],
        ]]))->toHaveKey('conditions.0.value');
});

it('grupo vazio', function () {
    expect(ruleErrors(['conditions' => [
        ['match' => 'any', 'conditions' => []],
    ]]))->toHaveKey('conditions.0.conditions');
});

it('grupo dentro de grupo é rejeitado com mensagem específica', function () {
    $errors = ruleErrors(['conditions' => [
        ['match' => 'all', 'conditions' => [
            ['match' => 'any', 'conditions' => [
                ['field' => 'description', 'op' => 'contains', 'value' => 'uber'],
            ]],
        ]],
    ]]);

    expect($errors)->toHaveKey('conditions.0.conditions.0')
        ->and($errors['conditions.0.conditions.0'])->toBe('Grupos não podem ter outros grupos.');
});

it('mais de 20 condições no total, contando as de dentro dos grupos', function () {
    $conditions = array_fill(0, 21, ['field' => 'amount', 'op' => 'gt', 'value' => 1]);

    expect(ruleErrors(['conditions' => $conditions]))->toHaveKey('conditions');
});

it('ação desconhecida', function () {
    expect(ruleErrors(['actions' => [
        ['type' => 'bogus'],
    ]]))->toHaveKey('actions.0.type');
});

it('set_category sem category_id inteiro', function () {
    expect(ruleErrors(['actions' => [
        ['type' => 'set_category'],
    ]]))->toHaveKey('actions.0.category_id');
});

it('set_description e set_payee com valor vazio ou maior que 255 caracteres', function () {
    expect(ruleErrors(['actions' => [
        ['type' => 'set_description', 'value' => ''],
    ]]))->toHaveKey('actions.0.value')
        ->and(ruleErrors(['actions' => [
            ['type' => 'set_payee', 'value' => str_repeat('a', 256)],
        ]]))->toHaveKey('actions.0.value');
});

it('add_tag sem tag_id', function () {
    expect(ruleErrors(['actions' => [
        ['type' => 'add_tag'],
    ]]))->toHaveKey('actions.0.tag_id');
});

it('dois set_category são rejeitados com mensagem específica', function () {
    $errors = ruleErrors(['actions' => [
        ['type' => 'set_category', 'category_id' => 1],
        ['type' => 'set_category', 'category_id' => 2],
    ]]);

    expect($errors)->toHaveKey('actions.1.type')
        ->and($errors['actions.1.type'])->toBe('Use no máximo uma ação deste tipo.');
});

it('add_tag repetindo a mesma tag', function () {
    expect(ruleErrors(['actions' => [
        ['type' => 'add_tag', 'tag_id' => 3],
        ['type' => 'add_tag', 'tag_id' => 3],
    ]]))->toHaveKey('actions.1.tag_id');
});

it('mais de 10 ações', function () {
    $actions = array_fill(0, 11, ['type' => 'ignore']);

    expect(ruleErrors(['actions' => $actions]))->toHaveKey('actions');
});
