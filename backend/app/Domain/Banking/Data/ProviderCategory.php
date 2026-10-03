<?php

namespace App\Domain\Banking\Data;

/**
 * Categoria do provedor, já traduzida (pt-BR). `fromArray()` fica no
 * provedor concreto (PluggyProvider), não aqui.
 */
final readonly class ProviderCategory
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $parentId,
    ) {}
}
