<?php

use App\Http\Controllers\PointageScanController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Offline scan station sync — Sanctum bearer-token only, no session (see
// PointageScanController for why: the station page must keep working through a whole
// offline day, which a session cookie can't guarantee).
Route::middleware('auth:sanctum')->prefix('pointage/scan-station')->group(function () {
    Route::get('/station-data', [PointageScanController::class, 'stationData'])->name('pointage.scan-station.data');
    Route::post('/sync', [PointageScanController::class, 'sync'])->name('pointage.scan-station.sync');
});
