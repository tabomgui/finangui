<?php

namespace App\Domain\Rules\Actions;

use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Data\RuleOutcome;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Grava numa transação o que uma avaliação de regras (RuleOutcome) decidiu.
 * Nunca mexe em perna de transferência.
 *
 * Memoiza por instância se uma categoria/tag ainda existe e está usável:
 * PreviewRule e ApplyRuleRetroactively reaproveitam a mesma instância ao
 * longo de todo o scan, e a mesma categoria/tag de uma regra se repete em
 * toda transação que casa — sem isso seria uma query a mais por linha.
 */
final class ApplyRuleOutcome
{
    /** @var array<int, bool> */
    private array $categoryUsable = [];

    /** @var array<int, bool> */
    private array $tagExists = [];

    /**
     * Só o que de fato mudaria: categoria/tag que não existem mais (regra
     * salva antes de excluí-las) ou categoria arquivada são ignoradas em
     * silêncio.
     *
     * @return array{category_id?: int, description?: string, payee?: string, tag_ids?: list<int>, is_ignored?: true}
     */
    public function changes(Transaction $transaction, RuleOutcome $outcome): array
    {
        if ($transaction->isTransferLeg()) {
            return [];
        }

        $changes = [];

        if ($outcome->categoryId !== null
            && $outcome->categoryId !== $transaction->category_id
            && $this->categoryUsable($outcome->categoryId)) {
            $changes['category_id'] = $outcome->categoryId;
        }

        if ($outcome->description !== null && $outcome->description !== $transaction->description) {
            $changes['description'] = $outcome->description;
        }

        if ($outcome->payee !== null && $outcome->payee !== $transaction->payee) {
            $changes['payee'] = $outcome->payee;
        }

        $tagIds = $this->newTagIds($transaction, $outcome->tagIds);
        if ($tagIds !== []) {
            $changes['tag_ids'] = $tagIds;
        }

        if ($outcome->ignore && ! $transaction->is_ignored) {
            $changes['is_ignored'] = true;
        }

        return $changes;
    }

    public function handle(Transaction $transaction, RuleOutcome $outcome): bool
    {
        $changes = $this->changes($transaction, $outcome);

        if ($changes === []) {
            return false;
        }

        DB::transaction(function () use ($transaction, $outcome, $changes): void {
            if (array_key_exists('category_id', $changes)) {
                $transaction->category_id = $changes['category_id'];
                $transaction->categorized_by = "rule:{$outcome->categoryRuleId}";
            }

            if (array_key_exists('description', $changes)) {
                $transaction->description = $changes['description'];
            }

            if (array_key_exists('payee', $changes)) {
                $transaction->payee = $changes['payee'];
            }

            if (array_key_exists('is_ignored', $changes)) {
                $transaction->is_ignored = true;
            }

            $transaction->save();

            if (array_key_exists('tag_ids', $changes)) {
                $transaction->tags()->syncWithoutDetaching($changes['tag_ids']);
                // A coleção carregada ficou desatualizada (sem as tags recém
                // sincronizadas): descarta para a próxima leitura recarregar,
                // senão uma segunda chamada com o mesmo outcome acharia que
                // as tags ainda são novas e reportaria mudança de novo.
                $transaction->unsetRelation('tags');
            }
        });

        return true;
    }

    /**
     * Mantém a ordem do outcome; descarta ids já presentes ou de tags
     * excluídas depois de a regra ser salva.
     *
     * @param  list<int>  $tagIds
     * @return list<int>
     */
    private function newTagIds(Transaction $transaction, array $tagIds): array
    {
        if ($tagIds === []) {
            return [];
        }

        $existing = $transaction->tags->pluck('id')->all();
        $candidates = array_values(array_diff($tagIds, $existing));

        return array_values(array_filter($candidates, fn (int $tagId) => $this->tagExists($tagId)));
    }

    private function categoryUsable(int $categoryId): bool
    {
        return $this->categoryUsable[$categoryId] ??= Category::query()->whereKey($categoryId)->where('is_archived', false)->exists();
    }

    private function tagExists(int $tagId): bool
    {
        return $this->tagExists[$tagId] ??= Tag::query()->whereKey($tagId)->exists();
    }
}
