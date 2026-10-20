<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    
public function up(): void
{
    Schema::create('sbis', function (Blueprint $table) {
        $table->id();

        
        $table->foreignId('parent_id')
            ->nullable()
            ->constrained('sbis')
            ->nullOnDelete();

        $table->boolean('is_positive');

        $table->foreignId('writer_id')
            ->constrained('users')
            ->restrictOnDelete();

        $table->foreignId('receiver_id')
            ->constrained('users')
            ->restrictOnDelete();

        $table->text('situation');
        $table->text('behavior');
        $table->text('impact');
        $table->text('suggestion')->nullable();

        $table->date('written_at');
        $table->date('answered_at')->nullable();

        $table->timestamps();
        $table->softDeletes();
    });
}

    public function down(): void
    {
        Schema::dropIfExists('sbis');
    }

};
