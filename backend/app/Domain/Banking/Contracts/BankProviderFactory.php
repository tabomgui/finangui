<?php

namespace App\Domain\Banking\Contracts;

use App\Domain\Banking\Errors\BankingDisabled;
use App\Models\User;

/**
 * Monta o BankProvider de um usuário específico a partir das credenciais que
 * ele mesmo cadastrou (App\Domain\Banking\Models\BankCredential). Não há mais
 * provedor global: todo código que fala com o provedor passa por aqui,
 * sempre informando o dono da conexão/request (controller/ações usam o
 * usuário autenticado; job de sync usa o dono da conexão).
 */
interface BankProviderFactory
{
    /**
     * @throws BankingDisabled quando o usuário não tem credenciais verificadas cadastradas
     */
    public function for(User $user): BankProvider;
}
