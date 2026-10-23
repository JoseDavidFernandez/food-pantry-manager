<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles of the household members (RN-38): admin, editor or viewer. Every existing membership
     * belongs to the person who created that household, so it becomes its admin (the default).
     */
    public function up(): void
    {
        Schema::table('household_user', function (Blueprint $table) {
            $table->string('role', 10)->default('admin')->after('user_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE household_user ADD CONSTRAINT household_user_role_valid CHECK (role IN ('admin', 'editor', 'viewer'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE household_user DROP CHECK household_user_role_valid');
        }

        Schema::table('household_user', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
