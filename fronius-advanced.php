<?php
// -----------------------------------------------------------------------------
// Fronius GEN24 + Smart Meter → PVOutput uploader (ADVANCED)
// - Auto-detects inverter IDs
// - Supports multiple inverters
// - Hardened for unattended operation
//
// ADVANCED USERS ONLY.
// Most users should use fronius.php instead.
// -----------------------------------------------------------------------------

// ---------------- USER CONFIGURATION -----------------------------------------

// IP address or hostname of your Fronius inverter
$ipAddress = 'PUT_YOUR_INVERTER_IP_ADDRESS_HERE';

// PVOutput credentials
$pvoutputAPIKey   = 'PUT_YOUR_PVOUTPUT_API_KEY_HERE';
$pvoutputSystemId = 'PUT_YOUR_PVOUTPUT_SYSTEM_ID_HERE';

// Diagnostics
define('DEBUG', false);   // set true for troubleshooting

// ---------------- INTERNAL CONFIG --------------------------------------------
$lockFile = __DIR__ . '/fronius-advanced.lock';
$logFile  = __DIR__ . '/fronius-advanced.log';
$httpTimeout = 10;

// ---------------- HELPERS -----------------------------------------------------
function logMsg(string $msg, bool $debugOnly = false): void
{
    if ($debugOnly && !DEBUG) {
        return;
    }

    global $logFile;
    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND
    );
}

function httpGetJson(string $url, int $timeout): array
{
    $context = stream_context_create([
        'http' => ['timeout' => $timeout]
    ]);

    $json = @file_get_contents($url, false, $context);
    if ($json === false) {
        throw new RuntimeException("HTTP fetch failed: {$url}");
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON from: {$url}");
    }

    return $data;
}

// ---------------- LOCKFILE ----------------------------------------------------
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit;
}

register_shutdown_function(function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

// ---------------- TIME --------------------------------------------------------
$date = date('Ymd');
$time = date('H:i');

// ---------------- MAIN LOGIC --------------------------------------------------
try {

    // -------------------------------------------------------------------------
    // AUTO-DETECT INVERTERS
    // -------------------------------------------------------------------------
    $info = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetInverterInfo.cgi",
        $httpTimeout
    );

    $devices = $info['Body']['Data'] ?? [];
    if (empty($devices)) {
        throw new RuntimeException('No inverters detected');
    }

    $totalPowerGeneration = 0;
    $totalEnergyToday     = 0;
    $voltageSamples       = [];
    $frequencySamples     = [];

    foreach ($devices as $deviceId => $device) {

        // StatusCode 7 = Running
        if (($device['StatusCode'] ?? 0) !== 7) {
            continue;
        }

        // Instantaneous power
        $powerData = httpGetJson(
            "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
            "?Scope=Device&DeviceID={$deviceId}&DataCollection=CommonInverterData",
            $httpTimeout
        );

        $p = $powerData['Body']['Data'] ?? null;
        if ($p === null) {
            continue;
        }

        $totalPowerGeneration += (int) ($p['PAC']['Value'] ?? 0);

        if (isset($p['UAC']['Value'])) {
            $voltageSamples[] = $p['UAC']['Value'];
        }
        if (isset($p['FAC']['Value'])) {
            $frequencySamples[] = $p['FAC']['Value'];
        }

        // Daily energy
        $energyData = httpGetJson(
            "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
            "?Scope=Device&DeviceID={$deviceId}&DataCollection=EnergyReal_WAC_Sum_Day",
            $httpTimeout
        );

        $totalEnergyToday += (int) (
            $energyData['Body']['Data']['EnergyReal_WAC_Sum_Day']['Value'] ?? 0
        );
    }

    if ($totalPowerGeneration === 0 && $totalEnergyToday === 0) {
        throw new RuntimeException('No active inverters producing data');
    }

    $voltage   = !empty($voltageSamples)
        ? round(array_sum($voltageSamples) / count($voltageSamples), 1)
        : null;

    $frequency = !empty($frequencySamples)
        ? round(array_sum($frequencySamples) / count($frequencySamples), 2)
        : null;

    // -------------------------------------------------------------------------
    // SMART METER
    // -------------------------------------------------------------------------
    $meter = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetMeterRealtimeData.cgi" .
        "?Scope=Device&DeviceId=0",
        $httpTimeout
    );

    $meterData = $meter['Body']['Data'] ?? null;
    if ($meterData === null) {
        throw new RuntimeException('Missing meter data');
    }

    $rawGridPower = (int) ($meterData['PowerReal_P_Sum']['Value'] ?? 0);

    $powerConsumption    = max(0, $rawGridPower);
    $netGridPower        = -$rawGridPower;
    $energyConsumedToday = (int) (
        $meterData['EnergyReal_WAC_Sum_Consumed_Day']['Value'] ?? 0
    );

    // -------------------------------------------------------------------------
    // PVOUTPUT PAYLOAD
    // -------------------------------------------------------------------------
    $data = [
        'd'  => $date,
        't'  => $time,
        'v2' => $totalPowerGeneration,
        'v3' => $totalEnergyToday,
        'v4' => $powerConsumption,
        'v8' => $netGridPower,
        'v9' => $energyConsumedToday,
    ];

    if ($voltage !== null)   $data['v6'] = $voltage;
    if ($frequency !== null) $data['v7'] = $frequency;

    logMsg('PVOutput payload: ' . json_encode($data), true);

    // -------------------------------------------------------------------------
    // SEND TO PVOUTPUT
    // -------------------------------------------------------------------------
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  =>
                "X-Pvoutput-Apikey: {$pvoutputAPIKey}\r\n" .
                "X-Pvoutput-SystemId: {$pvoutputSystemId}\r\n",
            'content' => http_build_query($data),
            'timeout' => $httpTimeout,
        ]
    ]);

    $result = @file_get_contents(
        'https://pvoutput.org/service/r2/addstatus.jsp',
        false,
        $context
    );

    if ($result === false) {
        throw new RuntimeException('PVOutput upload failed');
    }

    logMsg('PVOutput upload OK', true);

} catch (Throwable $e) {
    logMsg('ERROR: ' . $e->getMessage());
    exit(1);
}
