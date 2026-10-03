<?php

use App\Domain\Imports\Support\CsvReader;

it('divide linhas respeitando o delimitador dentro de aspas', function () {
    $content = "Data,Descrição,Valor\n01/01/2026,\"Loja, Centro\",10,00";

    expect(CsvReader::rows($content, ','))->toBe([
        ['line' => 1, 'cells' => ['Data', 'Descrição', 'Valor']],
        ['line' => 2, 'cells' => ['01/01/2026', 'Loja, Centro', '10', '00']],
    ]);
});

it('ignora linhas em branco e mantém o número real da linha no arquivo', function () {
    $content = "a,b\n\n   \nc,d";

    expect(CsvReader::rows($content, ','))->toBe([
        ['line' => 1, 'cells' => ['a', 'b']],
        ['line' => 4, 'cells' => ['c', 'd']],
    ]);
});

it('remove espaços nas bordas de cada célula', function () {
    $content = " a , b \n c , d ";

    expect(CsvReader::rows($content, ','))->toBe([
        ['line' => 1, 'cells' => ['a', 'b']],
        ['line' => 2, 'cells' => ['c', 'd']],
    ]);
});

it('respeita o delimitador ponto e vírgula', function () {
    $content = "a;b\nc;d";

    expect(CsvReader::rows($content, ';'))->toBe([
        ['line' => 1, 'cells' => ['a', 'b']],
        ['line' => 2, 'cells' => ['c', 'd']],
    ]);
});

it('não trata barra invertida como escape: ela é um caractere comum dentro da célula', function () {
    $content = 'a,"b\",c",d';

    expect(CsvReader::rows($content, ','))->toBe([
        ['line' => 1, 'cells' => ['a', 'b\\', 'c"', 'd']],
    ]);
});
