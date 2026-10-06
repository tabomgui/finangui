<?php

namespace App\Domain\Banking\Providers;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Contracts\BankProviderFactory;
use App\Domain\Banking\Errors\BankingDisabled;
use App\Models\User;

/**
 * Fábrica fake para teste (ver tests/Pest.php, fakeBankProvider()): devolve
 * sempre o mesmo BankProvider — normalmente um FakeBankProvider —, para
 * qualquer usuário, já que nenhum teste de domínio/HTTP deve precisar de
 * credenciais reais da Pluggy cadastradas para exercitar o Banking.
 * disable() simula "usuário sem credenciais" (BankingDisabled), sem
 * precisar de um estado "enabled" dentro do provedor em si.
 */
final class FakeBankProviderFactory implements BankProviderFactory
{
    private bool $disabled = false;

    public function __construct(private readonly BankProvider $provider) {}

    public function disable(): void
    {
        $this->disabled = true;
    }

    public function for(User $user): BankProvider
    {
        if ($this->disabled) {
            throw new BankingDisabled;
        }

        return $this->provider;
    }
}
