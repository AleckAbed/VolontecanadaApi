<?php

namespace App\Http\Middleware;

use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Journal d'activité (2.6) : inscrit les consultations, téléchargements et exports
 * d'éléments sensibles (dossiers, clients, documents, fichiers), seulement s'ils ont réussi.
 * Les créations, modifications et suppressions sont inscrites par le trait Auditable.
 */
class AuditAccess
{
    /** URI de la route => [action, type d'élément, paramètre portant l'identifiant] */
    private const ROUTES = [
        'api/admin/module-clients/{module_client}' => ['viewed', 'client', 'module_client'],
        'api/admin/dossiers/{dossier}' => ['viewed', 'dossier', 'dossier'],
        'api/admin/document-requests/{id}' => ['viewed', 'document_request', 'id'],
        'api/admin/document-requests/{id}/pdf' => ['downloaded', 'document_request', 'id'],
        'api/admin/dossier-documents/{id}/filled' => ['downloaded', 'dossier_document', 'id'],
        'api/admin/dossier-documents/{id}/edit-pdf' => ['downloaded', 'dossier_document', 'id'],
        'api/admin/dossier-supplementary-files/{id}' => ['downloaded', 'supplementary_file', 'id'],
        'api/admin/file-manager/items/{id}/download' => ['downloaded', 'file_item', 'id'],
        'api/admin/invitations/{id}' => ['viewed', 'invitation', 'id'],
        'api/admin/invitations/{id}/items/{itemId}/pdf' => ['downloaded', 'invitation_item', 'itemId'],
        'api/admin/invitations/{id}/uploads/{uploadId}/download' => ['downloaded', 'invitation_upload', 'uploadId'],
        'api/admin/invoices/{id}' => ['viewed', 'invoice', 'id'],
        'api/admin/questionnaires/{id}' => ['viewed', 'questionnaire', 'id'],
        'api/admin/dossiers/export-zip' => ['exported', 'dossier', 'dossier_id'],
        'api/collaborator/dossiers/{id}' => ['viewed', 'dossier', 'id'],
        'api/collaborator/documents/{docId}/pdf' => ['downloaded', 'dossier_document', 'docId'],
        'api/collaborator/dossiers/{id}/invitations/{invitationId}/items/{itemId}/pdf' => ['downloaded', 'invitation_item', 'itemId'],
        'api/collaborator/dossiers/{id}/invitations/{invitationId}/uploads/{uploadId}' => ['downloaded', 'invitation_upload', 'uploadId'],
        'api/collaborator/dossiers/{id}/supplementary-files/{fileId}' => ['downloaded', 'supplementary_file', 'fileId'],
        'api/client/my-data/export' => ['exported', 'client', null],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $uri = $request->route()?->uri();
        if ($uri && isset(self::ROUTES[$uri]) && $response->getStatusCode() < 300) {
            [$action, $type, $param] = self::ROUTES[$uri];
            $id = $param === null
                ? $request->user()?->getKey()
                : ($request->route($param) ?? $request->input($param));
            if (is_numeric($id)) {
                Audit::log($action, $type, (int) $id);
            }
        }

        return $response;
    }
}
