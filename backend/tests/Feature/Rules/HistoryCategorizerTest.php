<?php

use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Support\HistoryCategorizer;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Str;

beforeEach(function () {
    actingAsUser();
    $this->history = new HistoryCategorizer;
});

it('sugere a categoria mais frequente para a mesma chave e direção', function () {
    $a = Category::factory()->create();
    $b = Category::factory()->create();

    Transaction::factory()->count(2)->create(['description' => 'Uber trip 1', 'direction' => Direction::Out, 'category_id' => $a->id]);
    Transaction::factory()->create(['description' => 'Uber trip 2', 'direction' => Direction::Out, 'category_id' => $b->id]);

    expect($this->history->suggest('UBER TRIP', Direction::Out))->toBe($a->id);
});

it('em empate, usa a categoria da transação mais recente', function () {
    $a = Category::factory()->create();
    $b = Category::factory()->create();

    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $a->id, 'date' => '2026-01-01']);
    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $b->id, 'date' => '2026-02-01']);

    expect($this->history->suggest('UBER', Direction::Out))->toBe($b->id);
});

it('direção diferente não conta', function () {
    $category = Category::factory()->create();

    Transaction::factory()->create(['description' => 'Pix', 'direction' => Direction::In, 'category_id' => $category->id]);

    expect($this->history->suggest('PIX', Direction::Out))->toBeNull();
});

it('chave vazia devolve nulo sem consultar', function () {
    expect($this->history->suggest('', Direction::Out))->toBeNull();
});

it('categoria arquivada fica fora da sugestão', function () {
    $archived = Category::factory()->create(['is_archived' => true]);
    $active = Category::factory()->create();

    Transaction::factory()->count(3)->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $archived->id]);
    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $active->id]);

    expect($this->history->suggest('UBER', Direction::Out))->toBe($active->id);
});

it('pernas de transferência e transações ignoradas ficam fora', function () {
    $category = Category::factory()->create();

    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $category->id, 'transfer_id' => (string) Str::uuid()]);
    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $category->id, 'is_ignored' => true]);

    expect($this->history->suggest('UBER', Direction::Out))->toBeNull();
});

it('conta um parcelamento como um único uso, não uma parcela por uso', function () {
    $planCategory = Category::factory()->create();
    $singleCategory = Category::factory()->create();

    $plan = InstallmentPlan::factory()->create(['installments' => 12]);
    Transaction::factory()->count(12)->create([
        'account_id' => $plan->account_id,
        'description' => 'Notebook', 'direction' => Direction::Out, 'category_id' => $planCategory->id,
        'installment_plan_id' => $plan->id, 'status' => TransactionStatus::Posted,
    ]);

    Transaction::factory()->count(5)->create([
        'description' => 'Notebook', 'direction' => Direction::Out, 'category_id' => $singleCategory->id,
        'status' => TransactionStatus::Posted,
    ]);

    expect($this->history->suggest('NOTEBOOK', Direction::Out))->toBe($singleCategory->id);
});

it('ignora parcelas projetadas (status diferente de posted)', function () {
    $category = Category::factory()->create();

    Transaction::factory()->create(['description' => 'Notebook', 'direction' => Direction::Out, 'category_id' => $category->id, 'status' => TransactionStatus::Projected]);

    expect($this->history->suggest('NOTEBOOK', Direction::Out))->toBeNull();
});

it('isola o histórico entre usuários', function () {
    actingAsUser();
    $category = Category::factory()->create();
    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $category->id]);

    actingAsUser();
    expect($this->history->suggest('UBER', Direction::Out))->toBeNull();
});

it('suggestMany resolve vários pares numa só consulta, igual ao suggest() por linha', function () {
    $uber = Category::factory()->create();
    $uberOld = Category::factory()->create();
    $pix = Category::factory()->create();

    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $uber->id, 'date' => '2026-02-01']);
    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $uberOld->id, 'date' => '2026-01-01']);
    Transaction::factory()->create(['description' => 'Pix recebido', 'direction' => Direction::In, 'category_id' => $pix->id]);

    $pairs = [
        ['description_key' => 'UBER', 'direction' => 'out'],
        ['description_key' => 'PIX RECEBIDO', 'direction' => 'in'],
        ['description_key' => 'NADA AQUI', 'direction' => 'out'],
    ];

    $many = $this->history->suggestMany($pairs);

    expect($many)->toHaveCount(2)
        ->and($many['UBER|out'])->toBe($uber->id)
        ->and($many['PIX RECEBIDO|in'])->toBe($pix->id);
});

it('suggestMany ignora chaves vazias e dedupe pares repetidos sem duplicar a consulta', function () {
    $category = Category::factory()->create();
    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $category->id]);

    $many = $this->history->suggestMany([
        ['description_key' => '', 'direction' => 'out'],
        ['description_key' => 'UBER', 'direction' => 'out'],
        ['description_key' => 'UBER', 'direction' => 'out'],
    ]);

    expect($many)->toBe(['UBER|out' => $category->id]);
});

it('suggestMany devolve vazio sem consultar quando não há pares', function () {
    expect($this->history->suggestMany([]))->toBe([]);
});

it('suggestMany processa mais de 1000 pares em mais de uma consulta (chunk)', function () {
    $category = Category::factory()->create();
    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $category->id]);

    $pairs = [];
    for ($i = 0; $i < 1200; $i++) {
        $pairs[] = ['description_key' => "CHAVE {$i}", 'direction' => 'out'];
    }
    $pairs[] = ['description_key' => 'UBER', 'direction' => 'out'];

    $many = $this->history->suggestMany($pairs);

    expect($many)->toBe(['UBER|out' => $category->id]);
});
