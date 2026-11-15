<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at'); // konec služby pro zákaznici (bez úklidu)
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('total_price')->default(0);
            $table->text('customer_note')->nullable();
            $table->text('staff_note')->nullable();
            $table->string('source', 10)->default('online');
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('second_reminder_sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['salon_id', 'starts_at']);
            $table->index(['staff_id', 'starts_at']);
            $table->index(['customer_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        // Snapshot služeb – změna ceníku nemění historii.
        Schema::create('booking_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('price');
            $table->unsignedInteger('price_to')->nullable();
            $table->unsignedSmallInteger('work_1');
            $table->unsignedSmallInteger('pause')->default(0);
            $table->unsignedSmallInteger('work_2')->default(0);
            $table->unsignedSmallInteger('buffer_after')->default(0);
            $table->unsignedTinyInteger('sequence')->default(0);
        });

        // Obsazenost kalendáře: work a buffer blokují, pause ne.
        // Segmenty existují jen u rezervací, které drží místo (zrušením se mažou).
        Schema::create('booking_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('type', 8);

            $table->index(['staff_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_segments');
        Schema::dropIfExists('booking_items');
        Schema::dropIfExists('bookings');
    }
};
