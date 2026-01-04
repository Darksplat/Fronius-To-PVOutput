<?php
// -----------------------------------------------------------------------------
// Fronius GEN24 + Smart Meter → PVOutput uploader (STANDARD)
// -----------------------------------------------------------------------------

// ======================= USER CONFIG =======================

// Fronius inverter IP or hostname
$ipAddress = 'PUT_YOUR_INVERTER_IP_ADDRESS_HERE';

// PVOutput credentials
$pvoutputAPIKey   = 'PUT_YOUR_PVOUTPUT_API_KEY_HERE';
$pvoutputSystemId = 'PUT_YOUR_PVOUTPUT_SYSTEM_ID_HERE';

// ======================= OPTIONAL FEATURES =======================

// Set to true to enable debug logging
$DEBUG = false;

// ======================= INTERNAL CONFIG =======================
$inverterId  = 1;
$lockFile    = __DIR__ . '/fronius.lock';
$logFile     = __DIR__ . '/fronius.log';
$httpTimeout = 10;

// ======================= HELPERS =======================
function logMsg(string $msg, bool $debugOnly = false): void
{
    global $DEBUG, $logFile;
    if ($debugOnly && !$DEBUG) return;

    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND
    );
}

function httpGetJson(string $url, int $timeout): array
{
    $ctx = stream_context_create(['http' => ['timeout' => $timeout]]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) throw new RuntimeException("HTTP failed: {$url}");

    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException("Invalid JSON: {$url}");

    return $data;
}

// ======================= LOCKFILE =======================
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) exit;
register_shutdown_function(fn() => (flock($lockHandle, LOCK_UN) || fclose($lockHandle)));

// ======================= TIME =======================
$date = date('Ymd');
$time = date('H:i');

// ======================= MAIN =======================
try {

    $inv = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
        "?Scope=Device&DeviceID={$inverterId}&DataCollection=CommonInverterData",
        $httpTimeout
    );

    $i = $inv['Body']['Data'] ?? null;
    if (!$i) throw new RuntimeException('Missing inverter data');

    $powerGeneration = (int) ($i['PAC']['Value'] ?? 0);
    $voltage   = $i['UAC']['Value'] ?? null;
    $frequency = $i['FAC']['Value'] ?? null;

    $energy = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
        "?Scope=Device&DeviceID={$inverterId}&DataCollection=EnergyReal_WAC_Sum_Day",
        $httpTimeout
    );

    $energyToday = (int) (
        $energy['Body']['Data']['EnergyReal_WAC_Sum_Day']['Value'] ?? 0
    );

    $meter = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetMeterRealtimeData.cgi" .
        "?Scope=Device&DeviceId=0",
        $httpTimeout
    );

    $m = $meter['Body']['Data'] ?? null;
    if (!$m) throw new RuntimeException('Missing meter data');

    $rawGridPower = (int) ($m['PowerReal_P_Sum']['Value'] ?? 0);

    $data = [
        'd'  => $date,
        't'  => $time,
        'v2' => $powerGeneration,
        'v3' => $energyToday,
        'v4' => max(0, $rawGridPower),
        'v8' => -$rawGridPower,
        'v9' => (int) ($m['EnergyReal_WAC_Sum_Consumed_Day']['Value'] ?? 0),
    ];

    if ($voltage !== null)   $data['v6'] = round($voltage, 1);
    if ($frequency !== null) $data['v7'] = round($frequency, 2);

    logMsg('PVOutput payload: ' . json_encode($data), true);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  =>
                "X-Pvoutput-Apikey: {$pvoutputAPIKey}\r\n" .
                "X-Pvoutput-SystemId: {$pvoutputSystemId}\r\n",
            'content' => http_build_query($data),
            'timeout' => $httpTimeout,
        ]
    ]);

    if (@file_get_contents('https://pvoutput.org/service/r2/addstatus.jsp', false, $ctx) === false) {
        throw new RuntimeException('PVOutput upload failed');
    }

} catch (Throwable $e) {
    logMsg('ERROR: ' . $e->getMessage());
    exit(1);
}
