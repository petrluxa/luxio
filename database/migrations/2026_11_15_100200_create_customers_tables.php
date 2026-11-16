<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 20)->nullable(); // normalizovaný tvar +420123456789
            $table->foreignId('preferred_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->text('color_recipe')->nullable();
            $table->boolean('is_blocked')->default(false);
            // Ověřená = přidala ji kadeřnice, nebo jí už schválila první rezervaci.
            $table->boolean('is_verified')->default(false);
            $table->boolean('notifications_enabled')->default(true);
            $table->timestamp('consent_at')->nullable();
            $table->timestamps();

            $table->index(['salon_id', 'phone']);
            $table->index(['salon_id', 'name']);
        });

        Schema::create('customer_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique(); // sha256 – čitelný token se neukládá
            $table->foreignId('scope_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_access_tokens');
        Schema::dropIfExists('customers');
    }
};
