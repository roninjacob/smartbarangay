<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

final class CapacityMariaDb
{
    public static function configure(int $port, bool $create = false): string
    {
        if (! app()->environment('testing') || $port < 33180 || $port > 33199) {
            throw new RuntimeException('Disposable MariaDB test environment required.');
        }
        $pdo = new PDO('mysql:host=127.0.0.1;port='.$port, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $data = str_replace('\\', '/', $pdo->query('SELECT @@datadir')->fetchColumn());
        $root = rtrim(str_replace('\\', '/', dirname(rtrim($data, '/'))), '/');
        $prefix = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/sb-phase5-mariadb-';
        if (! str_starts_with(strtolower($root), strtolower($prefix))
            || basename(rtrim($data, '/')) !== 'data'
            || @file_get_contents($root.'/phase5-disposable.txt') !== 'SmartBarangay Phase 5 disposable MariaDB') {
            throw new RuntimeException('Refusing to use an unverified MariaDB server.');
        }
        if ($create) {
            $pdo->exec('CREATE DATABASE IF NOT EXISTS sb_phase5_capacity CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        config(['database.default' => 'capacity_test', 'database.connections.capacity_test' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port,
            'database' => 'sb_phase5_capacity', 'username' => 'root', 'password' => '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ], 'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('T', 32))]);
        DB::purge('capacity_test');

        return $root;
    }
}
