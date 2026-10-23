<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->string('client_request_id', 100);
            $table->string('agent_id', 100);
            $table->string('customer_name', 200);
            $table->date('date_of_birth');
            $table->string('destination', 20);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 20);
            $table->unsignedInteger('sum_insured');
            $table->decimal('premium_amount', 12, 2);
            $table->string('status', 32);
            $table->timestamps();

            $table->unique(['agent_id', 'client_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
