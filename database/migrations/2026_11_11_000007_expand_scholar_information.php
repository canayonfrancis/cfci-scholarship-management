<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No academic-program reference table existed; scholarship programs are a different concept.
        Schema::create('academic_programs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('students', function (Blueprint $table) {
            foreach (['lrn', 'philsys_id', 'four_ps_id'] as $name) {
                $table->text($name)->nullable(); // Application-encrypted strings, not numeric identifiers.
            }
            $table->string('suffix', 30)->nullable();
            $table->unsignedTinyInteger('sex')->nullable();
            $table->date('birthdate')->nullable();
            $table->foreignId('academic_program_id')->nullable()->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedSmallInteger('year_level')->nullable();
            $table->foreignId('academic_period_id')->nullable()->constrained()->restrictOnDelete()->restrictOnUpdate();
            foreach (['father_last_name', 'father_first_name', 'father_middle_name', 'mother_maiden_last_name', 'mother_maiden_first_name', 'mother_maiden_middle_name', 'street_barangay', 'city_municipality', 'province', 'region'] as $name) {
                $table->string($name)->nullable();
            }
            $table->string('zip_code', 20)->nullable();
            $table->string('disability_type', 60)->nullable();
            $table->boolean('solo_parent_dependent')->nullable();
            $table->boolean('first_generation_student')->nullable();
            $table->string('indigenous_people_group')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_program_id');
            $table->dropConstrainedForeignId('academic_period_id');
            $table->dropColumn(['lrn', 'philsys_id', 'four_ps_id', 'suffix', 'sex', 'birthdate', 'year_level', 'father_last_name', 'father_first_name', 'father_middle_name', 'mother_maiden_last_name', 'mother_maiden_first_name', 'mother_maiden_middle_name', 'street_barangay', 'city_municipality', 'province', 'region', 'zip_code', 'disability_type', 'solo_parent_dependent', 'first_generation_student', 'indigenous_people_group']);
        });
        Schema::dropIfExists('academic_programs');
    }
};
