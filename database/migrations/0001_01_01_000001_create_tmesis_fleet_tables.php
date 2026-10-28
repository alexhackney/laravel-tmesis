<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fleet control plane's own tables (tmesis v0.7.0, plan-v0.7.0-fleet.md F1).
 *
 * The package owns them because their semantics ARE the contract's receiving end: the
 * D12 token swap, the durable ack before the answer, the monotonic revision. An app
 * mirrors what it needs from the Events\Fleet events rather than reimplementing them.
 *
 * No plain token is ever stored. A join token and a fleet token are kept as the
 * sha256 hex of the value the node presents, the way the enrolment bootstrap token is.
 *
 *  - tmesis_fleet_join_tokens: one row per one-time join token. `first_used_at` and
 *    `node_id` are set together on the first enroll; a repeat inside the retry window
 *    returns the same node.
 *  - tmesis_fleet_nodes: one row per enrolled node id. `token_sha256` is the ONE live
 *    fleet token, so replacing it revokes the previous one by construction. The status
 *    columns are the last heartbeat's report.
 *  - tmesis_fleet_desired: the intended revision per node, which only moves when the
 *    canonical document's keyed hash moves. The DOCUMENT is not stored: it carries
 *    relay URLs, which are credentials this package never persists, and the provider
 *    is asked for it on every heartbeat anyway. `document_hash` is an HMAC-SHA256
 *    under the app key, not a bare sha256, so a stored hash cannot be brute-forced
 *    back to a short stream key inside a relay URL.
 *  - tmesis_fleet_commands: the queue. `first_sent_at` marks delivery in a 2xx answer;
 *    only an undelivered command can expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tmesis_fleet_join_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('token_sha256', 64)->unique();
            $table->string('name');
            $table->string('role')->nullable();
            $table->json('labels');
            $table->timestamp('expires_at');
            $table->timestamp('first_used_at')->nullable();
            $table->string('node_id', 26)->nullable();
            $table->timestamp('created_at');

            $table->index('expires_at');
        });

        Schema::create('tmesis_fleet_nodes', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('name');
            $table->string('role');
            $table->string('version');
            $table->json('listen');
            $table->json('capabilities');
            $table->json('labels');
            $table->char('token_sha256', 64)->nullable()->unique();
            $table->timestamp('enrolled_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('started_at')->nullable();
            $table->string('admission')->nullable();
            $table->json('stats')->nullable();
            $table->json('streams')->nullable();
            $table->unsignedBigInteger('applied_revision')->default(0);
            $table->unsignedBigInteger('rejected_revision')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->char('revocations_digest', 64)->nullable();
            $table->timestamp('stale_since')->nullable();

            $table->index('name');
            $table->index('last_heartbeat_at');
        });

        Schema::create('tmesis_fleet_desired', function (Blueprint $table) {
            $table->string('node_id', 26)->primary();
            $table->unsignedBigInteger('revision');
            $table->char('document_hash', 64);
            $table->char('revocations_digest', 64);
            $table->timestamp('updated_at');
        });

        Schema::create('tmesis_fleet_commands', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('node_id', 26);
            $table->string('kind');
            $table->string('stream')->nullable();
            $table->json('args');
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->timestamp('first_sent_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            $table->index(['node_id', 'acked_at', 'expired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tmesis_fleet_commands');
        Schema::dropIfExists('tmesis_fleet_desired');
        Schema::dropIfExists('tmesis_fleet_nodes');
        Schema::dropIfExists('tmesis_fleet_join_tokens');
    }
};
