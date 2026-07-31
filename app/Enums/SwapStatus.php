<?php

namespace App\Enums;

enum SwapStatus: string
{
    case PENDING = 'PENDING';
    case INITIATED = 'INITIATED';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    public function rank(): int
    {
        return match ($this) {
            self::PENDING => 0,
            self::INITIATED => 1,
            self::PROCESSING => 2,
            self::COMPLETED, self::FAILED => 3,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::FAILED;
    }
}
