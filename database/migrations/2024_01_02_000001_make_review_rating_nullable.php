<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Rating column is already nullable in create_reviews_table migration.
        // Original ->change() call caused Doctrine DBAL "unknown column type tinyinteger" error.
    }

    public function down(): void
    {
        //
    }
};
