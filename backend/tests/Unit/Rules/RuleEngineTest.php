<?php

use App\Domain\Rules\Data\RuleContext;
use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Data\RuleSubject;
use App\Domain\Rules\Support\RuleEngine;

function engineSubject(): RuleSubject
{
    return new RuleSubject('UBER TRIP', 'UBER *TRIP', '', '', 2500, 'out', 1, '2026-03-05');
}

function engineRule(int $id, array $actions, string $contains = 'uber'): RuleDefinition
{
    return new RuleDefinition($id, 'all', [['field' => 'description', 'op' => 'contains', 'value' => $contains]], $actions);
}

function ruleContext(array $overrides = []): RuleContext
{
    return new RuleContext(...array_merge(['hasCategory' => false, 'categoryManual' => false, 'descriptionLocked' => false, 'overwrite' => false, 'onlyCategory' => false], $overrides));
}

it('primeiro set_category vence e guarda a regra', function () {
    $outcome = RuleEngine::evaluate(engineSubject(), [
        engineRule(1, [['type' => 'set_category', 'category_id' => 10]]),
        engineRule(2, [['type' => 'set_category', 'category_id' => 20]]),
    ], ruleContext());

    expect($outcome->categoryId)->toBe(10)->and($outcome->categoryRuleId)->toBe(1)->and($outcome->matchedRuleIds)->toBe([1, 2]);
});

it('não mexe em categoria preenchida, salvo overwrite em categoria não manual', function () {
    $rules = [engineRule(1, [['type' => 'set_category', 'category_id' => 10]])];

    expect(RuleEngine::evaluate(engineSubject(), $rules, ruleContext(['hasCategory' => true]))->categoryId)->toBeNull()
        ->and(RuleEngine::evaluate(engineSubject(), $rules, ruleContext(['hasCategory' => true, 'overwrite' => true]))->categoryId)->toBe(10)
        ->and(RuleEngine::evaluate(engineSubject(), $rules, ruleContext(['hasCategory' => true, 'overwrite' => true, 'categoryManual' => true]))->categoryId)->toBeNull();
});

it('set_description respeita descrição travada; primeiro vence', function () {
    $rules = [engineRule(1, [['type' => 'set_description', 'value' => 'Uber']]), engineRule(2, [['type' => 'set_description', 'value' => 'Outro']])];

    expect(RuleEngine::evaluate(engineSubject(), $rules, ruleContext())->description)->toBe('Uber')
        ->and(RuleEngine::evaluate(engineSubject(), $rules, ruleContext(['descriptionLocked' => true]))->description)->toBeNull();
});

it('add_tag acumula sem repetir; ignore marca; payee primeiro vence', function () {
    $outcome = RuleEngine::evaluate(engineSubject(), [
        engineRule(1, [['type' => 'add_tag', 'tag_id' => 3], ['type' => 'set_payee', 'value' => 'Uber']]),
        engineRule(2, [['type' => 'add_tag', 'tag_id' => 3], ['type' => 'add_tag', 'tag_id' => 4], ['type' => 'ignore'], ['type' => 'set_payee', 'value' => 'X']]),
    ], ruleContext());

    expect($outcome->tagIds)->toBe([3, 4])->and($outcome->ignore)->toBeTrue()->and($outcome->payee)->toBe('Uber');
});

it('regra que não casa não contribui', function () {
    $outcome = RuleEngine::evaluate(engineSubject(), [engineRule(1, [['type' => 'ignore']], 'mercado')], ruleContext());

    expect($outcome->matched())->toBeFalse()->and($outcome->ignore)->toBeFalse();
});

it('modo só categoria ignora as outras ações', function () {
    $outcome = RuleEngine::evaluate(engineSubject(), [
        engineRule(1, [['type' => 'set_description', 'value' => 'Uber'], ['type' => 'ignore'], ['type' => 'add_tag', 'tag_id' => 3], ['type' => 'set_category', 'category_id' => 10]]),
    ], ruleContext(['onlyCategory' => true]));

    expect($outcome->categoryId)->toBe(10)->and($outcome->description)->toBeNull()->and($outcome->ignore)->toBeFalse()->and($outcome->tagIds)->toBe([]);
});

it('ignora set_category com id que não é inteiro positivo', function () {
    $rules = [
        engineRule(1, [['type' => 'set_category', 'category_id' => 0]]),
        engineRule(2, [['type' => 'set_category', 'category_id' => -1]]),
        engineRule(3, [['type' => 'set_category', 'category_id' => '10']]),
        engineRule(4, [['type' => 'set_category', 'category_id' => null]]),
    ];

    expect(RuleEngine::evaluate(engineSubject(), $rules, ruleContext())->categoryId)->toBeNull();
});

it('ignora add_tag com id que não é inteiro positivo, mas segue acumulando os válidos', function () {
    $outcome = RuleEngine::evaluate(engineSubject(), [
        engineRule(1, [['type' => 'add_tag', 'tag_id' => 0], ['type' => 'add_tag', 'tag_id' => -1], ['type' => 'add_tag', 'tag_id' => '5'], ['type' => 'add_tag', 'tag_id' => 7]]),
    ], ruleContext());

    expect($outcome->tagIds)->toBe([7]);
});

it('overwrite substitui categoria vinda de regra ou de histórico, nunca a manual', function () {
    $rules = [engineRule(1, [['type' => 'set_category', 'category_id' => 10]])];

    // categorized_by = 'rule:3' ou 'history': não é manual, overwrite pode substituir.
    expect(RuleEngine::evaluate(engineSubject(), $rules, ruleContext(['hasCategory' => true, 'overwrite' => true, 'categoryManual' => false]))->categoryId)->toBe(10);

    // categorized_by = 'manual': overwrite nunca substitui.
    expect(RuleEngine::evaluate(engineSubject(), $rules, ruleContext(['hasCategory' => true, 'overwrite' => true, 'categoryManual' => true]))->categoryId)->toBeNull();
});
