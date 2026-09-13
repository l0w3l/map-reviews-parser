<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('provider', 50)->default('yandex');
            $table->dropUnique(['user_id', 'source_id']);
            $table->unique(['user_id', 'provider', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // The unique index refuses rollback if different providers now share an ID.
            $table->unique(['user_id', 'source_id']);
            $table->dropUnique(['user_id', 'provider', 'source_id']);
            $table->dropColumn('provider');
        });
    }
};
