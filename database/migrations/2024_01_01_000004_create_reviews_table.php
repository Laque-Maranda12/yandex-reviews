<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('yandex_source_id')->constrained()->onDelete('cascade');
            $table->string('author_name');
            $table->string('author_phone')->nullable();
            $table->unsignedTinyInteger('rating')->nullable()->default(null);
            $table->text('text')->nullable();
            $table->string('branch_name')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('yandex_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['yandex_source_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
