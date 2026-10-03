<?php

use App\Domain\Imports\Support\CsvReader;

it('divide linhas respeitando o delimitador dentro de aspas', function () {
    $content = "Data,Descrição,Valor\n01/01/2026,\"Loja, Centro\",10,00";

    expect(CsvReader::rows($content, ','))->toBe([
        ['Data', 'Descrição', 'Valor'],
        ['01/01/2026', 'Loja, Centro', '10', '00'],
    ]);
});

it('ignora linhas em branco', function () {
    $content = "a,b\n\n   \nc,d";

    expect(CsvReader::rows($content, ','))->toBe([
        ['a', 'b'],
        ['c', 'd'],
    ]);
});

it('remove espaços nas bordas de cada célula', function () {
    $content = " a , b \n c , d ";

    expect(CsvReader::rows($content, ','))->toBe([
        ['a', 'b'],
        ['c', 'd'],
    ]);
});

it('respeita o delimitador ponto e vírgula', function () {
    $content = "a;b\nc;d";

    expect(CsvReader::rows($content, ';'))->toBe([
        ['a', 'b'],
        ['c', 'd'],
    ]);
});
