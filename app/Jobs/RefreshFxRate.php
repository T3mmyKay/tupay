<?php

namespace App\Jobs;

use App\Domain\Swap\FxRateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshFxRate implements ShouldQueue
{
    use Queueable;

    public function handle(FxRateService $rates): void
    {
        try {
            $rates->refresh();
        } finally {
            $rates->clearRefreshMarker();
        }
    }
}
