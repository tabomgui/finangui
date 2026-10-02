<?php

namespace App\Support;

final class GoogleCredentials
{
    /**
     * Login com Google só funciona com um OAuth client configurado na instância.
     */
    public static function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }
}
