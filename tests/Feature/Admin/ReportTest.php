<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/**
 * A succeeded generation that cost us $providerCost and the user 25% more.
 *
 * @param  array<string, mixed>  $attributes
 */
function billedGeneration(string $completedAt, int $providerCost, array $attributes = []): Generation
{
    return Generation::factory()->succeeded()->create([
        'completed_at' => $completedAt,
        'provider_cost_nanodollars' => $providerCost,
        'charge_nanodollars' => intdiv($providerCost * 125, 100),
        ...$attributes,
    ]);
}

function reportRange(): array
{
    return ['start_date' => '2026-11-01', 'end_date' => '2026-11-02'];
}

it('redirects guests to the login page', function () {
    $this->get(route('admin.report'))->assertRedirect(route('login'));
});

it('is only for operators', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.report'))
        ->assertForbidden();
});

it('reports spend, provider cost and profit per UTC day, matched against Pixelforge', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(pixelforgeFixture('usage'))]);
    // Pixelforge's usage fixture: 3 generations on 2026-11-01 billed $0.169080000.
    $alice = User::factory()->create(['name' => 'Alice']);
    billedGeneration('2026-11-01 00:00:00', 56_360_000, ['user_id' => $alice->id]);
    billedGeneration('2026-11-01 13:00:00', 56_360_000, ['user_id' => $alice->id]);
    billedGeneration('2026-11-01 23:59:59', 56_360_000);
    // Not billed by Pixelforge, so not counted: failed, still running, or completed outside the range.
    Generation::factory()->failed()->create(['completed_at' => '2026-11-01 10:00:00']);
    Generation::factory()->processing()->create();
    billedGeneration('2026-11-03 00:00:00', 1_000_000);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', reportRange()))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Report')
            ->where('startDate', '2026-11-01')
            ->where('endDate', '2026-11-02')
            ->where('report.pixelforge_available', true)
            ->where('report.days.0', [
                'date' => '2026-11-01',
                'generations' => 3,
                'credit_spend_nanodollars' => 211_350_000,
                'provider_cost_nanodollars' => 169_080_000,
                'profit_nanodollars' => 42_270_000,
                'pixelforge_generations' => 3,
                'pixelforge_cost_nanodollars' => 169_080_000,
                'difference_nanodollars' => 0,
            ])
            ->where('report.days.1.date', '2026-11-02')
            ->where('report.days.1.generations', 0)
            ->where('report.days.1.difference_nanodollars', 0)
            ->where('report.totals.provider_cost_nanodollars', 169_080_000)
            ->where('report.totals.difference_nanodollars', 0)
            ->has('report.users', 2)
            ->where('report.users.0.name', 'Alice')
            ->where('report.users.0.generations', 2)
            ->where('report.users.0.credit_spend_nanodollars', 140_900_000)
            ->where('report.users.0.profit_nanodollars', 28_180_000));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.test/v1/usage?start_date=2026-11-01&end_date=2026-11-02');
});

it('shows exactly how far a day is from what Pixelforge billed', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(pixelforgeFixture('usage'))]);
    billedGeneration('2026-11-01 12:00:00', 169_080_000);
    billedGeneration('2026-11-02 12:00:00', 1_000);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', reportRange()))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.days.0.difference_nanodollars', 0)
            ->where('report.days.1.difference_nanodollars', 1_000)
            ->where('report.days.1.pixelforge_generations', 0)
            ->where('report.totals.difference_nanodollars', 1_000));
});

it('counts a generation we waived as a loss', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(pixelforgeFixture('usage'))]);
    billedGeneration('2026-11-01 12:00:00', 169_080_000, ['charge_nanodollars' => 0, 'charge_waived' => true]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', reportRange()))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.days.0.credit_spend_nanodollars', 0)
            ->where('report.days.0.profit_nanodollars', -169_080_000)
            ->where('report.days.0.difference_nanodollars', 0));
});

it('groups generations of deleted accounts together', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(pixelforgeFixture('usage'))]);
    billedGeneration('2026-11-01 12:00:00', 169_080_000, ['user_id' => null]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', reportRange()))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.users.0.user_id', null)
            ->where('report.users.0.generations', 1));
});

it('still shows our side when Pixelforge cannot be reached', function () {
    Exceptions::fake();
    Http::fake(['pixelforge.test/v1/usage*' => Http::response('Service Unavailable', 503)]);
    billedGeneration('2026-11-01 12:00:00', 169_080_000);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', reportRange()))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.pixelforge_available', false)
            ->where('report.days.0.provider_cost_nanodollars', 169_080_000)
            ->where('report.days.0.pixelforge_cost_nanodollars', null)
            ->where('report.days.0.difference_nanodollars', null));
});

it('defaults to the last 30 days', function () {
    $this->travelTo('2026-11-17 15:00:00');
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(pixelforgeFixture('usage'))]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('startDate', '2026-10-19')
            ->where('endDate', '2026-11-17'));
});

it('rejects a range Pixelforge cannot report on', function (array $range, string $invalidField) {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', $range))
        ->assertInvalid($invalidField);
})->with([
    'more than 92 days' => [['start_date' => '2026-08-01', 'end_date' => '2026-11-01'], 'end_date'],
    'end before start' => [['start_date' => '2026-11-02', 'end_date' => '2026-11-01'], 'end_date'],
    'not a date' => [['start_date' => 'yesterday'], 'start_date'],
]);

it('lets an operator grant report access by email', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('app:grant-admin', ['email' => 'ops@example.com'])->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeTrue();
});

it('refuses to grant access to an unknown email', function () {
    $this->artisan('app:grant-admin', ['email' => 'nobody@example.com'])->assertFailed();
});
