<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quiz_material_analyses')) {
            Schema::table('quiz_material_analyses', function (Blueprint $table) {
                if (!Schema::hasColumn('quiz_material_analyses', 'chunk_embeddings')) {
                    $table->json('chunk_embeddings')->nullable()->after('chunks');
                }
                if (!Schema::hasColumn('quiz_material_analyses', 'embedding_model')) {
                    $table->string('embedding_model', 64)->nullable()->after('chunks');
                }
            });
        }

        if (Schema::hasTable('quiz_attempts')) {
            Schema::table('quiz_attempts', function (Blueprint $table) {
                if (!Schema::hasColumn('quiz_attempts', 'tab_switch_count')) {
                    $table->unsignedSmallInteger('tab_switch_count')->default(0)->after('marking_provider');
                }
                if (!Schema::hasColumn('quiz_attempts', 'focus_lost_seconds')) {
                    $table->unsignedInteger('focus_lost_seconds')->default(0)->after('marking_provider');
                }
                if (!Schema::hasColumn('quiz_attempts', 'integrity_flags')) {
                    $table->json('integrity_flags')->nullable()->after('marking_provider');
                }
                if (!Schema::hasColumn('quiz_attempts', 'delivered_question_ids')) {
                    $table->json('delivered_question_ids')->nullable()->after('marking_provider');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('quiz_material_analyses')) {
            Schema::table('quiz_material_analyses', function (Blueprint $table) {
                $drop = array_values(array_filter(
                    ['chunk_embeddings', 'embedding_model'],
                    fn (string $column) => Schema::hasColumn('quiz_material_analyses', $column)
                ));
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }

        if (Schema::hasTable('quiz_attempts')) {
            Schema::table('quiz_attempts', function (Blueprint $table) {
                $drop = array_values(array_filter(
                    ['tab_switch_count', 'focus_lost_seconds', 'integrity_flags', 'delivered_question_ids'],
                    fn (string $column) => Schema::hasColumn('quiz_attempts', $column)
                ));
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
};
