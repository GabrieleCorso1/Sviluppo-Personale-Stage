<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('necessities', function (Blueprint $table) {
            $table->unsignedBigInteger('allocation_baseline_id')->default(0);
        });

        DB::table('necessities')->update([
            'allocation_baseline_id' => DB::table('project_user')->max('id') ?? 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('necessities', function (Blueprint $table) {
            $table->dropColumn('allocation_baseline_id');
        });
    }
};
