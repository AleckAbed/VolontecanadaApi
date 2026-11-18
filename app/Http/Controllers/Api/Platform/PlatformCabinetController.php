<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Console de l'éditeur : vue d'ensemble des cabinets, suspension.
 * Lecture volontairement limitée à des compteurs : la plateforme n'a pas à
 * lire le contenu des dossiers des cabinets.
 */
class PlatformCabinetController extends Controller
{
    public function index(Request $request)
    {
        $counts = fn (string $table, ?callable $where = null) => DB::table($table)
            ->when($where, $where)
            ->select('cabinet_id', DB::raw('count(*) as n'))
            ->groupBy('cabinet_id')
            ->pluck('n', 'cabinet_id');

        $activeStaff = $counts('admins', fn ($q) => $q->where('is_active', true));
        $clients = $counts('clients');
        $dossiers = $counts('dossiers');
        $lastLogin = DB::table('admins')->select('cabinet_id', DB::raw('max(last_login_at) as last_login'))
            ->groupBy('cabinet_id')->pluck('last_login', 'cabinet_id');
        $owners = DB::table('admins')->where('role', Roles::OWNER)->orderBy('id')
            ->get(['cabinet_id', 'name', 'email'])->groupBy('cabinet_id');

        $search = trim((string) $request->query('search', ''));

        $cabinets = Cabinet::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Cabinet $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'province' => $c->province,
                'is_active' => (bool) $c->is_active,
                'created_at' => $c->created_at?->toIso8601String(),
                'owner' => ($o = $owners->get($c->id)?->first()) ? ['name' => $o->name, 'email' => $o->email] : null,
                'seats_used' => (int) ($activeStaff[$c->id] ?? 0),
                'clients_count' => (int) ($clients[$c->id] ?? 0),
                'dossiers_count' => (int) ($dossiers[$c->id] ?? 0),
                'last_login_at' => $lastLogin[$c->id] ?? null,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'cabinets' => $cabinets,
                'totals' => [
                    'cabinets' => $cabinets->count(),
                    'active' => $cabinets->where('is_active', true)->count(),
                    'seats' => $cabinets->sum('seats_used'),
                ],
            ],
        ]);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);

        $cabinet = Cabinet::findOrFail($id);
        $cabinet->update($data);

        // Suspension : les sessions du personnel du cabinet sont fermées.
        if (! $data['is_active']) {
            $adminIds = DB::table('admins')->where('cabinet_id', $cabinet->id)->pluck('id');
            DB::table('personal_access_tokens')
                ->where('tokenable_type', \App\Models\Admin::class)
                ->whereIn('tokenable_id', $adminIds)
                ->delete();
        }

        return response()->json([
            'success' => true,
            'message' => $data['is_active'] ? "Cabinet « {$cabinet->name} » réactivé." : "Cabinet « {$cabinet->name} » suspendu.",
        ]);
    }
}
