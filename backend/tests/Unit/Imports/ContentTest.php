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
