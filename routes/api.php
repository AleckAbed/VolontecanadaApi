<?php

use App\Http\Controllers\Api\ActionFeedController;
use App\Http\Controllers\Api\AdminAccessController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BookingAdminController;
use App\Http\Controllers\Api\MandateController;
use App\Http\Controllers\Api\PublicBookingController;
use App\Http\Controllers\Api\DeadlineController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PublicInvoiceController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\CabinetRegistrationController;
use App\Http\Controllers\Api\ClientAccessController;
use App\Http\Controllers\Api\ClientPortalController;
use App\Http\Controllers\Api\FieldMapController;
use App\Http\Controllers\Api\Platform\PlatformAuthController;
use App\Http\Controllers\Api\Platform\PlatformCabinetController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\AdminProfileController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ChatbotController;
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
    Route::middleware('throttle:login')->post('/login', [AdminAuthController::class, 'login']);

    // Liens d'accès du personnel : mot de passe oublié, invitation dans l'équipe
    Route::middleware('throttle:public-verify')->group(function () {
        Route::post('/password/forgot', [AdminAccessController::class, 'forgotPassword']);
        Route::post('/access/check', [AdminAccessController::class, 'check']);
        Route::post('/access/complete', [AdminAccessController::class, 'complete']);
    });

    // Routes protégées pour les admins
    Route::middleware(['auth:admin', 'cabinet'])->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);

        // Tableau « action requise », échéances, notifications (S5)
        Route::get('/actions', [ActionFeedController::class, 'index']);
        Route::post('/invitations/{id}/reviewed', [ActionFeedController::class, 'markReviewed']);
        Route::get('/deadlines', [DeadlineController::class, 'index']);
        Route::post('/deadlines', [DeadlineController::class, 'store']);
        Route::put('/deadlines/{id}', [DeadlineController::class, 'update']);
        Route::post('/deadlines/{id}/complete', [DeadlineController::class, 'complete']);
        Route::delete('/deadlines/{id}', [DeadlineController::class, 'destroy'])->middleware('role:owner,admin,advisor');
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);

        // Facturation des clients (S6)
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
        Route::put('/invoices/{id}', [InvoiceController::class, 'update']);
        Route::post('/invoices/{id}/send', [InvoiceController::class, 'send']);
        Route::post('/invoices/{id}/mark-paid', [InvoiceController::class, 'markPaid']);
        Route::post('/invoices/{id}/void', [InvoiceController::class, 'void'])->middleware('role:owner,admin,advisor');

        // Réservation en ligne, Google Agenda (S7)
        Route::get('/booking', [BookingAdminController::class, 'overview']);
        Route::get('/booking/appointments', [BookingAdminController::class, 'appointments']);
        Route::post('/booking/appointments/{id}/cancel', [BookingAdminController::class, 'cancelAppointment']);
        Route::get('/booking/availability', [BookingAdminController::class, 'availability']);
        Route::put('/booking/availability', [BookingAdminController::class, 'updateAvailability']);
        Route::post('/google/connect', [BookingAdminController::class, 'googleConnect']);
        Route::post('/google/disconnect', [BookingAdminController::class, 'googleDisconnect']);
        Route::middleware('role:owner,admin')->group(function () {
            Route::put('/booking/settings', [BookingAdminController::class, 'updateSettings']);
            Route::post('/booking/services', [BookingAdminController::class, 'storeService']);
            Route::put('/booking/services/{id}', [BookingAdminController::class, 'updateService']);
            Route::delete('/booking/services/{id}', [BookingAdminController::class, 'destroyService']);
        });

        // Mandats de représentation (S7)
        Route::get('/mandates', [MandateController::class, 'index']);
        Route::get('/mandates/preview', [MandateController::class, 'preview']);
        Route::post('/mandates', [MandateController::class, 'store']);
        Route::post('/mandates/{id}/void', [MandateController::class, 'void']);

        // Abonnement du cabinet, encaissement Stripe Connect, numéros de taxes
        Route::middleware('role:owner,admin')->group(function () {
            Route::get('/billing', [BillingController::class, 'show']);
            Route::put('/billing/taxes', [BillingController::class, 'updateTaxes']);
            Route::post('/billing/connect/refresh', [BillingController::class, 'refreshConnect']);
        });
        Route::middleware('role:owner')->group(function () {
            Route::post('/billing/subscribe', [BillingController::class, 'subscribe']);
            Route::post('/billing/portal', [BillingController::class, 'portal']);
            Route::post('/billing/connect', [BillingController::class, 'connect']);
        });

        // Équipe du cabinet (propriétaires et administrateurs)
        Route::middleware('role:owner,admin')->group(function () {
            Route::get('/team', [TeamController::class, 'index']);
            Route::post('/team', [TeamController::class, 'store']);
            Route::put('/team/{id}', [TeamController::class, 'update']);
            Route::post('/team/{id}/resend-invitation', [TeamController::class, 'resendInvitation']);
        });
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

        // Chatbot Volo — assistance plateforme (admin)
        Route::middleware('throttle:30,1')->post('/chatbot/ask', [ChatbotController::class, 'askAdmin']);
        Route::get('/chatbot/settings', [ChatbotController::class, 'getSettings']);
        Route::put('/chatbot/settings', [ChatbotController::class, 'updateSettings'])->middleware('role:owner,admin');

        // Services d'immigration (CRUD)
        Route::get('/immigration-services', [ImmigrationServiceController::class, 'index']);
        Route::post('/immigration-services', [ImmigrationServiceController::class, 'store'])->middleware('role:owner,admin');
        Route::put('/immigration-services/{id}', [ImmigrationServiceController::class, 'update'])->middleware('role:owner,admin');
        Route::delete('/immigration-services/{id}', [ImmigrationServiceController::class, 'destroy'])->middleware('role:owner,admin');

        // Dossier — édition inline notes + révocation accès collab
        Route::patch('/dossiers/{id}/notes', [\App\Http\Controllers\Api\DossierController::class, 'updateNotes']);
        Route::patch('/dossiers/{id}/collab-access', [\App\Http\Controllers\Api\DossierController::class, 'toggleCollabAccess']);

        // Fichiers supplémentaires sur dossier (admin)
        Route::get('/dossiers/{id}/supplementary-files', [DossierSupplementaryFileController::class, 'index']);
        Route::post('/dossiers/{id}/supplementary-files', [DossierSupplementaryFileController::class, 'store']);
        Route::get('/dossier-supplementary-files/{id}', [DossierSupplementaryFileController::class, 'show']);
        Route::delete('/dossier-supplementary-files/{id}', [DossierSupplementaryFileController::class, 'destroy'])->middleware('role:owner,admin,advisor');

        // Export ZIP — sélection multi-catégorie
        Route::get('/dossiers/{id}/export-catalog', [DossierSupplementaryFileController::class, 'exportCatalog']);
        Route::post('/dossiers/export-zip', [DossierSupplementaryFileController::class, 'exportZip']);

        Route::get('/module-clients/options', [ClientController::class, 'options']);
        Route::apiResource('module-clients', ClientController::class)->parameters(['module-client' => 'id'])->middlewareFor('destroy', 'role:owner,admin,advisor');
        Route::post('module-clients/{id}/family-members', [ClientController::class, 'addFamilyMember']);
        Route::put('module-clients/{id}/family-members/{memberId}', [ClientController::class, 'updateFamilyMember']);
        Route::delete('module-clients/{id}/family-members/{memberId}', [ClientController::class, 'destroyFamilyMember'])->middleware('role:owner,admin,advisor');
        // Accès du client à son portail (lien pour choisir son mot de passe)
        Route::post('module-clients/{id}/portal-invite', [ClientAccessController::class, 'invite']);

        Route::get('/dossiers/options', [DossierController::class, 'options']);
        Route::apiResource('dossiers', DossierController::class)->parameters(['dossier' => 'id'])->middlewareFor('destroy', 'role:owner,admin,advisor');

        // Modèles de documents PDF
        Route::get('/document-templates', [DocumentController::class, 'indexTemplates']);
        Route::post('/document-templates', [DocumentController::class, 'storeTemplate'])->middleware('role:owner,admin');
        Route::get('/document-templates/{id}', [DocumentController::class, 'showTemplate']);
        Route::put('/document-templates/{id}', [DocumentController::class, 'updateTemplate'])->middleware('role:owner,admin');
        Route::put('/document-templates/{id}/schema', [DocumentController::class, 'updateTemplateSchema'])->middleware('role:owner,admin');
        Route::delete('/document-templates/{id}', [DocumentController::class, 'destroyTemplate'])->middleware('role:owner,admin');
        Route::get('/document-templates/{id}/pdf', [DocumentController::class, 'servePdf']);
        // Correspondance des champs PDF avec le profil client (préremplissage)
        Route::get('/document-templates/{id}/field-map', [FieldMapController::class, 'show']);
        Route::put('/document-templates/{id}/field-map', [FieldMapController::class, 'update'])->middleware('role:owner,admin');

        // Demandes de documents envoyées aux clients
        Route::get('/document-requests', [DocumentController::class, 'indexRequests']);
        Route::post('/document-requests', [DocumentController::class, 'sendRequest']);
        Route::get('/document-requests/{id}', [DocumentController::class, 'showRequest']);
        Route::put('/document-requests/{id}/validate', [DocumentController::class, 'validateRequest']);
        Route::put('/document-requests/{id}/reject', [DocumentController::class, 'rejectRequest']);
        Route::get('/document-requests/{id}/pdf', [DocumentController::class, 'serveFilledPdf']);

        // Catégories (formulaires + documents)
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store'])->middleware('role:owner,admin');
        Route::put('/categories/{id}', [CategoryController::class, 'update'])->middleware('role:owner,admin');
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->middleware('role:owner,admin');

        // Types de formulaires
        Route::get('/form-types', [FormTypeController::class, 'index']);
        Route::post('/form-types', [FormTypeController::class, 'store'])->middleware('role:owner,admin');
        Route::put('/form-types/{id}', [FormTypeController::class, 'update'])->middleware('role:owner,admin');
        Route::delete('/form-types/{id}', [FormTypeController::class, 'destroy'])->middleware('role:owner,admin');

        // Invitations (envois groupés multi-items)
        Route::get('/invitations', [InvitationController::class, 'index']);
        Route::post('/invitations', [InvitationController::class, 'store']);
        Route::get('/invitations/{id}', [InvitationController::class, 'show']);
        Route::delete('/invitations/{id}', [InvitationController::class, 'destroy'])->middleware('role:owner,admin,advisor');
        Route::post('/invitations/{id}/resend', [InvitationController::class, 'resendEmail']);
        // Payload pré-remplissage depuis un dossier (documents + fichiers supplémentaires)
        Route::get('/dossiers/{id}/invitation-payload', [InvitationController::class, 'dossierInvitationPayload']);
        Route::get('/invitations/{id}/items/{itemId}/pdf', [InvitationController::class, 'adminItemPdf']);
        Route::get('/invitations/{id}/uploads/{uploadId}/download', [InvitationController::class, 'adminDownloadUpload']);

        // Collaborateurs (CRUD admin)
        Route::get('/collaborators', [CollaboratorController::class, 'index']);
        Route::post('/collaborators', [CollaboratorController::class, 'store'])->middleware('role:owner,admin');
        Route::get('/collaborators/{id}', [CollaboratorController::class, 'show']);
        Route::put('/collaborators/{id}', [CollaboratorController::class, 'update'])->middleware('role:owner,admin');
        Route::delete('/collaborators/{id}', [CollaboratorController::class, 'destroy'])->middleware('role:owner,admin');
        Route::post('/collaborators/{id}/send-welcome', [CollaboratorController::class, 'sendWelcomeLink'])->middleware('role:owner,admin');

        // Documents de base d'un dossier (admin)
        Route::get('/dossiers/{id}/documents', [DossierDocumentController::class, 'indexForDossier']);
        Route::post('/dossiers/{id}/documents', [DossierDocumentController::class, 'store']);
        Route::get('/dossier-documents/{id}', [DossierDocumentController::class, 'show']);
        Route::put('/dossier-documents/{id}', [DossierDocumentController::class, 'update']);
        Route::delete('/dossier-documents/{id}', [DossierDocumentController::class, 'destroy'])->middleware('role:owner,admin,advisor');
        Route::get('/dossier-documents/{id}/template', [DossierDocumentController::class, 'serveTemplate']);
        Route::get('/dossier-documents/{id}/filled', [DossierDocumentController::class, 'serveFilled']);
        Route::get('/dossier-documents/{id}/edit-pdf', [DossierDocumentController::class, 'serveForEdit']);
        Route::post('/dossier-documents/{id}/save-as-admin', [DossierDocumentController::class, 'saveAsAdmin']);
        Route::post('/dossier-documents/{id}/mark-complete', [DossierDocumentController::class, 'markComplete']);
        Route::post('/dossier-documents/{id}/mark-in-progress', [DossierDocumentController::class, 'markInProgress']);

        // News — articles
        Route::get('/news/articles', [NewsArticleController::class, 'index']);
        Route::post('/news/articles', [NewsArticleController::class, 'store'])->middleware('role:owner,admin');
        Route::get('/news/articles/{id}', [NewsArticleController::class, 'show']);
        Route::put('/news/articles/{id}', [NewsArticleController::class, 'update'])->middleware('role:owner,admin');
        Route::delete('/news/articles/{id}', [NewsArticleController::class, 'destroy'])->middleware('role:owner,admin');

        // News — sources
        Route::get('/news/sources', [NewsSourceController::class, 'index']);
        Route::post('/news/sources', [NewsSourceController::class, 'store'])->middleware('role:owner,admin');
        Route::put('/news/sources/{id}', [NewsSourceController::class, 'update'])->middleware('role:owner,admin');
        Route::delete('/news/sources/{id}', [NewsSourceController::class, 'destroy'])->middleware('role:owner,admin');
        Route::post('/news/sources/{id}/follow', [NewsSourceController::class, 'follow']);
        Route::post('/news/sources/{id}/unfollow', [NewsSourceController::class, 'unfollow']);

        // File Manager
        Route::get('/file-manager', [FileManagerController::class, 'index']);
        Route::post('/file-manager/folders', [FileManagerController::class, 'storeFolder']);
        Route::put('/file-manager/folders/{id}', [FileManagerController::class, 'updateFolder']);
        Route::delete('/file-manager/folders/{id}', [FileManagerController::class, 'destroyFolder'])->middleware('role:owner,admin,advisor');
        Route::post('/file-manager/folders/{id}/verify-lock', [FileManagerController::class, 'verifyLock']);
        Route::get('/file-manager/folders/{id}/permissions', [FileManagerController::class, 'getFolderPermissions']);
        Route::put('/file-manager/folders/{id}/permissions', [FileManagerController::class, 'updateFolderPermissions'])->middleware('role:owner,admin');
        Route::get('/file-manager/admins', [FileManagerController::class, 'listAdmins']);
        Route::post('/file-manager/items', [FileManagerController::class, 'uploadItem']);
        Route::get('/file-manager/items/{id}/download', [FileManagerController::class, 'downloadItem']);
        Route::post('/file-manager/items/{id}/favorite', [FileManagerController::class, 'toggleFavoriteItem']);
        Route::delete('/file-manager/items/{id}', [FileManagerController::class, 'destroyItem'])->middleware('role:owner,admin,advisor');
    });
});

// Facture vue et payée par le client via son lien (le jeton désigne le cabinet)
Route::middleware(['throttle:public-read', 'cabinet.code:invoice'])->group(function () {
    Route::get('/invoices/public/{token}', [PublicInvoiceController::class, 'show']);
    Route::post('/invoices/public/{token}/checkout', [PublicInvoiceController::class, 'checkout']);
});

// Réservation publique d'un cabinet (/reserver/{slug}) et rendez-vous géré par son lien
Route::middleware('throttle:public-read')->group(function () {
    Route::get('/booking/{slug}', [PublicBookingController::class, 'show']);
    Route::get('/booking/{slug}/slots', [PublicBookingController::class, 'slots']);
});
Route::middleware('throttle:public-write')->post('/booking/{slug}/appointments', [PublicBookingController::class, 'book']);
Route::middleware(['throttle:public-read', 'cabinet.code:appointment'])->group(function () {
    Route::get('/appointments/public/{token}', [PublicBookingController::class, 'manage']);
    Route::post('/appointments/public/{token}/cancel', [PublicBookingController::class, 'cancel']);
});

// Mandat lu et signé par le client via son lien
Route::middleware(['throttle:public-read', 'cabinet.code:mandate'])->get('/mandates/public/{token}', [MandateController::class, 'publicShow']);
Route::middleware(['throttle:public-write', 'cabinet.code:mandate'])->post('/mandates/public/{token}/sign', [MandateController::class, 'sign']);

// Retour de l'autorisation Google Agenda (le state désigne l'admin)
Route::middleware('throttle:public-verify')->get('/google/callback', [BookingAdminController::class, 'googleCallback']);

// Webhooks Stripe (signature vérifiée dans le contrôleur)
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);

// Inscription d'un nouveau cabinet (publique)
Route::middleware('throttle:signup')->post('/cabinets/register', [CabinetRegistrationController::class, 'register']);

// Console de l'éditeur de la plateforme (super-admin)
Route::prefix('platform')->group(function () {
    Route::middleware('throttle:login')->post('/login', [PlatformAuthController::class, 'login']);
    Route::middleware('auth:platform')->group(function () {
        Route::get('/me', [PlatformAuthController::class, 'me']);
        Route::post('/logout', [PlatformAuthController::class, 'logout']);
        Route::get('/cabinets', [PlatformCabinetController::class, 'index']);
        Route::patch('/cabinets/{id}', [PlatformCabinetController::class, 'update']);
    });
});

// Routes d'authentification CLIENT
Route::prefix('client')->group(function () {   
    // Inscription publique désactivée : un client n'est créé que par un cabinet.
    Route::middleware('throttle:login')->post('/login', [ClientAuthController::class, 'login']);

    // Accès au portail : mot de passe oublié, lien d'activation
    Route::middleware('throttle:public-verify')->group(function () {
        Route::post('/password/forgot', [ClientAccessController::class, 'forgotPassword']);
        Route::post('/access/check', [ClientAccessController::class, 'check']);
        Route::post('/access/complete', [ClientAccessController::class, 'complete']);
    });

    // Routes protégées pour les clients
    Route::middleware(['auth:client', 'cabinet'])->group(function () {
        Route::post('/logout', [ClientAuthController::class, 'logout']);
        Route::get('/me', [ClientAuthController::class, 'me']);

        // Portail : dossiers, démarches à compléter, profil d'immigration
        Route::get('/portal', [ClientPortalController::class, 'overview']);
        Route::get('/profile', [ClientPortalController::class, 'profile']);
        Route::put('/profile', [ClientPortalController::class, 'updateProfile']);
        Route::get('/invoices', [PublicInvoiceController::class, 'forClient']);
    });
});

// Routes publiques pour les questionnaires (mêmes limites que les invitations)
Route::middleware(['throttle:public-verify', 'cabinet.code:questionnaire'])->post('/questionnaires/verify', [QuestionnaireController::class, 'verifyAccess']);
Route::middleware(['throttle:public-write', 'cabinet.code:questionnaire'])->group(function () {
    Route::post('/questionnaires/{code}/save', [QuestionnaireController::class, 'saveFormData']);
    Route::post('/questionnaires/{code}/submit', [QuestionnaireController::class, 'submitForm']);
});

// Routes publiques pour le remplissage de documents (client via token)
Route::middleware(['throttle:public-read', 'cabinet.code:document'])->group(function () {
    Route::get('/documents/{token}', [DocumentController::class, 'getDocumentByToken']);
    Route::get('/documents/{token}/pdf', [DocumentController::class, 'serveBasePdfByToken']);
});
Route::middleware(['throttle:public-write', 'cabinet.code:document'])->group(function () {
    Route::post('/documents/{token}/save', [DocumentController::class, 'saveProgress']);
    Route::post('/documents/{token}/submit', [DocumentController::class, 'submitDocument']);
});

// Routes news lues par le tableau de bord : le cabinet vient du jeton s'il est présent (sinon liste vide).
Route::middleware('cabinet:optional')->get('/news/articles', [NewsArticleController::class, 'publicIndex']);
Route::middleware('cabinet:optional')->get('/news/articles/{slug}', [NewsArticleController::class, 'publicShow']);
Route::middleware('cabinet:optional')->get('/news/sources', [NewsSourceController::class, 'publicIndex']);

// Routes publiques pour les invitations groupées (client via code unique).
// Rate limiting :
//  - verify (email+code) : 10/min par IP — protège contre bruteforce d'identifiants
//  - lecture (show, pdf)  : 30/min par IP — permissif pour usage normal
//  - écriture (save, complete, submit) : 60/min par IP — autorise auto-save fréquent
Route::middleware(['throttle:public-verify', 'cabinet.code:invitation'])->group(function () {
    Route::post('/invitations/verify', [InvitationController::class, 'publicVerifyAccess']);
});
Route::middleware(['throttle:public-read', 'cabinet.code:invitation'])->group(function () {
    Route::get('/invitations/{code}', [InvitationController::class, 'publicShow']);
    Route::get('/invitations/{code}/items/{itemId}/pdf', [InvitationController::class, 'publicGetItemPdf']);
    Route::get('/invitations/{code}/attachments/{attachmentId}', [InvitationController::class, 'publicGetAttachment']);
});
Route::middleware(['throttle:public-write', 'cabinet.code:invitation'])->group(function () {
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
    Route::middleware('throttle:login')->post('/login', [CollaboratorAuthController::class, 'login']);

    // Activation du compte via le lien envoyé par l'admin (public, throttle)
    Route::middleware(['throttle:public-verify', 'cabinet.code:activation'])->group(function () {
        Route::post('/check-activation', [CollaboratorController::class, 'checkActivationToken']);
        Route::post('/activate', [CollaboratorController::class, 'activate']);
    });

    Route::middleware(['auth:collaborator', 'cabinet'])->group(function () {
        Route::post('/logout', [CollaboratorAuthController::class, 'logout']);
        Route::get('/me', [CollaboratorAuthController::class, 'me']);

        // Chatbot Volo — assistance plateforme (collab)
        Route::middleware('throttle:30,1')->post('/chatbot/ask', [ChatbotController::class, 'askCollab']);

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

// Route de surveillance — publique : ne révèle ni la configuration ni les erreurs.
Route::get('/ping', function () {
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $dbOk = true;
    } catch (\Throwable $e) {
        report($e);
        $dbOk = false;
    }

    return response()->json([
        'success' => $dbOk,
        'database' => $dbOk ? 'ok' : 'down',
        'timestamp' => now(),
    ], $dbOk ? 200 : 503);
});


