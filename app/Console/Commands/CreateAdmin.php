<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Rules\PhilippineContactNumber;
use Illuminate\Auth\Events\Registered;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class CreateAdmin extends Command
{
    protected $signature = 'smartbarangay:create-admin
        {--password-stdin : Read one password from piped standard input}
        {--name= : Administrator full name for stdin mode}
        {--email= : Administrator email for stdin mode}
        {--contact= : Optional contact number for stdin mode}';

    protected $description = 'Create an authorized Barangay Calayo administrator securely';

    public function handle(): int
    {
        $stdinMode = (bool) $this->option('password-stdin');
        if (! $stdinMode && ! $this->input->isInteractive()) {
            $this->error('Run this command interactively to enter the administrator details securely.');

            return self::FAILURE;
        }

        if ($stdinMode && (! is_string($this->option('name')) || ! is_string($this->option('email')))) {
            $this->error('--password-stdin requires --name and --email.');

            return self::FAILURE;
        }

        if (! $stdinMode && ($this->option('name') !== null || $this->option('email') !== null || $this->option('contact') !== null)) {
            $this->error('Use --password-stdin with the noninteractive administrator options.');

            return self::FAILURE;
        }

        $name = $stdinMode ? $this->option('name') : $this->ask('Admin Full Name');
        $email = $stdinMode ? $this->option('email') : $this->ask('Admin Email');
        $contact = $stdinMode ? $this->option('contact') : $this->ask('Contact Number (optional)');

        try {
            if ($stdinMode) {
                $password = $this->passwordFromStdin();
                $confirmation = $password;
            } else {
                // Disable visible-input fallback when the terminal cannot hide passwords.
                $password = $this->secret('Password', false);
                $confirmation = $this->secret('Password Confirmation', false);
            }
        } catch (RuntimeException $exception) {
            $this->error($stdinMode ? $exception->getMessage()
                : 'This terminal cannot hide password input. Use an interactive terminal with hidden input support.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => is_string($email) ? strtolower(trim($email)) : $email,
            'contact_number' => $contact,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'contact_number' => ['nullable', 'string', 'max:30', new PhilippineContactNumber],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();
        $user = new User([
            'name' => trim($data['name']),
            'email' => $data['email'],
            'contact_number' => $data['contact_number'] ?: null,
            'password' => $data['password'],
        ]);
        $user->role = UserRole::Admin;
        $user->is_active = true;
        $user->save();

        $this->info('SmartBarangay administrator created successfully.');
        try {
            event(new Registered($user));
            $this->info('A verification link has been sent. The administrator must verify their email before accessing the Admin area.');
        } catch (TransportExceptionInterface $exception) {
            report($exception);
            $this->warn('The account was created, but email delivery failed. Sign in and resend the verification email after configuring mail.');
        }

        return self::SUCCESS;
    }

    private function passwordFromStdin(): string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $stream ??= STDIN;

        if (! is_resource($stream) || ! function_exists('stream_isatty') || stream_isatty($stream)) {
            throw new RuntimeException('--password-stdin requires piped input. Password entry through a terminal is refused.');
        }

        // 72 bcrypt bytes, optional CRLF, and one extra byte to detect overflow.
        $password = @stream_get_contents($stream, 75);
        if ($password === false) {
            throw new RuntimeException('Unable to read password from standard input.');
        }

        if (str_ends_with($password, "\r\n")) {
            $password = substr($password, 0, -2);
        } elseif (str_ends_with($password, "\n")) {
            $password = substr($password, 0, -1);
        }

        if (strlen($password) > 72 || strpbrk($password, "\0\r\n") !== false) {
            throw new RuntimeException('Provide one password of at most 72 UTF-8 bytes, without NUL or embedded line breaks.');
        }

        return $password;
    }
}
