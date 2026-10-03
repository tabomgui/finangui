<?php

namespace App\Http\Resources;

use App\Domain\Banking\Data\ProviderAccountSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProviderAccountSuggestion
 */
final class ProviderAccountResource extends JsonResource
{
    public function __construct(ProviderAccountSuggestion $suggestion)
    {
        parent::__construct($suggestion);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProviderAccountSuggestion $suggestion */
        $suggestion = $this->resource;
        $account = $suggestion->account;

        return [
            'external_id' => $account->id,
            'name' => $account->name,
            'number' => $account->number,
            'kind' => $account->kind,
            'currency' => $account->currency,
            'balance' => $account->balanceCents,
            'suggested_account_id' => $suggestion->suggestedAccountId,
        ];
    }
}
