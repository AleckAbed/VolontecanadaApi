<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deadline;
use App\Models\Dossier;
use App\Services\DeadlineService;
use App\Support\Tenancy\CabinetRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Échéances du cabinet : liste (saisies + déduites), création, modification,
 * marquage comme faite.
 */
class DeadlineController extends Controller
{
    public function __construct(private DeadlineService $deadlines) {}

    public function index(Request $request)
    {
        $items = $this->deadlines->all($request->boolean('include_done'));

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'types' => collect(Deadline::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'counts' => [
                    'overdue' => $items->where('urgency', 'overdue')->count(),
                    'this_week' => $items->where('urgency', 'this_week')->count(),
                    'soon' => $items->where('urgency', 'soon')->count(),
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user('admin')->id;

        $deadline = Deadline::create($data);

        return response()->json(['success' => true, 'message' => 'Échéance ajoutée.', 'data' => ['id' => $deadline->id]], 201);
    }

    public function update(Request $request, int $id)
    {
        $deadline = Deadline::findOrFail($id);
        $deadline->update($this->validated($request, partial: true));

        return response()->json(['success' => true, 'message' => 'Échéance modifiée.']);
    }

    public function complete(Request $request, int $id)
    {
        $deadline = Deadline::findOrFail($id);
        $done = $request->boolean('done', true);
        $deadline->forceFill(['completed_at' => $done ? now() : null])->save();

        return response()->json(['success' => true, 'message' => $done ? 'Échéance marquée comme faite.' : 'Échéance rouverte.']);
    }

    public function destroy(int $id)
    {
        Deadline::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Échéance supprimée.']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'label' => [$req, 'string', 'max:255'],
            'type' => [$req, Rule::in(array_keys(Deadline::TYPES))],
            'due_date' => [$req, 'date'],
            'remind_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'dossier_id' => ['nullable', CabinetRule::exists('dossiers')],
            'client_id' => ['nullable', CabinetRule::exists('clients')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Une échéance de dossier est aussi rattachée à son client (pour la retrouver depuis la fiche client).
        if (! empty($data['dossier_id']) && empty($data['client_id'])) {
            $data['client_id'] = Dossier::find($data['dossier_id'])?->client_id;
        }

        return $data;
    }
}
