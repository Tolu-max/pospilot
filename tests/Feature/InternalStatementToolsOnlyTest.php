<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalStatementToolsOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_statement_upload_and_qa_routes_return_404_in_production(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->app['env'] = 'production';

        $this->actingAs($user)->get('/qa/integration')->assertNotFound();
        $this->actingAs($user)->get('/transactions/import')->assertNotFound();
        $this->withSession(['_token' => 'internal-test-token'])
            ->withHeader('X-CSRF-TOKEN', 'internal-test-token')
            ->actingAs($user)
            ->post('/transactions/import/preview')
            ->assertNotFound();
        $this->withSession(['_token' => 'internal-test-token'])
            ->withHeader('X-CSRF-TOKEN', 'internal-test-token')
            ->actingAs($user)
            ->post('/settlements/import/preview')
            ->assertNotFound();
    }
}
