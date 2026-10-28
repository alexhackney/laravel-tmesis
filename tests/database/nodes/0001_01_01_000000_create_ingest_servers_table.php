<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trimmed copy of tsp-web's `ingest_servers`, for TableNodeResolver's tests only.
 * Legacy nginx servers share the table, told apart by `type`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_servers', function (Blueprint $table) {
            $table->id();
            $table->string('hostname');
            $table->string('type')->default('nginx');
            $table->string('webhook_token', 64)->nullable();
            $table->text('admin_token')->nullable();
            $table->string('dns_name')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('role')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_servers');
    }
};
