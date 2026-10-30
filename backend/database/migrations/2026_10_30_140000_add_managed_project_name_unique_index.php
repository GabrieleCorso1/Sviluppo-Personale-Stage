<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasDuplicates = DB::table('projects')->where('is_course', false)
            ->select('alias', 'is_timesheet')->groupBy('alias', 'is_timesheet')
            ->havingRaw('COUNT(*) > 1')->exists();
        if ($hasDuplicates) {
            throw new RuntimeException('Sono presenti nomi progetto duplicati nello stesso tipo, inclusi quelli eliminati. Rinominarli prima della migration.');
        }

        Schema::table('projects', function (Blueprint $table) {
            // NULL leaves courses outside this constraint. Soft-deleted projects keep their name reserved.
            $table->string('managed_alias')->nullable()
                ->virtualAs('CASE WHEN is_course = 0 THEN alias ELSE NULL END');
            $table->unique(['managed_alias', 'is_timesheet'], 'projects_managed_name_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique('projects_managed_name_type_unique');
            $table->dropColumn('managed_alias');
        });
    }
};
