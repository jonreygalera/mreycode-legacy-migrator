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
        Schema::create('migration_legacy', function (Blueprint $table) {
            $table->id();
            $table->string('source_name');
            $table->bigInteger('legacy_id');
            $table->bigInteger('pk_id')->nullable()->index();
            $table->string('migration_table_name')->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['source_name', 'legacy_id', 'migration_table_name'], 'ml_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('migration_legacy');
    }
};
