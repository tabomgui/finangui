<?php

namespace App\Domain\Rules\Support;

use App\Domain\Rules\Data\RuleContext;
use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Data\RuleOutcome;
use App\Domain\Rules\Data\RuleSubject;
use App\Domain\Rules\Enums\RuleActionType;

/**
 * Avalia regras já ordenadas (prioridade crescente). Todas as que casam
 * contribuem; para categoria, descrição e favorecido vale a primeira.
 */
final class RuleEngine
{
    /**
     * @param  list<RuleDefinition>  $rules
     */
    public static function evaluate(RuleSubject $subject, array $rules, RuleContext $context): RuleOutcome
    {
        $outcome = new RuleOutcome;
        $canSetCategory = ! $context->hasCategory || ($context->overwrite && ! $context->categoryManual);

        foreach ($rules as $rule) {
            if (! RuleMatcher::matches($rule->match, $rule->conditions, $subject)) {
                continue;
            }
            $outcome->matchedRuleIds[] = $rule->id;

            foreach ($rule->actions as $action) {
                self::applyAction($action, $rule->id, $outcome, $context, $canSetCategory);
            }
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private static function applyAction(array $action, ?int $ruleId, RuleOutcome $outcome, RuleContext $context, bool $canSetCategory): void
    {
        $type = RuleActionType::tryFrom((string) ($action['type'] ?? ''));

        if ($type === null || ($context->onlyCategory && $type !== RuleActionType::SetCategory)) {
            return;
        }

        switch ($type) {
            case RuleActionType::SetCategory:
                $categoryId = $action['category_id'] ?? null;
                if ($canSetCategory && $outcome->categoryId === null && is_int($categoryId) && $categoryId > 0) {
                    $outcome->categoryId = $categoryId;
                    $outcome->categoryRuleId = $ruleId;
                }

                break;
            case RuleActionType::SetDescription:
                if (! $context->descriptionLocked && $outcome->description === null) {
                    $outcome->description = (string) $action['value'];
                }

                break;
            case RuleActionType::SetPayee:
                if ($outcome->payee === null) {
                    $outcome->payee = (string) $action['value'];
                }

                break;
            case RuleActionType::AddTag:
                $tagId = $action['tag_id'] ?? null;
                if (is_int($tagId) && $tagId > 0 && ! in_array($tagId, $outcome->tagIds, true)) {
                    $outcome->tagIds[] = $tagId;
                }

                break;
            case RuleActionType::Ignore:
                $outcome->ignore = true;

                break;
        }
    }
}
