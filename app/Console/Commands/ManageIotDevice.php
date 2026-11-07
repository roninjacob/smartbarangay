<?php

namespace App\Console\Commands;

use App\Services\IotDeviceProvisioning;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ManageIotDevice extends Command
{
    protected $signature = 'smartbarangay:iot-device {action : provision, rotate or revoke}
        {device? : Lowercase UUID v4; generated for provisioning when omitted}
        {--name= : Non-secret device label, at most 80 characters}
        {--output= : New private credential JSON path outside htdocs}';

    protected $description = 'Provision, rotate or revoke an independent IoT device identity';

    public function handle(IotDeviceProvisioning $provisioning): int
    {
        $action = $this->argument('action');
        $id = $this->argument('device') ?? ($action === 'provision' ? (string) Str::uuid() : '');
        $name = $this->option('name');
        if (! in_array($action, ['provision', 'rotate', 'revoke'], true)
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id)
            || ($action === 'provision' && (! is_string($name) || trim($name) === '' || mb_strlen($name) > 80))
            || ($action !== 'revoke' && ! is_string($this->option('output')))) {
            $this->error('Provide a valid action/device, provisioning label and new private output path.');

            return self::FAILURE;
        }
        try {
            if ($action === 'revoke') {
                $provisioning->revoke($id);
            } else {
                $provisioning->issue($id, (string) $name, $this->option('output'), $action === 'rotate');
            }
        } catch (\Throwable) {
            // Never render query bindings, encrypted material or private file contents.
            $this->error('Device operation failed. Check identity, migration readiness and private file permissions.');

            return self::FAILURE;
        }
        $this->info('Device '.$id.' '.$action.' succeeded.');
        if ($action !== 'revoke') {
            $this->info('Credential saved only to the requested private file. Keep it and all credential-bearing binaries private.');
        }

        return self::SUCCESS;
    }
}
