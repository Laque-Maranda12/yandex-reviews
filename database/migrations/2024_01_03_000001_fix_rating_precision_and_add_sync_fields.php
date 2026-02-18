<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yandex_sources', function (Blueprint $table) {
            // Fix rating precision: DECIMAL(2,1) can't store 4.85, only 4.9
            // DECIMAL(3,2) stores 0.00-9.99, enough for 1.00-5.00 scale
            $table->decimal('rating', 3, 2)->nullable()->change();

            // Track last sync time for incremental updates
            $table->timestamp('last_synced_at')->nullable()->after('total_reviews');
        });
    }

    public function down(): void
    {
        Schema::table('yandex_sources', function (Blueprint $table) {
            $table->decimal('rating', 2, 1)->nullable()->change();
            $table->dropColumn('last_synced_at');
        });
    }
};
