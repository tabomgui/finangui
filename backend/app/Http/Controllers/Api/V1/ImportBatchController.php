<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounts\Models\Account;
use App\Domain\Imports\Actions\CancelImportBatch;
use App\Domain\Imports\Actions\ConfirmImportBatch;
use App\Domain\Imports\Actions\CreateImportBatch;
use App\Domain\Imports\Actions\RevertImportBatch;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Queries\ImportPreview;
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
    private const LIST_LIMIT = 50;

    public function index(IndexImportBatchesRequest $request): AnonymousResourceCollection
    {
        $batches = ImportBatch::query()
            ->select(['id', 'user_id', 'account_id', 'format', 'filename', 'status', 'stats', 'created_at', 'completed_at', 'reverted_at'])
            ->with('account:id,name')
            ->when($request->filled('account_id'), fn ($q) => $q->where('account_id', $request->integer('account_id')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        return ImportBatchResource::collection($batches);
    }

    public function store(StoreImportBatchRequest $request, CreateImportBatch $createImportBatch, ImportPreview $preview): JsonResponse
    {
        $data = $request->validated();
        $account = Account::query()->findOrFail($data['account_id']);
        $format = isset($data['format']) ? ImportFormat::from($data['format']) : null;

        $batch = $createImportBatch->handle($account, $request->file('file'), $format);

        return ImportPreviewResource::make($preview->for($batch))->response()->setStatusCode(201);
    }

    public function show(ImportBatch $batch, ImportPreview $preview): ImportPreviewResource
    {
        return ImportPreviewResource::make($preview->for($batch));
    }

    public function confirm(ConfirmImportBatchRequest $request, ImportBatch $batch, ConfirmImportBatch $confirmImportBatch): ImportBatchResource
    {
        /** @var list<int> $skipLines */
        $skipLines = $request->validated('skip_lines') ?? [];

        $confirmed = $confirmImportBatch->handle($batch, $skipLines);

        return ImportBatchResource::make($confirmed->load('account'));
    }

    public function destroy(ImportBatch $batch, CancelImportBatch $cancelImportBatch): Response
    {
        $cancelImportBatch->handle($batch);

        return response()->noContent();
    }

    public function revert(ImportBatch $batch, RevertImportBatch $revertImportBatch): ImportBatchResource
    {
        $revertImportBatch->handle($batch);

        return ImportBatchResource::make($batch->refresh()->load('account'));
    }
}
