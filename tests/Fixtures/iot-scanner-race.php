<?php

use App\Models\User;
use App\Services\IotScannerSessions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
if (getenv('APP_ENV') !== 'testing' || ! preg_match('/sb-iot03-race-[0-9a-f-]+\.sqlite$/', $input['database'])
    || ! is_file($input['database'])) {
    exit(2);
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['app.key' => $input['key'], 'database.default' => 'sqlite', 'database.connections.sqlite.url' => null,
    'database.connections.sqlite.database' => $input['database'], 'session.driver' => 'database', 'iot.enabled' => true]);
DB::purge('sqlite');
$request = Request::create('/api/iot/v1/pair', 'POST');
try {
    if ($input['mode'] === 'pair') {
        $request->attributes->set('iot_device_id', $input['device']);
        $request->headers->set('X-SB-Key-Version', '1');
        app(IotScannerSessions::class)->pair($request, ['challenge' => $input['challenge'], 'boot_id' => str_repeat('1', 32)]);
        echo 'paired';
    } else {
        $store = new Store('race', new ArraySessionHandler(120), $input['session']);
        $store->start();
        $store->put(IotScannerSessions::BINDING_KEY, $input['binding']);
        $request->setLaravelSession($store);
        $request->setUserResolver(fn () => User::findOrFail($input['admin']));
        $result = app(IotScannerSessions::class)->poll($request);
        echo $result['result'] === null ? 'empty' : 'delivered';
    }
} catch (HttpException $exception) {
    echo 'rejected';
} catch (Throwable $exception) {
    // Lock contention must fail closed and must never report raw query bindings.
    echo 'unavailable';
}
