<?php

namespace App\Enums;

enum WalletType: string
{
    case USER = 'USER';
    case TREASURY = 'TREASURY';
    case CLEARING = 'CLEARING';
    case LIQUIDITY = 'LIQUIDITY';
}
