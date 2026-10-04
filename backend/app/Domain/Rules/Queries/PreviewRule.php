<?php

namespace App\Domain\Rules\Queries;

use App\Domain\Rules\Actions\ApplyRuleOutcome;
use App\Domain\Rules\Data\RuleContext;
use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Data\RulePreviewResult;
use App\Domain\Rules\Data\RulePreviewSample;
use App\Domain\Rules\Data\RuleSubject;
use App\Domain\Rules\Support\RuleEngine;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;

/**
 * Prévia síncrona de uma regra ainda não salva (ou de uma já salva, para
 * conferir antes de aplicar): conta quantas transações do usuário casam e
 * quantas de fato mudariam, mais uma amostra de até 20, mais recentes
 * primeiro. Nunca grava nada.
 */
final class PreviewRule
{
    private const SAMPLE_LIMIT = 20;

    public function __construct(
        private readonly ApplyRuleOutcome $apply,
    ) {}

    public function handle(RuleDefinition $definition, bool $overwrite): RulePreviewResult
    {
        $matched = 0;
        $changed = 0;
        $sample = [];

        // Carregar tags custa uma query a mais por lote; só compensa no scan
        // inteiro quando a regra tem add_tag (as outras ações nunca tocam
        // $transaction->tags). Sem add_tag, a amostra ainda carrega tags,
        // mas só para quem entrou nela (abaixo).
        $needsTags = $definition->usesAddTag();

        $query = Transaction::query()->whereNull('transfer_id')->excludingProjectedRecurrences();
        if ($needsTags) {
            $query->with('tags');
        }

        $query->lazyByIdDesc(500)
            ->each(function (Transaction $transaction) use ($definition, $overwrite, &$matched, &$changed, &$sample): void {
                $context = RuleContext::forExisting($transaction, $overwrite);

                $outcome = RuleEngine::evaluate(RuleSubject::fromTransaction($transaction), [$definition], $context);

                if (! $outcome->matched()) {
                    return;
                }

                $matched++;

                $changes = $this->apply->changes($transaction, $outcome);

                if ($changes === []) {
                    return;
                }

                $changed++;

                if (count($sample) < self::SAMPLE_LIMIT) {
                    $sample[] = RulePreviewSample::fromChanges($transaction, $changes);
                }
            });

        if ($sample !== []) {
            // Mais recentes primeiro pela data do lançamento (não pela ordem
            // de leitura, que é por id): id só é critério de desempate.
            usort($sample, fn (RulePreviewSample $a, RulePreviewSample $b): int => [$b->transaction->date, $b->transaction->id] <=> [$a->transaction->date, $a->transaction->id]);

            // Carrega as relações que a amostra exibe só para quem entrou
            // nela: load() preenche os mesmos objetos referenciados em
            // $sample. "tags" entra aqui só se ainda não veio do scan acima.
            $relations = ['account', 'category.parent', 'installmentPlan'];
            if (! $needsTags) {
                $relations[] = 'tags';
            }

            (new Collection(array_map(fn (RulePreviewSample $item) => $item->transaction, $sample)))->load($relations);
        }

        return new RulePreviewResult($matched, $changed, $sample);
    }
}
