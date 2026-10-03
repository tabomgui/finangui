<?php

namespace App\Domain\Transfers\Errors;

use App\Domain\Shared\DomainError;

final class TransferSuggestionNotPending extends DomainError
{
    public function __construct()
    {
        parent::__construct('Esta sugestão não está mais pendente.');
    }

    public function errorCode(): string
    {
        return 'transfer_suggestion_not_pending';
    }
}
