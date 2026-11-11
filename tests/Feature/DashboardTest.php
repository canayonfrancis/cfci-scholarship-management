<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_the_scholarship_dashboard(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 09:00:00', 'Asia/Manila'));

        $user = User::factory()->create([
            'name' => 'Francis C. Canayon',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertSeeText('Good morning, Francis!')
            ->assertSeeText('Thu, Sep 10, 2026')
            ->assertSee('Validations Trend')
            ->assertSee('Anne Dela Cruz')
            ->assertSee('Direct Scholarship Validation')
            ->assertDontSee('Create a New Application');
    }
}
