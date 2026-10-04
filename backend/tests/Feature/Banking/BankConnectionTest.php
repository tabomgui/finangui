<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Models\BankConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->user = actingAsUser();
});

it('nasce pending_link por padrão, sem precisar de refresh', function () {
    $connection = BankConnection::factory()->create();

    expect($connection->status)->toBe(ConnectionStatus::PendingLink)
        ->and($connection->provider)->toBe(BankProviderName::Pluggy);
});

it('casts: status, provider e last_synced_at', function () {
    $connection = BankConnection::factory()->active()->create(['last_synced_at' => '2026-03-07 10:00:00']);

    expect($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->last_synced_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('settings é guardado como array', function () {
    $connection = BankConnection::factory()->create(['settings' => ['foo' => 'bar']]);

    expect($connection->fresh()->settings)->toBe(['foo' => 'bar']);
});

it('accounts() lista as contas vinculadas à conexão', function () {
    $connection = BankConnection::factory()->create();
    $linked = Account::factory()->create(['connection_id' => $connection->id, 'external_id' => 'acc-1']);
    Account::factory()->create();

    expect($connection->accounts()->pluck('id')->all())->toBe([$linked->id]);
});

it('é isolado por usuário (BelongsToUser)', function () {
    $other = User::factory()->create();
    BankConnection::factory()->create(['user_id' => $other->id]);
    $mine = BankConnection::factory()->create(['user_id' => $this->user->id]);

    expect(BankConnection::all()->pluck('id')->all())->toBe([$mine->id]);
});

it('external_id é único por provedor', function () {
    BankConnection::factory()->create(['provider' => BankProviderName::Pluggy, 'external_id' => 'item-1']);

    expect(fn () => BankConnection::factory()->create(['provider' => BankProviderName::Pluggy, 'external_id' => 'item-1']))
        ->toThrow(QueryException::class);
});

it('duas contas sem vínculo (connection_id/external_id null) nunca colidem no unique', function () {
    // SQL trata NULL como distinto de NULL: o unique de
    // ['connection_id', 'external_id'] não impede duas contas manuais.
    Account::factory()->create();
    Account::factory()->create();

    expect(Account::count())->toBe(2);
});

it('a mesma conexão não pode repetir external_id em duas contas', function () {
    $connection = BankConnection::factory()->create();
    Account::factory()->create(['connection_id' => $connection->id, 'external_id' => 'acc-1']);

    expect(fn () => Account::factory()->create(['connection_id' => $connection->id, 'external_id' => 'acc-1']))
        ->toThrow(QueryException::class);
});
