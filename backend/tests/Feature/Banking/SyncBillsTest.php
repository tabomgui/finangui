<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Actions\SyncBills;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderBillPayment;
use App\Domain\Cards\Models\CardStatement;

beforeEach(function () {
    actingAsUser();
    $this->action = app(SyncBills::class);
    $this->card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create();
});

function providerBill(array $overrides = []): ProviderBill
{
    return new ProviderBill(
        id: $overrides['id'] ?? 'bill-1',
        dueDate: $overrides['dueDate'] ?? '2026-04-20',
        closingDate: array_key_exists('closingDate', $overrides) ? $overrides['closingDate'] : '2026-04-10',
        totalCents: $overrides['totalCents'] ?? 50000,
        payments: $overrides['payments'] ?? [],
    );
}

it('cria uma fatura nova com as datas do banco', function () {
    $this->action->handle($this->card, [providerBill()]);

    $statement = CardStatement::query()->where('account_id', $this->card->id)->first();
    expect($statement->external_id)->toBe('bill-1')
        ->and($statement->closing_date->toDateString())->toBe('2026-04-10')
        ->and($statement->due_date->toDateString())->toBe('2026-04-20')
        ->and($statement->reported_total->cents)->toBe(50000);
});

it('adota uma fatura local com o mesmo vencimento, sem external_id', function () {
    $local = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20',
    ]);

    $this->action->handle($this->card, [providerBill(['totalCents' => 77700])]);

    $local->refresh();
    expect($local->external_id)->toBe('bill-1')
        ->and($local->reported_total->cents)->toBe(77700)
        ->and(CardStatement::query()->where('account_id', $this->card->id)->count())->toBe(1);
});

it('closing_date ausente usa a nominal do InvoiceCycle para o vencimento', function () {
    $this->action->handle($this->card, [providerBill(['closingDate' => null, 'dueDate' => '2026-05-20'])]);

    $statement = CardStatement::query()->where('account_id', $this->card->id)->first();
    expect($statement->closing_date->toDateString())->toBe('2026-05-10');
});

it('datas novas que quebrariam a ordem com as vizinhas não são aplicadas, mas external_id e reported_total sim', function () {
    $previous = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-20']);
    $existing = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20', 'external_id' => 'bill-1']);
    $next = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-05-10', 'due_date' => '2026-05-20']);

    // O banco informa um fechamento que colidiria com a fatura anterior.
    $this->action->handle($this->card, [providerBill(['closingDate' => '2026-03-05', 'totalCents' => 99900])]);

    $existing->refresh();
    expect($existing->closing_date->toDateString())->toBe('2026-04-10')
        ->and($existing->due_date->toDateString())->toBe('2026-04-20')
        ->and($existing->external_id)->toBe('bill-1')
        ->and($existing->reported_total->cents)->toBe(99900);

    expect($previous->closing_date->toDateString())->toBe('2026-03-10')
        ->and($next->closing_date->toDateString())->toBe('2026-05-10');
});

it('datas que cabem entre as vizinhas são aplicadas', function () {
    $existing = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-11', 'due_date' => '2026-04-21', 'external_id' => 'bill-1']);

    $this->action->handle($this->card, [providerBill(['closingDate' => '2026-04-10', 'dueDate' => '2026-04-20'])]);

    $existing->refresh();
    expect($existing->closing_date->toDateString())->toBe('2026-04-10')
        ->and($existing->due_date->toDateString())->toBe('2026-04-20');
});

it('upsert pelo external_id em syncs seguintes só atualiza o total quando as datas não mudam', function () {
    $existing = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20', 'external_id' => 'bill-1', 'reported_total' => 10000]);

    $this->action->handle($this->card, [providerBill(['totalCents' => 20000])]);

    $existing->refresh();
    expect($existing->reported_total->cents)->toBe(20000)
        ->and($existing->closing_date->toDateString())->toBe('2026-04-10');
});

it('adota a fatura local mais próxima por vencimento (até 7 dias), mesmo sem o mesmo fechamento', function () {
    $local = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-08', 'due_date' => '2026-04-23']);

    $this->action->handle($this->card, [providerBill()]);

    $local->refresh();
    expect($local->external_id)->toBe('bill-1')
        ->and($local->closing_date->toDateString())->toBe('2026-04-10')
        ->and($local->due_date->toDateString())->toBe('2026-04-20')
        ->and(CardStatement::query()->where('account_id', $this->card->id)->count())->toBe(1);
});

it('adota a fatura local com o mesmo fechamento calculado, mesmo com o vencimento fora da janela de 7 dias', function () {
    $local = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-05-05']);

    $this->action->handle($this->card, [providerBill()]);

    $local->refresh();
    expect($local->external_id)->toBe('bill-1')
        ->and($local->due_date->toDateString())->toBe('2026-04-20')
        ->and(CardStatement::query()->where('account_id', $this->card->id)->count())->toBe(1);
});

it('vencimento perto de uma fatura que já pertence a outra fatura do banco: não adota, mas cria separada se a ordem permitir', function () {
    $other = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-03-20', 'due_date' => '2026-04-15', 'external_id' => 'other-bill',
    ]);

    $this->action->handle($this->card, [providerBill()]);

    $other->refresh();
    expect($other->external_id)->toBe('other-bill')
        ->and($other->due_date->toDateString())->toBe('2026-04-15');

    $created = CardStatement::query()->where('account_id', $this->card->id)->where('external_id', 'bill-1')->first();
    expect($created)->not->toBeNull()
        ->and($created->due_date->toDateString())->toBe('2026-04-20')
        ->and(CardStatement::query()->where('account_id', $this->card->id)->count())->toBe(2);
});

it('fatura nova que não cabe na ordem (fora da janela de adoção) adota a vizinha mais próxima em vez de inserir fora de ordem', function () {
    $local = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-05', 'due_date' => '2026-05-01']);

    // due=20/04 ficaria antes do vencimento da fatura "anterior" (01/05) —
    // não cabe na ordem; fora da janela de 7 dias para a adoção normal
    // (diferença de 11 dias), mas ainda é a única candidata disponível.
    $this->action->handle($this->card, [providerBill()]);

    $local->refresh();
    expect($local->external_id)->toBe('bill-1')
        // Fora de ordem: a fatura é adotada (ganha external_id/total), mas
        // as datas continuam as que já tinha — nunca aplicadas fora de ordem.
        ->and($local->closing_date->toDateString())->toBe('2026-04-05')
        ->and($local->due_date->toDateString())->toBe('2026-05-01')
        ->and(CardStatement::query()->where('account_id', $this->card->id)->count())->toBe(1);
});

it('fatura nova que não cabe na ordem e sem nenhuma candidata para adotar é ignorada (log, nada criado)', function () {
    $other = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-04-05', 'due_date' => '2026-05-01', 'external_id' => 'other-bill',
    ]);

    $this->action->handle($this->card, [providerBill()]);

    $other->refresh();
    expect($other->external_id)->toBe('other-bill')
        ->and($other->due_date->toDateString())->toBe('2026-05-01')
        ->and(CardStatement::query()->where('account_id', $this->card->id)->count())->toBe(1);
});

it('a vizinha mais próxima disponível para adotar, a mais de 45 dias do vencimento informado, nunca é adotada (log, nada criado)', function () {
    // Causa o conflito de ordem (já pertence a outra fatura do banco, não é
    // adotável) — mesmo cenário da fatura "sem nenhuma candidata" acima.
    $blocking = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-04-05', 'due_date' => '2026-05-01', 'external_id' => 'other-bill',
    ]);
    // A única candidata adotável, mas a mais de 45 dias do vencimento
    // informado pelo banco (2026-04-20) — não é "a vizinha do conflito",
    // é só a fatura mais próxima que existe.
    $tooFar = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-07-01', 'due_date' => '2026-07-10',
    ]);

    $this->action->handle($this->card, [providerBill()]);

    $tooFar->refresh();
    $blocking->refresh();
    expect($tooFar->external_id)->toBeNull()
        ->and($blocking->external_id)->toBe('other-bill')
        ->and(CardStatement::query()->where('account_id', $this->card->id)->count())->toBe(2);
});

it('grava reported_paid como a soma de payments[] da fatura do banco', function () {
    $this->action->handle($this->card, [providerBill([
        'payments' => [new ProviderBillPayment('pag-1', '2026-04-15', 12000), new ProviderBillPayment('pag-2', '2026-04-16', 3000)],
    ])]);

    $statement = CardStatement::query()->where('account_id', $this->card->id)->first();
    expect($statement->reported_paid->cents)->toBe(15000);
});

it('o mesmo id de pagamento repetido em duas faturas do lote conta reported_paid só uma vez, na fatura preferida', function () {
    $duplicatedPayment = new ProviderBillPayment('pag-dup', '2026-04-14', 30000);

    $this->action->handle($this->card, [
        providerBill(['id' => 'fatura-a', 'closingDate' => '2026-04-05', 'dueDate' => '2026-04-15', 'totalCents' => 50000, 'payments' => [$duplicatedPayment]]),
        providerBill(['id' => 'fatura-b', 'closingDate' => '2026-05-05', 'dueDate' => '2026-05-15', 'totalCents' => 20000, 'payments' => [$duplicatedPayment]]),
    ]);

    $a = CardStatement::query()->where('account_id', $this->card->id)->where('external_id', 'fatura-a')->firstOrFail();
    $b = CardStatement::query()->where('account_id', $this->card->id)->where('external_id', 'fatura-b')->firstOrFail();

    // A fatura preferida (fechamento mais recente que ainda é <= a data do
    // pagamento, mesma regra de CardPaymentMatcher) é fatura-a — ela leva o
    // reported_paid; fatura-b nunca conta o mesmo pagamento de novo.
    expect($a->reported_paid->cents)->toBe(30000)
        ->and($b->reported_paid)->toBeNull();
});

it('sem payments[] na fatura do banco, reported_paid continua null', function () {
    $this->action->handle($this->card, [providerBill()]);

    $statement = CardStatement::query()->where('account_id', $this->card->id)->first();
    expect($statement->reported_paid)->toBeNull();
});

it('fatura existente sem closingDate nunca tem o fechamento reescrito, mesmo depois do closing_day do cartão mudar', function () {
    $old = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-01-10', 'due_date' => '2026-01-20', 'external_id' => 'bill-old',
    ]);

    // Fatura mais recente com fechamento informado muda closing_day/due_day do cartão (de 10/20 para 5/15).
    $this->action->handle($this->card, [providerBill(['id' => 'bill-new', 'closingDate' => '2026-04-05', 'dueDate' => '2026-04-15'])]);
    expect($this->card->refresh()->closing_day)->toBe(5);

    // Sync seguinte: a fatura antiga chega de novo sem closingDate — o
    // fechamento calculado agora usaria o dia 5, mas o já gravado não pode mudar.
    $this->action->handle($this->card, [providerBill(['id' => 'bill-old', 'closingDate' => null, 'dueDate' => '2026-01-20', 'totalCents' => 12345])]);

    $old->refresh();
    expect($old->closing_date->toDateString())->toBe('2026-01-10')
        ->and($old->due_date->toDateString())->toBe('2026-01-20')
        ->and($old->reported_total->cents)->toBe(12345);
});

it('fatura adotada por proximidade de vencimento (ainda sem external_id) também preserva o fechamento quando o banco não informa closingDate', function () {
    $local = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-08', 'due_date' => '2026-04-23']);

    $this->action->handle($this->card, [providerBill(['closingDate' => null, 'dueDate' => '2026-04-20'])]);

    $local->refresh();
    expect($local->external_id)->toBe('bill-1')
        ->and($local->closing_date->toDateString())->toBe('2026-04-08');
});

it('fatura existente sem closingDate ainda pode ter o vencimento ajustado, desde que caiba com o fechamento gravado e as vizinhas', function () {
    $old = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-01-10', 'due_date' => '2026-01-20', 'external_id' => 'bill-old',
    ]);

    $this->action->handle($this->card, [providerBill(['id' => 'bill-old', 'closingDate' => null, 'dueDate' => '2026-01-22'])]);

    expect($old->refresh()->closing_date->toDateString())->toBe('2026-01-10')
        ->and($old->due_date->toDateString())->toBe('2026-01-22');
});

it('fatura existente sem closingDate: vencimento que não caberia (antes do fechamento gravado) é ignorado', function () {
    $old = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-01-10', 'due_date' => '2026-01-20', 'external_id' => 'bill-old',
    ]);

    $this->action->handle($this->card, [providerBill(['id' => 'bill-old', 'closingDate' => null, 'dueDate' => '2026-01-05'])]);

    expect($old->refresh()->due_date->toDateString())->toBe('2026-01-20');
});

it('a fatura mais recente informada pelo banco (maior fechamento) define closing_day/due_day do cartão', function () {
    $this->action->handle($this->card, [
        providerBill(['id' => 'bill-1', 'closingDate' => '2026-04-10', 'dueDate' => '2026-04-20']),
        providerBill(['id' => 'bill-2', 'closingDate' => '2026-05-05', 'dueDate' => '2026-05-15']),
    ]);

    $this->card->refresh();
    expect($this->card->closing_day)->toBe(5)
        ->and($this->card->due_day)->toBe(15);
});

it('closing_day/due_day não mudam quando já batem com a fatura mais recente', function () {
    $card = Account::factory()->creditCard(closingDay: 5, dueDay: 15)->create();

    $this->action->handle($card, [providerBill(['id' => 'bill-1', 'closingDate' => '2026-04-05', 'dueDate' => '2026-04-15'])]);

    $card->refresh();
    expect($card->closing_day)->toBe(5)->and($card->due_day)->toBe(15);
});

it('dias do cartão nunca reescrevem faturas já gravadas, só os ciclos futuros', function () {
    $existing = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-02-10', 'due_date' => '2026-02-20']);

    $this->action->handle($this->card, [providerBill(['id' => 'bill-1', 'closingDate' => '2026-04-05', 'dueDate' => '2026-04-15'])]);

    $existing->refresh();
    expect($existing->closing_date->toDateString())->toBe('2026-02-10')
        ->and($existing->due_date->toDateString())->toBe('2026-02-20');
    expect($this->card->refresh()->closing_day)->toBe(5);
});

it('sem nenhuma fatura no lote, closing_day/due_day do cartão ficam como estavam', function () {
    $this->action->handle($this->card, []);

    expect($this->card->refresh()->closing_day)->toBe(10)->and($this->card->due_day)->toBe(20);
});

it('usa o dia mais comum entre as últimas 3 faturas, não só o da mais recente, contra um desvio pontual de fim de semana', function () {
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create();

    $this->action->handle($card, [
        providerBill(['id' => 'bill-1', 'closingDate' => '2026-02-05', 'dueDate' => '2026-02-15']),
        providerBill(['id' => 'bill-2', 'closingDate' => '2026-03-05', 'dueDate' => '2026-03-15']),
        // Fechamento desta fatura caiu um dia depois (ex.: dia 5 era domingo).
        providerBill(['id' => 'bill-3', 'closingDate' => '2026-04-06', 'dueDate' => '2026-04-15']),
    ]);

    expect($card->refresh()->closing_day)->toBe(5)
        ->and($card->due_day)->toBe(15);
});

it('ignora o fechamento no último dia de um mês curto quando o dia atual do cartão é maior (mês sem esse dia, não mudança real)', function () {
    $card = Account::factory()->creditCard(closingDay: 31, dueDay: 10)->create();

    // Fevereiro de 2026 (não bissexto) só tem até o dia 28.
    $this->action->handle($card, [providerBill(['id' => 'bill-1', 'closingDate' => '2026-02-28', 'dueDate' => '2026-03-10'])]);

    expect($card->refresh()->closing_day)->toBe(31);
});

it('due_day do cartão também se ajusta quando o fechamento da fatura mais recente vem calculado (closing_date ausente)', function () {
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create();

    // Sem closingDate: o fechamento é calculado a partir dos dias atuais do
    // cartão (closingForDueDate cai de volta no dia 10 de fechamento, sem
    // achar um ciclo nominal que reproduza exatamente este vencimento), mas
    // due_day vem direto do vencimento informado pelo banco — aqui, 15.
    $this->action->handle($card, [providerBill(['id' => 'bill-1', 'closingDate' => null, 'dueDate' => '2026-05-15'])]);

    expect($card->refresh()->closing_day)->toBe(10)
        ->and($card->due_day)->toBe(15);
});

it('fatura antiga chegando por último no lote não muda o resultado: a ordenação é por fechamento, não pela ordem da lista', function () {
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create();

    $this->action->handle($card, [
        providerBill(['id' => 'bill-new', 'closingDate' => '2026-05-05', 'dueDate' => '2026-05-15']),
        providerBill(['id' => 'bill-old', 'closingDate' => '2026-02-10', 'dueDate' => '2026-02-20']),
    ]);

    expect($card->refresh()->closing_day)->toBe(5)
        ->and($card->due_day)->toBe(15);
});
