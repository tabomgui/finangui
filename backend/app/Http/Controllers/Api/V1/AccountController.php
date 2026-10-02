<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounts\Errors\AccountHasTransactions;
use App\Domain\Accounts\Models\Account;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class AccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = Account::query()
            ->withBalance()
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->where('is_archived', false))
            ->orderBy('is_archived')
            ->orderBy('name')
            ->get();

        return AccountResource::collection($accounts);
    }

    public function store(StoreAccountRequest $request): AccountResource
    {
        $account = Account::create($request->validated());

        return AccountResource::make($this->withBalance($account));
    }

    public function show(Account $account): AccountResource
    {
        return AccountResource::make($this->withBalance($account));
    }

    public function update(UpdateAccountRequest $request, Account $account): AccountResource
    {
        $account->update($request->validated());

        return AccountResource::make($this->withBalance($account));
    }

    public function destroy(Account $account): Response
    {
        if ($account->transactions()->exists()) {
            throw new AccountHasTransactions;
        }

        $account->delete();

        return response()->noContent();
    }

    private function withBalance(Account $account): Account
    {
        $loaded = Account::query()->withBalance()->findOrFail($account->id);
        $loaded->wasRecentlyCreated = $account->wasRecentlyCreated;

        return $loaded;
    }
}
