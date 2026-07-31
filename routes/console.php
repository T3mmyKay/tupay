<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment('Precision before velocity.');
})->purpose('Display a project principle');
