<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROGRESSIVE 90-DAY SYNC ENGINE - RESILIENCE PASS.
 *
 * Durable, resumable per-item work ledger for one AnalyticsSyncTask.
 * Discovery persists EVERY unique provider item here ONCE (source=
 * discovery, from getMedia()/getVideoList()) or (source=known_refresh,
 * from the existing refreshKnownMedia()/refreshKnownVideos() rotation
 * candidate set) - BEFORE any chunk job ever runs. Chunk jobs then only
 * ever act on rows already sitting in this table, keyed by chunk_index,
 * which is what makes a worker crash mid-run safely resumable: a chunk
 * job re-run after a crash finds already-terminal rows (status != pending)
 * and skips them, so nothing is double-processed or lost (Part 9/10).
 *
 * `payload` carries the raw discovery-time item fields (caption/timestamp/
 * permalink/media_type/etc.) needed later by a discovery-source chunk job
 * to run matching/insight-fetch WITHOUT a second discovery API call.
 * known_refresh-source rows leave payload null - the chunk job resolves
 * those straight from the existing snapshot row (InstagramMediaSnapshot/
 * TikTokVideoSnapshot), exactly like refreshKnownMedia() already did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_sync_task_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analytics_sync_task_id')->constrained()->cascadeOnDelete();
            $table->string('external_item_id');
            $table->string('media_type')->nullable();
            $table->dateTime('published_at')->nullable();
            // 1 = 0-29 days, 2 = 30-59 days, 3 = 60-89 days (discovery
            // age bucket). 0 = known_refresh source (age-independent
            // rotation candidate, not part of the age-bucket contract).
            $table->unsignedTinyInteger('stage')->default(0);
            $table->string('source', 20)->default('discovery');
            $table->unsignedInteger('chunk_index');
            $table->string('status', 20)->default('pending');
            $table->json('payload')->nullable();
            $table->dateTime('core_completed_at')->nullable();
            $table->string('optional_status', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['analytics_sync_task_id', 'external_item_id'], 'sync_task_items_task_external_unique');
            $table->index(['analytics_sync_task_id', 'chunk_index'], 'sync_task_items_task_chunk_idx');
            $table->index(['analytics_sync_task_id', 'status'], 'sync_task_items_task_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_sync_task_items');
    }
};
