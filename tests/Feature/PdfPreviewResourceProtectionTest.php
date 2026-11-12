<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CanonicalDocumentPreview;
use App\Services\FinalCertificatePdf;
use App\Services\GeneratedReservationDocuments;
use App\Services\ReservationDocumentInformation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class PdfPreviewResourceProtectionTest extends TestCase
{
    private int $renders = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'cache.default' => 'array', 'session.driver' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Storage::fake('local');
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-12 09:00:00', 'Asia/Manila'));
        $this->mock(FinalCertificatePdf::class, function ($mock) {
            $mock->shouldReceive('render')->andReturnUsing(function ($path, $placements) {
                $this->renders++;
                $this->assertSame(0, DB::transactionLevel());

                return (new FinalCertificatePdf)->render($path, $placements);
            });
        });
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function fixture(string $role = 'admin'): array
    {
        $resident = User::factory()->residencyApproved()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $actor = $role === 'resident' ? $resident : User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $service = Service::create(['name' => 'Synthetic Certificate', 'fee_amount' => '0.00']);
        $bytes = CertificatePdf::bytes();
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('a', 40).'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $master = $service->documentTemplate()->create(['original_filename' => 'Official.pdf', 'stored_path' => $path,
            'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'uploaded_at' => now()]);
        $template = $service->dynamicDocumentTemplate()->make();
        $template->forceFill(['service_document_template_id' => $master->id, 'source_revision' => $master->revision(),
            'source_content_hash' => hash('sha256', $bytes), 'source_filename' => 'Official.pdf', 'version' => 1, 'is_active' => true])->save();
        foreach (['resident_name' => 'text', 'date_issued' => 'date'] as $name => $type) {
            $field = $template->fields()->create(['field_name' => $name, 'source' => 'system', 'label' => $name,
                'field_type' => $type, 'is_required' => false]);
            $field->positions()->create(['page_number' => 1, 'x_position' => 40, 'y_position' => $type === 'text' ? 40 : 90,
                'width' => 400, 'height' => 30, 'font_size' => 12, 'alignment' => 'left', 'text_behavior' => 'wrap']);
        }
        $schedule = Schedule::first() ?? Schedule::create(['date' => now()->addDays(2)->toDateString(),
            'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10]);
        $reservation = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => 'approved']);
        $information = app(ReservationDocumentInformation::class);
        $information->store($reservation, $template->load('fields'), [], $information->profile($resident));
        $this->actingAs($actor);

        return [$actor, $reservation, $service, $template];
    }

    private function page(string $role, Reservation $reservation): string
    {
        return route($role.'.reservations.document-preview.show', $reservation);
    }

    private function pdf(string $role, Reservation $reservation, array $artifact): string
    {
        return route($role.'.reservations.document-preview.pdf', [$reservation, 'preview' => $artifact['token']]);
    }

    private function changeName(Reservation $reservation, string $name): void
    {
        $snapshot = $reservation->fresh()->resident_snapshot;
        $snapshot['resident_name'] = $name;
        $reservation->forceFill(['resident_snapshot' => $snapshot])->save();
    }

    public static function roles(): array
    {
        return [['admin'], ['resident']];
    }

    #[DataProvider('roles')]
    public function test_identical_pages_reuse_unreviewed_artifact_and_head_page_never_generates(string $role): void
    {
        [, $reservation] = $this->fixture($role);
        $page = $this->page($role, $reservation);
        $this->head($page)->assertOk();
        $this->assertSame(0, $this->renders);
        $first = $this->get($page)->assertOk()->viewData('artifact');
        $second = $this->get($page.'?fingerprint=untrusted&issue_date=2999-01-01')->assertOk()->viewData('artifact');
        $this->assertSame($first, $second);
        $this->assertFalse(Cache::get('document-preview:'.$first['token'])['viewed']);
        $this->assertCount(1, Storage::disk('local')->allFiles('document-previews'));
        $this->assertSame(1, $this->renders);
    }

    #[DataProvider('roles')]
    public function test_page_limit_returns_429_but_protected_pdf_view_remains_available(string $role): void
    {
        [, $reservation] = $this->fixture($role);
        $artifact = $this->get($this->page($role, $reservation))->assertOk()->viewData('artifact');
        for ($request = 1; $request < CanonicalDocumentPreview::PAGE_LIMIT; $request++) {
            $this->get($this->page($role, $reservation))->assertOk();
        }
        $this->get($this->page($role, $reservation))->assertStatus(429)->assertHeader('Retry-After')->assertSee('Try again');
        $this->get($this->pdf($role, $reservation, $artifact))->assertOk();
        $this->assertSame(1, $this->renders);
        $this->travel(61)->seconds();
        $this->get($this->page($role, $reservation))->assertOk();
        $this->assertSame(1, $this->renders);
    }

    #[DataProvider('roles')]
    public function test_generation_minute_budget_and_one_outstanding_artifact_per_pair(string $role): void
    {
        [, $reservation] = $this->fixture($role);
        for ($index = 0; $index < CanonicalDocumentPreview::GENERATION_MINUTE_LIMIT; $index++) {
            $this->changeName($reservation, 'Synthetic '.$index);
            $artifact = $this->get($this->page($role, $reservation))->assertOk()->viewData('artifact');
        }
        $this->changeName($reservation, 'One more generation');
        $this->get($this->page($role, $reservation))->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(CanonicalDocumentPreview::GENERATION_MINUTE_LIMIT, $this->renders);
        $this->assertSame([$artifact['path']], Storage::disk('local')->allFiles('document-previews'));
    }

    public function test_hour_budget_is_shared_across_reservations_and_reuse_does_not_spend_it(): void
    {
        [$actor, $reservation] = $this->fixture();
        // A small real PDF is enough for budget tests; normal rendering is tested separately.
        $this->mock(FinalCertificatePdf::class, fn ($mock) => $mock->shouldReceive('render')->andReturn(CertificatePdf::bytes()));
        for ($index = 0; $index < CanonicalDocumentPreview::GENERATION_HOUR_LIMIT; $index++) {
            if ($index > 0 && $index % CanonicalDocumentPreview::GENERATION_MINUTE_LIMIT === 0) {
                $this->travel(61)->seconds();
            }
            $this->changeName($reservation, 'Synthetic '.$index);
            $artifact = $this->get($this->page('admin', $reservation))->assertOk()->viewData('artifact');
        }
        $this->travel(61)->seconds();
        $this->get($this->page('admin', $reservation))->assertOk()->assertViewHas('artifact', $artifact);
        [, $other] = $this->fixture();
        $this->actingAs($actor)->get($this->page('admin', $other))->assertStatus(429)->assertHeader('Retry-After');
    }

    public static function invalidArtifacts(): array
    {
        return [['expired'], ['missing'], ['corrupt'], ['missing_token'], ['fingerprint'], ['issue_date'], ['date_values']];
    }

    #[DataProvider('invalidArtifacts')]
    public function test_invalid_artifacts_are_replaced_without_reusing_review_evidence(string $failure): void
    {
        [$actor, $reservation] = $this->fixture();
        $preview = app(CanonicalDocumentPreview::class);
        $first = $preview->create($reservation, $actor);
        $this->get($this->pdf('admin', $reservation, $first))->assertOk();
        if ($failure === 'expired') {
            $this->travel(31)->minutes();
        } elseif ($failure === 'missing') {
            Storage::disk('local')->delete($first['path']);
        } elseif ($failure === 'corrupt') {
            Storage::disk('local')->put($first['path'], str_repeat('x', $first['size']));
        } elseif ($failure === 'missing_token') {
            Cache::forget('document-preview:'.$first['token']);
        } else {
            $artifact = Cache::get('document-preview:'.$first['token']);
            if ($failure === 'issue_date') {
                $artifact['issue_date'] = '2026-11-13';
            } elseif ($failure === 'date_values') {
                $artifact['date_values']['document_year'] = '2999';
            } else {
                $artifact['fingerprint'] = str_repeat('0', 64);
            }
            Cache::put('document-preview:'.$first['token'], $artifact, 1800);
        }
        $second = $preview->create($reservation->fresh(), $actor);
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertFalse($second['viewed']);
        $this->assertFalse(Storage::disk('local')->exists($first['path']));
        $this->assertSame(2, $this->renders);
    }

    public static function changes(): array
    {
        return [['resident'], ['template'], ['placement'], ['source']];
    }

    #[DataProvider('changes')]
    public function test_saved_document_changes_invalidate_reuse(string $change): void
    {
        [$actor, $reservation] = $this->fixture();
        $preview = app(CanonicalDocumentPreview::class);
        $first = $preview->create($reservation, $actor);
        if ($change === 'resident') {
            $this->changeName($reservation, 'Changed snapshot');
        } elseif ($change === 'placement') {
            $answer = $reservation->documentAnswers()->firstOrFail();
            $field = $answer->field_snapshot;
            $field['positions'][0]['x_position'] += 5;
            $answer->forceFill(['field_snapshot' => $field])->save();
        } else {
            $snapshot = $reservation->document_template_snapshot;
            if ($change === 'template') {
                $snapshot['version']++;
            } else {
                $bytes = CertificatePdf::bytes('Changed preserved source');
                Storage::disk('local')->put($snapshot['source']['path'], $bytes);
                $snapshot['source']['size'] = strlen($bytes);
                $snapshot['source']['hash'] = hash('sha256', $bytes);
            }
            $reservation->forceFill(['document_template_snapshot' => $snapshot])->save();
        }
        $second = $preview->create($reservation->fresh(), $actor);
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertFalse($second['viewed']);
        $this->assertSame(2, $this->renders);
    }

    public function test_live_master_edits_preserve_reuse_of_unchanged_historical_snapshot(): void
    {
        [$actor, $reservation, $service, $template] = $this->fixture();
        $first = app(CanonicalDocumentPreview::class)->create($reservation, $actor);
        $template->fields()->first()->positions()->update(['x_position' => 200]);
        $template->increment('version');
        Storage::disk('local')->put($service->documentTemplate->stored_path, CertificatePdf::bytes('Changed live master'));
        $this->assertSame($first, app(CanonicalDocumentPreview::class)->create($reservation->fresh(), $actor));
        $this->assertSame(1, $this->renders);
    }

    public function test_owner_and_reservation_boundaries_survive_a_poisoned_reuse_locator(): void
    {
        [$actor, $reservation] = $this->fixture();
        [$other, $otherReservation] = $this->fixture();
        $preview = app(CanonicalDocumentPreview::class);
        $foreign = $preview->create($otherReservation, $other);
        Cache::put('document-preview-current:'.$actor->id.':'.$reservation->id, $foreign, 86400);
        $this->actingAs($actor);
        $own = $preview->create($reservation, $actor);
        $this->assertNotSame($foreign['token'], $own['token']);
        $this->assertTrue(Storage::disk('local')->exists($foreign['path']));
        $this->get($this->pdf('admin', $reservation, $foreign))->assertStatus(410);
        $this->get($this->pdf('admin', $otherReservation, $own))->assertStatus(410);
        $this->actingAs($reservation->user)->get($this->page('resident', $otherReservation))->assertNotFound();
        $this->get($this->page('admin', $reservation))->assertForbidden();
        auth()->logout();
        $this->get($this->page('admin', $reservation))->assertRedirect(route('login'));
    }

    public function test_reuse_preserves_get_review_gate_and_exact_finalized_bytes(): void
    {
        [$actor, $reservation] = $this->fixture();
        $page = $this->page('admin', $reservation);
        $artifact = $this->get($page)->assertOk()->viewData('artifact');
        $this->get($page)->assertOk()->assertViewHas('artifact', $artifact);
        $input = ['preview_token' => $artifact['token']];
        $finalize = route('admin.reservations.generated.store', $reservation);
        $this->post($finalize, $input)->assertSessionHasErrors('document');
        $this->head($this->pdf('admin', $reservation, $artifact))->assertOk();
        $this->post($finalize, $input)->assertSessionHasErrors('document');
        $bytes = $this->get($this->pdf('admin', $reservation, $artifact))->assertOk()->getContent();
        $reused = $this->get($page)->assertOk()->viewData('artifact');
        $this->assertTrue($reused['viewed']);
        $this->assertSame($artifact['expires'], $reused['expires']);
        $this->post($finalize, $input)->assertSessionHasNoErrors();
        $this->assertSame($bytes, app(GeneratedReservationDocuments::class)->bytes($reservation->fresh()));
        $this->get($page)->assertRedirect(route('admin.reservations.generated.show', $reservation));
        $this->assertSame(1, $this->renders);
        $this->assertDatabaseCount('reservation_documents', 1);
    }

    public function test_midnight_changes_issue_date_and_requires_fresh_review(): void
    {
        [$actor, $reservation] = $this->fixture();
        $this->travelTo(Carbon::parse('2026-11-12 23:59:00', 'Asia/Manila'));
        $preview = app(CanonicalDocumentPreview::class);
        $old = $preview->create($reservation, $actor);
        $this->get($this->pdf('admin', $reservation, $old))->assertOk();
        $this->travel(2)->minutes();
        $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $old['token']])->assertSessionHasErrors('document');
        $fresh = $preview->create($reservation->fresh(), $actor);
        $this->assertSame('2026-11-13', $fresh['issue_date']);
        $this->assertNotSame($old['token'], $fresh['token']);
        $this->assertFalse($fresh['viewed']);
        $this->get($this->pdf('admin', $reservation, $fresh))->assertOk();
        $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $fresh['token']])->assertSessionHasNoErrors();
    }

    public function test_overlapping_creation_is_rejected_and_retry_reuses_the_published_artifact(): void
    {
        [$actor, $reservation] = $this->fixture();
        $this->mock(FinalCertificatePdf::class, function ($mock) use ($actor, $reservation) {
            $mock->shouldReceive('render')->once()->andReturnUsing(function () use ($actor, $reservation) {
                $this->assertSame(0, DB::transactionLevel());
                try {
                    app(CanonicalDocumentPreview::class)->create($reservation, $actor);
                    $this->fail('Overlapping generation should be rejected.');
                } catch (TooManyRequestsHttpException $exception) {
                    $this->assertSame(429, $exception->getStatusCode());
                }

                return CertificatePdf::bytes();
            });
        });
        $preview = app(CanonicalDocumentPreview::class);
        $first = $preview->create($reservation, $actor);
        $this->assertSame($first, $preview->create($reservation->fresh(), $actor));
        $this->assertCount(1, Storage::disk('local')->allFiles('document-previews'));
    }

    public function test_state_changes_during_render_cannot_publish_stale_bytes(): void
    {
        [, $reservation] = $this->fixture();
        $this->mock(FinalCertificatePdf::class, function ($mock) use ($reservation) {
            $mock->shouldReceive('render')->once()->andReturnUsing(function () use ($reservation) {
                $this->changeName($reservation, 'Changed during render');

                return CertificatePdf::bytes();
            });
        });
        $this->get($this->page('admin', $reservation))->assertOk()->assertViewHas('artifact', null)->assertSee('expired or changed');
        $this->assertSame([], Storage::disk('local')->allFiles('document-previews'));
    }

    public function test_expired_creation_lock_cannot_publish_and_does_not_stick(): void
    {
        [, $reservation] = $this->fixture();
        $this->mock(FinalCertificatePdf::class, function ($mock) {
            $mock->shouldReceive('render')->once()->andReturnUsing(function () {
                $this->travel(121)->seconds();

                return CertificatePdf::bytes();
            });
        });
        $this->get($this->page('admin', $reservation))->assertStatus(429)->assertSee('took too long');
        $this->assertSame([], Storage::disk('local')->allFiles('document-previews'));
    }

    public function test_deactivation_during_render_blocks_publication(): void
    {
        [$actor, $reservation] = $this->fixture();
        $this->mock(FinalCertificatePdf::class, function ($mock) use ($actor) {
            $mock->shouldReceive('render')->once()->andReturnUsing(function () use ($actor) {
                User::whereKey($actor->id)->update(['is_active' => false]);

                return CertificatePdf::bytes();
            });
        });
        $this->get($this->page('admin', $reservation))->assertForbidden();
        $this->assertSame([], Storage::disk('local')->allFiles('document-previews'));
    }

    public function test_midnight_during_render_blocks_publication(): void
    {
        [, $reservation] = $this->fixture();
        $this->travelTo(Carbon::parse('2026-11-12 23:59:59', 'Asia/Manila'));
        $this->mock(FinalCertificatePdf::class, function ($mock) {
            $mock->shouldReceive('render')->once()->andReturnUsing(function () {
                $this->travel(2)->seconds();

                return CertificatePdf::bytes();
            });
        });
        $this->get($this->page('admin', $reservation))->assertOk()->assertViewHas('artifact', null)->assertSee('expired or changed');
        $this->assertSame([], Storage::disk('local')->allFiles('document-previews'));
    }

    public function test_cleanup_preserves_live_artifacts_sources_and_finalized_file_paths(): void
    {
        [$actor, $reservation] = $this->fixture();
        $old = app(CanonicalDocumentPreview::class)->create($reservation, $actor);
        $this->travel(25)->hours();
        $disk = Storage::disk('local');
        touch($disk->path($old['path']), now()->subHours(25)->timestamp);
        $active = app(CanonicalDocumentPreview::class)->create($reservation->fresh(), $actor);
        $final = 'reservation-documents/'.$reservation->id.'/'.str_repeat('b', 40).'.pdf';
        $disk->put($final, CertificatePdf::bytes());
        $this->assertSame(1, app(CanonicalDocumentPreview::class)->cleanup());
        $this->assertSame(0, app(CanonicalDocumentPreview::class)->cleanup());
        $this->assertTrue($disk->exists($active['path']));
        $this->assertTrue($disk->exists($reservation->document_template_snapshot['source']['path']));
        $this->assertTrue($disk->exists($final));
    }
}
