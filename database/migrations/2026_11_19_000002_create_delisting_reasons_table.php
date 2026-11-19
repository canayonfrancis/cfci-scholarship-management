<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delisting_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('delisting_reasons')->exists()) {
            throw new RuntimeException('Retain registered delisting reasons. Review a forward migration instead of dropping populated registry data.');
        }
        Schema::dropIfExists('delisting_reasons');
    }
};
