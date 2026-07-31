<?php

use Illuminate\Support\Facades\Route;

Route::get('/', static fn (): array => [
    'service' => 'Tupay Ledger & Settlement Engine',
    'status' => 'ok',
]);
