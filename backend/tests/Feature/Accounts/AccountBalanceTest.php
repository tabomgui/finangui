<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;

it('saldo = saldo inicial + entradas - saídas lançadas e não ignoradas', function () {
    actingAsUser();
    $account = Account::factory()->create(['opening_balance' => 100000]);

    Transaction::factory()->for($account)->income()->create(['amount' => 50000]);
    Transaction::factory()->for($account)->create(['amount' => 20000]);
    Transaction::factory()->for($account)->create(['amount' => 99999, 'is_ignored' => true]);
    Transaction::factory()->for($account)->create(['amount' => 99999, 'status' => TransactionStatus::Projected]);
    Transaction::factory()->for($account)->create(['amount' => 99999, 'status' => TransactionStatus::Pending]);

    $this->getJson("/api/v1/accounts/{$account->id}")->assertJsonPath('data.balance', 130000);
    $this->getJson('/api/v1/accounts')->assertJsonPath('data.0.balance', 130000);
});

it('conta sem transações tem saldo igual ao inicial', function () {
    actingAsUser();
    $account = Account::factory()->create(['opening_balance' => -5000]);

    $this->getJson("/api/v1/accounts/{$account->id}")->assertJsonPath('data.balance', -5000);
});

it('transferência move saldo entre as contas', function () {
    actingAsUser();
    $inter = Account::factory()->create(['name' => 'A', 'opening_balance' => 100000]);
    $nubank = Account::factory()->create(['name' => 'B', 'opening_balance' => 0]);

    $this->postJson('/api/v1/transfers', [
        'from_account_id' => $inter->id, 'to_account_id' => $nubank->id,
        'date' => '2026-10-01', 'amount' => 30000, 'description' => 'Reserva',
    ])->assertCreated();

    $this->getJson('/api/v1/accounts')
        ->assertJsonPath('data.0.balance', 70000)
        ->assertJsonPath('data.1.balance', 30000);
});

it('devolve o saldo ao criar e editar conta', function () {
    actingAsUser();

    $id = $this->postJson('/api/v1/accounts', ['name' => 'Inter', 'type' => 'checking', 'opening_balance' => 1000])
        ->assertJsonPath('data.balance', 1000)
        ->json('data.id');

    $this->patchJson("/api/v1/accounts/{$id}", ['opening_balance' => 2000])->assertJsonPath('data.balance', 2000);
});

it('cartão conectado ignora o saldo do banco e usa o razão completo, sem corte por dia', function () {
    actingAsUser();
    $connection = BankConnection::factory()->active()->create();
    $card = Account::factory()->creditCard()->create([
        'opening_balance' => -2000, 'connection_id' => $connection->id,
        'provider_balance' => -999999, 'provider_synced_at' => now(),
    ]);
    Transaction::factory()->for($card)->create(['amount' => 500]);

    $this->getJson("/api/v1/accounts/{$card->id}")->assertJsonPath('data.balance', -2500);
});

describe('conta conectada', function () {
    beforeEach(function () {
        actingAsUser();
        $this->travelTo('2026-10-15');
        $this->connection = BankConnection::factory()->active()->create();
    });

    function connectedAccount(array $overrides = []): Account
    {
        return Account::factory()->create([
            'opening_balance' => 0,
            'connection_id' => test()->connection->id,
            'provider_balance' => 100000,
            'provider_synced_at' => now(),
            ...$overrides,
        ]);
    }

    it('usa o saldo do banco hoje, ignorando lançamentos já refletidos nele', function () {
        $account = connectedAccount();
        Transaction::factory()->for($account)->create(['date' => '2026-10-10', 'amount' => 500]);

        $this->getJson("/api/v1/accounts/{$account->id}")->assertJsonPath('data.balance', 100000);
        $this->getJson('/api/v1/accounts')->assertJsonPath('data.0.balance', 100000);
    });

    it('lançamento manual pendente, projetado ou ignorado depois do dia nunca entra no saldo', function () {
        $account = connectedAccount();
        Transaction::factory()->for($account)->create(['date' => '2026-10-20', 'amount' => 1, 'status' => TransactionStatus::Pending]);
        Transaction::factory()->for($account)->create(['date' => '2026-10-20', 'amount' => 2, 'status' => TransactionStatus::Projected]);
        Transaction::factory()->for($account)->create(['date' => '2026-10-20', 'amount' => 3, 'is_ignored' => true]);

        $this->getJson("/api/v1/accounts/{$account->id}")->assertJsonPath('data.balance', 100000);
    });

    it('lançamento ignorado que veio do próprio banco entra no saldo, diferente de um ignorado manual', function () {
        $account = connectedAccount(['provider_synced_at' => '2026-10-10']);
        Transaction::factory()->for($account)->income()->create([
            'date' => '2026-10-12', 'amount' => 700, 'is_ignored' => true, 'source' => TransactionSource::Pluggy,
        ]); // estorno automático do banco, ignorado no app mas já contado pelo banco
        Transaction::factory()->for($account)->create([
            'date' => '2026-10-12', 'amount' => 400, 'is_ignored' => true,
        ]); // ignorado manual: nunca conta, nem pro banco nem pro razão

        $this->getJson("/api/v1/accounts/{$account->id}")->assertJsonPath('data.balance', 100700);
    });

    it('lançamento manual com data futura não muda o saldo de hoje, mesmo com sync de hoje', function () {
        $account = connectedAccount();
        Transaction::factory()->for($account)->create(['date' => '2026-10-20', 'amount' => 500]);

        $this->getJson("/api/v1/accounts/{$account->id}")->assertJsonPath('data.balance', 100000);
    });

    it('sincronização desatualizada soma os lançamentos feitos depois dela até hoje', function () {
        $account = connectedAccount(['provider_synced_at' => '2026-10-10']);
        Transaction::factory()->for($account)->create(['date' => '2026-10-12', 'amount' => 300]); // depois do sync, antes de hoje
        Transaction::factory()->for($account)->income()->create(['date' => '2026-10-14', 'amount' => 50]);

        $this->getJson("/api/v1/accounts/{$account->id}")->assertJsonPath('data.balance', 99750); // 100000 - 300 + 50
    });

    it('calcula o saldo de um dia passado descontando os lançamentos depois dele', function () {
        $account = connectedAccount();
        Transaction::factory()->for($account)->create(['date' => '2026-10-12', 'amount' => 300]);
        Transaction::factory()->for($account)->income()->create(['date' => '2026-10-11', 'amount' => 150]);

        $this->getJson('/api/v1/dashboard?month=2026-10&date=2026-10-10')
            ->assertOk()
            ->assertJsonPath('data.balance_date', '2026-10-10')
            ->assertJsonPath('data.total_balance', 100150); // 100000 - (-300 + 150)
    });

    it('usa o fim do mês como padrão quando o mês consultado já passou', function () {
        $account = connectedAccount();
        // Despesa depois do fim de setembro, mas antes do sync de hoje: já está refletida no
        // saldo do banco, então calcular o saldo em 30/09 precisa "devolver" esse valor.
        Transaction::factory()->for($account)->create(['date' => '2026-10-05', 'amount' => 500]);

        $this->getJson('/api/v1/dashboard?month=2026-09')
            ->assertOk()
            ->assertJsonPath('data.balance_date', '2026-09-30')
            ->assertJsonPath('data.total_balance', 100500);
    });

    it('aceita date igual a hoje', function () {
        connectedAccount();

        $this->getJson('/api/v1/dashboard?date=2026-10-15')->assertOk()->assertJsonPath('data.balance_date', '2026-10-15');
    });

    it('depois de desconectar, a conta volta a usar o razão interno', function () {
        $account = connectedAccount();
        Transaction::factory()->for($account)->create(['date' => '2026-10-10', 'amount' => 500]); // nunca contou pro saldo do banco

        $this->deleteJson("/api/v1/bank-connections/{$this->connection->id}")->assertNoContent();

        // Sem conexão, o saldo passa a ser opening_balance + lançamentos: 0 - 500.
        $this->getJson("/api/v1/accounts/{$account->id}")->assertJsonPath('data.balance', -500);
    });
});
