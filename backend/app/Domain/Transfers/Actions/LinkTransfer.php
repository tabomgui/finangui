<?php

namespace App\Domain\Transfers\Actions;

use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Errors\TransferLinkInvalid;
use App\Domain\Transfers\Models\TransferSuggestion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Única porta para ligar duas transações existentes como as duas pernas de
 * uma transferência: usada pela detecção automática, por aceitar sugestão e
 * por "juntar à mão".
 */
final class LinkTransfer
{
    public function __construct(private readonly AssignStatement $assignStatement) {}

    /**
     * @return string o transfer_id novo
     *
     * @throws TransferLinkInvalid
     * @throws ModelNotFoundException<Transaction>
     */
    public function handle(Transaction $out, Transaction $in, int $maxDays = 2): string
    {
        return DB::transaction(function () use ($out, $in, $maxDays) {
            /** @var Collection<int, Transaction> $legs */
            $legs = Transaction::query()->whereKey([$out->id, $in->id])->lockForUpdate()->get()->keyBy('id');

            $lockedOut = $legs->get($out->id);
            $lockedIn = $legs->get($in->id);

            if ($lockedOut === null || $lockedIn === null) {
                throw (new ModelNotFoundException)->setModel(Transaction::class, [$out->id, $in->id]);
            }

            $this->assertLinkable($lockedOut, $lockedIn, $maxDays);

            $transferId = (string) Str::uuid();

            $lockedOut->transfer_id = $transferId;
            $lockedOut->category_id = null;
            $lockedOut->categorized_by = null;
            $lockedOut->save();

            $lockedIn->transfer_id = $transferId;
            $lockedIn->category_id = null;
            $lockedIn->categorized_by = null;
            $this->assignStatement->handle($lockedIn);
            $lockedIn->save();

            TransferSuggestion::query()
                ->where('status', TransferSuggestionStatus::Pending)
                ->where(fn ($query) => $query
                    ->whereIn('out_transaction_id', [$lockedOut->id, $lockedIn->id])
                    ->orWhereIn('in_transaction_id', [$lockedOut->id, $lockedIn->id]))
                ->delete();

            return $transferId;
        });
    }

    /**
     * @throws TransferLinkInvalid
     */
    private function assertLinkable(Transaction $out, Transaction $in, int $maxDays): void
    {
        if ($out->isTransferLeg() || $in->isTransferLeg()) {
            throw new TransferLinkInvalid;
        }

        if ($out->direction !== Direction::Out || $in->direction !== Direction::In) {
            throw new TransferLinkInvalid;
        }

        if ($out->account_id === $in->account_id) {
            throw new TransferLinkInvalid;
        }

        if (! $out->amount->equals($in->amount)) {
            throw new TransferLinkInvalid;
        }

        if ($out->currency !== $in->currency) {
            throw new TransferLinkInvalid;
        }

        if (abs($out->date->diffInDays($in->date)) > $maxDays) {
            throw new TransferLinkInvalid;
        }
    }
}
