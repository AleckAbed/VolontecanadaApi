<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\FormType;
use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

class CategoryAndFormTypeSeeder extends Seeder
{
    public function run(): void
    {
        // Form categories
        $formGeneral = Category::firstOrCreate(
            ['name' => 'Questionnaires généraux', 'type' => 'form'],
            ['color' => 'blue', 'icon' => 'clipboard', 'sort_order' => 1, 'is_active' => true]
        );
        $formPstq = Category::firstOrCreate(
            ['name' => 'PSTQ / Pointage', 'type' => 'form'],
            ['color' => 'purple', 'icon' => 'chart', 'sort_order' => 2, 'is_active' => true]
        );

        // Document categories (mirror existing enum values)
        $catIrcc = Category::firstOrCreate(
            ['name' => 'Formulaires IRCC', 'type' => 'document'],
            ['color' => 'blue', 'icon' => 'flag', 'sort_order' => 1, 'is_active' => true]
        );
        $catCabinet = Category::firstOrCreate(
            ['name' => 'Documents Cabinet', 'type' => 'document'],
            ['color' => 'purple', 'icon' => 'briefcase', 'sort_order' => 2, 'is_active' => true]
        );
        $catContrat = Category::firstOrCreate(
            ['name' => 'Contrats', 'type' => 'document'],
            ['color' => 'green', 'icon' => 'file-signature', 'sort_order' => 3, 'is_active' => true]
        );
        $catAutre = Category::firstOrCreate(
            ['name' => 'Autres', 'type' => 'document'],
            ['color' => 'gray', 'icon' => 'file', 'sort_order' => 4, 'is_active' => true]
        );

        // Default form types (the 3 hardcoded ones)
        FormType::firstOrCreate(
            ['code' => 'questionnaire_demandeur_001'],
            [
                'name' => 'Questionnaire Demandeur 001',
                'description' => 'Questionnaire pour un nouveau demandeur',
                'category_id' => $formGeneral->id,
                'sort_order' => 1,
                'is_active' => true,
            ]
        );
        FormType::firstOrCreate(
            ['code' => 'questionnaire_repondant'],
            [
                'name' => 'Questionnaire Répondant',
                'description' => 'Questionnaire pour un répondant',
                'category_id' => $formGeneral->id,
                'sort_order' => 2,
                'is_active' => true,
            ]
        );
        FormType::firstOrCreate(
            ['code' => 'questionnaire_pstq_pointage'],
            [
                'name' => 'Questionnaire PSTQ Pointage',
                'description' => 'Questionnaire pour le calcul de pointage PSTQ',
                'category_id' => $formPstq->id,
                'sort_order' => 1,
                'is_active' => true,
            ]
        );

        // Backfill category_id on existing document templates from the enum 'category' string
        $catMap = [
            'ircc' => $catIrcc->id,
            'cabinet' => $catCabinet->id,
            'contrat' => $catContrat->id,
            'autre' => $catAutre->id,
        ];
        foreach (DocumentTemplate::whereNull('category_id')->get() as $template) {
            $cid = $catMap[$template->category] ?? $catAutre->id;
            $template->update(['category_id' => $cid]);
        }
    }
}
