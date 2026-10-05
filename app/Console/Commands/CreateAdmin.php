<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use App\Rules\PhilippineContactNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Exception\RuntimeException;

class CreateAdmin extends Command
{
    protected $signature = 'smartbarangay:create-admin';

    protected $description = 'Interactively create an authorized Barangay Calayo administrator';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively to enter the administrator details securely.');

            return self::FAILURE;
        }

        $name = $this->ask('Admin Full Name');
        $email = $this->ask('Admin Email');
        $contact = $this->ask('Contact Number (optional)');

        try {
            // Disable visible-input fallback when the terminal cannot hide passwords.
            $password = $this->secret('Password', false);
            $confirmation = $this->secret('Password Confirmation', false);
        } catch (RuntimeException $exception) {
            $this->error('This terminal cannot hide password input. Use an interactive terminal with hidden input support.');

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
}
