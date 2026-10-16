<?php

namespace App\Domain\Categorization\Support;

use App\Domain\Rules\Support\TextNormalizer;

/**
 * Chave de contraparte: agrupa lançamentos pendentes pela mesma origem/
 * destino, a partir da description_key já normalizada por
 * App\Domain\Rules\Support\TextNormalizer::key(). Tira um prefixo bancário
 * conhecido (ex.: "PIX ENVIADO") e, do que sobra, um sufixo de localização
 * (cidade heurística + país, ex.: "BRA" ou "CA"). Pix enviado e recebido da
 * mesma pessoa caem na mesma chave porque os dois prefixos tiram o mesmo
 * resto.
 *
 * As listas abaixo são só heurística, fáceis de aumentar: um prefixo, país
 * ou cidade novo que o banco passe a usar entra aqui, sem mexer no resto do
 * agrupamento.
 */
final class CounterpartyKey
{
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
     * Países/UF reconhecidos como último elemento do sufixo de localização
     * (ver matchSuffixWordCount()). Lista mínima de propósito: só o que o
     * exemplo real (Brasil) e o do design doc (EUA) precisam.
     *
     * @var list<string>
     */
    private const COUNTRIES = ['BRA', 'CA', 'US', 'USA'];

    /**
     * Cidades frequentes nas descrições de Pix/débito, sem palavras que
     * também são nomes de loja comuns (ex.: "Natal", "Santos", "Vitória"
     * ficaram de fora — cortariam nome de contraparte à toa). Mesmo
     * princípio do PREFIXES: a cidade com mais palavras vence (ver
     * citiesByWordCountDesc()), para "SAO PAULO" não parar em "PAULO".
     *
     * @var list<string>
     */
    private const CITIES = [
        'SAO PAULO', 'RIO DE JANEIRO', 'BELO HORIZONTE', 'PORTO ALEGRE',
        'CAXIAS DO SUL', 'SAO BERNARDO DO CAMPO', 'DUQUE DE CAXIAS',
        'RIBEIRAO PRETO', 'JOAO PESSOA', 'CAMPO GRANDE', 'BOA VISTA',
        'SAO LUIS', 'RIO BRANCO', 'PORTO VELHO', 'SAN FRANCISCO',
        'CURITIBA', 'FLORIANOPOLIS', 'CHAPECO', 'BRASILIA', 'SALVADOR',
        'FORTALEZA', 'RECIFE', 'GOIANIA', 'CAMPINAS', 'JOINVILLE',
        'BLUMENAU', 'LONDRINA', 'MARINGA', 'UBERLANDIA', 'SOROCABA',
        'NITEROI', 'GUARULHOS', 'OSASCO', 'JUNDIAI',
        'MANAUS', 'BELEM', 'MACEIO', 'ARACAJU', 'CUIABA',
        'MACAPA', 'TERESINA',
    ];

    /** @var list<string>|null */
    private static ?array $sortedPrefixes = null;

    /** @var list<string>|null */
    private static ?array $sortedCities = null;

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
     * extrair dali a parte equivalente à chave — precisa ser uma substring
     * de verdade do texto original (preserva grafia, dígitos e pontuação
     * como "*"/"-"), porque a regra padrão do frontend casa
     * `original_description contains <nome>`; só cai no título puro da
     * chave quando a extração não acha nada equivalente.
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
     * Tokeniza a descrição original só por espaço (preserva pontuação
     * interna de um token, ex.: "SHPP*BRASIL", "Auto-Posto") e normaliza
     * cada token com TextNormalizer::key() — um token pode virar zero
     * palavras (só dígito/pontuação, ex.: "123.456.789-00", "99" sozinho
     * não entra aqui) ou mais de uma (ex.: "SHPP*BRASIL" → "SHPP", "BRASIL",
     * já que o `*` também é separador para TextNormalizer).
     *
     * O prefixo/sufixo é casado só contra as palavras não vazias — um token
     * que não normaliza em nada nunca entra no match nem pode ser a
     * primeira/última palavra do que resta, então um token numérico isolado
     * logo após o prefixo ou logo antes do sufixo já fica de fora sozinho
     * (ex.: "12/10 COMPRA NO DEBITO PADARIA" não vira "12/10 Padaria"). Um
     * token assim no MEIO do que resta continua preservado, porque o nome
     * final é o recorte da string original entre o primeiro e o último
     * token mantido, não a junção das palavras normalizadas (ex.: "Loja 99
     * Exemplo" mantém o "99").
     */
    private static function extractFromOriginal(string $original): ?string
    {
        preg_match_all('/\S+/u', $original, $matches, PREG_OFFSET_CAPTURE);

        /** @var list<array{0: string, 1: int}> $tokens */
        $tokens = $matches[0];

        if ($tokens === []) {
            return null;
        }

        /** @var list<array{word: string, tokenIndex: int}> $wordEntries */
        $wordEntries = [];

        foreach ($tokens as $tokenIndex => $token) {
            foreach (explode(' ', TextNormalizer::key($token[0])) as $word) {
                if ($word !== '') {
                    $wordEntries[] = ['word' => $word, 'tokenIndex' => $tokenIndex];
                }
            }
        }

        if ($wordEntries === []) {
            return null;
        }

        $prefixCount = self::matchPrefixWordCount(array_column($wordEntries, 'word'));
        $afterPrefix = array_slice($wordEntries, $prefixCount);

        $suffixCount = self::matchSuffixWordCount(array_column($afterPrefix, 'word'));
        $remainder = $suffixCount > 0 ? array_slice($afterPrefix, 0, -$suffixCount) : $afterPrefix;

        if ($remainder === []) {
            return null;
        }

        $firstToken = $tokens[$remainder[0]['tokenIndex']];
        $lastToken = $tokens[end($remainder)['tokenIndex']];

        $start = $firstToken[1];
        $end = $lastToken[1] + strlen($lastToken[0]);

        // substr(), não mb_substr(): os offsets de preg_match_all são em
        // bytes, e um limite de token sempre cai numa borda de caractere
        // válida (o \S+ nunca partiu um caractere multibyte no meio) —
        // substr() por byte reproduz o trecho certo sem reconverter nada.
        return self::prettyCase(substr($original, $start, $end - $start));
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
     * Quantidade de palavras do sufixo "<cidade> <país>" a remover do
     * final — 0 quando a última palavra não é um país conhecido ou nenhuma
     * cidade conhecida aparece logo antes.
     *
     * @param  list<string>  $normalizedWords
     */
    private static function matchSuffixWordCount(array $normalizedWords): int
    {
        if ($normalizedWords === [] || ! in_array(end($normalizedWords), self::COUNTRIES, true)) {
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
        return self::$sortedPrefixes ??= self::sortByWordCountDesc(self::PREFIXES);
    }

    /**
     * @return list<string>
     */
    private static function citiesByWordCountDesc(): array
    {
        return self::$sortedCities ??= self::sortByWordCountDesc(self::CITIES);
    }

    /**
     * @param  list<string>  $phrases
     * @return list<string>
     */
    private static function sortByWordCountDesc(array $phrases): array
    {
        usort($phrases, fn (string $a, string $b) => substr_count($b, ' ') <=> substr_count($a, ' '));

        return $phrases;
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
