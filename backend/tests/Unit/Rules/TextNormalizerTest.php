<?php

use App\Domain\Rules\Support\TextNormalizer;

it('normaliza para maiúsculas sem acento e espaços colapsados', function () {
    expect(TextNormalizer::normalize('  Padaria  São   João '))->toBe('PADARIA SAO JOAO')
        ->and(TextNormalizer::normalize('ação Ç ü'))->toBe('ACAO C U')
        ->and(TextNormalizer::normalize(null))->toBe('')
        ->and(TextNormalizer::normalize(''))->toBe('');
});

it('a chave tira dígitos e pontuação', function () {
    expect(TextNormalizer::key('UBER *TRIP 1234'))->toBe('UBER TRIP')
        ->and(TextNormalizer::key('Pix enviado - João 12/09'))->toBe('PIX ENVIADO JOAO')
        ->and(TextNormalizer::key('123 456'))->toBe('');
});

it('normaliza transliterando ß e ligaduras, e tratando espaço não separável como espaço', function () {
    expect(TextNormalizer::normalize('straße'))->toBe('STRASSE')
        ->and(TextNormalizer::normalize("\u{FB01}le"))->toBe('FILE')
        ->and(TextNormalizer::normalize("a\u{00A0}b"))->toBe('A B');
});

it('normaliza para vazio texto sem equivalente ascii (emoji, CJK)', function () {
    expect(TextNormalizer::normalize('😀😀'))->toBe('')
        ->and(TextNormalizer::normalize('日本語'))->toBe('');
});

it('a chave nunca passa de 255 caracteres, mesmo quando a transliteração expande o texto', function () {
    $key = TextNormalizer::key(str_repeat('Щ', 200));

    expect(mb_strlen($key))->toBeLessThanOrEqual(255);
});
