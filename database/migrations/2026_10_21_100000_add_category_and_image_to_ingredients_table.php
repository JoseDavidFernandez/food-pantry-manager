<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Additive only: existing ingredients keep all their data, with no
     * category ("Sin clasificar") and no image until the user chooses them.
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->string('category', 30)->nullable()->after('default_unit');
            // Local copy of an image the user chose, and what its licence requires to show.
            $table->string('image_path', 255)->nullable()->after('category');
            $table->string('image_provider', 20)->nullable()->after('image_path');
            $table->string('image_source_url', 500)->nullable()->after('image_provider');
            $table->string('image_author', 255)->nullable()->after('image_source_url');
            $table->string('image_license', 100)->nullable()->after('image_author');
            $table->string('image_license_url', 500)->nullable()->after('image_license');
            $table->timestamp('image_updated_at')->nullable()->after('image_license_url');
            $table->index(['household_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'category']);
            $table->dropColumn([
                'category', 'image_path', 'image_provider', 'image_source_url',
                'image_author', 'image_license', 'image_license_url', 'image_updated_at',
            ]);
        });
    }
};
