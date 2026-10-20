<?php

namespace App\Domain\Rules\Data;

use App\Domain\Rules\Enums\ReconcileGroup;
use App\Domain\Rules\Enums\ReviewGroup;
use App\Domain\Transactions\Models\Transaction;

/**
 * O que a reconciliação de uma regra ou a revisão das regras decidiu para um
 * lançamento (ver App\Domain\Rules\Support\CategoryChangePlanner).
 * categoryId nulo: tirar a categoria. ruleId: a regra que decide a nova
 * categoria (ou, ao tirar, a que tinha categorizado), nula quando não existe
 * mais. outcome: a avaliação a gravar por ApplyRuleOutcome, nula ao tirar a
 * categoria.
 */
final readonly class CategoryChange
{
    public function __construct(
        public Transaction $transaction,
        public ReconcileGroup|ReviewGroup $group,
        public ?int $categoryId,
        public ?int $ruleId,
        public ?RuleOutcome $outcome,
    ) {}
}
