<?php

namespace App\Domain\Rules\Data;

/**
 * Estado da transação que limita o que as regras podem mudar: se já tem
 * categoria, se foi definida à mão, se a descrição está travada, se a
 * aplicação retroativa pode sobrescrever e se o lançamento é manual (só
 * recebe categoria, nunca as outras ações).
 */
final readonly class RuleContext
{
    public function __construct(
        public bool $hasCategory,
        public bool $categoryManual,
        public bool $descriptionLocked,
        public bool $overwrite,
        public bool $onlyCategory,
    ) {}
}
