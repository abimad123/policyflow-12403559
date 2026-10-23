<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_requests', function (Blueprint $table) {
            $table->unique('provider_reference');
        });

        Schema::table('issuance_attempts', function (Blueprint $table) {
            $table->string('retry_key', 100)->nullable();
            $table->unique(['policy_request_id', 'retry_key']);
        });

        Schema::create('mock_provider_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider_reference', 100)->unique();
            $table->string('policy_number', 100)->unique();
            $table->string('status', 32);
            $table->timestamps();
        });

        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100)->unique();
            $table->foreignId('policy_request_id')->constrained()->cascadeOnDelete();
            $table->string('provider_reference', 100);
            $table->string('event_type', 32);
            $table->string('policy_number', 100);
            $table->boolean('applied');
            $table->jsonb('payload');
            $table->timestamps();

            $table->index(['policy_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
        Schema::dropIfExists('mock_provider_policies');

        Schema::table('issuance_attempts', function (Blueprint $table) {
            $table->dropUnique(['policy_request_id', 'retry_key']);
            $table->dropColumn('retry_key');
        });

        Schema::table('policy_requests', function (Blueprint $table) {
            $table->dropUnique(['provider_reference']);
        });
    }
};
