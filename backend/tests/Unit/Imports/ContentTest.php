<?php

use App\Domain\Imports\Support\Content;

it('remove o BOM UTF-8 do início do conteúdo', function () {
    $raw = "\xEF\xBB\xBFData;Valor\n01/01/2026;10,00";

    expect(Content::normalize($raw))->toBe("Data;Valor\n01/01/2026;10,00");
});

it('converte conteúdo em Windows-1252 para UTF-8', function () {
    $raw = mb_convert_encoding("Data Lançamento;Valor\n", 'Windows-1252', 'UTF-8');

    expect(Content::normalize($raw))->toBe("Data Lançamento;Valor\n");
});

it('mantém conteúdo já em UTF-8 sem alterar os caracteres', function () {
    $raw = "Data Lançamento;Valor\n01/01/2026;10,00";

    expect(Content::normalize($raw))->toBe($raw);
});

it('normaliza quebras de linha CRLF e CR para LF', function () {
    expect(Content::normalize("a;b\r\nc;d\re;f"))->toBe("a;b\nc;d\ne;f");
});

it('converte UTF-16LE (com BOM) para UTF-8', function () {
    $raw = "\xFF\xFE".mb_convert_encoding("Data Lançamento;Valor\n01/01/2026;10,00", 'UTF-16LE', 'UTF-8');

    expect(Content::normalize($raw))->toBe("Data Lançamento;Valor\n01/01/2026;10,00");
});

it('converte UTF-16BE (com BOM) para UTF-8', function () {
    $raw = "\xFE\xFF".mb_convert_encoding("Data Lançamento;Valor\n01/01/2026;10,00", 'UTF-16BE', 'UTF-8');

    expect(Content::normalize($raw))->toBe("Data Lançamento;Valor\n01/01/2026;10,00");
});

it('converte só as linhas realmente inválidas quando o resto do arquivo já é UTF-8', function () {
    $utf8Line = 'Café bom';
    $latin1Line = mb_convert_encoding('Pastelaria São Paulo', 'Windows-1252', 'UTF-8');
    $raw = $utf8Line."\n".$latin1Line."\n".$utf8Line;

    expect(Content::normalize($raw))->toBe($utf8Line."\nPastelaria São Paulo\n".$utf8Line);
});
