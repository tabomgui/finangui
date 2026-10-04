<?php

use App\Domain\Rules\Data\RuleSubject;
use App\Domain\Rules\Support\RuleMatcher;

function matcherSubject(array $overrides = []): RuleSubject
{
    return new RuleSubject(...array_merge([
        'description' => 'PADARIA SAO JOAO',
        'originalDescription' => 'PAG*PADARIA SAO JOAO 123',
        'payee' => '',
        'notes' => '',
        'amount' => 1590,
        'direction' => 'out',
        'accountId' => 7,
        'date' => '2026-03-05',
    ], $overrides));
}

function matcherMatches(array $condition, ?RuleSubject $s = null): bool
{
    return RuleMatcher::matches('all', [$condition], $s ?? matcherSubject());
}

it('operadores de texto normalizam o valor', function () {
    expect(matcherMatches(['field' => 'description', 'op' => 'contains', 'value' => 'são joão']))->toBeTrue()
        ->and(matcherMatches(['field' => 'description', 'op' => 'not_contains', 'value' => 'mercado']))->toBeTrue()
        ->and(matcherMatches(['field' => 'description', 'op' => 'starts_with', 'value' => 'padaria']))->toBeTrue()
        ->and(matcherMatches(['field' => 'description', 'op' => 'ends_with', 'value' => 'joao']))->toBeTrue()
        ->and(matcherMatches(['field' => 'description', 'op' => 'equals', 'value' => ' padaria  são joão ']))->toBeTrue()
        ->and(matcherMatches(['field' => 'description', 'op' => 'not_equals', 'value' => 'padaria']))->toBeTrue()
        ->and(matcherMatches(['field' => 'original_description', 'op' => 'contains', 'value' => 'pag*']))->toBeTrue();
});

it('campo vazio: contains falha, not_contains passa', function () {
    expect(matcherMatches(['field' => 'payee', 'op' => 'contains', 'value' => 'x']))->toBeFalse()
        ->and(matcherMatches(['field' => 'payee', 'op' => 'not_contains', 'value' => 'x']))->toBeTrue();
});

it('regex é case-insensitive, sem acento e em unicode', function () {
    expect(matcherMatches(['field' => 'description', 'op' => 'regex', 'value' => '^padaria\s+s[aã]o']))->toBeTrue()
        ->and(matcherMatches(['field' => 'description', 'op' => 'regex', 'value' => 'joão$']))->toBeTrue()
        ->and(matcherMatches(['field' => 'description', 'op' => 'regex', 'value' => '^mercado']))->toBeFalse();
});

it('regex inválida nunca casa (defesa: a validação já recusa)', function () {
    expect(matcherMatches(['field' => 'description', 'op' => 'regex', 'value' => '([']))->toBeFalse();
});

it('regex escapa só til não escapado, preservando um til já escapado pelo usuário', function () {
    $subject = matcherSubject(['description' => 'PRECO R$ 10 ~ 20']);

    expect(matcherMatches(['field' => 'description', 'op' => 'regex', 'value' => '10 \~ 20'], $subject))->toBeTrue()
        ->and(matcherMatches(['field' => 'description', 'op' => 'regex', 'value' => '10 ~ 20'], $subject))->toBeTrue();
});

it('restaura pcre.backtrack_limit depois de avaliar uma regex', function () {
    $before = ini_get('pcre.backtrack_limit');

    matcherMatches(['field' => 'description', 'op' => 'regex', 'value' => '^padaria']);

    expect(ini_get('pcre.backtrack_limit'))->toBe($before);
});

it('valor numérico, direção, conta e data', function () {
    expect(matcherMatches(['field' => 'amount', 'op' => 'equals', 'value' => 1590]))->toBeTrue()
        ->and(matcherMatches(['field' => 'amount', 'op' => 'gt', 'value' => 1000]))->toBeTrue()
        ->and(matcherMatches(['field' => 'amount', 'op' => 'lte', 'value' => 1589]))->toBeFalse()
        ->and(matcherMatches(['field' => 'direction', 'op' => 'equals', 'value' => 'out']))->toBeTrue()
        ->and(matcherMatches(['field' => 'direction', 'op' => 'not_equals', 'value' => 'out']))->toBeFalse()
        ->and(matcherMatches(['field' => 'account_id', 'op' => 'equals', 'value' => 7]))->toBeTrue()
        ->and(matcherMatches(['field' => 'date', 'op' => 'gte', 'value' => '2026-03-05']))->toBeTrue()
        ->and(matcherMatches(['field' => 'date', 'op' => 'lt', 'value' => '2026-03-05']))->toBeFalse();
});

it('all exige todas; any exige uma', function () {
    $a = ['field' => 'description', 'op' => 'contains', 'value' => 'padaria'];
    $b = ['field' => 'amount', 'op' => 'gt', 'value' => 999999];

    expect(RuleMatcher::matches('all', [$a, $b], matcherSubject()))->toBeFalse()
        ->and(RuleMatcher::matches('any', [$a, $b], matcherSubject()))->toBeTrue();
});

it('grupos de um nível', function () {
    $group = ['match' => 'any', 'conditions' => [
        ['field' => 'description', 'op' => 'contains', 'value' => 'mercado'],
        ['field' => 'description', 'op' => 'contains', 'value' => 'padaria'],
    ]];

    expect(RuleMatcher::matches('all', [$group, ['field' => 'direction', 'op' => 'equals', 'value' => 'out']], matcherSubject()))->toBeTrue()
        ->and(RuleMatcher::matches('all', [$group, ['field' => 'direction', 'op' => 'equals', 'value' => 'in']], matcherSubject()))->toBeFalse();
});

it('lista vazia de condições não casa', function () {
    expect(RuleMatcher::matches('all', [], matcherSubject()))->toBeFalse()
        ->and(RuleMatcher::matches('any', [], matcherSubject()))->toBeFalse();
});

it('nó que não é array nunca casa e não lança', function () {
    expect(RuleMatcher::matches('all', ['oops', 123, null], matcherSubject()))->toBeFalse()
        ->and(RuleMatcher::matches('any', ['oops', 123, null], matcherSubject()))->toBeFalse();
});

it('grupo com conditions que não é lista nunca casa e não lança', function () {
    $brokenGroup = ['match' => 'any', 'conditions' => 'not-a-list'];

    expect(RuleMatcher::matches('all', [$brokenGroup], matcherSubject()))->toBeFalse();
});

it('field, op ou value vindos como array ou objeto nunca casam e não lançam', function () {
    expect(matcherMatches(['field' => ['x'], 'op' => 'contains', 'value' => 'padaria']))->toBeFalse()
        ->and(matcherMatches(['field' => 'description', 'op' => ['x'], 'value' => 'padaria']))->toBeFalse()
        ->and(matcherMatches(['field' => 'description', 'op' => 'contains', 'value' => ['x']]))->toBeFalse()
        ->and(matcherMatches(['field' => new stdClass, 'op' => 'contains', 'value' => 'padaria']))->toBeFalse()
        ->and(matcherMatches(['field' => 'direction', 'op' => 'equals', 'value' => new stdClass]))->toBeFalse()
        ->and(matcherMatches(['field' => 'account_id', 'op' => 'equals', 'value' => new stdClass]))->toBeFalse();
});
