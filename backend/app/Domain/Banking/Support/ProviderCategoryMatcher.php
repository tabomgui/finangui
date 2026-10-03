<?php

namespace App\Domain\Banking\Support;

use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\Direction;

/**
 * Casa a categoria do provedor (nome traduzido, ver TransactionMapper) com
 * uma categoria ativa do usuário — terceiro passo da categorização de uma
 * transação nova importada do banco, depois de regras e histórico (ver
 * `App\Domain\Imports\Actions\IngestTransactions`).
 *
 * Ordem de tentativa: sinônimo da folha → sinônimo do pai → nome exato da
 * folha → nome exato do pai. Em qualquer tentativa, a categoria candidata
 * precisa ter o `kind` certo pra direção da transação, e só pode ser uma
 * categoria de transferência (`is_transfer`) quando o sinônimo usado
 * permite isso explicitamente (TRANSFER_ALLOWED) — nome exato nunca escolhe
 * transferência, mesmo que o usuário tenha uma categoria comum com esse
 * nome por coincidência.
 */
final class ProviderCategoryMatcher
{
    /**
     * Sinônimo (nome traduzido da Pluggy, normalizado) → nome da categoria
     * padrão do app (ver `App\Domain\Categories\Actions\SeedDefaultCategories`).
     *
     * @var array<string, string>
     */
    private const SYNONYMS = [
        'SUPERMERCADO' => 'Mercado',
        'RESTAURANTES' => 'Restaurantes',
        'ENTREGA DE COMIDA' => 'Delivery',
        'DELIVERY' => 'Delivery',
        'TAXI E TRANSPORTE PRIVADO URBANO' => 'Aplicativos',
        'TRANSPORTE POR APLICATIVO' => 'Aplicativos',
        'POSTOS DE GASOLINA' => 'Combustível',
        'COMBUSTIVEL' => 'Combustível',
        'FARMACIA' => 'Farmácia',
        'STREAMING DE VIDEO' => 'Streaming',
        'SERVICOS DE STREAMING' => 'Streaming',
        'ALUGUEL' => 'Aluguel',
        'ELETRICIDADE' => 'Energia',
        'ENERGIA' => 'Energia',
        'INTERNET' => 'Internet',
        'IMPOSTOS' => 'Impostos',
        'TARIFAS BANCARIAS' => 'Tarifas bancárias',
        'SALARIO' => 'Salário',
        'PAGAMENTO DE CARTAO DE CREDITO' => 'Pagamento de fatura',
        'TRANSFERENCIA MESMA TITULARIDADE' => 'Transferências',
        'MESMA TITULARIDADE' => 'Transferências',
        'INVESTIMENTOS' => 'Investimentos',
    ];

    /**
     * Só estes sinônimos (chave normalizada, mesmas de SYNONYMS) podem
     * escolher uma categoria `is_transfer`; os demais nunca escolhem, nem
     * quando o usuário renomeou uma categoria comum pra um desses nomes.
     *
     * @var list<string>
     */
    private const TRANSFER_ALLOWED = [
        'PAGAMENTO DE CARTAO DE CREDITO',
        'TRANSFERENCIA MESMA TITULARIDADE',
        'MESMA TITULARIDADE',
        'INVESTIMENTOS',
    ];

    /**
     * @param  array{name: string, parent: string|null}  $providerCategory  ver TransactionMapper::toParsedRow()
     * @param  array<string, list<array{id: int, kind: string, is_transfer: bool, has_parent: bool}>>  $usableCategoriesByName  categorias ativas do usuário, agrupadas pelo nome normalizado (ver IngestTransactions)
     */
    public static function match(array $providerCategory, array $usableCategoriesByName, Direction $direction): ?int
    {
        $kind = $direction === Direction::In ? 'income' : 'expense';

        $leaf = TextNormalizer::key($providerCategory['name']);
        $parent = $providerCategory['parent'] !== null ? TextNormalizer::key($providerCategory['parent']) : null;

        foreach (self::attempts($leaf, $parent) as [$targetName, $allowTransfer]) {
            $id = self::resolve($usableCategoriesByName[$targetName] ?? [], $kind, $allowTransfer);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return list<array{0: string, 1: bool}>
     */
    private static function attempts(string $leaf, ?string $parent): array
    {
        $attempts = [];

        foreach ([$leaf, $parent] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $synonymTarget = self::SYNONYMS[$candidate] ?? null;

            if ($synonymTarget !== null) {
                $attempts[] = [TextNormalizer::key($synonymTarget), in_array($candidate, self::TRANSFER_ALLOWED, true)];
            }
        }

        $attempts[] = [$leaf, false];

        if ($parent !== null) {
            $attempts[] = [$parent, false];
        }

        return $attempts;
    }

    /**
     * @param  list<array{id: int, kind: string, is_transfer: bool, has_parent: bool}>  $candidates
     */
    private static function resolve(array $candidates, string $kind, bool $allowTransfer): ?int
    {
        $filtered = array_values(array_filter(
            $candidates,
            fn (array $c) => $c['kind'] === $kind && (! $c['is_transfer'] || $allowTransfer),
        ));

        if ($filtered === []) {
            return null;
        }

        if (count($filtered) === 1) {
            return $filtered[0]['id'];
        }

        // Mais de uma categoria ativa com o mesmo nome (usuário pode ter
        // criado uma subcategoria com o mesmo nome de uma categoria raiz):
        // prefere a subcategoria. Empate entre duas do mesmo nível é
        // ambíguo de propósito — não arrisca escolher a errada.
        $children = array_values(array_filter($filtered, fn (array $c) => $c['has_parent']));

        return count($children) === 1 ? $children[0]['id'] : null;
    }
}
