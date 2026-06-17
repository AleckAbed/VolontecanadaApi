<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminProfileController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ClientAuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CollaboratorAuthController;
use App\Http\Controllers\Api\CollaboratorController;
use App\Http\Controllers\Api\CollaboratorWorkspaceController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DossierController;
use App\Http\Controllers\Api\DossierDocumentController;
use App\Http\Controllers\Api\DossierSupplementaryFileController;
use App\Http\Controllers\Api\ImmigrationServiceController;
use App\Http\Controllers\Api\FormTypeController;
use App\Http\Controllers\Api\FileManagerController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\NewsArticleController;
use App\Http\Controllers\Api\NewsSourceController;
use App\Http\Controllers\Api\QuestionnaireController;
use App\Http\Controllers\Api\StatisticsController;
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
        // Vérification du mot de passe sans rotation de token — utilisé par le
        // lock screen pour confirmer l'identité du même admin connecté.
        Route::middleware('throttle:5,1')->post('/verify-password', [AdminAuthController::class, 'verifyPassword']);

        // Profil admin
        Route::put('/profile', [AdminProfileController::class, 'updateProfile']);
        Route::post('/profile/change-password', [AdminProfileController::class, 'changePassword']);

        // Écran de verrouillage
        Route::get('/profile/lock-screen-settings', [AdminProfileController::class, 'getLockScreenSettings']);
        Route::put('/profile/lock-screen-settings', [AdminProfileController::class, 'updateLockScreenSettings']);
        Route::post('/profile/lock-screen-backgrounds', [AdminProfileController::class, 'uploadLockScreenBackground']);
        Route::delete('/profile/lock-screen-backgrounds/{filename}', [AdminProfileController::class, 'deleteLockScreenBackground'])->where('filename', '[A-Za-z0-9\-\._]+');
        Route::get('/profile/lock-screen-backgrounds/{filename}', [AdminProfileController::class, 'serveLockScreenBackground'])->where('filename', '[A-Za-z0-9\-\._]+');

        // Routes pour les questionnaires
        Route::post('/questionnaires/send', [QuestionnaireController::class, 'sendQuestionnaire']);
        Route::get('/questionnaires', [QuestionnaireController::class, 'listQuestionnaires']);
        Route::get('/questionnaires/{id}', [QuestionnaireController::class, 'getQuestionnaire']);
        Route::get('/clients', [QuestionnaireController::class, 'getClients']);

        // Module Client (CRUD clients, membres famille, dossiers)
        // Statistiques cabinet (dashboard + analytics)
        Route::get('/statistics/overview', [StatisticsController::class, 'overview']);

        // Services d'immigration (CRUD)
        Route::get('/immigration-services', [ImmigrationServiceController::class, 'index']);
        Route::post('/immigration-services', [ImmigrationServiceController::class, 'store']);
        Route::put('/immigration-services/{id}', [ImmigrationServiceController::class, 'update']);
        Route::delete('/immigration-services/{id}', [ImmigrationServiceController::class, 'destroy']);

        // Dossier — édition inline notes + révocation accès collab
        Route::patch('/dossiers/{id}/notes', [\App\Http\Controllers\Api\DossierController::class, 'updateNotes']);
        Route::patch('/dossiers/{id}/collab-access', [\App\Http\Controllers\Api\DossierController::class, 'toggleCollabAccess']);

        // Fichiers supplémentaires sur dossier (admin)
        Route::get('/dossiers/{id}/supplementary-files', [DossierSupplementaryFileController::class, 'index']);
        Route::post('/dossiers/{id}/supplementary-files', [DossierSupplementaryFileController::class, 'store']);
        Route::get('/dossier-supplementary-files/{id}', [DossierSupplementaryFileController::class, 'show']);
        Route::delete('/dossier-supplementary-files/{id}', [DossierSupplementaryFileController::class, 'destroy']);

        // Export ZIP — sélection multi-catégorie
        Route::get('/dossiers/{id}/export-catalog', [DossierSupplementaryFileController::class, 'exportCatalog']);
        Route::post('/dossiers/export-zip', [DossierSupplementaryFileController::class, 'exportZip']);

        Route::get('/module-clients/options', [ClientController::class, 'options']);
        Route::apiResource('module-clients', ClientController::class)->parameters(['module-client' => 'id']);
        Route::post('module-clients/{id}/family-members', [ClientController::class, 'addFamilyMember']);
        Route::put('module-clients/{id}/family-members/{memberId}', [ClientController::class, 'updateFamilyMember']);
        Route::delete('module-clients/{id}/family-members/{memberId}', [ClientController::class, 'destroyFamilyMember']);

        Route::get('/dossiers/options', [DossierController::class, 'options']);
        Route::apiResource('dossiers', DossierController::class)->parameters(['dossier' => 'id']);

        // Modèles de documents PDF
        Route::get('/document-templates', [DocumentController::class, 'indexTemplates']);
        Route::post('/document-templates', [DocumentController::class, 'storeTemplate']);
        Route::get('/document-templates/{id}', [DocumentController::class, 'showTemplate']);
        Route::put('/document-templates/{id}', [DocumentController::class, 'updateTemplate']);
        Route::put('/document-templates/{id}/schema', [DocumentController::class, 'updateTemplateSchema']);
        Route::delete('/document-templates/{id}', [DocumentController::class, 'destroyTemplate']);
        Route::get('/document-templates/{id}/pdf', [DocumentController::class, 'servePdf']);

        // Demandes de documents envoyées aux clients
        Route::get('/document-requests', [DocumentController::class, 'indexRequests']);
        Route::post('/document-requests', [DocumentController::class, 'sendRequest']);
        Route::get('/document-requests/{id}', [DocumentController::class, 'showRequest']);
        Route::put('/document-requests/{id}/validate', [DocumentController::class, 'validateRequest']);
        Route::put('/document-requests/{id}/reject', [DocumentController::class, 'rejectRequest']);
        Route::get('/document-requests/{id}/pdf', [DocumentController::class, 'serveFilledPdf']);

        // Catégories (formulaires + documents)
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{id}', [CategoryController::class, 'update']);
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

        // Types de formulaires
        Route::get('/form-types', [FormTypeController::class, 'index']);
        Route::post('/form-types', [FormTypeController::class, 'store']);
        Route::put('/form-types/{id}', [FormTypeController::class, 'update']);
        Route::delete('/form-types/{id}', [FormTypeController::class, 'destroy']);

        // Invitations (envois groupés multi-items)
        Route::get('/invitations', [InvitationController::class, 'index']);
        Route::post('/invitations', [InvitationController::class, 'store']);
        Route::get('/invitations/{id}', [InvitationController::class, 'show']);
        Route::delete('/invitations/{id}', [InvitationController::class, 'destroy']);
        Route::post('/invitations/{id}/resend', [InvitationController::class, 'resendEmail']);
        Route::get('/invitations/{id}/items/{itemId}/pdf', [InvitationController::class, 'adminItemPdf']);
        Route::get('/invitations/{id}/uploads/{uploadId}/download', [InvitationController::class, 'adminDownloadUpload']);

        // Collaborateurs (CRUD admin)
        Route::get('/collaborators', [CollaboratorController::class, 'index']);
        Route::post('/collaborators', [CollaboratorController::class, 'store']);
        Route::get('/collaborators/{id}', [CollaboratorController::class, 'show']);
        Route::put('/collaborators/{id}', [CollaboratorController::class, 'update']);
        Route::delete('/collaborators/{id}', [CollaboratorController::class, 'destroy']);
        Route::post('/collaborators/{id}/send-welcome', [CollaboratorController::class, 'sendWelcomeLink']);

        // Documents de base d'un dossier (admin)
        Route::get('/dossiers/{id}/documents', [DossierDocumentController::class, 'indexForDossier']);
        Route::post('/dossiers/{id}/documents', [DossierDocumentController::class, 'store']);
        Route::get('/dossier-documents/{id}', [DossierDocumentController::class, 'show']);
        Route::put('/dossier-documents/{id}', [DossierDocumentController::class, 'update']);
        Route::delete('/dossier-documents/{id}', [DossierDocumentController::class, 'destroy']);
        Route::get('/dossier-documents/{id}/template', [DossierDocumentController::class, 'serveTemplate']);
        Route::get('/dossier-documents/{id}/filled', [DossierDocumentController::class, 'serveFilled']);

        // News — articles
        Route::get('/news/articles', [NewsArticleController::class, 'index']);
        Route::post('/news/articles', [NewsArticleController::class, 'store']);
        Route::get('/news/articles/{id}', [NewsArticleController::class, 'show']);
        Route::put('/news/articles/{id}', [NewsArticleController::class, 'update']);
        Route::delete('/news/articles/{id}', [NewsArticleController::class, 'destroy']);

        // News — sources
        Route::get('/news/sources', [NewsSourceController::class, 'index']);
        Route::post('/news/sources', [NewsSourceController::class, 'store']);
        Route::put('/news/sources/{id}', [NewsSourceController::class, 'update']);
        Route::delete('/news/sources/{id}', [NewsSourceController::class, 'destroy']);
        Route::post('/news/sources/{id}/follow', [NewsSourceController::class, 'follow']);
        Route::post('/news/sources/{id}/unfollow', [NewsSourceController::class, 'unfollow']);

        // File Manager
        Route::get('/file-manager', [FileManagerController::class, 'index']);
        Route::post('/file-manager/folders', [FileManagerController::class, 'storeFolder']);
        Route::put('/file-manager/folders/{id}', [FileManagerController::class, 'updateFolder']);
        Route::delete('/file-manager/folders/{id}', [FileManagerController::class, 'destroyFolder']);
        Route::post('/file-manager/folders/{id}/verify-lock', [FileManagerController::class, 'verifyLock']);
        Route::get('/file-manager/folders/{id}/permissions', [FileManagerController::class, 'getFolderPermissions']);
        Route::put('/file-manager/folders/{id}/permissions', [FileManagerController::class, 'updateFolderPermissions']);
        Route::get('/file-manager/admins', [FileManagerController::class, 'listAdmins']);
        Route::post('/file-manager/items', [FileManagerController::class, 'uploadItem']);
        Route::get('/file-manager/items/{id}/download', [FileManagerController::class, 'downloadItem']);
        Route::post('/file-manager/items/{id}/favorite', [FileManagerController::class, 'toggleFavoriteItem']);
        Route::delete('/file-manager/items/{id}', [FileManagerController::class, 'destroyItem']);
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

// Routes publiques pour le remplissage de documents (client via token)
Route::get('/documents/{token}', [DocumentController::class, 'getDocumentByToken']);
Route::get('/documents/{token}/pdf', [DocumentController::class, 'serveBasePdfByToken']);
Route::post('/documents/{token}/save', [DocumentController::class, 'saveProgress']);
Route::post('/documents/{token}/submit', [DocumentController::class, 'submitDocument']);

// Routes publiques news (consultables par l'admin connecté ou non)
Route::get('/news/articles', [NewsArticleController::class, 'publicIndex']);
Route::get('/news/articles/{slug}', [NewsArticleController::class, 'publicShow']);
Route::get('/news/sources', [NewsSourceController::class, 'publicIndex']);

// Routes publiques pour les invitations groupées (client via code unique).
// Rate limiting :
//  - verify (email+code) : 10/min par IP — protège contre bruteforce d'identifiants
//  - lecture (show, pdf)  : 30/min par IP — permissif pour usage normal
//  - écriture (save, complete, submit) : 60/min par IP — autorise auto-save fréquent
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/invitations/verify', [InvitationController::class, 'publicVerifyAccess']);
});
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/invitations/{code}', [InvitationController::class, 'publicShow']);
    Route::get('/invitations/{code}/items/{itemId}/pdf', [InvitationController::class, 'publicGetItemPdf']);
});
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/invitations/{code}/items/{itemId}/save-form', [InvitationController::class, 'publicSaveFormItem']);
    Route::post('/invitations/{code}/items/{itemId}/save-document', [InvitationController::class, 'publicSaveDocumentItem']);
    Route::post('/invitations/{code}/items/{itemId}/complete', [InvitationController::class, 'publicCompleteItem']);
    Route::post('/invitations/{code}/submit', [InvitationController::class, 'publicSubmitAll']);
    // Documents complémentaires libres téléversés par le client
    Route::post('/invitations/{code}/uploads', [InvitationController::class, 'publicUploadFile']);
    Route::delete('/invitations/{code}/uploads/{uploadId}', [InvitationController::class, 'publicDeleteUpload']);
});

// Routes COLLABORATEUR — espace de travail dédié
Route::prefix('collaborator')->group(function () {
    Route::post('/login', [CollaboratorAuthController::class, 'login']);

    // Activation du compte via le lien envoyé par l'admin (public, throttle)
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/check-activation', [CollaboratorController::class, 'checkActivationToken']);
        Route::post('/activate', [CollaboratorController::class, 'activate']);
    });

    Route::middleware('auth:collaborator')->group(function () {
        Route::post('/logout', [CollaboratorAuthController::class, 'logout']);
        Route::get('/me', [CollaboratorAuthController::class, 'me']);

        // Dossiers attribués
        Route::get('/dossiers', [CollaboratorWorkspaceController::class, 'listDossiers']);
        Route::get('/dossiers/{id}', [CollaboratorWorkspaceController::class, 'showDossier']);

        // Documents de base (remplissage)
        Route::get('/documents/{docId}', [CollaboratorWorkspaceController::class, 'getDocumentMeta']);
        Route::get('/documents/{docId}/pdf', [CollaboratorWorkspaceController::class, 'getDocumentPdf']);
        Route::post('/documents/{docId}/save', [CollaboratorWorkspaceController::class, 'saveDocument']);
        Route::post('/documents/{docId}/complete', [CollaboratorWorkspaceController::class, 'markComplete']);

        // Uploads libres
        Route::post('/dossiers/{id}/uploads', [CollaboratorWorkspaceController::class, 'uploadFile']);
        Route::delete('/dossiers/{id}/uploads/{uploadId}', [CollaboratorWorkspaceController::class, 'deleteUpload']);

        // Lecture des PDF remplis du client (read-only)
        Route::get('/dossiers/{id}/invitations/{invitationId}/items/{itemId}/pdf', [CollaboratorWorkspaceController::class, 'getInvitationItemPdf']);

        // Fichiers téléversés librement par le client lors de l'invitation
        Route::get('/dossiers/{id}/invitations/{invitationId}/uploads/{uploadId}', [CollaboratorWorkspaceController::class, 'getInvitationClientUpload']);

        // Fichiers supplémentaires du dossier (lecture seule pour le collab)
        Route::get('/dossiers/{id}/supplementary-files/{fileId}', [CollaboratorWorkspaceController::class, 'getSupplementaryFile']);

        // Détail d'un item d'invitation (lecture seule, pour le viewer de formulaire)
        Route::get('/invitation-items/{itemId}', [CollaboratorWorkspaceController::class, 'getInvitationItem']);
    });
});

// Route de test — diagnostic rapide
Route::get('/ping', function () {
    $db = ['ok' => false, 'error' => null];
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $db['ok'] = true;
        $db['driver'] = config('database.default');
        $db['database'] = config('database.connections.' . config('database.default') . '.database');
    } catch (\Throwable $e) {
        $db['error'] = $e->getMessage();
    }
    return response()->json([
        'success' => true,
        'message' => 'API is running',
        'timestamp' => now(),
        'env' => config('app.env'),
        'debug' => config('app.debug'),
        'php_version' => PHP_VERSION,
        'database' => $db,
    ]);
});


