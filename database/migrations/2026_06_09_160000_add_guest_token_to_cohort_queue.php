<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('livezoom_cohort_queue_entries')) {
            return;
        }

        Schema::table('livezoom_cohort_queue_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('livezoom_cohort_queue_entries', 'guest_token')) {
                $table->string('guest_token', 64)->nullable()->after('student_id');
            }
        });

        Schema::table('livezoom_cohort_queue_entries', function (Blueprint $table) {
            if (Schema::hasColumn('livezoom_cohort_queue_entries', 'guest_token')) {
                $table->index(['livezoom_cohort_id', 'guest_token'], 'lz_queue_cohort_guest_idx');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('livezoom_cohort_queue_entries')) {
            return;
        }

        Schema::table('livezoom_cohort_queue_entries', function (Blueprint $table) {
            if (Schema::hasColumn('livezoom_cohort_queue_entries', 'guest_token')) {
                $table->dropIndex('lz_queue_cohort_guest_idx');
                $table->dropColumn('guest_token');
            }
        });
    }
};
