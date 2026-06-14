<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\NewsSource;
use App\Models\NewsArticle;
use Illuminate\Database\Seeder;

class NewsSeeder extends Seeder
{
    public function run(): void
    {
        // Default news categories (uses generic Category table with type='news')
        $cats = [
            ['name' => 'Lois IRCC', 'icon' => 'scales', 'color' => 'blue', 'sort_order' => 1],
            ['name' => 'Passeport & Visa', 'icon' => 'passport', 'color' => 'purple', 'sort_order' => 2],
            ['name' => 'Programmes provinciaux', 'icon' => 'buildings', 'color' => 'green', 'sort_order' => 3],
            ['name' => 'Immigration internationale', 'icon' => 'globe', 'color' => 'amber', 'sort_order' => 4],
            ['name' => 'Regroupement familial', 'icon' => 'users', 'color' => 'pink', 'sort_order' => 5],
            ['name' => 'Permis d\'études', 'icon' => 'graduation-cap', 'color' => 'indigo', 'sort_order' => 6],
        ];
        $categoryIds = [];
        foreach ($cats as $c) {
            $cat = Category::firstOrCreate(
                ['name' => $c['name'], 'type' => 'news'],
                array_merge($c, ['is_active' => true])
            );
            $categoryIds[$c['name']] = $cat->id;
        }

        // Default sources (official Canadian immigration bodies)
        $sources = [
            ['name' => 'IRCC Canada', 'website' => 'canada.ca/ircc', 'followers_count' => 24560, 'avatar' => 'https://isomorphic-furyroad.s3.amazonaws.com/public/avatars/avatar-15.webp'],
            ['name' => 'MIFI Québec', 'website' => 'quebec.ca/immigration', 'followers_count' => 18230, 'avatar' => 'https://isomorphic-furyroad.s3.amazonaws.com/public/avatars/avatar-14.webp'],
            ['name' => 'CISR — Conseil d\'immigration', 'website' => 'irb-cisr.gc.ca', 'followers_count' => 12110, 'avatar' => 'https://isomorphic-furyroad.s3.amazonaws.com/public/avatars/avatar-10.webp'],
            ['name' => 'Programme PCP Ontario', 'website' => 'ontario.ca/oinp', 'followers_count' => 9870, 'avatar' => 'https://isomorphic-furyroad.s3.amazonaws.com/public/avatars/avatar-11.webp'],
            ['name' => 'Service Canada', 'website' => 'canada.ca', 'followers_count' => 15040, 'avatar' => 'https://isomorphic-furyroad.s3.amazonaws.com/public/avatars/avatar-12.webp'],
            ['name' => 'PCP Colombie-Britannique', 'website' => 'welcomebc.ca', 'followers_count' => 6420, 'avatar' => 'https://isomorphic-furyroad.s3.amazonaws.com/public/avatars/avatar-13.webp'],
            ['name' => 'PCP Alberta', 'website' => 'alberta.ca/aaip', 'followers_count' => 5230, 'avatar' => 'https://isomorphic-furyroad.s3.amazonaws.com/public/avatars/avatar-14.webp'],
        ];
        $sourceIds = [];
        $i = 1;
        foreach ($sources as $s) {
            $src = NewsSource::firstOrCreate(
                ['name' => $s['name']],
                array_merge($s, ['sort_order' => $i++, 'is_active' => true])
            );
            $sourceIds[$s['name']] = $src->id;
        }

        // Default seed articles
        $articles = [
            [
                'title' => 'Mise à jour du seuil minimum d\'Entrée Express',
                'summary' => 'IRCC abaisse le seuil de points CRS pour mai 2026, ouvrant la porte à plus de candidats qualifiés.',
                'content' => "<p>IRCC a annoncé l'abaissement du seuil minimum de points CRS (Système de classement global) pour le tirage du mois.</p><p>Cette mise à jour vise à répondre à la pénurie de main-d'œuvre dans plusieurs provinces canadiennes.</p>",
                'thumbnail' => 'https://isomorphic-furyroad.s3.amazonaws.com/public/podcast/recent-played-image-1.webp',
                'category_id' => $categoryIds['Lois IRCC'],
                'source_id' => $sourceIds['IRCC Canada'],
                'read_time' => '4 min de lecture',
                'is_featured' => true,
                'is_published' => true,
                'published_at' => now()->subDays(2),
            ],
            [
                'title' => 'PSTQ : nouveaux critères de pointage 2026',
                'summary' => 'Le Québec révise les pondérations du Programme de sélection des travailleurs qualifiés.',
                'content' => "<p>Les nouveaux critères de pointage du PSTQ entreront en vigueur dès juin 2026.</p>",
                'thumbnail' => 'https://isomorphic-furyroad.s3.amazonaws.com/public/podcast/recent-played-image-2.webp',
                'category_id' => $categoryIds['Programmes provinciaux'],
                'source_id' => $sourceIds['MIFI Québec'],
                'read_time' => '6 min de lecture',
                'is_featured' => true,
                'is_published' => true,
                'published_at' => now()->subDays(7),
            ],
            [
                'title' => 'Permis de travail post-diplôme : durée prolongée',
                'summary' => 'Les diplômés de programmes spécifiques pourront obtenir un PTPD prolongé jusqu\'à 3 ans.',
                'content' => "<p>IRCC étend la durée maximale du Permis de travail post-diplôme pour les diplômés en sciences et technologies.</p>",
                'thumbnail' => 'https://isomorphic-furyroad.s3.amazonaws.com/public/podcast/recent-played-image-3.webp',
                'category_id' => $categoryIds['Permis d\'études'],
                'source_id' => $sourceIds['IRCC Canada'],
                'read_time' => '5 min de lecture',
                'is_published' => true,
                'published_at' => now()->subDays(12),
            ],
            [
                'title' => 'Programme des candidats des provinces : ouverture des tirages',
                'summary' => 'Plusieurs provinces ouvrent leurs nouveaux flux d\'immigration pour Q3 2026.',
                'content' => "<p>L'Ontario, l'Alberta et la Colombie-Britannique annoncent des tirages PCP imminents.</p>",
                'thumbnail' => 'https://isomorphic-furyroad.s3.amazonaws.com/public/podcast/recent-played-image-4.webp',
                'category_id' => $categoryIds['Programmes provinciaux'],
                'source_id' => $sourceIds['Programme PCP Ontario'],
                'read_time' => '3 min de lecture',
                'is_published' => true,
                'published_at' => now()->subDays(18),
            ],
        ];
        foreach ($articles as $a) {
            $a['slug'] = NewsArticle::generateUniqueSlug($a['title']);
            NewsArticle::firstOrCreate(['title' => $a['title']], $a);
        }
    }
}
