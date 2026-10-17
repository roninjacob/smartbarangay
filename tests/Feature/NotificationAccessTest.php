<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
    }

    private function user(string $role = 'resident', array $attributes = []): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, ...$attributes]);
    }

    private function notification(User $user, array $attributes = [])
    {
        return $user->notifications()->create(['id' => (string) Str::uuid(), 'event_key' => 'fixture:'.Str::uuid(),
            'type' => $user->role === UserRole::Admin ? 'admin.new_reservation' : 'resident.request_submitted',
            'data' => ['title' => 'Request Submitted', 'message' => 'Safe historical message', 'reservation_id' => 999999, 'category' => 'confirmation'],
            ...$attributes]);
    }

    public static function endpoints(): array
    {
        $cases = [];
        foreach (['resident', 'admin'] as $role) {
            foreach (['index', 'open', 'read', 'read-all'] as $action) {
                $cases[] = [$role, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('endpoints')]
    public function test_access_requires_correct_role_active_verified_and_csrf(string $role, string $action): void
    {
        $user = $this->user($role);
        $n = $this->notification($user);
        $url = route($role.'.notifications.'.$action, in_array($action, ['open', 'read']) ? $n->id : []);
        $method = $action === 'index' ? 'GET' : 'POST';
        $this->call($method, $url)->assertRedirect(route('login'));
        $this->actingAs($this->user($role === 'admin' ? 'resident' : 'admin'))->call($method, $url)->assertForbidden();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->call($method, $url)->assertRedirect(route('verification.notice'));
        $user->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($user)->call($method, $url)->assertRedirect(route('login'));
        $user->forceFill(['is_active' => true])->save();
        $this->actingAs($user);
        if ($action !== 'index') {
            $this->app['env'] = 'local';
            $this->post($url)->assertStatus(419);
        } else {
            $this->get($url)->assertOk();
        }
        $this->assertNull($n->fresh()->read_at);
    }

    public static function ownerPairs(): array
    {
        return [['resident', 'resident'], ['resident', 'admin'], ['admin', 'admin'], ['admin', 'resident']];
    }

    #[DataProvider('ownerPairs')]
    public function test_foreign_and_guessed_notification_ids_never_bypass_ownership(string $actorRole, string $ownerRole): void
    {
        $actor = $this->user($actorRole);
        $owner = $this->user($ownerRole);
        $n = $this->notification($owner);
        $this->actingAs($actor);
        foreach (['open', 'read'] as $action) {
            $this->post(route($actorRole.'.notifications.'.$action, $n->id))->assertNotFound();
            $this->post(route($actorRole.'.notifications.'.$action, (string) Str::uuid()))->assertNotFound();
        }
        $this->post(route($actorRole.'.notifications.read-all'))->assertRedirect();
        $this->assertNull($n->fresh()->read_at);
        $this->assertDatabaseCount('notifications', 1);
    }

    public static function roles(): array
    {
        return [['resident'], ['admin']];
    }

    #[DataProvider('roles')]
    public function test_history_bell_filters_pagination_are_readonly_bounded_and_ordered(string $role): void
    {
        $user = $this->user($role);
        for ($i = 0; $i < 32; $i++) {
            $this->notification($user, ['created_at' => now()->subMinutes($i), 'read_at' => $i === 0 ? now() : null]);
        }
        $this->actingAs($user);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->get(route($role.'.notifications.index'))->assertOk()->assertSee('Notifications, 31 unread');
        $page = $response->viewData('notifications');
        $this->assertSame(32, $page->total());
        $this->assertCount(15, $page->items());
        $this->assertSame($user->notifications()->reorder()->orderByDesc('created_at')->orderByDesc('id')->first()->id, $page->items()[0]->id);
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"notifications"'));
        $this->assertTrue($queries->contains(fn ($q) => str_contains($q['query'], 'limit 5')));
        $this->assertTrue($queries->contains(fn ($q) => str_contains($q['query'], 'limit 15')));
        $this->assertTrue($queries->contains(fn ($q) => str_contains($q['query'], 'count(*)')));
        DB::disableQueryLog();
        $this->get(route($role.'.notifications.index', ['filter' => 'unread']))->assertOk()->assertSee('filter=unread', false);
        $this->get(route($role.'.notifications.index', ['filter' => 'unread', 'page' => 2]))->assertOk();
        $this->get(route($role.'.home'))->assertOk();
        $this->assertSame(31, $user->unreadNotifications()->count());
        $this->assertDatabaseCount('notifications', 32);
    }

    #[DataProvider('roles')]
    public function test_open_read_and_read_all_only_change_own_read_state(string $role): void
    {
        $user = $this->user($role);
        $other = $this->user($role);
        $foreign = $this->notification($other);
        $owner = $role === 'resident' ? $user : $this->user();
        $reservation = Reservation::create(['user_id' => $owner->id,
            'service_id' => Service::create(['name' => 'Clearance'])->id,
            'schedule_id' => Schedule::create(['date' => '2026-12-25', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 5])->id,
            'status' => 'pending']);
        $n = $this->notification($user, ['data' => ['title' => 'Request Submitted', 'message' => 'Safe', 'reservation_id' => $reservation->id]]);
        $second = $this->notification($user);
        $before = $reservation->fresh()->getRawOriginal();
        $this->actingAs($user)->post(route($role.'.notifications.open', $n->id), ['reservation_id' => 9999, 'url' => 'https://evil.example'])->assertRedirect(route($role.'.reservations.show', $reservation));
        $this->assertNotNull($n->fresh()->read_at);
        $this->assertNull($second->fresh()->read_at);
        $this->post(route($role.'.notifications.read', $second->id))->assertRedirect();
        $this->assertNotNull($second->fresh()->read_at);
        $this->post(route($role.'.notifications.read-all'))->assertRedirect();
        $this->post(route($role.'.notifications.read-all'))->assertRedirect();
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
        $this->assertDatabaseCount('notifications', 3);
        foreach (['checkin_logs', 'reservation_status_histories', 'reservation_payments', 'qr_tickets'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->get(route($role.'.notifications.index', ['filter' => 'unread']))->assertSee('You&#039;re all caught up.', false);
    }

    public function test_tampered_target_foreign_reservation_unknown_type_and_missing_target_fail_safely(): void
    {
        $user = $this->user();
        $other = $this->user();
        $foreign = Reservation::create(['user_id' => $other->id, 'service_id' => Service::create(['name' => 'Private'])->id,
            'schedule_id' => Schedule::create(['date' => '2026-12-25', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 5])->id, 'status' => 'pending']);
        foreach ([['type' => 'resident.request_submitted', 'data' => ['reservation_id' => $foreign->id, 'url' => 'https://evil.example']],
            ['type' => 'admin.new_reservation'], ['data' => ['reservation_id' => 'https://evil.example']], ['data' => []]] as $attributes) {
            $n = $this->notification($user, $attributes);
            $this->actingAs($user)->post(route('resident.notifications.open', $n->id))->assertRedirect(route('resident.notifications.index'));
            $this->assertNotNull($n->fresh()->read_at);
        }
        $this->get(route('resident.reservations.show', $foreign))->assertNotFound();
    }

    public function test_empty_states_escaping_and_badge_cap(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get(route('resident.notifications.index'))->assertSee('No notifications yet.');
        for ($i = 0; $i < 100; $i++) {
            $this->notification($user, ['data' => ['title' => '<script>bad</script>', 'message' => '<img src=x onerror=bad()>']]);
        }
        $this->get(route('resident.notifications.index'))->assertSee('99+')->assertSee('Notifications, 100 unread')
            ->assertSee('&lt;script&gt;bad&lt;/script&gt;', false)->assertDontSee('<script>bad</script>', false)->assertDontSee('<img src=x onerror=bad()>', false);
        $this->post(route('resident.notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertDatabaseCount('notifications', 100);
    }
}
