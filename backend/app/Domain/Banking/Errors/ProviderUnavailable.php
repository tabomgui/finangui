<?php

namespace App\Domain\Banking\Errors;

use RuntimeException;

/**
 * Falha transitória do provedor (5xx, 429, timeout de rede). Não é um
 * DomainError: quem lida com isso é o job de sincronização, que tenta de
 * novo com espera crescente antes de marcar a conexão como `error`.
 */
final class ProviderUnavailable extends RuntimeException {}
