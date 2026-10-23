<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureLocalDemo;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PolicyWebUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_dashboard_renders_filters_table_and_refresh_control(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Policy requests')
            ->assertSee('status-filter')
            ->assertSee('refresh-list')
            ->assertSee('policy-rows');
    }

    public function test_creation_page_renders_all_intake_fields(): void
    {
        $response = $this->get('/policies/new')->assertOk()->assertSee('create-form');

        foreach (['client_request_id', 'agent_id', 'customer_name', 'date_of_birth', 'destination', 'trip_start', 'trip_end', 'plan_code', 'sum_insured'] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
    }

    public function test_detail_page_renders_audit_sections_and_actions(): void
    {
        $this->get('/policies/42')
            ->assertOk()
            ->assertSee('data-policy-id="42"', false)
            ->assertSee('State history')
            ->assertSee('Issuance attempts')
            ->assertSee('Provider events')
            ->assertSee('action-submit')
            ->assertSee('action-retry')
            ->assertSee('action-dispatch')
            ->assertSee('action-reconcile');

        $this->get('/policies/not-a-number')->assertNotFound();
    }

    public function test_demo_ui_middleware_blocks_non_local_environments(): void
    {
        $original = app()->environment();
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->expectException(NotFoundHttpException::class);
            (new EnsureLocalDemo)->handle(Request::create('/'), fn () => response('visible'));
        } finally {
            app()->detectEnvironment(fn () => $original);
        }
    }

    public function test_mock_callback_receiver_is_not_available_outside_local_demo(): void
    {
        $original = app()->environment();
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->postJson('/api/mock-insurer/callback', [])->assertForbidden();
        } finally {
            app()->detectEnvironment(fn () => $original);
        }
    }
}
