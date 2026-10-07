<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderBillPayment;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Enums\SyncRunStatus;
use App\Domain\Banking\Enums\SyncTrigger;
use App\Domain\Banking\Errors\ProviderAuthFailed;
use App\Domain\Banking\Errors\ProviderUnavailable;
use App\Domain\Banking\Jobs\SyncConnection;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankSyncRun;
use App\Domain\Banking\Models\BankSyncRunItem;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->fake = fakeBankProvider();
    $this->user = actingAsUser();
    $this->itemId = '00000000-0000-0000-0000-0000000000f1';
});

it('cria a run no início e fecha success sem avisos quando tudo vai bem', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $account = Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-1']);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [providerAccount(['id' => 'acc-1'])];
    $this->fake->transactionsByAccount['acc-1'] = [syncProviderTransaction(['id' => 'tx-new'])];

    runConnectionSync($connection->id, SyncTrigger::Manual);

    $run = BankSyncRun::query()->where('connection_id', $connection->id)->first();
    expect($run)->not->toBeNull()
        ->and($run->trigger)->toBe(SyncTrigger::Manual)
        ->and($run->status)->toBe(SyncRunStatus::Success)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->added_count)->toBe(1)
        ->and($run->warnings)->toBe([])
        ->and($run->error)->toBeNull();

    $item = BankSyncRunItem::query()->where('run_id', $run->id)->first();
    expect($item)->not->toBeNull()
        ->and($item->account_name)->toBe($account->name)
        ->and($item->transaction_id)->not->toBeNull();
});

it('fecha a run como error, com mensagem sem segredo nenhum, quando a Pluggy recusa as credenciais', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $this->fake->failNext(new ProviderAuthFailed);

    runConnectionSync($connection->id);

    $run = BankSyncRun::query()->where('connection_id', $connection->id)->first();
    expect($run->status)->toBe(SyncRunStatus::Error)
        ->and($run->error)->toBe('A Pluggy recusou suas credenciais. Atualize em Configurações.')
        ->and($run->error)->not->toContain('client_secret')
        ->and($run->finished_at)->not->toBeNull();
});

it('fecha a run como error quando a conexão não tem credenciais (BankingDisabled)', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    disableBankProvider();

    runConnectionSync($connection->id);

    $run = BankSyncRun::query()->where('connection_id', $connection->id)->first();
    expect($run->status)->toBe(SyncRunStatus::Error)
        ->and($run->error)->not->toBeNull();
});

it('fecha a run como error quando o banco pede reconexão (needs_reauth)', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId, 'status' => 'LOGIN_ERROR', 'errorMessage' => 'Senha incorreta.']);

    runConnectionSync($connection->id);

    $run = BankSyncRun::query()->where('connection_id', $connection->id)->first();
    // A run guarda sempre a mensagem fixa, nunca o texto livre do banco
    // (esse vai para o log e para last_error da conexão, não para a run).
    expect($run->status)->toBe(SyncRunStatus::Error)
        ->and($run->error)->toBe('O banco pediu para reconectar.')
        ->and($connection->refresh()->last_error)->toBe('Senha incorreta.');
});

it('failed(): fecha a run como error depois de esgotadas as tentativas', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);

    // Simula o estado que lockedConnection() deixaria antes de uma exceção
    // inesperada derrubar o job (ex.: timeout) — ver SyncConnection::failed().
    $job = new SyncConnection($connection->id, SyncTrigger::Scheduled);
    app()->call([$job, 'handle']);
    $run = BankSyncRun::query()->where('connection_id', $connection->id)->first();

    // Reabre a run como "running" de novo, simulando uma tentativa que
    // nunca chegou a terminar (o cenário real de failed()).
    $connection->update(['status' => ConnectionStatus::Active, 'settings' => ['sync_meta' => [
        'sync_started_at' => CarbonImmutable::now()->toIso8601String(),
        'start_status' => ConnectionStatus::Active->value,
        'sync_run_id' => $run->id,
    ]]]);
    $run->update(['status' => SyncRunStatus::Running, 'finished_at' => null]);

    (new SyncConnection($connection->id, SyncTrigger::Scheduled))->failed(new RuntimeException('timeout'));

    expect($run->refresh()->status)->toBe(SyncRunStatus::Error)
        ->and($run->error)->toBe('Não foi possível falar com o banco. Tentaremos de novo.')
        ->and($run->finished_at)->not->toBeNull();
});

it('failed(): fecha a run mesmo quando a conexão já virou needs_reauth no meio do caminho', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $run = BankSyncRun::factory()->create(['connection_id' => $connection->id]);
    $connection->update(['status' => ConnectionStatus::NeedsReauth, 'last_error' => 'Reconexão necessária.', 'settings' => ['sync_meta' => [
        'sync_started_at' => CarbonImmutable::now()->toIso8601String(),
        'start_status' => ConnectionStatus::Active->value,
        'sync_run_id' => $run->id,
    ]]]);

    (new SyncConnection($connection->id, SyncTrigger::Scheduled))->failed(new RuntimeException('timeout'));

    // A conexão continua needs_reauth (não regredida) mas a run, antes
    // presa em running para sempre, é fechada mesmo assim.
    expect($connection->refresh()->status)->toBe(ConnectionStatus::NeedsReauth)
        ->and($run->refresh()->status)->toBe(SyncRunStatus::Error)
        ->and($run->finished_at)->not->toBeNull();
});

it('failed(): fecha a run mesmo quando a conexão já foi superada por um sync mais novo', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $run = BankSyncRun::factory()->create(['connection_id' => $connection->id]);
    $connection->update(['status' => ConnectionStatus::Active, 'last_synced_at' => now()->addMinute(), 'settings' => ['sync_meta' => [
        'sync_started_at' => CarbonImmutable::now()->toIso8601String(),
        'start_status' => ConnectionStatus::Active->value,
        'sync_run_id' => $run->id,
    ]]]);

    (new SyncConnection($connection->id, SyncTrigger::Scheduled))->failed(new RuntimeException('timeout'));

    expect($connection->refresh()->last_synced_at)->not->toBeNull()
        ->and($run->refresh()->status)->toBe(SyncRunStatus::Error)
        ->and($run->finished_at)->not->toBeNull();
});

it('retry: uma segunda tentativa com o mesmo job_uuid (release/rethrow) reaproveita a mesma run, não cria outra', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $jobUuid = (string) Str::uuid();

    // settings.sync_meta já aponta para uma run `running` desta conexão,
    // com o job_uuid que a próxima tentativa (mesmo dispatch) vai repetir —
    // simula a tentativa anterior ter sido liberada de volta para a fila
    // sem fechar a run (ver SyncConnection::sync(), catch(ProviderUnavailable)
    // transitório).
    $run = BankSyncRun::factory()->create(['connection_id' => $connection->id, 'trigger' => SyncTrigger::Scheduled, 'job_uuid' => $jobUuid]);
    $connection->update(['settings' => ['sync_meta' => [
        'sync_started_at' => CarbonImmutable::now()->toIso8601String(),
        'start_status' => ConnectionStatus::Active->value,
        'sync_run_id' => $run->id,
    ]]]);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [];

    runConnectionSync($connection->id, SyncTrigger::Scheduled, $jobUuid);

    expect(BankSyncRun::query()->where('connection_id', $connection->id)->count())->toBe(1)
        ->and($run->refresh()->status)->toBe(SyncRunStatus::Success);
});

it('um dispatch novo (job_uuid diferente) nunca reaproveita uma run running de outra tentativa; fecha a antiga como interrompida', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    // Run presa: nenhum sync_meta na conexão aponta para ela (simula um
    // processo morto que nunca chamou failed() nem finishRun()).
    $stuck = BankSyncRun::factory()->create(['connection_id' => $connection->id, 'job_uuid' => (string) Str::uuid()]);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [];

    runConnectionSync($connection->id, SyncTrigger::Manual, (string) Str::uuid());

    expect($stuck->refresh()->status)->toBe(SyncRunStatus::Error)
        ->and($stuck->error)->toBe('Sincronização interrompida')
        ->and(BankSyncRun::query()->where('connection_id', $connection->id)->count())->toBe(2)
        ->and(BankSyncRun::query()->where('connection_id', $connection->id)->where('status', SyncRunStatus::Success)->count())->toBe(1);
});

it('updated_count conta transações já existentes que o banco relatou diferente', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00'));
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => '2026-09-20 10:00:00']);
    $account = Account::factory()->create([
        'user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-1',
        'provider_history_synced_at' => '2026-09-20 10:00:00',
    ]);
    Transaction::factory()->create([
        'account_id' => $account->id, 'external_id' => 'ext-changed', 'status' => 'posted',
        'source' => TransactionSource::Pluggy, 'direction' => Direction::Out, 'amount' => 5000,
        'date' => '2026-09-25', 'description' => 'Compra', 'original_description' => 'Compra',
    ]);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [providerAccount(['id' => 'acc-1'])];
    // amount não conta mais como "mudou" (nunca é sobrescrito num lançamento
    // existente) — só a descrição do banco (original_description) é o que
    // detona o update aqui.
    $this->fake->transactionsByAccount['acc-1'] = [syncProviderTransaction([
        'id' => 'ext-changed', 'date' => '2026-09-25', 'amountCents' => 5000, 'description' => 'Compra (ajustada)',
    ])];

    runConnectionSync($connection->id);

    $run = BankSyncRun::query()->where('connection_id', $connection->id)->first();
    expect($run->added_count)->toBe(0)
        ->and($run->updated_count)->toBe(1);
});

it('bills_count conta as faturas do cartão upadas neste sync', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create([
        'user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-card',
    ]);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [providerAccount(['id' => 'acc-card', 'kind' => 'credit_card'])];
    $this->fake->billsByAccount['acc-card'] = [providerBill(['id' => 'bill-1', 'dueDate' => '2026-11-10', 'closingDate' => '2026-10-31'])];

    runConnectionSync($connection->id);

    expect($card->refresh())->not->toBeNull();
    $run = BankSyncRun::query()->where('connection_id', $connection->id)->first();
    expect($run->bills_count)->toBe(1);
});

it('refresh recusado pela Pluggy vira aviso e a run fecha partial (não error)', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-1']);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId, 'lastUpdatedAt' => CarbonImmutable::now()->subMinutes(45)]);
    $this->fake->accountsByItem[$this->itemId] = [providerAccount(['id' => 'acc-1'])];
    $wrapped = providerFailingOn($this->fake, 'refreshItem', new ProviderUnavailable('limite de refresh'));
    fakeBankProvider($wrapped);

    runConnectionSync($connection->id, SyncTrigger::Manual);

    $run = BankSyncRun::query()->where('connection_id', $connection->id)->first();
    expect($run->status)->toBe(SyncRunStatus::Partial)
        ->and($run->warnings)->toHaveCount(1)
        ->and($run->error)->toBeNull();
});

it('pagamentos duplicados ignorados pela reconciliação viram aviso; pagamentos/transferências normais só em stats', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-20'));
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'conta-1']);
    Account::factory()->creditCard(closingDay: 5, dueDay: 12)->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'cartao-1']);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [
        providerAccount(['id' => 'conta-1', 'balanceCents' => 200000]),
        providerAccount(['id' => 'cartao-1', 'kind' => 'credit_card', 'balanceCents' => 45000, 'creditLimitCents' => 500000]),
    ];
    $this->fake->billsByAccount['cartao-1'] = [
        providerBill([
            'id' => 'fatura-anterior', 'closingDate' => '2026-04-05', 'dueDate' => '2026-04-12', 'totalCents' => 58000,
            'payments' => [new ProviderBillPayment('pagto-1', '2026-04-14', 58000)],
        ]),
    ];
    $this->fake->transactionsByAccount['cartao-1'] = [
        syncProviderTransaction([
            'id' => 'credito-1', 'date' => '2026-04-14', 'amountCents' => 58000, 'direction' => Direction::In,
            'description' => 'Pagamento recebido', 'billId' => 'fatura-anterior',
        ]),
        syncProviderTransaction([
            'id' => 'credito-2', 'date' => '2026-04-14', 'amountCents' => 58000, 'direction' => Direction::In,
            'description' => 'Pagto debito automatico', 'billId' => 'fatura-anterior',
        ]),
    ];
    $this->fake->transactionsByAccount['conta-1'] = [
        syncProviderTransaction(['id' => 'debito-1', 'date' => '2026-04-14', 'amountCents' => 58000, 'direction' => Direction::Out, 'description' => 'Pagamento de fatura cartao']),
    ];

    runConnectionSync($connection->id);

    $run = BankSyncRun::query()->where('connection_id', $connection->id)->first();
    expect($run->stats['payments_recognized'])->toBe(1)
        ->and($run->stats['duplicates_ignored'])->toBe(1)
        ->and($run->warnings)->toHaveCount(1)
        ->and($run->warnings[0])->toContain('duplicado')
        ->and($run->status)->toBe(SyncRunStatus::Partial);
});

it('limita os itens com snapshot a 500, mas added_count conta tudo', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-1']);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [providerAccount(['id' => 'acc-1'])];
    $this->fake->transactionsByAccount['acc-1'] = array_map(
        fn (int $i) => syncProviderTransaction(['id' => "tx-many-{$i}", 'date' => '2026-09-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT)]),
        range(1, 501),
    );

    runConnectionSync($connection->id);

    $run = BankSyncRun::query()->where('connection_id', $connection->id)->first();
    expect($run->added_count)->toBe(501)
        ->and(BankSyncRunItem::query()->where('run_id', $run->id)->count())->toBe(500);
})->group('slow');

it('added_count/updated_count acumulam entre tentativas de um mesmo retry, não são sobrescritos', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-1']);
    $jobUuid = (string) Str::uuid();

    // Simula uma run que já tinha acumulado progresso de uma conta
    // sincronizada numa tentativa anterior deste mesmo retry (processo
    // morto antes de terminar as demais contas).
    $run = BankSyncRun::factory()->create([
        'connection_id' => $connection->id, 'job_uuid' => $jobUuid,
        'added_count' => 2, 'updated_count' => 1,
    ]);
    $connection->update(['settings' => ['sync_meta' => [
        'sync_started_at' => CarbonImmutable::now()->toIso8601String(),
        'start_status' => ConnectionStatus::Active->value,
        'sync_run_id' => $run->id,
    ]]]);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [providerAccount(['id' => 'acc-1'])];
    $this->fake->transactionsByAccount['acc-1'] = [syncProviderTransaction(['id' => 'tx-retry-new'])];

    runConnectionSync($connection->id, SyncTrigger::Scheduled, $jobUuid);

    // 2 (já persistidos) + 1 (esta tentativa) = 3, nunca só 1.
    expect($run->refresh()->added_count)->toBe(3)
        ->and($run->updated_count)->toBe(1);
});

it('added_count e os itens excluem as parcelas futuras projetadas localmente (source installment)', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    Account::factory()->creditCard(closingDay: 20, dueDay: 28)->create([
        'user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-card-installment',
    ]);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [providerAccount(['id' => 'acc-card-installment', 'kind' => 'credit_card'])];
    // Parcela 1 de 3: o sync lança a 1 e projeta localmente a 2 e a 3
    // (App\Domain\Imports\Actions\ProjectInstallments::projectRemaining(),
    // source = installment) — nenhuma das duas veio do banco agora.
    $this->fake->transactionsByAccount['acc-card-installment'] = [syncProviderTransaction([
        'id' => 'ext-parcel-1', 'date' => '2026-01-15', 'amountCents' => 10000,
        'installment' => ['number' => 1, 'total' => 3],
    ])];

    runConnectionSync($connection->id);

    $run = BankSyncRun::query()->where('connection_id', $connection->id)->first();
    expect($run->added_count)->toBe(1)
        ->and(BankSyncRunItem::query()->where('run_id', $run->id)->count())->toBe(1);
});

it('isola runs por usuário', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [];

    runConnectionSync($connection->id);

    $other = actingAsUser();
    expect(BankSyncRun::query()->count())->toBe(0);

    Auth::login($this->user);
    expect(BankSyncRun::query()->count())->toBe(1);
});
