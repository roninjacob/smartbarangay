<?php

namespace App\Services;

use App\Models\IotDevice;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class IotDeviceProvisioning
{
    public function issue(string $id, string $name, string $output, bool $rotate = false): void
    {
        $parent = realpath(dirname($output));
        $webRoot = realpath(dirname(base_path()));
        if ($parent === false || $webRoot === false || ! $this->absolutePath($output)
            || str_starts_with(strtolower($parent.DIRECTORY_SEPARATOR), strtolower($webRoot.DIRECTORY_SEPARATOR))
            || file_exists($output) || is_link($output)) {
            throw new RuntimeException('Use a new absolute file path in an existing private directory outside htdocs.');
        }
        $this->assertPrivateDirectory($parent);
        $previousMask = umask(0077);
        try {
            $file = @fopen($output, 'x+b');
        } finally {
            umask($previousMask);
        }
        if ($file === false) {
            throw new RuntimeException('Cannot create private credential file.');
        }
        try {
            $this->restrictFile($output);
            DB::transaction(function () use ($id, $name, $file, $rotate) {
                $device = $rotate ? IotDevice::lockForUpdate()->findOrFail($id) : new IotDevice;
                if ($device->revoked_at || ($rotate && $device->credential_version >= 999999999)) {
                    throw new RuntimeException('Device is not eligible for rotation.');
                }
                $credential = bin2hex(random_bytes(32));
                $device->forceFill([
                    'id' => $id, 'name' => $rotate ? $device->name : $name,
                    'credential' => $credential,
                    'credential_version' => $rotate ? $device->credential_version + 1 : 1,
                ])->save();
                $json = json_encode([
                    'device_id' => $device->id,
                    'key_version' => $device->credential_version,
                    'key_hex' => $credential,
                    'protocol' => 'SB-IOT-1',
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
                if (fwrite($file, $json) !== strlen($json) || ! fflush($file)) {
                    throw new RuntimeException('Private file write failed.');
                }
            });
        } catch (\Throwable $exception) {
            fclose($file);
            @unlink($output);
            throw new RuntimeException('Device credential operation failed. Check identity, schema and private output permissions.');
        }
        fclose($file);
    }

    public function revoke(string $id): void
    {
        DB::transaction(function () use ($id) {
            $device = IotDevice::lockForUpdate()->findOrFail($id);
            if (! $device->revoked_at) {
                $device->forceFill(['revoked_at' => now('UTC')])->save();
            }
        });
    }

    private function absolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('/\A[A-Za-z]:[\\\\\/]/', $path);
    }

    private function restrictFile(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $identity = new Process(['whoami', '/user', '/fo', 'csv', '/nh']);
            $identity->mustRun();
            if (! preg_match('/S-1-5-[0-9-]+/', $identity->getOutput(), $match)) {
                throw new RuntimeException('Cannot determine credential file owner.');
            }
            (new Process(['icacls', $path, '/inheritance:r', '/grant:r', '*'.$match[0].':F', '*S-1-5-18:F']))->mustRun();
        } elseif (! chmod($path, 0600)) {
            throw new RuntimeException('Cannot restrict credential file permissions.');
        }
    }

    private function assertPrivateDirectory(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            if ((fileperms($path) & 0077) !== 0) {
                throw new RuntimeException('Credential output directory must be private.');
            }

            return;
        }
        // Check the directory before creating a file, avoiding a readable-empty-file race.
        $script = '$ErrorActionPreference = "Stop"; '
            .'$allowed = @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value, "S-1-5-18"); '
            .'$acl = [IO.Directory]::GetAccessControl(\''.str_replace("'", "''", $path).'\'); '
            .'$rules = $acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]); '
            .'$unsafe = @($rules | Where-Object { $_.AccessControlType -eq "Allow" -and $_.IdentityReference.Value -notin $allowed }); '
            .'if ($unsafe.Count -gt 0) { exit 1 }; [Console]::Write("private")';
        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        $process = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded]);
        $process->mustRun();
        if ($process->getOutput() !== 'private') {
            throw new RuntimeException('Cannot verify private directory permissions.');
        }
    }
}
