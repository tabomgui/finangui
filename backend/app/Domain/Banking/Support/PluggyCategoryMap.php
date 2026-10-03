<?php

namespace App\Domain\Banking\Support;

/**
 * Mapa estático de ids de categoria da Pluggy (`GET /categories`, ver
 * https://docs.pluggy.ai/docs/transaction-categories) para o nome de uma
 * categoria padrão (`App\Domain\Categories\Actions\SeedDefaultCategories`).
 * Terceiro passo da categorização de uma transação nova importada do Pluggy
 * — só depois de regras e histórico (ver
 * `App\Domain\Imports\Actions\IngestTransactions`) — e só vale se o usuário
 * ainda tiver uma categoria ativa com esse nome.
 *
 * O prefixo de 4 dígitos (categoria + subcategoria) vence o de 2 (só a
 * categoria); nenhum prefixo correspondente → null (nunca inventa categoria).
 *
 * A Pluggy não publica a tabela numérica completa — a doc pública só mostra
 * dois pares como exemplo ("01000000"/"Income", "01010000"/"Salary/pro-labore"
 * e "05080000"/"Transfer - TED" sob "05000000"/"Transfers"); o resto da
 * tabela só sai de uma chamada autenticada a `GET /categories`, que este
 * ambiente não tem como fazer (sem rede em teste, sem credencial aqui). As
 * entradas abaixo marcadas "confirmado" vêm literalmente desses exemplos; as
 * demais foram inferidas pela ordem documentada da árvore de categorias
 * (https://docs.pluggy.ai/docs/transaction-categories) — mesma contagem de
 * posição que bateu com o "05080000" confirmado (Transfers é o 5º grupo da
 * árvore, TED é o 8º item dentro dele). Confira contra uma chamada real a
 * `GET /categories` antes de confiar nisso para decidir categoria de
 * usuário em produção; o pior caso de um id errado aqui é só a sugestão
 * nunca aparecer (ids desconhecidos caem em null).
 *
 * "Internet" ficou de fora de propósito: na árvore da Pluggy ele é
 * subcategoria de "Telecommunications" dentro de "Services" (um terceiro
 * nível), e este mapa só olha prefixo de 2 ou 4 dígitos — não dá para
 * distinguir Internet de Mobile/TV nesse tamanho de prefixo sem arriscar
 * categorizar conta de celular como Internet.
 *
 * @see https://docs.pluggy.ai/docs/transaction-categories
 */
final class PluggyCategoryMap
{
    /**
     * Chaves que parecem inteiro puro (ex.: "11", "1201") são guardadas pelo
     * PHP como int, não string — o lookup em categoryFor() funciona igual
     * (o acesso por string equivalente também é convertido), então a
     * assinatura aceita os dois.
     *
     * @var array<int|string, string>
     */
    private const MAP = [
        // Income (01) > Salary/pro-labore (01) — confirmado no exemplo da doc.
        '0101' => 'Salário',
        // Investments (03): sem subcategoria usada aqui, mapeado pelo grupo.
        '03' => 'Investimentos',
        // Transfers (05): fallback genérico; "0509" (mais específico) vence
        // para pagamento de fatura.
        '05' => 'Transferências',
        // Transfers (05) > Credit card payment (09, depois de TED=08, que é o
        // confirmado "05080000" da doc).
        '0509' => 'Pagamento de fatura',
        // Digital services (10) > Video streaming (02) / Music streaming (03).
        '1002' => 'Streaming',
        '1003' => 'Streaming',
        // Groceries (11): sem subcategoria documentada, mapeado pelo grupo.
        '11' => 'Mercado',
        // Food and drinks (12) > Eating out (01) / Food delivery (02).
        '1201' => 'Restaurantes',
        '1202' => 'Delivery',
        // Taxes (16): sem distinção por subtipo aqui.
        '16' => 'Impostos',
        // Bank fees (17): sem distinção por subtipo aqui.
        '17' => 'Tarifas bancárias',
        // Housing (18) > Rent (01).
        '1801' => 'Aluguel',
        // Utilities (19) > Electricity (02).
        '1902' => 'Energia',
        // Healthcare (20) > Pharmacy (02).
        '2002' => 'Farmácia',
        // Transportation (21) > Taxi and ride-hailing (01).
        '2101' => 'Aplicativos',
        // Automotive (22) > Gas stations (01).
        '2201' => 'Combustível',
    ];

    /**
     * Nome da categoria padrão sugerida para o id de categoria da Pluggy, ou
     * null se nenhum prefixo (4 ou 2 dígitos) é conhecido.
     */
    public static function categoryFor(?string $providerCategoryId): ?string
    {
        if ($providerCategoryId === null || $providerCategoryId === '') {
            return null;
        }

        return self::MAP[substr($providerCategoryId, 0, 4)]
            ?? self::MAP[substr($providerCategoryId, 0, 2)]
            ?? null;
    }
}
