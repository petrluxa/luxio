<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_from');
            $table->unsignedInteger('price_to')->nullable();
            // Fáze trvání v minutách: práce – pauza (působení barvy) – práce.
            $table->unsignedSmallInteger('work_1');
            $table->unsignedSmallInteger('pause')->default(0);
            $table->unsignedSmallInteger('work_2')->default(0);
            $table->unsignedSmallInteger('buffer_after')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['salon_id', 'is_active', 'sort']);
        });

        // Které služby kadeřnice dělá + její vlastní cena a délky (null = převzít ze služby).
        Schema::create('staff_service', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('price_from')->nullable();
            $table->unsignedSmallInteger('work_1')->nullable();
            $table->unsignedSmallInteger('pause')->nullable();
            $table->unsignedSmallInteger('work_2')->nullable();

            $table->primary(['user_id', 'service_id']);
        });

        Schema::create('working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 1 = pondělí … 7 = neděle
            $table->time('starts_at');
            $table->time('ends_at');

            $table->index(['user_id', 'weekday']);
        });

        // Výjimka pro jeden den: nahradí běžnou pracovní dobu a platí i ve státní svátek.
        Schema::create('working_hour_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');

            $table->index(['user_id', 'date']);
        });

        Schema::create('time_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();

            $table->index(['user_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_offs');
        Schema::dropIfExists('working_hour_overrides');
        Schema::dropIfExists('working_hours');
        Schema::dropIfExists('staff_service');
        Schema::dropIfExists('services');
    }
};
