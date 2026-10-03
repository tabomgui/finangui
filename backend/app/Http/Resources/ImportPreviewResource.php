<?php

namespace App\Http\Resources;

use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Models\ImportBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Prévia de um lote (upload recém-criado ou lote pendente reconsultado):
 * o lote, uma linha por decisão do IngestionPlanner e o resumo por
 * desfecho. Quem monta isso (ImportBatchController) já resolveu, fora daqui,
 * a categoria sugerida (CategorizeTransaction::suggest(), só para `new`) e
 * os dados da transação casada (match), para esta classe ficar só
 * formatando — sem consultar nada.
 *
 * @mixin ImportBatch
 */
final class ImportPreviewResource extends JsonResource
{
    /**
     * @param  list<RowDecision>  $decisions
     * @param  array<int, int>  $suggestedCategoryIds  categoria sugerida por linha (só outcome "new")
     * @param  array<int, array{id: int, date: string, description: string}>  $matches  transação casada por id
     */
    public function __construct(
        ImportBatch $batch,
        private readonly array $decisions,
        private readonly array $suggestedCategoryIds = [],
        private readonly array $matches = [],
    ) {
        parent::__construct($batch);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ImportBatch $batch */
        $batch = $this->resource;

        /** @var list<array{line: int, reason: string}> $failed */
        $failed = $batch->stats['failed'] ?? [];

        $summary = [
            'new' => 0,
            'duplicate' => 0,
            'update' => 0,
            'replace_installment' => 0,
            'adopt' => 0,
            'swap_pending' => 0,
            'failed' => count($failed),
        ];

        $rows = [];

        foreach ($this->decisions as $decision) {
            $summary[$decision->outcome->value]++;
            $rows[] = $this->rowToArray($decision);
        }

        return [
            'batch' => ImportBatchResource::make($batch)->toArray($request),
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * `when()` (não um `if` montando o array à mão): é o que o Scramble
     * reconhece para documentar a chave como opcional — um `if` comum faz a
     * doc (e o tipo gerado pro frontend) achar que a chave é sempre
     * obrigatória.
     *
     * @return array<string, mixed>
     */
    private function rowToArray(RowDecision $decision): array
    {
        $row = $decision->row;
        $hasMatch = $decision->transactionId !== null && isset($this->matches[$decision->transactionId]);

        return [
            'line' => $row->line,
            'date' => $row->date,
            'amount' => $row->amount,
            'direction' => $row->direction,
            'description' => $row->description,
            'outcome' => $decision->outcome,
            'installment' => $this->when($row->installment !== null, fn () => $row->installment),
            'match' => $this->when($hasMatch, fn () => $this->matches[$decision->transactionId]),
            'suggested_category_id' => $this->when(
                isset($this->suggestedCategoryIds[$row->line]),
                fn () => $this->suggestedCategoryIds[$row->line],
            ),
        ];
    }
}
