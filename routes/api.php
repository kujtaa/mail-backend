<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Auth
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->get('/auth/me', [AuthController::class, 'me']);

Route::middleware(['auth:sanctum', 'approved'])->prefix('dashboard')->group(function () {
    Route::get('/stats', [DashboardController::class, 'stats']);
    Route::get('/breakdown', [DashboardController::class, 'breakdown']);
    Route::get('/categories', [DashboardController::class, 'categories']);
    Route::get('/browse-overview', [DashboardController::class, 'browseOverview']);
    Route::get('/categories-list', [DashboardController::class, 'categoriesList']);
    Route::get('/browse-emails', [DashboardController::class, 'browseEmails']);
    Route::post('/purchase-batch', [DashboardController::class, 'purchaseBatch']);
    Route::post('/purchase-batch-multi', [DashboardController::class, 'purchaseBatchMulti']);
    Route::get('/my-batches', [DashboardController::class, 'myBatches']);
    Route::get('/my-batches/{batchId}/emails', [DashboardController::class, 'batchEmails']);
    Route::post('/send-email', [DashboardController::class, 'sendEmail']);
    Route::post('/send-manual', [DashboardController::class, 'sendManual']);
    Route::get('/sent-history', [DashboardController::class, 'sentHistory']);
    Route::get('/smtp-settings', [DashboardController::class, 'getSmtpSettings']);
    Route::put('/smtp-settings', [DashboardController::class, 'saveSmtpSettings']);
    Route::post('/smtp-test', [DashboardController::class, 'testSmtp']);
});
