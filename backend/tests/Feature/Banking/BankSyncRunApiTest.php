<?php

use App\Domain\Banking\Enums\SyncRunStatus;
use App\Domain\Banking\Enums\SyncTrigger;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankSyncRun;
use App\Domain\Banking\Models\BankSyncRunItem;
use App\Models\User;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id]);
});

it('lista as runs de uma conexão, mais recente primeiro', function () {
    $older = BankSyncRun::factory()->success()->create(['connection_id' => $this->connection->id, 'trigger' => SyncTrigger::Scheduled]);
    $this->travelTo(now()->addMinute());
    $newer = BankSyncRun::factory()->success()->create(['connection_id' => $this->connection->id, 'trigger' => SyncTrigger::Manual, 'added_count' => 3]);

    $response = $this->getJson("/api/v1/bank-connections/{$this->connection->id}/sync-runs")->assertOk()->json('data');

    expect($response)->toHaveCount(2)
        ->and($response[0]['id'])->toBe($newer->id)
        ->and($response[0]['trigger'])->toBe('manual')
        ->and($response[0]['added_count'])->toBe(3)
        ->and($response[1]['id'])->toBe($older->id);
});

it('pagina por cursor', function () {
    foreach (range(1, 3) as $i) {
        BankSyncRun::factory()->success()->create(['connection_id' => $this->connection->id]);
    }

    $first = $this->getJson("/api/v1/bank-connections/{$this->connection->id}/sync-runs?per_page=2")->assertOk()->json();

    expect($first['data'])->toHaveCount(2)
        ->and($first['links']['next'])->not->toBeNull();

    $second = $this->getJson($first['links']['next'])->assertOk()->json('data');
    expect($second)->toHaveCount(1);
});

it('omite finished_at, provider_updated_at e error quando a run ainda está running', function () {
    BankSyncRun::factory()->create(['connection_id' => $this->connection->id]);

    $response = $this->getJson("/api/v1/bank-connections/{$this->connection->id}/sync-runs")->assertOk()->json('data');

    expect($response[0])->not->toHaveKey('finished_at')
        ->and($response[0])->not->toHaveKey('provider_updated_at')
        ->and($response[0])->not->toHaveKey('error')
        ->and($response[0]['status'])->toBe('running')
        ->and($response[0]['warnings'])->toBe([]);
});

it('uma conexão de outro usuário dá 404', function () {
    $otherUser = User::factory()->create();
    $other = BankConnection::factory()->active()->create(['user_id' => $otherUser->id]);

    $this->getJson("/api/v1/bank-connections/{$other->id}/sync-runs")->assertNotFound();
});

it('detalhe traz os itens adicionados', function () {
    $run = BankSyncRun::factory()->success()->create(['connection_id' => $this->connection->id, 'added_count' => 2]);
    $item1 = BankSyncRunItem::factory()->create(['run_id' => $run->id, 'account_name' => 'Conta A', 'description' => 'Compra 1']);
    $item2 = BankSyncRunItem::factory()->create(['run_id' => $run->id, 'account_name' => 'Conta A', 'description' => 'Compra 2']);

    $response = $this->getJson("/api/v1/bank-sync-runs/{$run->id}")->assertOk()->json('data');

    expect($response['id'])->toBe($run->id)
        ->and($response['items'])->toHaveCount(2)
        ->and($response['items_truncated'])->toBeFalse()
        ->and(collect($response['items'])->pluck('description'))->toContain('Compra 1', 'Compra 2');
});

it('items_truncated fica true quando added_count passa do que foi de fato gravado com snapshot', function () {
    $run = BankSyncRun::factory()->success()->create(['connection_id' => $this->connection->id, 'added_count' => 5]);
    BankSyncRunItem::factory()->create(['run_id' => $run->id]);

    $response = $this->getJson("/api/v1/bank-sync-runs/{$run->id}")->assertOk()->json('data');

    expect($response['items_truncated'])->toBeTrue();
});

it('transaction_id fica omitido quando a transação foi excluída (nullOnDelete)', function () {
    $run = BankSyncRun::factory()->success()->create(['connection_id' => $this->connection->id]);
    BankSyncRunItem::factory()->create(['run_id' => $run->id, 'transaction_id' => null]);

    $response = $this->getJson("/api/v1/bank-sync-runs/{$run->id}")->assertOk()->json('data');

    expect($response['items'][0])->not->toHaveKey('transaction_id');
});

it('uma run de outro usuário dá 404', function () {
    $otherUser = User::factory()->create();
    $otherConnection = BankConnection::factory()->active()->create(['user_id' => $otherUser->id]);
    $other = BankSyncRun::factory()->success()->create(['connection_id' => $otherConnection->id, 'user_id' => $otherUser->id]);

    $this->getJson("/api/v1/bank-sync-runs/{$other->id}")->assertNotFound();
});

it('fecha run status error também aparece corretamente no detalhe', function () {
    $run = BankSyncRun::factory()->error('A Pluggy recusou suas credenciais.')->create(['connection_id' => $this->connection->id]);

    $response = $this->getJson("/api/v1/bank-sync-runs/{$run->id}")->assertOk()->json('data');

    expect($response['status'])->toBe(SyncRunStatus::Error->value)
        ->and($response['error'])->toBe('A Pluggy recusou suas credenciais.');
});

it('devolve stats quando presente, e omite quando null', function () {
    $withStats = BankSyncRun::factory()->success()->create([
        'connection_id' => $this->connection->id,
        'stats' => ['payments_recognized' => 2, 'duplicates_ignored' => 0, 'transfers_linked' => 1],
    ]);
    $withoutStats = BankSyncRun::factory()->success()->create(['connection_id' => $this->connection->id, 'stats' => null]);

    $withStatsResponse = $this->getJson("/api/v1/bank-sync-runs/{$withStats->id}")->assertOk()->json('data');
    $withoutStatsResponse = $this->getJson("/api/v1/bank-sync-runs/{$withoutStats->id}")->assertOk()->json('data');

    expect($withStatsResponse['stats']['payments_recognized'])->toBe(2)
        ->and($withStatsResponse['stats']['duplicates_ignored'])->toBe(0)
        ->and($withStatsResponse['stats']['transfers_linked'])->toBe(1)
        ->and($withoutStatsResponse)->not->toHaveKey('stats');
});

it('os itens do detalhe vêm ordenados por data, depois id', function () {
    $run = BankSyncRun::factory()->success()->create(['connection_id' => $this->connection->id]);
    $later = BankSyncRunItem::factory()->create(['run_id' => $run->id, 'date' => '2026-09-10', 'description' => 'Depois']);
    $earlierA = BankSyncRunItem::factory()->create(['run_id' => $run->id, 'date' => '2026-09-01', 'description' => 'Antes A']);
    $earlierB = BankSyncRunItem::factory()->create(['run_id' => $run->id, 'date' => '2026-09-01', 'description' => 'Antes B']);

    $response = $this->getJson("/api/v1/bank-sync-runs/{$run->id}")->assertOk()->json('data');

    expect(collect($response['items'])->pluck('id')->all())->toBe([$earlierA->id, $earlierB->id, $later->id]);
});
