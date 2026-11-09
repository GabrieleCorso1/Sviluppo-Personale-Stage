<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sbi_revisions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sbi_id')
                ->constrained('sbis')
                ->restrictOnDelete();

            $table->unsignedInteger('revision_number');
            $table->boolean('is_positive');
            $table->date('event_date');

            $table->text('situation');
            $table->text('behavior');
            $table->text('impact');
            $table->text('suggestion')->nullable();
            $table->text('personal_notes')->nullable();

            $table->timestamp('viewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['sbi_id', 'revision_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbi_revisions');
    }
};
