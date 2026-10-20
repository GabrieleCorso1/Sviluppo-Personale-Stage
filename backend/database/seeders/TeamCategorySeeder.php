<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TeamCategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $fileStorageId = DB::table('filestorage')
            ->where('name', 'default-team')
            ->value('id');

        if (!$fileStorageId) {
            throw new \RuntimeException(
                'File default-team non trovato. Esegui prima FileStorageSeeder.'
            );
        }

        $categories = [
            [
                'alias' => 'Web Development',
                'description' => 'Team dedicato allo sviluppo di applicazioni web.',
                'ideal_velocity' => 0.85,
                'color_code' => '#3498DB',
            ],
            [
                'alias' => 'UX/UI Design',
                'description' => 'Team dedicato alla progettazione UX e UI.',
                'ideal_velocity' => 0.75,
                'color_code' => '#9B59B6',
            ],
            [
                'alias' => 'Backend Services',
                'description' => 'Team dedicato ai servizi e alle API.',
                'ideal_velocity' => 0.90,
                'color_code' => '#2ECC71',
            ],
        ];

        foreach ($categories as $category) {
            DB::table('teamcategories')->updateOrInsert(
                ['alias' => $category['alias']],
                [
                    'description' => $category['description'],
                    'ideal_velocity' => $category['ideal_velocity'],
                    'filestorage_id' => $fileStorageId,
                    'color_code' => $category['color_code'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}