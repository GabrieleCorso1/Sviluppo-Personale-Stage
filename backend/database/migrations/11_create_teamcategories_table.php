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
        Schema::create('teamcategories', function (Blueprint $table) {
                $table->id();

                $table->tinyText('alias');
                $table->text('description');
                $table->decimal('ideal_velocity', 3, 2)->unsigned();
                $table->foreignId('filestorage_id')
                    ->constrained('filestorage')
                    ->restrictOnDelete();
                $table->tinyText('color_code');

                $table->timestamps();
                $table->softDeletes();
        });
}

public function down(): void
{
        Schema::dropIfExists('teamcategories');
}

};
