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
        Schema::create('legacy_migrator', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('migrate')->index();
            $table->string('status')->index();
            $table->integer('batch')->default(1)->index();
            $table->integer('total_migrated')->nullable()->comment('Total number of records migrated upon success only.');
            $table->text('message')->nullable()->comment('Optional message info for this migration. May be long.');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legacy_migrator');
    }
};