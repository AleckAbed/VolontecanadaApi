<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\FamilyMember;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ClientSeeder extends Seeder
{
    /**
     * Insère au moins 10 clients avec différents scénarios : seuls et familles.
     */
    public function run(): void
    {
        $clients = [
            // 1 - Client seul
            [
                'client_type' => 'single',
                'first_name' => 'Jean',
                'last_name' => 'Dupont',
                'email' => 'jean.dupont@simulation.ca',
                'password' => Hash::make('Motdepasse123!'),
                'phone' => '+1 514 100 0001',
                'date_of_birth' => '1990-05-20',
                'nationality' => 'France',
                'passport_number' => 'FR-SIM-001',
                'address' => '123 Rue Principale, Paris, France',
                'family_members' => [],
            ],
            // 2 - Client seul
            [
                'client_type' => 'single',
                'first_name' => 'Sophie',
                'last_name' => 'Leroy',
                'email' => 'sophie.leroy@simulation.ca',
                'password' => Hash::make('Motdepasse123!'),
                'phone' => '+1 514 100 0002',
                'date_of_birth' => '1988-11-12',
                'nationality' => 'Belgique',
                'passport_number' => 'BE-SIM-002',
                'address' => '45 Avenue Louise, Bruxelles',
                'family_members' => [],
            ],
            // 3 - Famille : demandeur + conjoint
            [
                'client_type' => 'family',
                'first_name' => 'Marie',
                'last_name' => 'Martin',
                'email' => 'marie.martin@simulation.ca',
                'password' => Hash::make('Motdepasse123!'),
                'phone' => '+1 438 111 2222',
                'date_of_birth' => '1985-03-10',
                'nationality' => 'Canada',
                'passport_number' => 'CA-SIM-003',
                'address' => '200 Rue Sainte-Catherine, Montréal, QC',
                'family_members' => [
                    [
                        'first_name' => 'Paul',
                        'last_name' => 'Martin',
                        'relationship' => 'Conjoint(e)',
                        'date_of_birth' => '1984-12-01',
                        'nationality' => 'Canada',
                        'passport_number' => 'CA-SIM-003B',
                        'phone' => '+1 438 333 4444',
                        'email' => 'paul.martin@simulation.ca',
                    ],
                ],
            ],
            // 4 - Famille : demandeur + 2 enfants
            [
                'client_type' => 'family',
                'first_name' => 'Ahmed',
                'last_name' => 'Ben Ali',
                'email' => 'ahmed.benali@simulation.ca',
                'password' => Hash::make('Motdepasse123!'),
                'phone' => '+1 438 777 8888',
                'date_of_birth' => '1982-07-15',
                'nationality' => 'Maroc',
                'passport_number' => 'MA-SIM-004',
                'address' => '10 Avenue Hassan II, Casablanca',
                'family_members' => [
                    [
                        'first_name' => 'Sara',
                        'last_name' => 'Ben Ali',
                        'relationship' => 'Enfant',
                        'date_of_birth' => '2010-02-05',
                        'nationality' => 'Maroc',
                    ],
                    [
                        'first_name' => 'Rayan',
                        'last_name' => 'Ben Ali',
                        'relationship' => 'Enfant',
                        'date_of_birth' => '2013-09-18',
                        'nationality' => 'Maroc',
                    ],
                ],
            ],
            // 5 - Client seul
            [
                'client_type' => 'single',
                'first_name' => 'Carlos',
                'last_name' => 'Garcia',
                'email' => 'carlos.garcia@simulation.ca',
                'password' => Hash::make('Motdepasse123!'),
                'phone' => '+1 514 500 0005',
                'date_of_birth' => '1979-01-30',
                'nationality' => 'Mexique',
                'passport_number' => 'MX-SIM-005',
                'address' => 'Calle Reforma 100, Mexico DF',
                'family_members' => [],
            ],
            // 6 - Famille : demandeur + conjoint + 1 enfant
            [
                'client_type' => 'family',
                'first_name' => 'Yuki',
                'last_name' => 'Tanaka',
                'email' => 'yuki.tanaka@simulation.ca',
                'password' => Hash::make('Motdepasse123!'),
                'phone' => '+1 438 600 0006',
                'date_of_birth' => '1987-04-22',
                'nationality' => 'Japon',
                'passport_number' => 'JP-SIM-006',
                'address' => 'Shibuya 1-2-3, Tokyo',
                'family_members' => [
                    [
                        'first_name' => 'Hiroshi',
                        'last_name' => 'Tanaka',
                        'relationship' => 'Conjoint(e)',
                        'date_of_birth' => '1986-08-14',
                        'nationality' => 'Japon',
                        'passport_number' => 'JP-SIM-006B',
                        'email' => 'hiroshi.tanaka@simulation.ca',
                    ],
                    [
                        'first_name' => 'Aiko',
                        'last_name' => 'Tanaka',
                        'relationship' => 'Enfant',
                        'date_of_birth' => '2015-06-10',
                        'nationality' => 'Japon',
                    ],
                ],
            ],
            // 7 - Client seul
            [
                'client_type' => 'single',
                'first_name' => 'Elena',
                'last_name' => 'Kowalski',
                'email' => 'elena.kowalski@simulation.ca',
                'password' => Hash::make('Motdepasse123!'),
                'phone' => '+1 514 700 0007',
                'date_of_birth' => '1992-09-05',
                'nationality' => 'Pologne',
                'passport_number' => 'PL-SIM-007',
                'address' => 'ul. Marszałkowska 50, Varsovie',
                'family_members' => [],
            ],
            // 8 - Famille : demandeur + 3 enfants
            [
                'client_type' => 'family',
                'first_name' => 'Fatima',
                'last_name' => 'Hassan',
                'email' => 'fatima.hassan@simulation.ca',
                'password' => Hash::make('Motdepasse123!'),
                'phone' => '+1 438 800 0008',
                'date_of_birth' => '1980-12-01',
                'nationality' => 'Égypte',
                'passport_number' => 'EG-SIM-008',
                'address' => 'Corniche, Alexandrie',
                'family_members' => [
                    [
                        'first_name' => 'Omar',
                        'last_name' => 'Hassan',
                        'relationship' => 'Enfant',
                        'date_of_birth' => '2008-03-15',
                        'nationality' => 'Égypte',
                    ],
                    [
                        'first_name' => 'Layla',
                        'last_name' => 'Hassan',
                        'relationship' => 'Enfant',
                        'date_of_birth' => '2011-07-20',
                        'nationality' => 'Égypte',
                    ],
                    [
                        'first_name' => 'Khalid',
                        'last_name' => 'Hassan',
                        'relationship' => 'Enfant',
                        'date_of_birth' => '2014-11-08',
                        'nationality' => 'Égypte',
                    ],
                ],
            ],
            // 9 - Client seul
            [
                'client_type' => 'single',
                'first_name' => 'Lucas',
                'last_name' => 'Silva',
                'email' => 'lucas.silva@simulation.ca',
                'password' => Hash::make('Motdepasse123!'),
                'phone' => '+1 514 900 0009',
                'date_of_birth' => '1995-02-28',
                'nationality' => 'Brésil',
                'passport_number' => 'BR-SIM-009',
                'address' => 'Avenida Paulista 1000, São Paulo',
                'family_members' => [],
            ],
            // 10 - Famille : demandeur + conjoint + 2 enfants
            [
                'client_type' => 'family',
                'first_name' => 'Priya',
                'last_name' => 'Sharma',
                'email' => 'priya.sharma@simulation.ca',
                'password' => Hash::make('Motdepasse123!'),
                'phone' => '+1 438 101 0010',
                'date_of_birth' => '1988-06-15',
                'nationality' => 'Inde',
                'passport_number' => 'IN-SIM-010',
                'address' => 'Connaught Place, New Delhi',
                'family_members' => [
                    [
                        'first_name' => 'Raj',
                        'last_name' => 'Sharma',
                        'relationship' => 'Conjoint(e)',
                        'date_of_birth' => '1987-10-20',
                        'nationality' => 'Inde',
                        'passport_number' => 'IN-SIM-010B',
                        'email' => 'raj.sharma@simulation.ca',
                    ],
                    [
                        'first_name' => 'Arjun',
                        'last_name' => 'Sharma',
                        'relationship' => 'Enfant',
                        'date_of_birth' => '2012-04-12',
                        'nationality' => 'Inde',
                    ],
                    [
                        'first_name' => 'Ananya',
                        'last_name' => 'Sharma',
                        'relationship' => 'Enfant',
                        'date_of_birth' => '2016-08-25',
                        'nationality' => 'Inde',
                    ],
                ],
            ],
            // 11 - Client seul (bonus)
            [
                'client_type' => 'single',
                'first_name' => 'Olivier',
                'last_name' => 'Tremblay',
                'email' => 'olivier.tremblay@simulation.ca',
                'password' => Hash::make('Motdepasse123!'),
                'phone' => '+1 418 111 0011',
                'date_of_birth' => '1983-11-11',
                'nationality' => 'Canada',
                'passport_number' => 'CA-SIM-011',
                'address' => '1000 Rue Saint-Jean, Québec, QC',
                'family_members' => [],
            ],
        ];

        foreach ($clients as $data) {
            $familyMembers = $data['family_members'];
            unset($data['family_members']);

            $client = Client::create($data);

            foreach ($familyMembers as $member) {
                $client->familyMembers()->create($member);
            }
        }

        $this->command->info('Clients créés : ' . count($clients) . ' (dont familles avec membres).');
    }
}
