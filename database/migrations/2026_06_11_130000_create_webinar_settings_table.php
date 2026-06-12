<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webinar_settings')) {
            return;
        }

        Schema::create('webinar_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('recording_enabled')->default(false);
            $table->timestamp('session_started_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_settings');
    }
};
