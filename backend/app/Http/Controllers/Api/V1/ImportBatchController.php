<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounts\Models\Account;
use App\Domain\Imports\Actions\ConfirmImportBatch;
use App\Domain\Imports\Actions\CreateImportBatch;
use App\Domain\Imports\Actions\RevertImportBatch;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Enums\RowOutcome;
use App\Domain\Imports\Errors\ImportBatchNotPending;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Support\IngestionPlanner;
use App\Domain\Rules\Actions\CategorizeTransaction;
use App\Domain\Transactions\Models\Transaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\ConfirmImportBatchRequest;
use App\Http\Requests\Imports\IndexImportBatchesRequest;
use App\Http\Requests\Imports\StoreImportBatchRequest;
use App\Http\Resources\ImportBatchResource;
use App\Http\Resources\ImportPreviewResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class ImportBatchController extends Controller
{
    public function index(IndexImportBatchesRequest $request): AnonymousResourceCollection
    {
        $batches = ImportBatch::query()
            ->with('account')
            ->when($request->filled('account_id'), fn ($q) => $q->where('account_id', $request->integer('account_id')))
            ->orderByDesc('created_at')
            ->get();

        return ImportBatchResource::collection($batches);
    }

    public function store(StoreImportBatchRequest $request, CreateImportBatch $createImportBatch, CategorizeTransaction $categorize): JsonResponse
    {
        $data = $request->validated();
        $account = Account::query()->findOrFail($data['account_id']);
        $format = isset($data['format']) ? ImportFormat::from($data['format']) : null;

        ['batch' => $batch, 'decisions' => $decisions] = $createImportBatch->handle($account, $request->file('file'), $format);
        $batch->setRelation('account', $account);

        return ImportPreviewResource::make(
            $batch,
            $decisions,
            $this->suggestions($account, $decisions, $categorize),
            $this->matches($decisions),
        )->response()->setStatusCode(201);
    }

    public function show(ImportBatch $batch, IngestionPlanner $planner, CategorizeTransaction $categorize): ImportBatchResource|ImportPreviewResource
    {
        $batch->load('account');

        if ($batch->status !== ImportBatchStatus::Pending) {
            return ImportBatchResource::make($batch);
        }

        /** @var list<array<string, mixed>> $storedRows */
        $storedRows = $batch->rows ?? [];
        $rows = array_map(ParsedRow::fromArray(...), $storedRows);
        $decisions = $planner->plan($batch->account, $rows);

        return ImportPreviewResource::make(
            $batch,
            $decisions,
            $this->suggestions($batch->account, $decisions, $categorize),
            $this->matches($decisions),
        );
    }

    public function confirm(ConfirmImportBatchRequest $request, ImportBatch $batch, ConfirmImportBatch $confirmImportBatch): ImportBatchResource
    {
        /** @var list<int> $skipLines */
        $skipLines = $request->validated('skip_lines') ?? [];

        $confirmed = $confirmImportBatch->handle($batch, $skipLines);

        return ImportBatchResource::make($confirmed->load('account'));
    }

    public function destroy(ImportBatch $batch): Response
    {
        if ($batch->status !== ImportBatchStatus::Pending) {
            throw new ImportBatchNotPending;
        }

        $batch->delete();

        return response()->noContent();
    }

    public function revert(ImportBatch $batch, RevertImportBatch $revertImportBatch): ImportBatchResource
    {
        $revertImportBatch->handle($batch);

        return ImportBatchResource::make($batch->refresh()->load('account'));
    }

    /**
     * Categoria sugerida por linha (chave = número da linha), só para
     * decisões "new": monta uma transação equivalente à que seria criada,
     * sem salvar, e reaproveita o mesmo pipeline de sugestão da confirmação.
     *
     * @param  list<RowDecision>  $decisions
     * @return array<int, int>
     */
    private function suggestions(Account $account, array $decisions, CategorizeTransaction $categorize): array
    {
        $suggestions = [];

        foreach ($decisions as $decision) {
            if ($decision->outcome !== RowOutcome::New) {
                continue;
            }

            $row = $decision->row;

            $transaction = new Transaction([
                'account_id' => $account->id,
                'date' => $row->date,
                'amount' => $row->amount,
                'direction' => $row->direction,
                'currency' => $account->currency,
                'description' => $row->description,
                'original_description' => $row->description,
            ]);

            $suggestion = $categorize->suggest($transaction);

            if ($suggestion !== null) {
                $suggestions[$row->line] = $suggestion['category_id'];
            }
        }

        return $suggestions;
    }

    /**
     * Dados (id, data, descrição) das transações que decisões diferentes de
     * "new" apontam, numa única consulta.
     *
     * @param  list<RowDecision>  $decisions
     * @return array<int, array{id: int, date: string, description: string}>
     */
    private function matches(array $decisions): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn (RowDecision $decision) => $decision->transactionId, $decisions),
        )));

        if ($ids === []) {
            return [];
        }

        return Transaction::query()->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Transaction $transaction) => [$transaction->id => [
                'id' => $transaction->id,
                'date' => $transaction->date->toDateString(),
                'description' => $transaction->description,
            ]])
            ->all();
    }
}
