<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstagramWebhookTest extends TestCase
{
    public function test_verify_with_correct_token_returns_challenge(): void
    {
        config(['services.instagram.webhook_verify_token' => 'correct-token']);

        $response = $this->get('/webhooks/instagram?hub_mode=subscribe&hub_verify_token=correct-token&hub_challenge=12345');

        $response->assertStatus(200);
        $response->assertSee('12345', false);
    }

    public function test_verify_with_wrong_token_returns_forbidden(): void
    {
        config(['services.instagram.webhook_verify_token' => 'correct-token']);

        $response = $this->get('/webhooks/instagram?hub_mode=subscribe&hub_verify_token=wrong-token&hub_challenge=12345');

        $response->assertStatus(403);
    }

    public function test_post_webhook_event_returns_200(): void
    {
        $response = $this->postJson('/webhooks/instagram', [
            'object' => 'instagram',
            'entry' => [['id' => '123', 'time' => 1234567890]],
        ]);

        $response->assertStatus(200);
    }
}
