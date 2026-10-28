<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable delivery store behind the default dedupe driver.
 *
 * The unique index on `delivery` IS the dedupe: a second insert of the same delivery
 * id raises a unique constraint violation, which the deduper catches and reports as a
 * duplicate. That is atomic in every supported database, which an in-application
 * check-then-insert would not be.
 *
 * `body` holds the raw bytes verbatim. They are what the signature covered, so a
 * stored delivery can still be re-verified later.
 *
 * The node has its 204 before any listener runs and never retries an acknowledged
 * delivery, so the row is the only copy left when a listener throws. It is kept
 * unprocessed, and the dispatch-state columns record what happened to it:
 *
 *  - `failed_attempts`, `last_error` and `failed_at` describe the most recent failure.
 *    `last_error` is the exception class and a truncated message, never the body.
 *  - `leased_until` is the dispatch lease. Set on the claim and by
 *    `tmesis:webhooks:retry` through one conditional UPDATE, so two runs, or a run
 *    racing the original request, never dispatch the same row twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tmesis_webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('delivery', 26)->unique();
            $table->string('event');
            $table->string('node');
            $table->timestamp('occurred_at')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->json('headers');
            $table->longText('body');
            $table->timestamp('stored_at');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('leased_until')->nullable();

            // Pruning filters on stored_at; the doctor's "claimed but never
            // processed" check filters on processed_at.
            $table->index('stored_at');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tmesis_webhook_deliveries');
    }
};
