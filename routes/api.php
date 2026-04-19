<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\UnsubscribeController;
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

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/companies', [AdminController::class, 'listCompanies']);
    Route::post('/add-credits', [AdminController::class, 'addCredits']);
    Route::post('/approve/{companyId}', [AdminController::class, 'approveCompany']);
    Route::post('/reject/{companyId}', [AdminController::class, 'rejectCompany']);
    Route::get('/all-emails', [AdminController::class, 'allEmails']);
    Route::get('/filter-options', [AdminController::class, 'filterOptions']);
    Route::get('/no-website-businesses', [AdminController::class, 'noWebsiteBusinesses']);
    Route::get('/transactions', [AdminController::class, 'transactions']);
    Route::post('/set-plan', [AdminController::class, 'setPlan']);
    Route::delete('/companies/{companyId}', [AdminController::class, 'deleteCompany']);
    Route::post('/set-sources', [AdminController::class, 'setSources']);
    Route::get('/unsubscribed', [AdminController::class, 'listUnsubscribed']);
    Route::delete('/unsubscribed/{unsubId}', [AdminController::class, 'removeUnsubscribed']);
});

Route::get('/unsubscribe/{token}', [UnsubscribeController::class, 'handle']);
Route::post('/unsubscribe/{token}', [UnsubscribeController::class, 'handle']);
