<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Categories\Models\Category;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
});

function nubankFile(string $content, string $name = 'extrato.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

it('faz upload, detecta o formato e devolve a prévia sem gravar nada', function () {
    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.batch.format', 'nubank');
    $response->assertJsonPath('data.batch.status', 'pending');
    $response->assertJsonCount(5, 'data.rows');
    $response->assertJsonPath('data.summary.failed', 2);

    expect(Transaction::count())->toBe(0)
        ->and(ImportBatch::count())->toBe(1);
});

it('aceita um formato explícito, mesmo que a detecção automática escolhesse outro', function () {
    // Conteúdo é do formato nubank (separador ",", cabeçalho Data,Valor,...);
    // forçar "inter" (separador ";", outro cabeçalho) prova que o parâmetro
    // venceu a detecção: sem o forçar, o upload teria dado 201 como no teste
    // acima.
    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
        'format' => 'inter',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('file');
});

it('formato explícito que bate com o conteúdo cria o lote normalmente', function () {
    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
        'format' => 'nubank',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.batch.format', 'nubank');
});

it('recusa format=pluggy no upload: não há parser de arquivo para esse formato', function () {
    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
        'format' => 'pluggy',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['format']);
});

it('formato não reconhecido e sem format explícito dá 422 em file', function () {
    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile("isto nao e um extrato\nde banco nenhum\n", 'arquivo.csv'),
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['file', 'format']);
    expect($response->json('message'))->toContain('Não reconhecemos o formato deste arquivo.');
});

it('conta em moeda diferente de BRL dá 422 em account_id', function () {
    $usdAccount = Account::factory()->create(['user_id' => $this->user->id, 'currency' => 'USD']);

    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $usdAccount->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('account_id');
    expect($response->json('message'))->toContain('Importação só disponível para contas em reais.');
});

it('formato ofx forçado sobre conteúdo csv (sem nenhum bloco STMTTRN) dá 422 em file', function () {
    // Sem o `unrecognized` explícito, o OfxParser devolveria rows=[] e
    // failed=[] para este conteúdo (nenhuma falha reportada) — um lote
    // "vazio" criado em silêncio em vez de rejeitado.
    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
        'format' => 'ofx',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('file');
});

it('arquivo maior que 2MB dá 422', function () {
    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => UploadedFile::fake()->create('extrato.csv', 3000),
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('extensão fora de csv,ofx,txt dá 422', function () {
    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => UploadedFile::fake()->create('extrato.pdf', 10),
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('conta de outro usuário dá 422 em account_id', function () {
    $other = Account::factory()->create(['user_id' => User::factory()->create()->id]);

    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $other->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('account_id');
});

it('mais de 5000 linhas dá 422 em file', function () {
    $lines = ['Data,Valor,Identificador,Descrição'];
    for ($i = 0; $i < 5001; $i++) {
        $lines[] = sprintf('07/03/2026,-10.00,id-%d,Compra %d', $i, $i);
    }

    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(implode("\n", $lines), 'grande.csv'),
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('linhas inválidas aparecem em summary.failed e em batch.stats.failed', function () {
    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.summary.failed', 2);
    $response->assertJsonCount(2, 'data.batch.stats.failed');
});

it('confirma a importação: cria as transações, fecha o lote e respeita skip_lines', function () {
    $create = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ]);

    $batchId = $create->json('data.batch.id');
    $lines = collect($create->json('data.rows'))->pluck('line')->all();
    $skippedLine = $lines[0];

    $confirm = $this->postJson("/api/v1/import-batches/{$batchId}/confirm", [
        'skip_lines' => [$skippedLine],
    ]);

    $confirm->assertOk();
    $confirm->assertJsonPath('data.status', 'completed');
    $confirm->assertJsonMissingPath('data.rows');
    $confirm->assertJsonPath('data.stats.skipped', 1);
    $confirm->assertJsonCount(2, 'data.stats.failed');

    expect(Transaction::where('account_id', $this->account->id)->count())->toBe(count($lines) - 1)
        ->and(ImportBatch::findOrFail($batchId)->status->value)->toBe('completed');
});

it('confirmar duas vezes dá 409 import_batch_not_pending', function () {
    $create = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ]);
    $batchId = $create->json('data.batch.id');

    $this->postJson("/api/v1/import-batches/{$batchId}/confirm")->assertOk();
    $again = $this->postJson("/api/v1/import-batches/{$batchId}/confirm");

    $again->assertStatus(409);
    expect($again->json('code'))->toBe('import_batch_not_pending');
});

it('prévia e confirmação concordam quando nada muda entre elas', function () {
    $content = "Data,Valor,Identificador,Descrição\n07/03/2026,-45.90,,Compra Mercado Exemplo\n";

    $create = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile($content),
    ]);
    $create->assertJsonPath('data.rows.0.outcome', 'new');
    $batchId = $create->json('data.batch.id');

    $show = $this->getJson("/api/v1/import-batches/{$batchId}");
    $show->assertJsonPath('data.rows.0.outcome', 'new');

    $confirm = $this->postJson("/api/v1/import-batches/{$batchId}/confirm");
    $confirm->assertJsonPath('data.stats.inserted', 1);
});

it('um lançamento manual criado entre a prévia e a confirmação é adotado na confirmação', function () {
    $content = "Data,Valor,Identificador,Descrição\n07/03/2026,-45.90,,Compra Mercado Exemplo\n";

    $create = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile($content),
    ]);
    $create->assertJsonPath('data.rows.0.outcome', 'new');
    $batchId = $create->json('data.batch.id');

    $manual = Transaction::factory()->create([
        'account_id' => $this->account->id,
        'amount' => 4590,
        'direction' => Direction::Out,
        'date' => '2026-03-07',
        'description' => 'Compra Mercado Exemplo',
        'original_description' => 'Compra Mercado Exemplo',
    ]);

    $confirm = $this->postJson("/api/v1/import-batches/{$batchId}/confirm");

    $confirm->assertJsonPath('data.stats.adopted', 1);
    $confirm->assertJsonPath('data.stats.inserted', 0);

    $manual->refresh();
    expect($manual->external_id)->not->toBeNull()
        ->and(Transaction::where('account_id', $this->account->id)->count())->toBe(1);
});

it('cancela um lote pendente e dá 404 depois disso', function () {
    $create = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ]);
    $batchId = $create->json('data.batch.id');

    $this->deleteJson("/api/v1/import-batches/{$batchId}")->assertNoContent();

    $this->getJson("/api/v1/import-batches/{$batchId}")->assertNotFound();
});

it('cancelar um lote já concluído dá 409', function () {
    $create = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ]);
    $batchId = $create->json('data.batch.id');
    $this->postJson("/api/v1/import-batches/{$batchId}/confirm")->assertOk();

    $response = $this->deleteJson("/api/v1/import-batches/{$batchId}");

    $response->assertStatus(409);
    expect($response->json('code'))->toBe('import_batch_not_pending');
});

it('reverte um lote concluído', function () {
    $create = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ]);
    $batchId = $create->json('data.batch.id');
    $this->postJson("/api/v1/import-batches/{$batchId}/confirm")->assertOk();

    $response = $this->postJson("/api/v1/import-batches/{$batchId}/revert");

    $response->assertOk();
    $response->assertJsonPath('data.status', 'reverted');
    expect(Transaction::where('account_id', $this->account->id)->count())->toBe(0);
});

it('revertible só é true para o lote completed mais recente de cada conta, na listagem e no show', function () {
    $otherAccount = Account::factory()->create(['user_id' => $this->user->id]);

    $firstBatchId = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ])->json('data.batch.id');
    $this->postJson("/api/v1/import-batches/{$firstBatchId}/confirm")->assertOk();

    $otherAccountBatchId = $this->postJson('/api/v1/import-batches', [
        'account_id' => $otherAccount->id,
        'file' => nubankFile(importFixture('nubank.csv'), 'outro.csv'),
    ])->json('data.batch.id');
    $this->postJson("/api/v1/import-batches/{$otherAccountBatchId}/confirm")->assertOk();

    $secondBatchId = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('inter.csv'), 'inter.csv'),
    ])->json('data.batch.id');
    $this->postJson("/api/v1/import-batches/{$secondBatchId}/confirm")->assertOk();

    $list = $this->getJson('/api/v1/import-batches')->json('data');
    $revertibleById = collect($list)->pluck('revertible', 'id');

    expect($revertibleById[$firstBatchId])->toBeFalse()
        ->and($revertibleById[$secondBatchId])->toBeTrue()
        ->and($revertibleById[$otherAccountBatchId])->toBeTrue();

    $this->getJson("/api/v1/import-batches/{$firstBatchId}")
        ->assertJsonPath('data.batch.revertible', false);
    $this->getJson("/api/v1/import-batches/{$secondBatchId}")
        ->assertJsonPath('data.batch.revertible', true);
});

it('revertible volta a false depois de revertido, e o lote anterior volta a ser revertible', function () {
    $firstBatchId = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ])->json('data.batch.id');
    $this->postJson("/api/v1/import-batches/{$firstBatchId}/confirm")->assertOk();

    $secondBatchId = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('inter.csv'), 'inter.csv'),
    ])->json('data.batch.id');
    $this->postJson("/api/v1/import-batches/{$secondBatchId}/confirm")->assertOk();

    $revert = $this->postJson("/api/v1/import-batches/{$secondBatchId}/revert");
    $revert->assertOk()->assertJsonPath('data.revertible', false);

    $this->getJson("/api/v1/import-batches/{$firstBatchId}")
        ->assertJsonPath('data.batch.revertible', true);
});

it('não permite reverter um lote que não é o mais recente concluído da conta', function () {
    $firstBatchId = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ])->json('data.batch.id');
    $this->postJson("/api/v1/import-batches/{$firstBatchId}/confirm")->assertOk();

    $secondBatchId = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('inter.csv'), 'inter.csv'),
    ])->json('data.batch.id');
    $this->postJson("/api/v1/import-batches/{$secondBatchId}/confirm")->assertOk();

    $this->postJson("/api/v1/import-batches/{$firstBatchId}/revert")
        ->assertStatus(409)
        ->assertJsonPath('code', 'import_batch_not_revertible');
});

it('lista não traz rows, filtra por account_id e só mostra lotes do usuário', function () {
    $otherAccount = Account::factory()->create(['user_id' => $this->user->id]);
    $otherUser = User::factory()->create();
    $otherUserBatch = ImportBatch::factory()->create([
        'user_id' => $otherUser->id,
        'account_id' => Account::factory()->create(['user_id' => $otherUser->id])->id,
    ]);

    $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ])->assertCreated();

    $this->postJson('/api/v1/import-batches', [
        'account_id' => $otherAccount->id,
        'file' => nubankFile(importFixture('nubank.csv'), 'outro.csv'),
    ])->assertCreated();

    $response = $this->getJson('/api/v1/import-batches?account_id='.$this->account->id);

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonMissingPath('data.0.rows');
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($otherUserBatch->id);
});

it('lotes pendentes com mais de 24h são apagados no próximo upload do usuário, sem afetar lotes recentes nem de outro usuário', function () {
    $stale = ImportBatch::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'status' => 'pending',
    ]);
    ImportBatch::withoutGlobalScopes()->whereKey($stale->id)->update(['created_at' => now()->subHours(25)]);

    $fresh = ImportBatch::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'status' => 'pending',
    ]);

    $otherUser = User::factory()->create();
    $otherStale = ImportBatch::factory()->create([
        'account_id' => Account::factory()->create(['user_id' => $otherUser->id])->id,
        'user_id' => $otherUser->id,
        'status' => 'pending',
    ]);
    ImportBatch::withoutGlobalScopes()->whereKey($otherStale->id)->update(['created_at' => now()->subHours(25)]);

    $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ])->assertCreated();

    expect(ImportBatch::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(ImportBatch::query()->whereKey($fresh->id)->exists())->toBeTrue()
        ->and(ImportBatch::withoutGlobalScopes()->whereKey($otherStale->id)->exists())->toBeTrue();
});

it('lote de outro usuário dá 404 em todas as rotas', function () {
    $otherUser = User::factory()->create();
    $otherBatch = ImportBatch::factory()->create([
        'user_id' => $otherUser->id,
        'account_id' => Account::factory()->create(['user_id' => $otherUser->id])->id,
        'status' => 'completed',
    ]);

    $this->getJson("/api/v1/import-batches/{$otherBatch->id}")->assertNotFound();
    $this->postJson("/api/v1/import-batches/{$otherBatch->id}/confirm")->assertNotFound();
    $this->deleteJson("/api/v1/import-batches/{$otherBatch->id}")->assertNotFound();
    $this->postJson("/api/v1/import-batches/{$otherBatch->id}/revert")->assertNotFound();
});

it('skip_lines aceita só inteiros positivos e distintos, até 5000 itens', function () {
    $create = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ]);
    $batchId = $create->json('data.batch.id');

    $this->postJson("/api/v1/import-batches/{$batchId}/confirm", ['skip_lines' => [0]])
        ->assertUnprocessable()->assertJsonValidationErrors('skip_lines.0');

    $this->postJson("/api/v1/import-batches/{$batchId}/confirm", ['skip_lines' => [2, 2]])
        ->assertUnprocessable();

    $this->postJson("/api/v1/import-batches/{$batchId}/confirm", ['skip_lines' => ['x']])
        ->assertUnprocessable()->assertJsonValidationErrors('skip_lines.0');

    $this->postJson("/api/v1/import-batches/{$batchId}/confirm", ['skip_lines' => range(1, 5001)])
        ->assertUnprocessable()->assertJsonValidationErrors('skip_lines');
});

it('reverter o lote mais antigo quando já existe um mais recente concluído dá 409', function () {
    $first = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile("Data,Valor,Identificador,Descrição\n07/03/2026,-10.00,a,Compra A\n"),
    ])->json('data.batch.id');
    $this->postJson("/api/v1/import-batches/{$first}/confirm")->assertOk();

    $second = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile("Data,Valor,Identificador,Descrição\n08/03/2026,-20.00,b,Compra B\n"),
    ])->json('data.batch.id');
    $this->postJson("/api/v1/import-batches/{$second}/confirm")->assertOk();

    $response = $this->postJson("/api/v1/import-batches/{$first}/revert");

    $response->assertStatus(409);
    expect($response->json('code'))->toBe('import_batch_not_revertible');
});

it('reverter um lote já revertido dá 409', function () {
    $create = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ]);
    $batchId = $create->json('data.batch.id');
    $this->postJson("/api/v1/import-batches/{$batchId}/confirm")->assertOk();
    $this->postJson("/api/v1/import-batches/{$batchId}/revert")->assertOk();

    $response = $this->postJson("/api/v1/import-batches/{$batchId}/revert");

    $response->assertStatus(409);
    expect($response->json('code'))->toBe('import_batch_not_revertible');
});

it('a prévia mostra match e suggested_category_id, e outros desfechos além de "new"', function () {
    $category = Category::factory()->create();
    Transaction::factory()->create([
        'account_id' => $this->account->id,
        'description' => 'Loja Historico', 'original_description' => 'Loja Historico',
        'direction' => Direction::Out, 'amount' => 5000, 'category_id' => $category->id, 'date' => '2025-01-01',
    ]);
    $manual = Transaction::factory()->create([
        'account_id' => $this->account->id,
        'description' => 'Padaria Exemplo', 'original_description' => 'Padaria Exemplo',
        'direction' => Direction::Out, 'amount' => 1590, 'date' => '2026-03-05',
    ]);

    $content = "Data,Valor,Identificador,Descrição\n"
        ."07/03/2026,-50.00,,Loja Historico\n"
        .'05/03/2026,-15.90,,Padaria Exemplo'."\n";

    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile($content, 'misto.csv'),
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.rows.0.outcome', 'new');
    $response->assertJsonPath('data.rows.0.suggested_category_id', $category->id);
    $response->assertJsonMissingPath('data.rows.0.match');

    $response->assertJsonPath('data.rows.1.outcome', 'adopt');
    $response->assertJsonPath('data.rows.1.match.id', $manual->id);
    $response->assertJsonPath('data.rows.1.match.description', 'Padaria Exemplo');
    $response->assertJsonMissingPath('data.rows.1.suggested_category_id');

    $response->assertJsonPath('data.summary.new', 1);
    $response->assertJsonPath('data.summary.adopt', 1);
});

it('prévia concorda com a confirmação para duas parcelas novas da mesma compra no mesmo arquivo', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $content = "date,title,amount\n"
        ."2026-04-05,Notebook Exemplo Parcela 2/10,350.00\n"
        .'2026-05-05,Notebook Exemplo Parcela 3/10,350.00'."\n";

    $create = $this->postJson('/api/v1/import-batches', [
        'account_id' => $card->id,
        'file' => nubankFile($content, 'fatura.csv'),
    ]);

    $create->assertCreated();
    $create->assertJsonPath('data.rows.0.outcome', 'new');
    $create->assertJsonPath('data.rows.1.outcome', 'replace_installment');
    $create->assertJsonMissingPath('data.rows.1.match');
    $create->assertJsonPath('data.summary.new', 1);
    $create->assertJsonPath('data.summary.replace_installment', 1);

    $batchId = $create->json('data.batch.id');

    $show = $this->getJson("/api/v1/import-batches/{$batchId}");
    $show->assertJsonPath('data.rows.0.outcome', 'new');
    $show->assertJsonPath('data.rows.1.outcome', 'replace_installment');

    $confirm = $this->postJson("/api/v1/import-batches/{$batchId}/confirm");
    $confirm->assertJsonPath('data.stats.inserted', 1);
    $confirm->assertJsonPath('data.stats.replaced', 1);

    // Plano de 10 parcelas, mas o arquivo só cobre a partir da 2ª: a 1ª nunca
    // é criada (projectRemainingInstallments só projeta pra frente), então
    // são 9 transações (parcelas 2 a 10), não 10.
    expect(InstallmentPlan::where('account_id', $card->id)->count())->toBe(1)
        ->and(Transaction::where('account_id', $card->id)->count())->toBe(9);
});

it('prévia de um lote concluído mostra rows vazio e summary a partir de stats', function () {
    $create = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile(importFixture('nubank.csv')),
    ]);
    $batchId = $create->json('data.batch.id');
    $this->postJson("/api/v1/import-batches/{$batchId}/confirm")->assertOk();

    $response = $this->getJson("/api/v1/import-batches/{$batchId}");

    $response->assertOk();
    $response->assertJsonPath('data.batch.status', ImportBatchStatus::Completed->value);
    $response->assertJsonCount(0, 'data.rows');
    $response->assertJsonPath('data.summary.failed', 2);
    expect($response->json('data.summary.new'))->toBeGreaterThan(0);
});

it('prévia com muitas linhas novas distintas roda um número limitado de consultas e concorda com a sugestão por linha', function () {
    $matched = Category::factory()->create();

    $lines = ['Data,Valor,Identificador,Descrição'];
    for ($i = 0; $i < 150; $i++) {
        Transaction::factory()->create([
            'account_id' => $this->account->id,
            'description' => "Loja Historico {$i}", 'original_description' => "Loja Historico {$i}",
            'direction' => Direction::Out, 'amount' => 1000, 'category_id' => $matched->id, 'date' => '2025-01-01',
        ]);
        $lines[] = sprintf('07/03/2026,-10.00,hist-%d,Loja Historico %d', $i, $i);
    }
    for ($i = 0; $i < 150; $i++) {
        $lines[] = sprintf('07/03/2026,-20.00,novo-%d,Loja Sem Historico %d', $i, $i);
    }

    $content = implode("\n", $lines);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    $response = $this->postJson('/api/v1/import-batches', [
        'account_id' => $this->account->id,
        'file' => nubankFile($content, 'grande.csv'),
    ]);

    $response->assertCreated();
    expect($queryCount)->toBeLessThan(30);

    $rows = collect($response->json('data.rows'));
    $withHistory = $rows->filter(fn (array $row) => str_starts_with($row['description'], 'Loja Historico'));
    $withoutHistory = $rows->filter(fn (array $row) => str_starts_with($row['description'], 'Loja Sem Historico'));

    expect($withHistory)->toHaveCount(150)
        ->and($withHistory->every(fn (array $row) => ($row['suggested_category_id'] ?? null) === $matched->id))->toBeTrue()
        ->and($withoutHistory)->toHaveCount(150)
        ->and($withoutHistory->every(fn (array $row) => ! array_key_exists('suggested_category_id', $row)))->toBeTrue();
});
