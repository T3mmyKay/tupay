<?php

namespace App\Domain\Swap;

use RuntimeException;

final class IdempotencyConflict extends RuntimeException {}
