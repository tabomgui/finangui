<?php

namespace App\Domain\Transfers\Support;

use App\Domain\Transfers\Data\TransferCandidate;

/**
 * Puro, sem banco: pistas de descrição (e nome da outra conta) que
 * contribuem pontuação em TransferMatcher::score() e são exigidas para
 * ligar automaticamente (TransferMatcher::canAutoLink()).
 */
final class TransferClues
{
    /** TRANSF casa como prefixo (TRANSFERENCIA, TRANSFERIDO); as demais exigem a palavra exata. */
    private const EVIDENCE_KEYWORDS = ['TRANSF', 'PIX', 'TED', 'DOC', 'PAGAMENTO', 'FATURA', 'APLICACAO', 'RESGATE'];

    private const PAYMENT_KEYWORDS = ['PAGAMENTO', 'FATURA'];

    private const MIN_ACCOUNT_NAME_LENGTH = 3;

    public static function hasClue(TransferCandidate $out, TransferCandidate $in): bool
    {
        return self::hasKeywordClue($out->description)
            || self::hasKeywordClue($in->description)
            || self::hasAccountNameClue($out, $in);
    }

    public static function hasPaymentClue(TransferCandidate $out, TransferCandidate $in): bool
    {
        foreach (self::PAYMENT_KEYWORDS as $keyword) {
            if (self::matchesKeyword($out->description, $keyword) || self::matchesKeyword($in->description, $keyword)) {
                return true;
            }
        }

        return false;
    }

    public static function hasEstornoClue(TransferCandidate $out, TransferCandidate $in): bool
    {
        return self::matchesKeyword($out->description, 'ESTORNO') || self::matchesKeyword($in->description, 'ESTORNO');
    }

    private static function hasKeywordClue(string $description): bool
    {
        foreach (self::EVIDENCE_KEYWORDS as $keyword) {
            if (self::matchesKeyword($description, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private static function hasAccountNameClue(TransferCandidate $out, TransferCandidate $in): bool
    {
        if (mb_strlen($in->accountName) >= self::MIN_ACCOUNT_NAME_LENGTH && self::matchesKeyword($out->description, $in->accountName)) {
            return true;
        }

        if (mb_strlen($out->accountName) >= self::MIN_ACCOUNT_NAME_LENGTH && self::matchesKeyword($in->description, $out->accountName)) {
            return true;
        }

        return false;
    }

    /**
     * Palavra inteira, não substring: "UNITED" não casa `TED`, "DOCERIA"
     * não casa `DOC`, conta "Inter" não casa "INTERNET" na descrição.
     * `TRANSF` é o único prefixo (casa TRANSFERENCIA, TRANSFERIDO etc.);
     * preg_quote escapa qualquer caractere especial de um nome de conta
     * usado como pista.
     */
    private static function matchesKeyword(string $haystack, string $keyword): bool
    {
        $suffix = $keyword === 'TRANSF' ? '[A-Z0-9]*' : '';
        $pattern = '/\b'.preg_quote($keyword, '/').$suffix.'\b/';

        return preg_match($pattern, $haystack) === 1;
    }
}
