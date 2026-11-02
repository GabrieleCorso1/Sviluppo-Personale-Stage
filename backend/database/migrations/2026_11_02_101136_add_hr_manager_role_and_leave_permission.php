<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->enum('alias', ['Admin', 'Project Manager', 'Team Leader', 'User', 'HR Manager'])->default('User')->change();
        });
        $now = now();
        if (! DB::table('roles')->where('alias', 'HR Manager')->exists()) {
            DB::table('roles')->insert(['alias' => 'HR Manager', 'description' => 'Gestisce ferie, permessi e ROL.', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (['users.view' => 'Visualizzare gli utenti.', 'users.leaves.manage' => 'Gestire ferie, permessi e ROL.'] as $name => $description) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'description' => $description, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach (['Admin' => ['users.leaves.manage'], 'HR Manager' => ['users.view', 'users.leaves.manage']] as $alias => $permissions) {
            $role = DB::table('roles')->where('alias', $alias)->value('id');
            if ($role !== null) {
                foreach ($permissions as $name) {
                    DB::table('permission_role')->updateOrInsert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('name', $name)->value('id')], []);
                }
            }
        }
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('alias', 'HR Manager')->value('id');
        if ($role !== null && DB::table('role_user')->where('role_id', $role)->exists()) {
            throw new RuntimeException('Riassegnare gli utenti HR Manager prima del rollback.');
        }
        DB::table('roles')->where('alias', 'HR Manager')->delete();
        DB::table('permissions')->where('name', 'users.leaves.manage')->delete();
        Schema::table('roles', function (Blueprint $table) {
            $table->enum('alias', ['Admin', 'Project Manager', 'Team Leader', 'User'])->default('User')->change();
        });
    }
};
