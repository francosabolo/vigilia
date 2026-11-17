<?php

use App\Enums\IncidentResolution;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Incident;
use App\Models\Site;
use App\Models\SiteReport;
use App\Models\User;
use App\Notifications\MonthlyReportReady;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['vigilia.ai.enabled' => false]);
    $this->client = Client::factory()->create();
    $this->site = Site::factory()->create(['organization_id' => $this->client->organization_id, 'client_id' => $this->client->id, 'created_at' => now()->subMonths(3)]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->site->organization_id]);
});

it('builds the monthly report from the month data and stores its text', function () {
    $month = now()->subMonthNoOverflow();
    Incident::factory()->create(['site_id' => $this->site->id, 'detected_at' => $month->copy()->day(10), 'resolution' => IncidentResolution::Confirmed, 'resolved_at' => $month->copy()->day(10)->addHour(), 'status' => 'resolved']);
    Incident::factory()->create(['site_id' => $this->site->id, 'detected_at' => now()->subMonths(2)]);

    $this->actingAs($this->admin)->get("/sites/{$this->site->id}/report?month={$month->format('Y-m')}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('reports/site')
            ->where('report.metrics.confirmed', 1)
            ->has('report.incidents', 1)
            ->where('report.narrative', fn ($text) => str_contains($text, 'Se confirmaron 1 intrusiones')));

    expect(SiteReport::where('site_id', $this->site->id)->where('month', $month->format('Y-m'))->exists())->toBeTrue();
    $this->actingAs($this->admin)->get("/sites/{$this->site->id}/report?month=".now()->addMonth()->format('Y-m'))->assertNotFound();
});

it("e-mails last month's report to the client's administration once", function () {
    Notification::fake();
    $manager = User::factory()->create(['role' => UserRole::ClientAdmin, 'organization_id' => $this->site->organization_id, 'client_id' => $this->client->id]);
    $other = User::factory()->create(['role' => UserRole::ClientAdmin, 'organization_id' => $this->site->organization_id, 'client_id' => Client::factory()->create()->id]);

    $this->artisan('vigilia:monthly-reports')->assertSuccessful();
    $this->artisan('vigilia:monthly-reports')->assertSuccessful();

    Notification::assertSentToTimes($manager, MonthlyReportReady::class, 1);
    Notification::assertNotSentTo($other, MonthlyReportReady::class);
});

it('writes the incident report from its timeline and refreshes it when the incident changes', function () {
    $incident = Incident::factory()->create(['site_id' => $this->site->id, 'title' => 'Persona en el alambrado']);

    $this->actingAs($this->admin)->get("/incidents/{$incident->id}/report")->assertOk()
        ->assertInertia(fn ($page) => $page->component('reports/incident')->where('narrative', fn ($text) => str_contains($text, 'Persona en el alambrado') && str_contains($text, 'sigue abierto')));

    $this->travel(1)->minutes();
    $incident->update(['status' => 'resolved', 'resolution' => IncidentResolution::FalseAlarm, 'resolved_at' => now()]);

    $this->actingAs($this->admin)->get("/incidents/{$incident->id}/report")->assertOk()
        ->assertInertia(fn ($page) => $page->where('narrative', fn ($text) => str_contains($text, 'Falsa alarma')));
});

it('keeps reports inside who can see the site', function () {
    $outsider = User::factory()->create(['role' => UserRole::Operator]);
    $incident = Incident::factory()->create(['site_id' => $this->site->id]);

    $this->actingAs($outsider)->get("/sites/{$this->site->id}/report")->assertForbidden();
    $this->actingAs($outsider)->get("/incidents/{$incident->id}/report")->assertForbidden();
});
