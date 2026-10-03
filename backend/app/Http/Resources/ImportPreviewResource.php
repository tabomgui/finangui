<?php

namespace App\Http\Resources;

use App\Domain\Imports\Data\ImportPreviewData;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Enums\ImportBatchStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sempre o mesmo formato (batch, rows, summary), tanto para o upload quanto
 * para um GET de qualquer lote, pendente ou não: pendente mostra a prévia
 * de verdade (uma linha por decisão do IngestionPlanner); não-pendente
 * mostra `rows` vazio e `summary` a partir de `stats` do próprio lote (as
 * contagens já fechadas pela confirmação) — nunca dois formatos de resposta
 * pro mesmo endpoint.
 *
 * @mixin ImportPreviewData
 */
final class ImportPreviewResource extends JsonResource
{
    public function __construct(ImportPreviewData $data)
    {
        parent::__construct($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ImportPreviewData $data */
        $data = $this->resource;

        $rows = [];
        foreach ($data->decisions as $decision) {
            $rows[] = $this->rowToArray($decision, $data);
        }

        return [
            'batch' => ImportBatchResource::make($data->batch)->toArray($request),
            'rows' => $rows,
            'summary' => $this->summary($data),
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
    private function rowToArray(RowDecision $decision, ImportPreviewData $data): array
    {
        $row = $decision->row;
        $hasMatch = $decision->transactionId !== null && isset($data->matches[$decision->transactionId]);

        return [
            'line' => $row->line,
            'date' => $row->date,
            'amount' => $row->amount,
            'direction' => $row->direction,
            'description' => $row->description,
            'outcome' => $decision->outcome,
            'installment' => $this->when($row->installment !== null, fn () => $this->installmentOf($row)),
            'match' => $this->when($hasMatch, fn () => $data->matches[$decision->transactionId]),
            'suggested_category_id' => $this->when(
                isset($data->suggestedCategoryIds[$row->line]),
                fn () => $data->suggestedCategoryIds[$row->line],
            ),
        ];
    }

    /**
     * Só chamada quando `$row->installment !== null`: o tipo de retorno
     * (sem `| null`) é o que o Scramble usa para documentar o valor da
     * chave — diferente de devolver `$row->installment` direto, cujo tipo
     * declarado em ParsedRow é nullable.
     *
     * @return array{number: int, total: int}
     */
    private function installmentOf(ParsedRow $row): array
    {
        /** @var array{number: int, total: int} $installment */
        $installment = $row->installment;

        return $installment;
    }

    /**
     * Pendente: tally das decisões do IngestionPlanner (mesmas chaves do
     * RowOutcome). Não-pendente: `stats` do lote já fechado pela
     * confirmação, que usa outra nomenclatura (ex.: `inserted` em vez de
     * `new` — é o que de fato mudou no banco, não um desfecho de linha).
     *
     * @return array{new: int, duplicate: int, update: int, replace_installment: int, adopt: int, swap_pending: int, failed: int}
     */
    private function summary(ImportPreviewData $data): array
    {
        /** @var array<string, mixed> $stats */
        $stats = $data->batch->stats ?? [];

        if ($data->batch->status !== ImportBatchStatus::Pending) {
            /** @var list<array{line: int, reason: string}> $failed */
            $failed = $stats['failed'] ?? [];

            return [
                'new' => (int) ($stats['inserted'] ?? 0),
                'duplicate' => (int) ($stats['duplicates'] ?? 0),
                'update' => (int) ($stats['updated'] ?? 0),
                'replace_installment' => (int) ($stats['replaced'] ?? 0),
                'adopt' => (int) ($stats['adopted'] ?? 0),
                'swap_pending' => (int) ($stats['swapped'] ?? 0),
                'failed' => count($failed),
            ];
        }

        /** @var list<array{line: int, reason: string}> $failed */
        $failed = $stats['failed'] ?? [];

        $summary = [
            'new' => 0,
            'duplicate' => 0,
            'update' => 0,
            'replace_installment' => 0,
            'adopt' => 0,
            'swap_pending' => 0,
            'failed' => count($failed),
        ];

        foreach ($data->decisions as $decision) {
            $summary[$decision->outcome->value]++;
        }

        return $summary;
    }
}
