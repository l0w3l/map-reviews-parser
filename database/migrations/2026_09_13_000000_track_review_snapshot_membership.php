<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedBigInteger('last_successful_run_id')->nullable();
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('last_seen_run_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', fn (Blueprint $table) => $table->dropColumn('last_seen_run_id'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('last_successful_run_id'));
    }
};
