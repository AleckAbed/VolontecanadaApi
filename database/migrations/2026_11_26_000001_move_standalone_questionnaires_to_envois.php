<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 2.16 : un seul système d'envoi. Chaque questionnaire envoyé seul depuis l'ancien écran
 * « Questionnaires », ou resté orphelin après la suppression de son envoi, devient un envoi
 * d'un seul formulaire. Ses réponses, son statut et son lien déjà envoyé au client ne
 * changent pas : le questionnaire reste le même, il est seulement rattaché à un envoi.
 */
return new class extends Migration
{
    public const MARKER = 'Repris de l’ancien écran « Questionnaires ».';

    public function up(): void
    {
        $itemIds = DB::table('invitation_items')->pluck('id');
        $standalone = DB::table('questionnaire_requests')
            ->where(fn ($q) => $q->whereNull('invitation_item_id')->orWhereNotIn('invitation_item_id', $itemIds))
            ->orderBy('id')
            ->get();

        foreach ($standalone as $qr) {
            $formTypeId = DB::table('form_types')->where('cabinet_id', $qr->cabinet_id)->where('code', $qr->form_type)->value('id');
            if (! $formTypeId) {
                Log::warning('Questionnaire non repris : type de formulaire introuvable', ['questionnaire_id' => $qr->id, 'form_type' => $qr->form_type]);

                continue;
            }

            $status = in_array($qr->status, ['pending', 'in_progress', 'completed', 'expired'], true) ? $qr->status : 'pending';
            DB::transaction(function () use ($qr, $formTypeId, $status) {
                do {
                    $code = bin2hex(random_bytes(16));
                } while (DB::table('invitations')->where('unique_code', $code)->exists());

                $invitationId = DB::table('invitations')->insertGetId([
                    'cabinet_id' => $qr->cabinet_id,
                    'unique_code' => $code,
                    'client_type' => $qr->client_type ?: 'custom',
                    'client_id' => $qr->client_id,
                    'custom_name' => $qr->custom_name,
                    'email' => $qr->email,
                    'phone' => $qr->phone,
                    'message' => self::MARKER,
                    'allow_uploads' => false,
                    'status' => $status,
                    'sent_at' => $qr->sent_at,
                    'expires_at' => $qr->expires_at,
                    'completed_at' => $qr->completed_at,
                    'sent_by' => $qr->sent_by,
                    'email_sent' => (bool) $qr->email_sent,
                    'email_error' => $qr->email_error,
                    'created_at' => $qr->created_at,
                    'updated_at' => now(),
                ]);
                $itemId = DB::table('invitation_items')->insertGetId([
                    'cabinet_id' => $qr->cabinet_id,
                    'invitation_id' => $invitationId,
                    'item_kind' => 'form',
                    'form_type_id' => $formTypeId,
                    'linked_questionnaire_code' => $qr->unique_code,
                    'status' => $status === 'expired' ? 'pending' : $status,
                    'sort_order' => 0,
                    'completed_at' => $qr->completed_at,
                    'created_at' => $qr->created_at,
                    'updated_at' => now(),
                ]);
                DB::table('questionnaire_requests')->where('id', $qr->id)->update(['invitation_item_id' => $itemId]);
            });
        }
    }

    public function down(): void
    {
        $invitationIds = DB::table('invitations')->where('message', self::MARKER)->pluck('id');
        $itemIds = DB::table('invitation_items')->whereIn('invitation_id', $invitationIds)->pluck('id');
        DB::table('questionnaire_requests')->whereIn('invitation_item_id', $itemIds)->update(['invitation_item_id' => null]);
        DB::table('invitations')->whereIn('id', $invitationIds)->delete();
    }
};
