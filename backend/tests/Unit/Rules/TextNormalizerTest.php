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
