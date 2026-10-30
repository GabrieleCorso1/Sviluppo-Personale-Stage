<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $allowedTypes = ['ferie', 'permesso', 'rol'];
        $existingTypes = DB::table('user_leave_balances')
            ->distinct()
            ->pluck('leave_type');

        foreach ($existingTypes as $type) {
            if (! in_array($type, $allowedTypes, true)) {
                throw new RuntimeException(
                    'Conversione leave_type interrotta: sono presenti valori diversi da ferie, permesso o rol.'
                );
            }
        }

        Schema::table('user_leave_balances', function (Blueprint $table) {
            $table->enum('leave_type', ['ferie', 'permesso', 'rol'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_leave_balances', function (Blueprint $table) {
            $table->string('leave_type')->change();
        });
    }
};
