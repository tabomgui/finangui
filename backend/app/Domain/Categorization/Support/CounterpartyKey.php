<?php

namespace App\Domain\Categorization\Support;

use Illuminate\Support\Str;

/**
 * Chave de contraparte: agrupa lançamentos pendentes pela mesma origem/
 * destino, a partir da description_key já normalizada por
 * App\Domain\Rules\Support\TextNormalizer::key(). Tira um prefixo bancário
 * conhecido (ex.: "PIX ENVIADO") e, do que sobra, um sufixo de localização
 * (cidade heurística + país "BRA"). Pix enviado e recebido da mesma pessoa
 * caem na mesma chave porque os dois prefixos tiram o mesmo resto.
 *
 * As duas listas abaixo são só heurística, fáceis de aumentar: um prefixo ou
 * cidade nova que o banco passe a usar entra aqui, sem mexer no resto do
 * agrupamento.
 */
final class CounterpartyKey
{
    private const COUNTRY = 'BRA';

    /**
     * Ordem não importa: o match sempre tenta o prefixo com mais palavras
     * primeiro (ver prefixesByWordCountDesc()), então "PIX ENVIADO
     * DEVOLVIDO" vence "PIX ENVIADO" quando os dois bateriam.
     *
     * @var list<string>
     */
    private const PREFIXES = [
        'PIX ENVIADO DEVOLVIDO',
        'PIX ENVIADO',
        'PIX RECEBIDO',
        'COMPRA NO DEBITO',
        'PAGAMENTO EFETUADO',
        'PAGAMENTO DE TITULO',
        'BOLETO RECEBIDO HORAS',
        'RESGATE',
        'APLICACAO',
        'TRANSFERENCIA ENVIADA',
        'TRANSFERENCIA RECEBIDA',
        'TED ENVIADA',
        'TED RECEBIDA',
    ];

    /**
     * Cidades brasileiras frequentes nas descrições de Pix/débito. Mesmo
     * princípio do PREFIXES: a cidade com mais palavras vence (ver
     * citiesByWordCountDesc()), para "SAO PAULO" não parar em "PAULO".
     *
     * @var list<string>
     */
    private const CITIES = [
        'SAO PAULO', 'RIO DE JANEIRO', 'BELO HORIZONTE', 'PORTO ALEGRE',
        'CAXIAS DO SUL', 'SAO BERNARDO DO CAMPO', 'DUQUE DE CAXIAS',
        'RIBEIRAO PRETO', 'JOAO PESSOA', 'CAMPO GRANDE', 'BOA VISTA',
        'SAO LUIS', 'RIO BRANCO', 'PORTO VELHO', 'SAO JOSE',
        'CURITIBA', 'FLORIANOPOLIS', 'CHAPECO', 'BRASILIA', 'SALVADOR',
        'FORTALEZA', 'RECIFE', 'GOIANIA', 'CAMPINAS', 'JOINVILLE',
        'BLUMENAU', 'LONDRINA', 'MARINGA', 'UBERLANDIA', 'SOROCABA',
        'NITEROI', 'GUARULHOS', 'OSASCO', 'JUNDIAI', 'VITORIA',
        'MANAUS', 'BELEM', 'NATAL', 'MACEIO', 'ARACAJU', 'CUIABA',
        'PALMAS', 'MACAPA', 'TERESINA', 'SANTOS',
    ];

    /**
     * description_key (já normalizada) → chave de contraparte. Vazia
     * continua vazia; quando não sobra nada depois do prefixo (ex.:
     * "RESGATE"), a chave é o próprio prefixo.
     */
    public static function fromDescriptionKey(string $descriptionKey): string
    {
        if ($descriptionKey === '') {
            return '';
        }

        $words = explode(' ', $descriptionKey);

        $prefixCount = self::matchPrefixWordCount($words);
        $afterPrefix = array_slice($words, $prefixCount);

        $suffixCount = self::matchSuffixWordCount($afterPrefix);
        $remainderWords = $suffixCount > 0 ? array_slice($afterPrefix, 0, -$suffixCount) : $afterPrefix;

        $remainder = implode(' ', $remainderWords);

        if ($remainder !== '') {
            return $remainder;
        }

        return $prefixCount > 0 ? implode(' ', array_slice($words, 0, $prefixCount)) : $descriptionKey;
    }

    /**
     * Nome exibido do grupo: por padrão, a chave em "Título Caso". Quando a
     * descrição original (de um lançamento do grupo) é informada, prefere
     * extrair dali a parte equivalente à chave — preserva grafia (ex.:
     * "Fulano De Tal") e dígitos que a chave normalizada descarta (ex.:
     * "Loja 99"); só cai no título puro da chave quando a extração não acha
     * nada equivalente.
     */
    public static function displayName(string $key, ?string $originalDescription = null): string
    {
        if ($originalDescription !== null) {
            $extracted = self::extractFromOriginal($originalDescription);

            if ($extracted !== null) {
                return $extracted;
            }
        }

        return $key === '' ? '' : self::titleCase($key);
    }

    /**
     * Mesmo recorte de matchPrefixWordCount()/matchSuffixWordCount(), mas
     * aplicado a palavras tokenizadas da descrição original (que preserva
     * caixa, acento e dígitos) em vez da description_key.
     */
    private static function extractFromOriginal(string $original): ?string
    {
        $tokens = preg_split('/[\s\-:;*|]+/u', trim($original), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false || $tokens === []) {
            return null;
        }

        $normalized = array_map(fn (string $token) => mb_strtoupper(Str::ascii($token)), $tokens);

        $prefixCount = self::matchPrefixWordCount($normalized);
        $afterPrefix = array_slice($tokens, $prefixCount);
        $afterPrefixNormalized = array_slice($normalized, $prefixCount);

        $suffixCount = self::matchSuffixWordCount($afterPrefixNormalized);
        $remainder = $suffixCount > 0 ? array_slice($afterPrefix, 0, -$suffixCount) : $afterPrefix;

        $name = trim(implode(' ', $remainder));

        return $name !== '' ? self::prettyCase($name) : null;
    }

    /**
     * @param  list<string>  $normalizedWords
     */
    private static function matchPrefixWordCount(array $normalizedWords): int
    {
        foreach (self::prefixesByWordCountDesc() as $prefix) {
            $prefixWords = explode(' ', $prefix);
            $count = count($prefixWords);

            if (count($normalizedWords) >= $count && array_slice($normalizedWords, 0, $count) === $prefixWords) {
                return $count;
            }
        }

        return 0;
    }

    /**
     * Quantidade de palavras do sufixo "<cidade> BRA" a remover do final —
     * 0 quando a última palavra não é "BRA" ou nenhuma cidade conhecida
     * aparece logo antes.
     *
     * @param  list<string>  $normalizedWords
     */
    private static function matchSuffixWordCount(array $normalizedWords): int
    {
        if ($normalizedWords === [] || end($normalizedWords) !== self::COUNTRY) {
            return 0;
        }

        $withoutCountry = array_slice($normalizedWords, 0, -1);

        foreach (self::citiesByWordCountDesc() as $city) {
            $cityWords = explode(' ', $city);
            $count = count($cityWords);

            if (count($withoutCountry) >= $count && array_slice($withoutCountry, -$count) === $cityWords) {
                return $count + 1;
            }
        }

        return 0;
    }

    /**
     * @return list<string>
     */
    private static function prefixesByWordCountDesc(): array
    {
        $prefixes = self::PREFIXES;
        usort($prefixes, fn (string $a, string $b) => substr_count($b, ' ') <=> substr_count($a, ' '));

        return $prefixes;
    }

    /**
     * @return list<string>
     */
    private static function citiesByWordCountDesc(): array
    {
        $cities = self::CITIES;
        usort($cities, fn (string $a, string $b) => substr_count($b, ' ') <=> substr_count($a, ' '));

        return $cities;
    }

    /**
     * Título puro, usado tanto no fallback de displayName() quanto para
     * arrumar um trecho extraído que veio todo em caixa alta ou baixa (ver
     * prettyCase()).
     */
    private static function titleCase(string $text): string
    {
        return mb_convert_case($text, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Preserva a grafia de um trecho extraído da descrição original quando
     * ela já tem caixa mista (provável nome próprio bem formatado, ex.:
     * "Fulano De Tal"); só força título quando está tudo numa caixa só
     * (texto bruto do banco, ex.: "LOJA EXEMPLO" ou "loja exemplo").
     */
    private static function prettyCase(string $text): string
    {
        if (mb_strtoupper($text) === $text || mb_strtolower($text) === $text) {
            return self::titleCase($text);
        }

        return $text;
    }
}
