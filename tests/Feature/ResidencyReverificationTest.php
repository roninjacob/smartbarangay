<?php

namespace Tests\Feature;

use App\Enums\ResidencyStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ResidencyProfileChanges;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResidencyReverificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function resident(): User
    {
        return User::factory()->residencyApproved()->create(['role' => UserRole::Resident,
            'is_active' => true, 'name' => 'Synthetic Resident', 'address' => 'Calayo', 'residency_revision' => 4]);
    }

    private function demographics(array $changes = []): array
    {
        return array_replace(['birthdate' => '2000-01-01', 'civil_status' => null, 'occupation' => null,
            'address_street' => 'Sample Street', 'address_zone_purok' => 'Purok 1',
            'emergency_contact_name' => null, 'emergency_contact_number' => null], $changes);
    }

    public static function materialFields(): array
    {
        return [['name', 'Changed Resident'], ['address', 'Changed address'], ['birthdate', '2001-02-03'],
            ['address_street', 'New Street'], ['address_zone_purok', 'Purok 2'], ['address_street', null]];
    }

    #[DataProvider('materialFields')]
    public function test_material_changes_require_review_and_keep_audit(string $field, ?string $value): void
    {
        $resident = $this->resident();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $resident->forceFill(['residency_reviewed_by' => $admin->id, 'residency_reviewed_at' => now()])->save();
        $resident->residencyReviews()->make()->forceFill(['reviewed_by' => $admin->id, 'from_status' => 'pending',
            'to_status' => 'approved', 'resident_message' => 'Previous approval', 'reviewed_at' => now()])->save();
        $profile = $resident->profile()->make()->forceFill($this->demographics());
        $profile->save();
        $before = in_array($field, ['name', 'address']) ? $resident->getAttribute($field) : $profile->getAttribute($field);
        $this->actingAs($resident);
        if (in_array($field, ['name', 'address'])) {
            $this->put(route('resident.profile.update'), array_replace(['name' => $resident->name,
                'address' => $resident->address], [$field => $value]))->assertSessionHasNoErrors();
        } else {
            $this->put(route('resident.profile.demographics.update'), $this->demographics([$field => $value]))->assertSessionHasNoErrors();
        }
        $resident->refresh();
        $this->assertSame(ResidencyStatus::Pending, $resident->residency_status);
        $this->assertSame(5, $resident->residency_revision);
        $this->assertNull($resident->residency_reviewed_by);
        $this->assertNull($resident->residency_reviewed_at);
        $this->assertSame(1, $resident->residencyReviews()->count());
        $audit = AuditLog::where('action', ResidencyProfileChanges::ACTION)->sole();
        $this->assertSame($resident->id, $audit->user_id);
        $this->assertSame([$field => $value], $audit->new_values['fields']);
        $this->assertSame('approved', $audit->old_values['residency_status']);
        $this->assertSame('pending', $audit->new_values['residency_status']);
        $this->assertEquals($field === 'birthdate' ? $before->toDateString() : $before, $audit->old_values['fields'][$field]);
        $this->get(route('resident.reservations.create'))->assertForbidden();
        $this->get(route('resident.home'))->assertOk()->assertDontSee('Previous approval');
        $this->get(route('resident.reservations.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.show', $resident))->assertOk()
            ->assertSee('Identity and address change history')->assertSee('Previous approval');
    }

    public function test_nonmaterial_and_identical_updates_preserve_approval(): void
    {
        $resident = $this->resident();
        $resident->profile()->make()->forceFill($this->demographics())->save();
        $this->actingAs($resident)->put(route('resident.profile.update'), ['name' => $resident->name,
            'address' => $resident->address, 'contact_number' => '09171234567'])->assertSessionHasNoErrors();
        $this->put(route('resident.profile.demographics.update'), $this->demographics(['occupation' => 'Teacher',
            'civil_status' => 'Married', 'emergency_contact_name' => 'Sample Contact',
            'emergency_contact_number' => '09181234567']))->assertSessionHasNoErrors();
        $this->assertSame(ResidencyStatus::Approved, $resident->fresh()->residency_status);
        $this->assertSame(4, $resident->fresh()->residency_revision);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_first_demographic_entry_requires_review(): void
    {
        $resident = $this->resident();
        $this->actingAs($resident)->put(route('resident.profile.demographics.update'), $this->demographics())->assertSessionHasNoErrors();
        $this->assertSame(ResidencyStatus::Pending, $resident->fresh()->residency_status);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_material_edit_invalidates_open_admin_review_and_fresh_review_succeeds(): void
    {
        $resident = $this->resident();
        $resident->forceFill(['residency_status' => 'pending'])->save();
        $this->actingAs($resident)->put(route('resident.profile.update'), ['name' => 'Changed Resident',
            'address' => $resident->address])->assertSessionHasNoErrors();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->actingAs($admin)->patch(route('admin.users.residency', $resident), ['residency_status' => 'approved',
            'expected_residency_revision' => 4])->assertSessionHasErrors('residency_status');
        $this->assertSame(ResidencyStatus::Pending, $resident->fresh()->residency_status);
        $this->patch(route('admin.users.residency', $resident), ['residency_status' => 'approved',
            'expected_residency_revision' => 5])->assertSessionHasNoErrors();
        $this->assertTrue($resident->fresh()->canSubmitReservations());
    }

    public function test_audit_failure_rolls_back_profile_and_residency_together(): void
    {
        $resident = $this->resident();
        AuditLog::creating(function () {
            throw new \RuntimeException('Synthetic audit failure');
        });
        try {
            $this->actingAs($resident)->put(route('resident.profile.demographics.update'), $this->demographics())->assertStatus(500);
            $this->assertSame(ResidencyStatus::Approved, $resident->fresh()->residency_status);
            $this->assertSame(4, $resident->fresh()->residency_revision);
            $this->assertDatabaseCount('resident_profiles', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }
    }

    public function test_alternate_endpoints_cannot_write_material_or_approval_fields(): void
    {
        $resident = $this->resident();
        $this->actingAs($resident)->put(route('resident.profile.demographics.update'), $this->demographics([
            'name' => 'Injected', 'residency_status' => 'approved']))->assertSessionHasErrors('profile');
        $this->put(route('resident.profile.update'), ['name' => 'Injected', 'residency_status' => 'approved'])
            ->assertSessionHasErrors('residency_status');
        $this->assertSame('Synthetic Resident', $resident->fresh()->name);
        $this->assertSame(4, $resident->fresh()->residency_revision);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_profile_changes_do_not_create_residency_audits(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->actingAs($admin)->put(route('admin.profile.update'), ['name' => 'Changed Admin',
            'address' => 'Changed address'])->assertSessionHasNoErrors();
        $this->assertSame('Changed Admin', $admin->fresh()->name);
        $this->assertSame(0, $admin->fresh()->residency_revision);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_material_audit_history_cannot_be_changed_or_deleted(): void
    {
        $resident = $this->resident();
        $this->actingAs($resident)->put(route('resident.profile.update'), ['name' => 'Changed Resident',
            'address' => $resident->address])->assertSessionHasNoErrors();
        $audit = AuditLog::sole();
        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $audit->forceFill(['new_values' => []])->save();
                } else {
                    $audit->delete();
                }
                $this->fail('Immutable audit operation was accepted.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('Audit records cannot be', $exception->getMessage());
            }
        }
        $this->assertSame(['name' => 'Changed Resident'], $audit->fresh()->new_values['fields']);
        $this->assertDatabaseCount('audit_logs', 1);
    }
}
