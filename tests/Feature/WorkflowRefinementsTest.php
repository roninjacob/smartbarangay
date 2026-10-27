<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\ConfigurationAudit;
use App\Services\DocumentTemplateConfiguration;
use App\Services\QrTicketIssuer;
use App\Services\ServiceDocumentTemplates;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class WorkflowRefinementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Storage::fake('local');
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-27 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function fixture(?User $resident = null, string $status = 'pending'): Reservation
    {
        $resident ??= User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $service = Service::create(['name' => 'Clearance '.Service::count(), 'fee_amount' => '0.00']);
        $schedule = Schedule::first() ?? Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 10]);

        return Reservation::create(['user_id' => $resident->id, 'service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
    }

    public function test_service_edit_requires_current_record_bound_revision_and_stale_edit_does_not_write(): void
    {
        $r = $this->fixture();
        $service = $r->service;
        $revision = app(ConfigurationAudit::class)->revision($service);
        $other = Service::create(['name' => 'Other', 'fee_amount' => '0.00']);
        $this->actingAs($this->admin())->put(route('admin.services.update', $service), [
            'name' => 'Current clearance', 'fee_amount' => '25.00', 'edit_revision' => $revision,
        ])->assertSessionHasNoErrors();
        foreach ([$revision, str_repeat('a', 64), app(ConfigurationAudit::class)->revision($other), null] as $invalid) {
            $this->put(route('admin.services.update', $service), ['name' => 'Stale', 'fee_amount' => '1.00', 'edit_revision' => $invalid])
                ->assertSessionHasErrors('edit_revision');
            $this->assertSame('25.00', $service->fresh()->fee_amount);
        }
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame('Current clearance', $service->fresh()->name);
    }

    public function test_schedule_capacity_rejects_old_form_and_past_creation_is_blocked(): void
    {
        $r = $this->fixture();
        $slot = $r->schedule;
        $token = app(ConfigurationAudit::class)->revision($slot);
        $data = ['date' => '2026-11-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 12, 'edit_revision' => $token];
        $this->actingAs($this->admin())->put(route('admin.schedules.update', $slot), $data)->assertSessionHasNoErrors();
        $this->put(route('admin.schedules.update', $slot), [...$data, 'capacity' => 20])->assertSessionHasErrors('edit_revision');
        $this->assertSame(12, $slot->fresh()->capacity);
        foreach (['09:59', '10:00'] as $start) {
            $this->post(route('admin.schedules.store'), ['date' => '2026-10-27', 'start_time' => $start, 'end_time' => '11:00', 'capacity' => 5])
                ->assertSessionHasErrors('date');
        }
        $this->assertDatabaseCount('schedules', 1);
        $this->post(route('admin.schedules.store'), ['date' => '2026-10-27', 'start_time' => '10:01', 'end_time' => '11:00', 'capacity' => 5])
            ->assertSessionHasNoErrors();
    }

    public function test_resident_filters_remain_owned_paginated_and_validate_inputs(): void
    {
        $r = $this->fixture();
        $this->fixture($r->user, 'completed');
        $foreign = $this->fixture(null, 'completed');
        $this->actingAs($r->user)->get('/resident/reservations?view=history')->assertOk()
            ->assertViewHas('reservations', fn ($rows) => $rows->total() === 1);
        $this->get('/resident/reservations?view=active&status=pending')->assertOk()
            ->assertViewHas('reservations', fn ($rows) => $rows->total() === 1);
        $this->get('/resident/reservations?search=%23'.$foreign->id)->assertOk()
            ->assertViewHas('reservations', fn ($rows) => $rows->total() === 0);
        $this->getJson('/resident/reservations?view=invalid')->assertUnprocessable();
        $this->getJson('/resident/reservations?status=invalid')->assertUnprocessable();
        for ($i = 0; $i < 11; $i++) {
            $this->fixture($r->user);
        }
        $this->get('/resident/reservations?view=active')->assertOk()
            ->assertViewHas('reservations', fn ($rows) => $rows->total() === 12 && $rows->count() === 10)
            ->assertSee('view=active', false);
    }

    public function test_ready_details_link_to_owned_qr_without_consuming_it_and_profile_can_resume(): void
    {
        $r = $this->fixture(null, 'ready_for_pickup');
        $ticket = app(QrTicketIssuer::class)->issue($r);
        $this->actingAs($r->user)->get(route('resident.reservations.show', $r))->assertOk()
            ->assertSee('Your next step')->assertSee(route('resident.qr-tickets.show', $ticket), false);
        $this->assertNull($ticket->fresh()->used_at);
        $this->get('/resident/profile')->assertDontSee('Return to your request');
        $this->withSession(['reservation_wizard' => ['resident_id' => $r->user_id, 'service_id' => $r->service_id]])
            ->get('/resident/profile')->assertSee('Return to your request');
        $this->withSession(['reservation_wizard' => ['resident_id' => 999, 'service_id' => $r->service_id]])
            ->get('/resident/profile')->assertDontSee('Return to your request');
    }

    public function test_rejection_has_a_separate_escaped_public_reason_and_private_notes_stay_private(): void
    {
        $r = $this->fixture();
        $public = 'Please replace the unreadable ID. <script>alert(1)</script>';
        $this->actingAs($this->admin())->patch(route('admin.reservations.status', $r), [
            'expected_status' => 'pending', 'status' => 'rejected', 'notes' => 'PRIVATE INTERNAL NOTE',
        ])->assertSessionHasErrors('resident_message');
        $this->assertSame('pending', $r->fresh()->status->value);
        $this->patch(route('admin.reservations.status', $r), [
            'expected_status' => 'pending', 'status' => 'rejected', 'notes' => 'PRIVATE INTERNAL NOTE', 'resident_message' => $public,
        ])->assertSessionHasNoErrors();
        $this->actingAs($r->user)->get(route('resident.reservations.show', $r))->assertOk()
            ->assertSee($public)->assertDontSee($public, false)->assertDontSee('PRIVATE INTERNAL NOTE');
        $this->get(route('resident.request-status.show', $r))->assertOk()->assertSee($public)->assertDontSee('PRIVATE INTERNAL NOTE');
        $this->actingAs(User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]))
            ->get(route('resident.request-status.show', $r))->assertNotFound();
        $this->assertDatabaseCount('reservation_status_histories', 1);
    }

    public function test_resident_activation_is_audited_without_private_fields(): void
    {
        $r = $this->fixture();
        $this->actingAs($this->admin())->patch(route('admin.users.status', $r->user), ['expected_is_active' => 1, 'is_active' => 0])
            ->assertSessionHasNoErrors();
        $log = AuditLog::where('action', 'updated_resident_account')->sole();
        $this->assertSame(['is_active' => true], $log->old_values);
        $this->assertSame(['is_active' => false], $log->new_values);
        $this->assertArrayNotHasKey('email', $log->new_values);
    }

    public function test_document_master_and_configuration_changes_are_audited_without_storage_paths(): void
    {
        $r = $this->fixture();
        $admin = $this->admin();
        $this->actingAs($admin);
        $service = $r->service;
        $paths = [];
        DB::transaction(function () use ($service, $admin, &$paths) {
            app(ServiceDocumentTemplates::class)->replace($service,
                UploadedFile::fake()->createWithContent('Official.pdf', CertificatePdf::bytes()), $admin->id, $paths);
        });
        $master = $service->documentTemplate()->first();
        $builder = app(DocumentTemplateConfiguration::class);
        $builder->create($service, ['pdf_id' => $master->id, 'pdf_revision' => $master->revision(), 'is_active' => false], $admin->id);
        $template = $service->dynamicDocumentTemplate()->first();
        $builder->mutate($service, $template->version, fn ($record) => $record->fields()->create([
            'field_name' => 'resident_name', 'label' => 'Resident Name', 'source' => 'system', 'field_type' => 'text',
        ]));
        $this->assertDatabaseHas('audit_logs', ['action' => 'created_official_template']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'created_document_configuration']);
        $log = AuditLog::where('action', 'updated_document_configuration')->sole();
        $this->assertSame([], $log->old_values['fields']);
        $this->assertSame('resident_name', $log->new_values['fields'][0]['field_name']);
        $this->assertStringNotContainsString('stored_path', $log->toJson());
        $this->assertStringNotContainsString('service-document-templates/', $log->toJson());
        $this->get('/admin/services')->assertOk()->assertSee('Manual processing');
        $builder->mutate($service, $template->fresh()->version, fn ($record) => $record->fill(['is_active' => true]));
        $this->get('/admin/services')->assertOk()->assertSee('Dynamic printing ready');
    }
}
