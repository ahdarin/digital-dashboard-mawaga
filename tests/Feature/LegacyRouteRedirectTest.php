<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression untuk KI-12 (Revision Log & Publishing Tracker legacy duplikat
 * tab resmi Produksi) - lihat docs/USER_MANUAL_SOURCE_OF_TRUTH.md Bagian 22.
 * URL lama di-redirect (bukan 404) ke tab resmi untuk backward-compat.
 */
class LegacyRouteRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_publishing_tracker_redirects_to_production_workflow_published_tab(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $response = $this->actingAs($user)->get('/publishing-tracker');

        $response->assertRedirect('/production-workflow?tab=published');
    }

    public function test_legacy_revision_log_redirects_to_production_workflow_revisions_tab(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $response = $this->actingAs($user)->get('/revision-log');

        $response->assertRedirect('/production-workflow?tab=revisions');
    }
}
