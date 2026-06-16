<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_material_analyses', function (Blueprint $table) {
            $table->json('chunk_embeddings')->nullable()->after('chunks');
            $table->string('embedding_model', 64)->nullable()->after('chunk_embeddings');
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('tab_switch_count')->default(0)->after('marking_provider');
            $table->unsignedInteger('focus_lost_seconds')->default(0)->after('tab_switch_count');
            $table->json('integrity_flags')->nullable()->after('focus_lost_seconds');
            $table->json('delivered_question_ids')->nullable()->after('integrity_flags');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_material_analyses', function (Blueprint $table) {
            $table->dropColumn(['chunk_embeddings', 'embedding_model']);
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn(['tab_switch_count', 'focus_lost_seconds', 'integrity_flags', 'delivered_question_ids']);
        });
    }
};
