<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_requests', function (Blueprint $table) {
            $table->string('provider_reference', 100)->nullable();
            $table->jsonb('last_provider_response')->nullable();
            $table->unsignedInteger('submission_attempt_count')->default(0);
            $table->string('policy_number', 100)->nullable()->unique();
        });

        Schema::create('policy_state_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained()->cascadeOnDelete();
            $table->string('previous_state', 32)->nullable();
            $table->string('new_state', 32);
            $table->jsonb('context')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['policy_request_id', 'occurred_at']);
        });

        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('scenario', 32);
            $table->string('result', 32);
            $table->string('provider_reference', 100)->nullable();
            $table->jsonb('response')->nullable();
            $table->timestamps();

            $table->unique(['policy_request_id', 'attempt_number']);
        });

        // Requests created in Stage 2 predate the history table.
        DB::table('policy_requests')->select(['id', 'status', 'created_at'])->orderBy('id')
            ->chunkById(100, function ($requests) {
                foreach ($requests as $request) {
                    DB::table('policy_state_histories')->insert([
                        'policy_request_id' => $request->id,
                        'previous_state' => null,
                        'new_state' => $request->status,
                        'context' => json_encode(['source' => 'stage_2_backfill']),
                        'occurred_at' => $request->created_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
        Schema::dropIfExists('policy_state_histories');

        Schema::table('policy_requests', function (Blueprint $table) {
            $table->dropUnique(['policy_number']);
            $table->dropColumn(['provider_reference', 'last_provider_response', 'submission_attempt_count', 'policy_number']);
        });
    }
};
