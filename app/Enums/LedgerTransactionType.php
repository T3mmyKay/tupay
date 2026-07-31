<?php

namespace App\Enums;

enum LedgerTransactionType: string
{
    case FUNDING = 'FUNDING';
    case SWAP_DEBIT = 'SWAP_DEBIT';
    case SETTLEMENT_CREDIT = 'SETTLEMENT_CREDIT';
}
