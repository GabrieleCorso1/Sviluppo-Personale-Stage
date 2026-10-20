<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FileStorageSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('filestorage')->insert([
            [
                'path' => 'seed/avatars',
                'name' => 'default-avatar',
                'extension' => 'png',
                'type' => 'image',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'path' => 'seed/projects',
                'name' => 'default-project',
                'extension' => 'png',
                'type' => 'image',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'path' => 'seed/teamcategories',
                'name' => 'default-team',
                'extension' => 'png',
                'type' => 'image',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}