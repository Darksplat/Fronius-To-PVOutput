<?php
// -----------------------------------------------------------------------------
// Fronius GEN24 + Smart Meter → PVOutput uploader
// STEP 5: HARDENED (cron-safe, logged, resilient)
// -----------------------------------------------------------------------------

// ---------------- CONFIG ------------------------------------------------------
$ipAddress = ''; // Add your IP Address of your Fronius Inverter here

// PVOutput API key
$pvoutputAPIKey = ""; // Add your PVOutput API key

// PVOutput System ID
$pvoutputSystemId = ""; // Add your PVOutput System ID

$inverterId = 1;

// Files
$lockFile = __DIR__ . '/fronius.lock';
$logFile  = __DIR__ . '/fronius.log';

// Timeouts
$httpTimeout = 10;

// ---------------- HELPERS -----------------------------------------------------
function logMsg(string $msg)
{
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
        'http' => [
            'timeout' => $timeout,
        ]
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
    // Another instance is running
    exit;
}

// Ensure lock is released
register_shutdown_function(function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

// ---------------- TIME --------------------------------------------------------
$date = date('Ymd');
$time = date('H:i');

// ---------------- MAIN LOGIC --------------------------------------------------
try {

    // Inverter power
    $invPower = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
        "?Scope=Device&DeviceID={$inverterId}&DataCollection=CommonInverterData",
        $httpTimeout
    );

    $invData = $invPower['Body']['Data'] ?? null;
    if ($invData === null) {
        throw new RuntimeException('Missing inverter power data');
    }

    $powerGeneration = (int) ($invData['PAC']['Value'] ?? 0);
    $voltage   = isset($invData['UAC']['Value']) ? round($invData['UAC']['Value'], 1) : null;
    $frequency = isset($invData['FAC']['Value']) ? round($invData['FAC']['Value'], 2) : null;

    // Inverter daily energy
    $invEnergy = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
        "?Scope=Device&DeviceID={$inverterId}&DataCollection=EnergyReal_WAC_Sum_Day",
        $httpTimeout
    );

    $energyGeneratedToday = (int) (
        $invEnergy['Body']['Data']['EnergyReal_WAC_Sum_Day']['Value'] ?? 0
    );

    // Smart meter
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

    $powerConsumption = max(0, $rawGridPower);
    $netGridPower     = -$rawGridPower;

    $energyConsumedToday = (int) (
        $meterData['EnergyReal_WAC_Sum_Consumed_Day']['Value'] ?? 0
    );

    // PVOutput payload
    $data = [
        'd'  => $date,
        't'  => $time,
        'v2' => $powerGeneration,
        'v3' => $energyGeneratedToday,
        'v4' => $powerConsumption,
        'v8' => $netGridPower,
        'v9' => $energyConsumedToday,
    ];

    if ($voltage !== null)   $data['v6'] = $voltage;
    if ($frequency !== null) $data['v7'] = $frequency;

    // Send to PVOutput
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

} catch (Throwable $e) {
    logMsg($e->getMessage());
    exit(1);
}
