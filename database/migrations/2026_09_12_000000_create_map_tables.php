<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_id');
            $table->string('url');
            $table->string('name')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count')->nullable();
            $table->unsignedInteger('reviews_count')->nullable();
            $table->string('status')->default('queued');
            $table->text('error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'source_id']);
        });
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('source_id');
            $table->string('author');
            $table->timestamp('published_at');
            $table->text('text');
            $table->unsignedTinyInteger('rating');
            $table->timestamps();
            $table->unique(['organization_id', 'source_id']);
            $table->index(['organization_id', 'published_at']);
        });
        Schema::create('parse_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued');
            $table->unsignedInteger('collected')->default(0);
            $table->json('snapshot')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parse_runs');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('organizations');
    }
};
