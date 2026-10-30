<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasDuplicates = DB::table('users')
            ->select('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->isNotEmpty();

        if ($hasDuplicates) {
            throw new RuntimeException(
                'Impossibile rendere univoca users.email: risolvere prima le email duplicate, inclusi gli utenti eliminati logicamente.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email', 'users_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
        });
    }
};
