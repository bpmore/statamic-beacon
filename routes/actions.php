<?php

use Bpmore\Beacon\Http\Controllers\LiveController;
use Illuminate\Support\Facades\Route;

// /!/statamic-beacon/live: the alerts the emergency fast path injects
// between page loads. Served from Beacon's own store, never fetched live.
Route::get('live', LiveController::class)->name('beacon.live');
