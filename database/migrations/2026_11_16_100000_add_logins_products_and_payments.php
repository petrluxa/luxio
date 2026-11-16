<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Přihlašovací jméno místo e-mailu, katalog přípravků, použité přípravky u návštěvy,
 * vyúčtování a doklad o zaplacení.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Přihlašuje se jménem (např. „sabina“) – aplikace žádné e-maily neposílá, takže adresa
        // není k ničemu. Kadeřnice bez jména a hesla je jen v rozvrhu a sama se nepřihlásí.
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('email', 'login');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('login')->nullable()->change();
            $table->string('password')->nullable()->change();
        });

        // Údaje na doklad o zaplacení.
        Schema::table('salons', function (Blueprint $table) {
            $table->string('company')->nullable()->after('name'); // provozovatel (jméno nebo firma)
            $table->string('ico', 12)->nullable()->after('company');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->string('brand', 60);
            $table->string('line', 80)->nullable();
            $table->string('name', 160);
            $table->string('kind', 60)->nullable(); // šampon, maska, oxidant…
            $table->string('category', 16);
            $table->string('size', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['salon_id', 'category']);
            $table->index(['salon_id', 'brand']);
        });

        // Co kadeřnice při návštěvě použila. Název je opis – úprava katalogu nemění historii.
        Schema::create('booking_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 255);
            $table->string('note', 160)->nullable(); // odstín, množství
            $table->unsignedTinyInteger('sequence')->default(0);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedInteger('paid_amount')->nullable()->after('total_price');
            $table->string('payment_method', 10)->nullable()->after('paid_amount');
            $table->timestamp('paid_at')->nullable()->after('payment_method');
            $table->string('receipt_number', 20)->nullable()->after('paid_at');

            $table->unique(['salon_id', 'receipt_number']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['salon_id', 'receipt_number']);
            $table->dropColumn(['paid_amount', 'payment_method', 'paid_at', 'receipt_number']);
        });

        Schema::dropIfExists('booking_products');
        Schema::dropIfExists('products');

        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn(['company', 'ico']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('login', 'email');
        });
    }
};
