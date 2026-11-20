<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Mail\CabinetRequestMail;
use App\Models\Admin;
use App\Models\CabinetRequest;
use App\Services\CabinetProvisioner;
use App\Support\AdminAccessTokens;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Console plateforme : étude des demandes d'ouverture de cabinet.
 * Accepter crée le cabinet et son propriétaire « invité », qui reçoit un lien pour
 * choisir son mot de passe (et régler la double authentification).
 */
class PlatformCabinetRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', CabinetRequest::PENDING);

        $items = CabinetRequest::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => ['pending' => CabinetRequest::where('status', CabinetRequest::PENDING)->count()],
        ]);
    }

    public function approve(Request $request, int $id, CabinetProvisioner $provisioner)
    {
        $req = CabinetRequest::findOrFail($id);
        if ($req->status !== CabinetRequest::PENDING) {
            return response()->json(['success' => false, 'message' => 'Cette demande a déjà été traitée.'], 422);
        }

        $data = $request->validate([
            // La console peut corriger le nom affiché avant de créer le cabinet.
            'cabinet_name' => 'sometimes|string|max:150',
        ]);

        $emailTaken = CurrentCabinet::withoutScope(fn () => Admin::acrossCabinets()->where('email', $req->email)->exists());
        if ($emailTaken) {
            return response()->json([
                'success' => false,
                'message' => 'Un compte du personnel existe déjà avec ce courriel : impossible de créer un second cabinet pour lui.',
            ], 422);
        }

        [$cabinet, $owner] = $provisioner->create(
            $data['cabinet_name'] ?? $req->cabinet_name,
            $req->province,
            $req->contact_name,
            $req->email,
            null,
        );
        $token = AdminAccessTokens::issue($owner, AdminAccessTokens::INVITE);

        $req->forceFill([
            'status' => CabinetRequest::APPROVED,
            'cabinet_id' => $cabinet->id,
            'decided_by' => $request->user()?->id,
            'decided_at' => now(),
        ])->save();

        Mail::to($req->email)->send(new CabinetRequestMail($req, CabinetRequestMail::APPROVED, $token));

        return response()->json([
            'success' => true,
            'message' => "Cabinet « {$cabinet->name} » créé. {$req->email} a reçu son lien d'activation.",
            'data' => ['cabinet_id' => $cabinet->id],
        ]);
    }

    public function decline(Request $request, int $id)
    {
        $req = CabinetRequest::findOrFail($id);
        if ($req->status !== CabinetRequest::PENDING) {
            return response()->json(['success' => false, 'message' => 'Cette demande a déjà été traitée.'], 422);
        }
        $data = $request->validate([
            'reason' => 'nullable|string|max:2000',
            'notify' => 'sometimes|boolean',
        ]);

        $req->forceFill([
            'status' => CabinetRequest::DECLINED,
            'decline_reason' => $data['reason'] ?? null,
            'decided_by' => $request->user()?->id,
            'decided_at' => now(),
        ])->save();

        // Demande manifestement abusive : on peut refuser sans répondre.
        if ($data['notify'] ?? true) {
            Mail::to($req->email)->send(new CabinetRequestMail($req, CabinetRequestMail::DECLINED));
        }

        return response()->json(['success' => true, 'message' => 'Demande refusée.']);
    }
}
