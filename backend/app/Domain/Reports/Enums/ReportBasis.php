<?php

namespace App\Domain\Reports\Enums;

/**
 * Visão de competência dos relatórios: purchase (data do lançamento) ou
 * statement (vencimento da fatura, para transações de cartão).
 */
enum ReportBasis: string
{
    case Purchase = 'purchase';
    case Statement = 'statement';
}
