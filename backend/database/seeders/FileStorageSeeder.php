<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FileStorageSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach (['default-avatar', 'default-project', 'default-team'] as $name) {
            DB::table('filestorage')->updateOrInsert(
                ['name' => $name],
                [
                    'path' => 'images/defaults',
                    'extension' => 'svg',
                    'type' => 'image',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
