<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_log', function (Blueprint $table) {
            $table->id();
            $table->morphs('notifiable');
            $table->string('type', 64);
            $table->string('channel', 8); // push | mail
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
    }
};
