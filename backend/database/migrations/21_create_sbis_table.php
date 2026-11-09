<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sbis', function (Blueprint $table) {
            $table->id();

            $table->foreignId('writer_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('receiver_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbis');
    }
};
