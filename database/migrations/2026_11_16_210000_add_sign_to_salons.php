<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nápis salonu („cedule“) v záhlaví rozvrhu: velký nápis a drobný podtitul pod ním.
 * Když nápis chybí, použije se název salonu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->string('sign_title', 40)->nullable()->after('name');
            $table->string('sign_subtitle', 60)->nullable()->after('sign_title');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn(['sign_title', 'sign_subtitle']);
        });
    }
};
