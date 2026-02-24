<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\ClientAuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DossierController;
use App\Http\Controllers\Api\QuestionnaireController;
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

// Routes publiques

// Routes d'authentification ADMIN
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);

    // Routes protégées pour les admins
    Route::middleware('auth:admin')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);

        // Routes pour les questionnaires
        Route::post('/questionnaires/send', [QuestionnaireController::class, 'sendQuestionnaire']);
        Route::get('/questionnaires', [QuestionnaireController::class, 'listQuestionnaires']);
        Route::get('/questionnaires/{id}', [QuestionnaireController::class, 'getQuestionnaire']);
        Route::get('/clients', [QuestionnaireController::class, 'getClients']);

        // Module Client (CRUD clients, membres famille, dossiers)
        Route::get('/module-clients/options', [ClientController::class, 'options']);
        Route::apiResource('module-clients', ClientController::class)->parameters(['module-client' => 'id']);
        Route::post('module-clients/{id}/family-members', [ClientController::class, 'addFamilyMember']);
        Route::put('module-clients/{id}/family-members/{memberId}', [ClientController::class, 'updateFamilyMember']);
        Route::delete('module-clients/{id}/family-members/{memberId}', [ClientController::class, 'destroyFamilyMember']);

        Route::get('/dossiers/options', [DossierController::class, 'options']);
        Route::apiResource('dossiers', DossierController::class)->parameters(['dossier' => 'id']);
    });
});

// Routes d'authentification CLIENT
Route::prefix('client')->group(function () {   
    Route::post('/register', [ClientAuthController::class, 'register']);
    Route::post('/login', [ClientAuthController::class, 'login']);

    // Routes protégées pour les clients
    Route::middleware('auth:client')->group(function () {
        Route::post('/logout', [ClientAuthController::class, 'logout']);
        Route::get('/me', [ClientAuthController::class, 'me']);
    });
});

// Routes publiques pour les questionnaires
Route::post('/questionnaires/verify', [QuestionnaireController::class, 'verifyAccess']);
Route::post('/questionnaires/{code}/save', [QuestionnaireController::class, 'saveFormData']);
Route::post('/questionnaires/{code}/submit', [QuestionnaireController::class, 'submitForm']);

// Route de test
Route::get('/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is running',
        'timestamp' => now(),
    ]);
});


