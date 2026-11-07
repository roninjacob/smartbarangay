<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Two isolated test processes exercise the real HTTP kernel against one temp SQLite file.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = realpath((string) getenv('DB_DATABASE'));
$temporaryRoot = realpath(sys_get_temp_dir());
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || ! $database || ! $temporaryRoot
    || ! str_starts_with(strtolower($database), strtolower($temporaryRoot.DIRECTORY_SEPARATOR.'sb-iot02-'))
    || basename($database) !== 'concurrency.sqlite') {
    exit(2);
}
$input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['iot.enabled' => true, 'database.connections.sqlite.busy_timeout' => 5000]);
DB::connection()->statement('PRAGMA busy_timeout = 5000');
while (microtime(true) < $input['start_at']) {
    usleep(1000);
}
$request = Request::create('/api/iot/v1/probe', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'off',
], '{"message":"probe"}');
foreach ($input['headers'] as $name => $value) {
    $request->headers->set($name, $value);
}
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo $response->getStatusCode();
