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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->enum('gender', ['male','female'])->nullable();
            $table->string('azure_email');
            $table->string('company_email');
            $table->boolean('is_intern');
            $table->boolean('isAllocation_visible');
            
            
            $table->string('email');
            $table->string('password');
            $table->rememberToken();
            $table->date('birth_date');
            $table->date('hiring_date');
            $table->date('resignation_date')->nullable();
            $table->boolean('remember_me');

            $table->foreignId('filestorage_id')
            ->constrained('filestorage')
            ->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
