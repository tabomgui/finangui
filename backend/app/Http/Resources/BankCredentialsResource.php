<?php

namespace App\Http\Resources;

use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Models\BankCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resposta de GET/PUT /bank-credentials
 * (App\Http\Controllers\Api\V1\BankCredentialController). Recebe a
 * credencial do usuário autenticado ou null (nenhuma cadastrada ainda).
 * client_id_hint e verified_at só aparecem quando configurado (omitidos,
 * nunca null — ver CLAUDE.md sobre o Readable<T> do openapi-fetch);
 * client_id/client_secret nunca saem daqui (nem chegam a sair de
 * App\Domain\Banking\Models\BankCredential — ver $hidden).
 *
 * @mixin BankCredential
 */
final class BankCredentialsResource extends JsonResource
{
    /**
     * @return array{configured: bool, provider: string, client_id_hint?: string, verified_at?: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'configured' => $this->configured(),
            'provider' => BankProviderName::Pluggy->value,
            'client_id_hint' => $this->when($this->configured(), fn (): string => $this->client_id_hint),
            'verified_at' => $this->when($this->configured(), fn (): string => $this->verified_at->toIso8601String()),
        ];
    }

    private function configured(): bool
    {
        return $this->resource instanceof BankCredential && $this->verified_at !== null;
    }
}
