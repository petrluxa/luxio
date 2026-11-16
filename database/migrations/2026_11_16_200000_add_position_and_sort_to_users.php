<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tým salonu nejsou jen kadeřnice: každý má zaměření (kadeřnice, manikúra, pedikúra, kosmetika…),
 * podle kterého se rozvrh dělí na skupiny, a pořadí sloupce v rozvrhu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('position', 40)->nullable()->after('name');
            $table->unsignedSmallInteger('sort')->default(0)->after('is_bookable');
        });

        // Dosavadní pořadí bylo podle založení – zůstává.
        foreach (DB::table('users')->orderBy('id')->pluck('id') as $index => $id) {
            DB::table('users')->where('id', $id)->update(['sort' => $index + 1]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['position', 'sort']);
        });
    }
};
