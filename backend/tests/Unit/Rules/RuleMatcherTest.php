<?php

use App\Domain\Rules\Data\RuleSubject;
use App\Domain\Rules\Support\RuleMatcher;

function subject(array $overrides = []): RuleSubject
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

function matches(array $condition, ?RuleSubject $s = null): bool
{
    return RuleMatcher::matches('all', [$condition], $s ?? subject());
}

it('operadores de texto normalizam o valor', function () {
    expect(matches(['field' => 'description', 'op' => 'contains', 'value' => 'são joão']))->toBeTrue()
        ->and(matches(['field' => 'description', 'op' => 'not_contains', 'value' => 'mercado']))->toBeTrue()
        ->and(matches(['field' => 'description', 'op' => 'starts_with', 'value' => 'padaria']))->toBeTrue()
        ->and(matches(['field' => 'description', 'op' => 'ends_with', 'value' => 'joao']))->toBeTrue()
        ->and(matches(['field' => 'description', 'op' => 'equals', 'value' => ' padaria  são joão ']))->toBeTrue()
        ->and(matches(['field' => 'description', 'op' => 'not_equals', 'value' => 'padaria']))->toBeTrue()
        ->and(matches(['field' => 'original_description', 'op' => 'contains', 'value' => 'pag*']))->toBeTrue();
});

it('campo vazio: contains falha, not_contains passa', function () {
    expect(matches(['field' => 'payee', 'op' => 'contains', 'value' => 'x']))->toBeFalse()
        ->and(matches(['field' => 'payee', 'op' => 'not_contains', 'value' => 'x']))->toBeTrue();
});

it('regex é case-insensitive, sem acento e em unicode', function () {
    expect(matches(['field' => 'description', 'op' => 'regex', 'value' => '^padaria\s+s[aã]o']))->toBeTrue()
        ->and(matches(['field' => 'description', 'op' => 'regex', 'value' => 'joão$']))->toBeTrue()
        ->and(matches(['field' => 'description', 'op' => 'regex', 'value' => '^mercado']))->toBeFalse();
});

it('regex inválida nunca casa (defesa: a validação já recusa)', function () {
    expect(matches(['field' => 'description', 'op' => 'regex', 'value' => '([']))->toBeFalse();
});

it('valor numérico, direção, conta e data', function () {
    expect(matches(['field' => 'amount', 'op' => 'equals', 'value' => 1590]))->toBeTrue()
        ->and(matches(['field' => 'amount', 'op' => 'gt', 'value' => 1000]))->toBeTrue()
        ->and(matches(['field' => 'amount', 'op' => 'lte', 'value' => 1589]))->toBeFalse()
        ->and(matches(['field' => 'direction', 'op' => 'equals', 'value' => 'out']))->toBeTrue()
        ->and(matches(['field' => 'direction', 'op' => 'not_equals', 'value' => 'out']))->toBeFalse()
        ->and(matches(['field' => 'account_id', 'op' => 'equals', 'value' => 7]))->toBeTrue()
        ->and(matches(['field' => 'date', 'op' => 'gte', 'value' => '2026-03-05']))->toBeTrue()
        ->and(matches(['field' => 'date', 'op' => 'lt', 'value' => '2026-03-05']))->toBeFalse();
});

it('all exige todas; any exige uma', function () {
    $a = ['field' => 'description', 'op' => 'contains', 'value' => 'padaria'];
    $b = ['field' => 'amount', 'op' => 'gt', 'value' => 999999];

    expect(RuleMatcher::matches('all', [$a, $b], subject()))->toBeFalse()
        ->and(RuleMatcher::matches('any', [$a, $b], subject()))->toBeTrue();
});

it('grupos de um nível', function () {
    $group = ['match' => 'any', 'conditions' => [
        ['field' => 'description', 'op' => 'contains', 'value' => 'mercado'],
        ['field' => 'description', 'op' => 'contains', 'value' => 'padaria'],
    ]];

    expect(RuleMatcher::matches('all', [$group, ['field' => 'direction', 'op' => 'equals', 'value' => 'out']], subject()))->toBeTrue()
        ->and(RuleMatcher::matches('all', [$group, ['field' => 'direction', 'op' => 'equals', 'value' => 'in']], subject()))->toBeFalse();
});

it('lista vazia de condições não casa', function () {
    expect(RuleMatcher::matches('all', [], subject()))->toBeFalse()
        ->and(RuleMatcher::matches('any', [], subject()))->toBeFalse();
});
