<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Categories\Models\Category;
use App\Domain\Categorization\Queries\PendingCategorization;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Str;

describe('autenticado', function () {
    beforeEach(function () {
        actingAsUser();
        $this->account = Account::factory()->create();
    });

    it('inclui o pendente de verdade e exclui os demais critérios', function () {
        $pending = Transaction::factory()->for($this->account)->create(['description' => 'Padaria Teste']);

        // projetado
        Transaction::factory()->for($this->account)->create(['description' => 'Padaria Teste', 'status' => 'projected']);
        // ainda não lançado
        Transaction::factory()->for($this->account)->create(['description' => 'Padaria Teste', 'status' => 'pending']);
        // ignorado
        Transaction::factory()->for($this->account)->create(['description' => 'Padaria Teste', 'is_ignored' => true]);
        // perna de transferência
        Transaction::factory()->for($this->account)->create(['description' => 'Padaria Teste', 'transfer_id' => (string) Str::uuid()]);
        // pagamento de fatura reconhecido
        $statement = CardStatement::factory()->create();
        Transaction::factory()->create([
            'account_id' => $statement->account_id,
            'description' => 'Pagamento fatura',
            'direction' => 'in',
            'statement_id' => $statement->id,
            'card_payment_statement_id' => $statement->id,
        ]);
        // conta arquivada
        $archived = Account::factory()->archived()->create();
        Transaction::factory()->for($archived)->create(['description' => 'Padaria Teste']);
        // já categorizado
        $category = Category::factory()->create();
        Transaction::factory()->for($this->account)->create(['description' => 'Padaria Teste', 'category_id' => $category->id]);

        $result = app(PendingCategorization::class)->pending();

        expect($result->totalPending)->toBe(1)
            ->and($result->groupCount)->toBe(1)
            ->and($result->groups[0]->transactions[0]->id)->toBe($pending->id);
    });

    it('agrupa pix enviado e recebido da mesma contraparte', function () {
        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix enviado  - Fulano De Tal', 'direction' => 'out', 'amount' => 5000,
        ]);
        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix recebido - Fulano De Tal', 'direction' => 'in', 'amount' => 3000,
        ]);

        $result = app(PendingCategorization::class)->pending();

        expect($result->groupCount)->toBe(1);

        $group = $result->groups[0];
        expect($group->key)->toBe('FULANO DE TAL')
            ->and($group->outCount)->toBe(1)
            ->and($group->inCount)->toBe(1)
            ->and($group->net)->toBe(3000 - 5000)
            ->and($group->transactions)->toHaveCount(2);
    });

    it('agrupa transferência pelo pix enviada e recebida, com o nome sem o prefixo', function () {
        Transaction::factory()->for($this->account)->create([
            'description' => 'Transferência recebida pelo Pix - EMPRESA EXEMPLO',
            'direction' => 'in', 'amount' => 8000, 'date' => '2026-01-01',
        ]);
        Transaction::factory()->for($this->account)->create([
            'description' => 'Transferência enviada pelo Pix - EMPRESA EXEMPLO',
            'direction' => 'out', 'amount' => 2000, 'date' => '2026-02-01',
        ]);

        $result = app(PendingCategorization::class)->pending();

        expect($result->groupCount)->toBe(1);

        $group = $result->groups[0];
        expect($group->key)->toBe('EMPRESA EXEMPLO')
            ->and($group->name)->toBe('Empresa Exemplo')
            ->and($group->inCount)->toBe(1)
            ->and($group->outCount)->toBe(1);
    });

    it('ordena os grupos pela quantidade de lançamentos, decrescente', function () {
        Transaction::factory()->for($this->account)->count(3)->create(['description' => 'Pix enviado - Maior Grupo']);
        Transaction::factory()->for($this->account)->count(1)->create(['description' => 'Pix enviado - Menor Grupo']);

        $result = app(PendingCategorization::class)->pending();

        expect($result->groups)->toHaveCount(2)
            ->and($result->groups[0]->key)->toBe('MAIOR GRUPO')
            ->and($result->groups[1]->key)->toBe('MENOR GRUPO');
    });

    it('sugere a categoria do histórico só quando o grupo é de uma direção só', function () {
        $category = Category::factory()->create();

        // histórico: mesma description_key, mesma direção, já categorizado.
        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix enviado - Fulano De Tal', 'direction' => 'out',
            'category_id' => $category->id, 'date' => '2026-01-01',
        ]);

        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix enviado - Fulano De Tal', 'direction' => 'out', 'date' => '2026-02-01',
        ]);

        $result = app(PendingCategorization::class)->pending();

        $group = collect($result->groups)->firstWhere('key', 'FULANO DE TAL');
        expect($group->suggestionCategoryId)->toBe($category->id);
    });

    it('não sugere nada quando o grupo mistura entrada e saída', function () {
        $category = Category::factory()->create();

        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix enviado - Fulano De Tal', 'direction' => 'out',
            'category_id' => $category->id, 'date' => '2026-01-01',
        ]);

        Transaction::factory()->for($this->account)->create(['description' => 'Pix enviado - Fulano De Tal', 'direction' => 'out']);
        Transaction::factory()->for($this->account)->create(['description' => 'Pix recebido - Fulano De Tal', 'direction' => 'in']);

        $result = app(PendingCategorization::class)->pending();

        $group = collect($result->groups)->firstWhere('key', 'FULANO DE TAL');
        expect($group->suggestionCategoryId)->toBeNull();
    });

    it('não sugere nada quando as description_keys do grupo divergem sobre a categoria', function () {
        $categoryA = Category::factory()->create();
        $categoryB = Category::factory()->create();

        // histórico: duas description_keys diferentes, mesma contraparte
        // (mesmo prefixo removido), mesma direção, categorias diferentes.
        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix enviado - Fulano De Tal', 'direction' => 'out',
            'category_id' => $categoryA->id, 'date' => '2026-01-01',
        ]);
        Transaction::factory()->for($this->account)->create([
            'description' => 'Compra no débito - Fulano De Tal', 'direction' => 'out',
            'category_id' => $categoryB->id, 'date' => '2026-01-02',
        ]);

        Transaction::factory()->for($this->account)->create(['description' => 'Pix enviado - Fulano De Tal', 'direction' => 'out']);
        Transaction::factory()->for($this->account)->create(['description' => 'Compra no débito - Fulano De Tal', 'direction' => 'out']);

        $result = app(PendingCategorization::class)->pending();

        $group = collect($result->groups)->firstWhere('key', 'FULANO DE TAL');
        expect($group->suggestionCategoryId)->toBeNull();
    });

    it('um filtro de direção pode revelar uma sugestão que o grupo misto não teria sem o filtro', function () {
        $category = Category::factory()->create();

        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix enviado - Fulano De Tal', 'direction' => 'out',
            'category_id' => $category->id, 'date' => '2026-01-01',
        ]);
        Transaction::factory()->for($this->account)->create(['description' => 'Pix enviado - Fulano De Tal', 'direction' => 'out']);
        Transaction::factory()->for($this->account)->create(['description' => 'Pix recebido - Fulano De Tal', 'direction' => 'in']);

        $query = app(PendingCategorization::class);

        $unfiltered = collect($query->pending()->groups)->firstWhere('key', 'FULANO DE TAL');
        $filtered = collect($query->pending(direction: Direction::Out)->groups)->firstWhere('key', 'FULANO DE TAL');

        expect($unfiltered->suggestionCategoryId)->toBeNull()
            ->and($filtered->suggestionCategoryId)->toBe($category->id);
    });

    it('não sugere categoria de despesa para um grupo de entrada (o apply recusaria)', function () {
        $expense = Category::factory()->create();

        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix recebido - Ciclano Exemplo', 'direction' => 'in',
            'category_id' => $expense->id, 'date' => '2026-01-01',
        ]);
        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix recebido - Ciclano Exemplo', 'direction' => 'in', 'date' => '2026-02-01',
        ]);

        $group = $this->getJson('/api/v1/categorization/pending')->assertOk()->json('data.groups.0');

        expect($group['key'])->toBe('CICLANO EXEMPLO')
            ->and($group)->not->toHaveKey('suggestion');
    });

    it('sugere categoria de transferência, ou filha de uma, para as duas direções', function () {
        $transferParent = Category::factory()->transfer()->create();
        $transferChild = Category::factory()->create(['parent_id' => $transferParent->id]);

        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix recebido - Ciclano Exemplo', 'direction' => 'in',
            'category_id' => $transferChild->id, 'date' => '2026-01-01',
        ]);
        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix recebido - Ciclano Exemplo', 'direction' => 'in', 'date' => '2026-02-01',
        ]);
        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix enviado - Beltrano Exemplo', 'direction' => 'out',
            'category_id' => $transferParent->id, 'date' => '2026-01-01',
        ]);
        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix enviado - Beltrano Exemplo', 'direction' => 'out', 'date' => '2026-02-01',
        ]);

        $groups = collect($this->getJson('/api/v1/categorization/pending')->assertOk()->json('data.groups'))->keyBy('key');

        expect($groups['CICLANO EXEMPLO']['suggestion']['category_id'])->toBe($transferChild->id)
            ->and($groups['BELTRANO EXEMPLO']['suggestion']['category_id'])->toBe($transferParent->id);
    });

    it('a sugestão aceita no apply não dá erro de validação', function () {
        $income = Category::factory()->income()->create();

        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix recebido - Ciclano Exemplo', 'direction' => 'in',
            'category_id' => $income->id, 'date' => '2026-01-01',
        ]);
        $pending = Transaction::factory()->for($this->account)->create([
            'description' => 'Pix recebido - Ciclano Exemplo', 'direction' => 'in', 'date' => '2026-02-01',
        ]);

        $group = $this->getJson('/api/v1/categorization/pending')->assertOk()->json('data.groups.0');

        expect($group['suggestion']['category_id'])->toBe($income->id);

        $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$pending->id],
            'category_id' => $group['suggestion']['category_id'],
        ])->assertOk();
    });

    it('não sugere nada quando não há histórico para a chave', function () {
        Transaction::factory()->for($this->account)->create(['description' => 'Pix enviado - Fulano De Tal', 'direction' => 'out']);

        $result = app(PendingCategorization::class)->pending();

        expect($result->groups[0]->suggestionCategoryId)->toBeNull();
    });

    it('monta o nome a partir da descrição original do lançamento mais recente do grupo', function () {
        Transaction::factory()->for($this->account)->create([
            'description' => 'Compra no débito - Loja Exemplo Chapeco Bra', 'date' => '2026-01-01',
        ]);
        Transaction::factory()->for($this->account)->create([
            'description' => 'Compra no débito - Loja 99 Exemplo Chapeco Bra', 'date' => '2026-02-01',
        ]);

        $result = app(PendingCategorization::class)->pending();

        expect($result->groups[0]->name)->toBe('Loja 99 Exemplo');
    });

    it('expõe original_description de cada lançamento, não só description', function () {
        Transaction::factory()->for($this->account)->create([
            'description' => 'Apelido', 'original_description' => 'PIX ENVIADO FULANO',
        ]);

        $transaction = $this->getJson('/api/v1/categorization/pending')->assertOk()->json('data.groups.0.transactions.0');

        expect($transaction['description'])->toBe('Apelido')
            ->and($transaction['original_description'])->toBe('PIX ENVIADO FULANO');
    });

    it('tira o nome de original_description, não de description (apelido editado não conta)', function () {
        Transaction::factory()->for($this->account)->create([
            'description' => 'Apelido', 'original_description' => 'PIX ENVIADO FULANO',
        ]);

        $result = app(PendingCategorization::class)->pending();

        expect($result->groups[0]->name)->toBe('Fulano');
    });

    it('o nome do grupo é sempre achável (sem caixa) dentro do original_description do lançamento mais recente', function () {
        Transaction::factory()->for($this->account)->create([
            'original_description' => 'Compra no débito - Padaria Teste Chapeco Bra', 'date' => '2026-01-01',
        ]);
        $mostRecent = Transaction::factory()->for($this->account)->create([
            'original_description' => 'Compra no débito - Padaria Teste 2 Chapeco Bra', 'date' => '2026-02-01',
        ]);

        $result = app(PendingCategorization::class)->pending();

        $name = $result->groups[0]->name;
        expect(mb_stripos($mostRecent->original_description, $name))->not->toBeFalse();
    });

    it('filtra por direção', function () {
        Transaction::factory()->for($this->account)->create(['description' => 'Pix enviado - Fulano', 'direction' => 'out']);
        Transaction::factory()->for($this->account)->create(['description' => 'Pix recebido - Beltrano', 'direction' => 'in']);

        $result = app(PendingCategorization::class)->pending(direction: Direction::In);

        expect($result->groupCount)->toBe(1)
            ->and($result->groups[0]->key)->toBe('BELTRANO');
    });

    it('filtra por busca na chave ou na descrição', function () {
        Transaction::factory()->for($this->account)->create(['description' => 'Pix enviado - Fulano De Tal']);
        Transaction::factory()->for($this->account)->create(['description' => 'Compra no débito - Mercado Teste']);

        $result = app(PendingCategorization::class)->pending(search: 'fulano');

        expect($result->groupCount)->toBe(1)
            ->and($result->groups[0]->key)->toBe('FULANO DE TAL');
    });

    it('trunca quando passa do limite, mantendo só os lançamentos mais recentes', function () {
        Transaction::factory()->for($this->account)->create(['description' => 'Lançamento 1', 'date' => '2026-01-01']);
        Transaction::factory()->for($this->account)->create(['description' => 'Lançamento 2', 'date' => '2026-01-02']);
        $mostRecent = Transaction::factory()->for($this->account)->create(['description' => 'Lançamento 3', 'date' => '2026-01-03']);

        $result = app(PendingCategorization::class)->pending(limit: 2);

        expect($result->truncated)->toBeTrue()
            // total_pending é o total de verdade (3), não o recorte
            // truncado (2) — igual ao que /pending-count devolveria.
            ->and($result->totalPending)->toBe(3)
            ->and(collect($result->groups)->flatMap(fn ($g) => $g->transactions)->pluck('id'))->toContain($mostRecent->id);
    });

    it('não trunca quando o total está dentro do limite', function () {
        Transaction::factory()->for($this->account)->create(['description' => 'Lançamento único']);

        $result = app(PendingCategorization::class)->pending(limit: 5000);

        expect($result->truncated)->toBeFalse();
    });

    it('descrição só de dígito não agrupa lançamentos sem relação entre si', function () {
        Transaction::factory()->for($this->account)->create(['description' => '11111']);
        Transaction::factory()->for($this->account)->create(['description' => '22222']);

        $result = app(PendingCategorization::class)->pending();

        expect($result->groupCount)->toBe(2);
    });

    it('isola pendentes entre usuários', function () {
        Transaction::factory()->for($this->account)->create(['description' => 'Pix enviado - Fulano']);

        actingAsUser();
        $otherAccount = Account::factory()->create();
        Transaction::factory()->for($otherAccount)->create(['description' => 'Pix enviado - Beltrano']);

        $result = app(PendingCategorization::class)->pending();

        expect($result->groupCount)->toBe(1)
            ->and($result->groups[0]->key)->toBe('BELTRANO');
    });

    it('a contagem leve exclui os mesmos critérios que a listagem', function () {
        Transaction::factory()->for($this->account)->create(['description' => 'Padaria Teste']);

        Transaction::factory()->for($this->account)->create(['description' => 'Padaria Teste', 'status' => 'projected']);
        Transaction::factory()->for($this->account)->create(['description' => 'Padaria Teste', 'status' => 'pending']);
        Transaction::factory()->for($this->account)->create(['description' => 'Padaria Teste', 'is_ignored' => true]);
        Transaction::factory()->for($this->account)->create(['description' => 'Padaria Teste', 'transfer_id' => (string) Str::uuid()]);

        $statement = CardStatement::factory()->create();
        Transaction::factory()->create([
            'account_id' => $statement->account_id,
            'description' => 'Pagamento fatura',
            'direction' => 'in',
            'statement_id' => $statement->id,
            'card_payment_statement_id' => $statement->id,
        ]);

        $archived = Account::factory()->archived()->create();
        Transaction::factory()->for($archived)->create(['description' => 'Padaria Teste']);

        $category = Category::factory()->create();
        Transaction::factory()->for($this->account)->create(['description' => 'Padaria Teste', 'category_id' => $category->id]);

        $result = app(PendingCategorization::class)->count();

        expect($result->totalPending)->toBe(1)
            ->and($result->groupCount)->toBe(1);
    });

    it('a contagem leve bate com os totais da listagem', function () {
        Transaction::factory()->for($this->account)->create(['description' => 'Pix enviado - Fulano']);
        Transaction::factory()->for($this->account)->create(['description' => 'Pix recebido - Fulano']);
        Transaction::factory()->for($this->account)->create(['description' => 'Compra no débito - Mercado Teste']);

        $query = app(PendingCategorization::class);
        $listing = $query->pending();
        $count = $query->count();

        expect($count->totalPending)->toBe($listing->totalPending)
            ->and($count->groupCount)->toBe($listing->groupCount);
    });

    it('GET /categorization/pending devolve os grupos no formato esperado', function () {
        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix enviado - Fulano De Tal', 'direction' => 'out', 'amount' => 4000,
        ]);

        $response = $this->getJson('/api/v1/categorization/pending')->assertOk();

        $response->assertJsonStructure([
            'data' => ['total_pending', 'group_count', 'groups' => [['key', 'name', 'out_count', 'in_count', 'net', 'transactions']]],
        ]);

        $group = $response->json('data.groups.0');
        expect($group)->not->toHaveKey('suggestion')
            ->and($response->json('data'))->not->toHaveKey('truncated')
            ->and($group['transactions'][0])->toHaveKeys(['id', 'date', 'description', 'original_description', 'amount', 'direction', 'account_id', 'account_name']);
    });

    it('GET /categorization/pending expõe suggestion.category_id quando há sugestão', function () {
        $category = Category::factory()->create();

        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix enviado - Fulano De Tal', 'direction' => 'out',
            'category_id' => $category->id, 'date' => '2026-01-01',
        ]);
        Transaction::factory()->for($this->account)->create([
            'description' => 'Pix enviado - Fulano De Tal', 'direction' => 'out', 'date' => '2026-02-01',
        ]);

        $response = $this->getJson('/api/v1/categorization/pending')->assertOk();

        expect($response->json('data.groups.0.suggestion.category_id'))->toBe($category->id);
    });

    it('GET /categorization/pending-count devolve os totais', function () {
        Transaction::factory()->for($this->account)->count(2)->create(['description' => 'Pix enviado - Fulano De Tal']);

        $this->getJson('/api/v1/categorization/pending-count')
            ->assertOk()
            ->assertJson(['data' => ['total_pending' => 2, 'group_count' => 1]]);
    });
});

it('GET /categorization/pending exige login', function () {
    $this->getJson('/api/v1/categorization/pending')->assertUnauthorized();
});

it('GET /categorization/pending-count exige login', function () {
    $this->getJson('/api/v1/categorization/pending-count')->assertUnauthorized();
});
