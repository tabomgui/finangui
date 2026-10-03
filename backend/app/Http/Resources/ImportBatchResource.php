<?php

namespace App\Http\Resources;

use App\Domain\Imports\Models\ImportBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ImportBatch
 */
final class ImportBatchResource extends JsonResource
{
    /**
     * `failed` é a única chave presente enquanto o lote está pendente (as
     * contagens só existem depois de confirmado/revertido); as demais usam
     * `when()` para ficarem ausentes em vez de aparecer como zero — o
     * Scramble só documenta uma chave como opcional vindo de `when()`/
     * `whenLoaded()`, nunca de um `if` comum (ver CLAUDE.md sobre nunca
     * tipar uma chave só como null).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
            ]),
            'format' => $this->format,
            'format_label' => $this->format->label(),
            'filename' => $this->filename,
            'status' => $this->status,
            'stats' => $this->statsToArray(),
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'reverted_at' => $this->reverted_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statsToArray(): array
    {
        return [
            'failed' => $this->failedStats(),
            'inserted' => $this->when(isset($this->stats['inserted']), fn () => (int) $this->stats['inserted']),
            'duplicates' => $this->when(isset($this->stats['duplicates']), fn () => (int) $this->stats['duplicates']),
            'updated' => $this->when(isset($this->stats['updated']), fn () => (int) $this->stats['updated']),
            'replaced' => $this->when(isset($this->stats['replaced']), fn () => (int) $this->stats['replaced']),
            'adopted' => $this->when(isset($this->stats['adopted']), fn () => (int) $this->stats['adopted']),
            'swapped' => $this->when(isset($this->stats['swapped']), fn () => (int) $this->stats['swapped']),
            'skipped' => $this->when(isset($this->stats['skipped']), fn () => (int) $this->stats['skipped']),
        ];
    }

    /**
     * Método (não variável com `@var`) de propósito: é o tipo de retorno
     * declarado aqui que o Scramble usa para documentar `stats.failed` —
     * um `@var` numa variável local não bastou (o `??` com o literal `[]`
     * confundia a inferência).
     *
     * @return list<array{line: int, reason: string}>
     */
    private function failedStats(): array
    {
        /** @var list<array{line: int, reason: string}>|null $failed */
        $failed = $this->stats['failed'] ?? null;

        return $failed ?? [];
    }
}
