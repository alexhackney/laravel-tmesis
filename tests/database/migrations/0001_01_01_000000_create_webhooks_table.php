<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shape of a consumer's shared `webhooks` table (tsp-web's), for the `table`
 * driver's tests only. Deliberately no index on `source` or `processed_at`, as there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('reprocessed_at')->nullable();
            $table->boolean('reprocessing_queued')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
