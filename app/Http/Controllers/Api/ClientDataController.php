<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataRequest;
use App\Services\Compliance\ClientDataExporter;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/** Droits du client sur ses renseignements (3.10) : copie de ses données, demande de suppression. */
class ClientDataController extends Controller
{
    public function export(Request $request, ClientDataExporter $exporter)
    {
        $client = $request->user();
        $path = $exporter->build($client);

        return response()->download($path, 'mes-donnees-' . now()->format('Y-m-d') . '.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    public function requests(Request $request)
    {
        return response()->json(['success' => true, 'data' => DataRequest::where('client_id', $request->user()->id)
            ->latest()->get(['id', 'type', 'status', 'response', 'created_at', 'handled_at'])]);
    }

    public function storeRequest(Request $request, NotificationService $notifications)
    {
        $data = $request->validate(['message' => 'nullable|string|max:2000', 'confirm' => 'required|accepted']);
        $client = $request->user();

        if (DataRequest::where('client_id', $client->id)->where('status', 'pending')->exists()) {
            return response()->json(['success' => false, 'message' => 'Une demande est déjà en cours de traitement.'], 422);
        }

        $dr = DataRequest::create([
            'client_id' => $client->id,
            'type' => 'erasure',
            'client_name' => trim("{$client->first_name} {$client->last_name}"),
            'client_email' => $client->email,
            'message' => $data['message'] ?? null,
            'status' => 'pending',
        ]);

        $notifications->notify(
            $notifications->managerIds(),
            'data_request',
            "Demande de suppression de données : {$dr->client_name}",
            'À traiter dans les 30 jours (Loi 25).',
            '/admin/conformite',
            "data_request:{$dr->id}",
        );

        return response()->json(['success' => true, 'message' => 'Demande envoyée. Le cabinet vous répondra par courriel, au plus tard dans 30 jours.', 'data' => $dr->only(['id', 'status', 'created_at'])], 201);
    }
}
