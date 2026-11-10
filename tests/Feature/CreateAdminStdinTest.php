<?php

namespace Tests\Feature;

use App\Console\Commands\CreateAdmin;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CreateAdminStdinTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $this->assertSame('testing', app()->environment());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Notification::fake();
    }

    private function provision(string $payload, array $options = []): array
    {
        $input = new ArrayInput(array_replace([
            '--password-stdin' => true, '--name' => 'Provisioning Test Admin',
            '--email' => ' ADMIN@example.test ', '--contact' => '0917 123 4567',
        ], $options));
        $input->setInteractive(false);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $payload);
        rewind($stream);
        $input->setStream($stream);
        $output = new BufferedOutput;
        $command = app(CreateAdmin::class);
        $command->setLaravel($this->app);

        try {
            return [$command->run($input, $output), $output->fetch()];
        } finally {
            fclose($stream);
        }
    }

    public static function acceptedFraming(): array
    {
        return [[''], ["\n"], ["\r\n"]];
    }

    #[DataProvider('acceptedFraming')]
    public function test_stdin_creates_hashed_active_unverified_admin_without_prompts(string $ending): void
    {
        $password = 'Synthetic-Admin-Password123';
        [$status, $output] = $this->provision($password.$ending);

        $this->assertSame(0, $status);
        $admin = User::sole();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertNull($admin->email_verified_at);
        $this->assertSame('admin@example.test', $admin->email);
        $this->assertSame('0917 123 4567', $admin->contact_number);
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertNotSame($password, $admin->getRawOriginal('password'));
        $this->assertStringNotContainsString($password, $output);
        $this->assertStringNotContainsString('Password Confirmation', $output);
        Notification::assertSentTo($admin, VerifyEmail::class);
    }

    public function test_optional_contact_and_password_spaces_are_preserved(): void
    {
        [$status] = $this->provision("  Synthetic-Password123  \n", ['--contact' => null]);

        $this->assertSame(0, $status);
        $this->assertNull(User::sole()->contact_number);
        $this->assertTrue(Hash::check('  Synthetic-Password123  ', User::sole()->password));
    }

    public static function invalidPayloads(): array
    {
        return [
            'empty' => [''], 'empty line' => ["\n"], 'spaces only' => ["   \n"],
            'short' => ['Ab123'], 'letters only' => ['OnlyLettersHere'], 'digits only' => ['1234567890'],
            'overlong' => [str_repeat('Ab1', 25)], 'utf8 bcrypt overflow' => [str_repeat('é', 36).'A1'],
            'nul' => ["Synthetic123\0secret"], 'embedded newline' => ["Synthetic123\nsecond"],
            'extra newline' => ["Synthetic123\n\n"], 'bare carriage return' => ["Synthetic123\r"],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_password_is_rejected_without_disclosure(string $payload): void
    {
        [$status, $output] = $this->provision($payload);

        $this->assertSame(1, $status);
        $this->assertSame(0, User::count());
        if (strlen(trim($payload)) > 0) {
            $this->assertStringNotContainsString($payload, $output);
        }
        Notification::assertNothingSent();
    }

    public static function invalidOptions(): array
    {
        return [
            [['--name' => null]], [['--email' => null]], [['--name' => '']],
            [['--email' => 'invalid']], [['--contact' => 'invalid']],
            [['--password-stdin' => false]],
        ];
    }

    #[DataProvider('invalidOptions')]
    public function test_missing_or_invalid_noninteractive_options_do_not_create_account(array $options): void
    {
        [$status, $output] = $this->provision('Synthetic-Password123', $options);

        $this->assertSame(1, $status);
        $this->assertSame(0, User::count());
        $this->assertStringNotContainsString('Synthetic-Password123', $output);
        Notification::assertNothingSent();
    }

    public static function existingRoles(): array
    {
        return [[UserRole::Resident], [UserRole::Admin]];
    }

    #[DataProvider('existingRoles')]
    public function test_existing_accounts_are_never_overwritten(UserRole $role): void
    {
        $existing = User::factory()->create(['email' => 'admin@example.test', 'role' => $role, 'is_active' => false]);
        $original = $existing->fresh()->getRawOriginal();
        [$status, $output] = $this->provision('Synthetic-Password123');

        $this->assertSame(1, $status);
        $this->assertStringContainsString('already been taken', $output);
        $this->assertSame(1, User::count());
        $this->assertSame($original, $existing->fresh()->getRawOriginal());
        Notification::assertNothingSent();
    }

    public function test_closed_input_fails_without_creating_an_account(): void
    {
        $input = new ArrayInput(['--password-stdin' => true, '--name' => 'Test Admin', '--email' => 'admin@example.test']);
        $input->setInteractive(false);
        $stream = fopen('php://memory', 'w+');
        $input->setStream($stream);
        fclose($stream);
        $output = new BufferedOutput;
        $command = app(CreateAdmin::class);
        $command->setLaravel($this->app);

        $this->assertSame(1, $command->run($input, $output));
        $this->assertStringContainsString('requires piped input', $output->fetch());
        $this->assertSame(0, User::count());
    }

    public function test_password_cannot_be_supplied_as_an_option(): void
    {
        $this->assertFalse(app(CreateAdmin::class)->getDefinition()->hasOption('password'));
    }

    public function test_email_failure_keeps_created_admin_unverified(): void
    {
        Event::listen(Registered::class, static function (): void {
            throw new TransportException('Synthetic mail failure');
        });
        [$status, $output] = $this->provision('Synthetic-Password123');

        $this->assertSame(0, $status);
        $this->assertSame(UserRole::Admin, User::sole()->role);
        $this->assertNull(User::sole()->email_verified_at);
        $this->assertStringContainsString('email delivery failed', $output);
        $this->assertStringNotContainsString('Synthetic-Password123', $output);
    }

    public function test_bcrypt_boundary_is_accepted_without_truncation(): void
    {
        $password = str_repeat('Ab1', 24);
        [$status] = $this->provision($password."\r\n");

        $this->assertSame(0, $status);
        $this->assertTrue(Hash::check($password, User::sole()->password));
    }

    public function test_real_stdin_works_with_hosting_process_functions_disabled(): void
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
    'database.connections.sqlite.url' => null, 'cache.default' => 'array', 'session.driver' => 'array']);
Illuminate\Support\Facades\DB::purge('sqlite');
if (app()->environment() !== 'testing' || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== ':memory:') exit(20);
if (function_exists('proc_open') || function_exists('shell_exec')) exit(21);
Illuminate\Support\Facades\Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
Illuminate\Support\Facades\Notification::fake();
$status = $app->handleCommand(new Symfony\Component\Console\Input\ArgvInput([
    'artisan', 'smartbarangay:create-admin', '--password-stdin', '--no-interaction',
    '--name=Synthetic CLI Admin', '--email=cli-admin@example.test',
]));
if ($status !== 0) exit(22);
$user = App\Models\User::sole();
if ($user->role !== App\Enums\UserRole::Admin || !$user->is_active || $user->email_verified_at !== null
    || password_get_info($user->password)['algoName'] !== 'bcrypt') exit(23);
PHP;

        $process = new Process([PHP_BINARY, '-d', 'disable_functions=proc_open,shell_exec,exec,system,passthru', '-r', $script], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'BCRYPT_ROUNDS' => '4',
        ]);
        $password = 'Synthetic-Only-Password123';
        $process->setInput($password."\n");
        $process->setTimeout(60);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString($password, $process->getOutput().$process->getErrorOutput());
        $this->assertSame(0, User::count());
    }
}
