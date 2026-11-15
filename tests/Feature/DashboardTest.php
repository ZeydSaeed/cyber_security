<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    /** The owner removed the dashboard (2026-11-15): it leads to /admin. */
    public function test_the_dashboard_address_leads_to_the_grades_overview()
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertRedirect('/admin');
        $this->get('/notifications')->assertRedirect('/admin');
        $this->get('/admin/reports')->assertRedirect('/admin');
    }
}
