<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\ParseResult;
use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Support\FormatDetector;
use App\Domain\Imports\Support\IngestionPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Lê e normaliza o arquivo enviado, detecta (ou usa) o formato, parseia e
 * grava um lote pendente — sem tocar em transações: a decisão de cada linha
 * (IngestionPlanner::plan) é só para a prévia, a mesma cascata que
 * ConfirmImportBatch/IngestTransactions vão rodar de novo (sob trava) na
 * confirmação.
 */
final class CreateImportBatch
{
    private const MAX_LINES = 5000;

    public function __construct(
        private readonly IngestionPlanner $planner,
    ) {}

    /**
     * @return array{batch: ImportBatch, decisions: list<RowDecision>}
     *
     * @throws ValidationException
     */
    public function handle(Account $account, UploadedFile $file, ?ImportFormat $format): array
    {
        // Lotes pendentes antigos nunca são confirmáveis de volta pelo
        // usuário (a tela de upload sempre parte de um lote novo), então não
        // custa nada mantê-los por aí além de 24h — e um deles poderia até
        // colidir com um `external_id` sintético reaproveitado por um novo
        // upload do mesmo arquivo.
        ImportBatch::query()
            ->where('status', ImportBatchStatus::Pending)
            ->where('created_at', '<', CarbonImmutable::now()->subHours(24))
            ->delete();

        $raw = file_get_contents($file->getRealPath());
        $parsed = FormatDetector::parse($raw === false ? '' : $raw, $format, $account->isCreditCard());

        if ($parsed['format'] === null || $this->isUnrecognized($parsed['result'])) {
            throw ValidationException::withMessages([
                'file' => 'Não reconhecemos o formato deste arquivo. Escolha o banco.',
            ]);
        }

        /** @var ImportFormat $resolvedFormat */
        $resolvedFormat = $parsed['format'];
        /** @var ParseResult $result */
        $result = $parsed['result'];

        if (count($result->rows) + count($result->failed) > self::MAX_LINES) {
            throw ValidationException::withMessages([
                'file' => 'O arquivo tem mais de 5000 linhas.',
            ]);
        }

        $batch = ImportBatch::create([
            'account_id' => $account->id,
            'format' => $resolvedFormat,
            'source' => $resolvedFormat->source()->value,
            'filename' => $file->getClientOriginalName(),
            'status' => ImportBatchStatus::Pending,
            'rows' => array_map(fn (ParsedRow $row) => $row->toArray(), $result->rows),
            'stats' => ['failed' => $result->failed],
        ]);

        $decisions = $this->planner->plan($account, $result->rows);

        return ['batch' => $batch, 'decisions' => $decisions];
    }

    /**
     * Um formato forçado que não bate com o conteúdo não falha por parser
     * (que só sabe fazer uma coisa: tentar achar o próprio cabeçalho) — vira
     * exatamente a mesma falha que "cabeçalho não encontrado" de qualquer
     * outro arquivo ilegível: nenhuma linha válida, uma única falha.
     */
    private function isUnrecognized(?ParseResult $result): bool
    {
        return $result !== null
            && $result->rows === []
            && count($result->failed) === 1
            && $result->failed[0]['reason'] === 'Cabeçalho não encontrado.';
    }
}
