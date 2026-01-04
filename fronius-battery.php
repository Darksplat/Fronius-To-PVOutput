<?php
// -----------------------------------------------------------------------------
// Fronius GEN24 + Smart Meter → PVOutput uploader (STANDARD + BATTERY READY)
// Battery support is OPTIONAL and DISABLED by default
// -----------------------------------------------------------------------------

// ---------------- USER CONFIG -------------------------------------------------

// Fronius inverter IP or hostname
$ipAddress = 'PUT_YOUR_INVERTER_IP_ADDRESS_HERE';

// PVOutput credentials
$pvoutputAPIKey   = 'PUT_YOUR_PVOUTPUT_API_KEY_HERE';
$pvoutputSystemId = 'PUT_YOUR_PVOUTPUT_SYSTEM_ID_HERE';

// Battery support (GEN24 Hybrid)
// ⚠️ ENABLE ONLY if you have a battery installed type true to enable
define('ENABLE_BATTERY', false);

// Diagnostics type true to enable
define('DEBUG', false);

// ---------------- INTERNAL CONFIG --------------------------------------------
$inverterId  = 1;
$lockFile    = __DIR__ . '/fronius.lock';
$logFile     = __DIR__ . '/fronius.log';
$httpTimeout = 10;

// ---------------- HELPERS -----------------------------------------------------
function logMsg(string $msg, bool $debugOnly = false): void
{
    if ($debugOnly && !DEBUG) return;

    global $logFile;
    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND
    );
}

function httpGetJson(string $url, int $timeout): array
{
    $context = stream_context_create(['http' => ['timeout' => $timeout]]);
    $json = @file_get_contents($url, false, $context);
    if ($json === false) throw new RuntimeException("HTTP failed: {$url}");

    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException("Invalid JSON: {$url}");

    return $data;
}

// ---------------- LOCKFILE ----------------------------------------------------
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) exit;
register_shutdown_function(fn() => (flock($lockHandle, LOCK_UN) || fclose($lockHandle)));

// ---------------- TIME --------------------------------------------------------
$date = date('Ymd');
$time = date('H:i');

// ---------------- MAIN --------------------------------------------------------
try {

    // Inverter instantaneous data
    $inv = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
        "?Scope=Device&DeviceID={$inverterId}&DataCollection=CommonInverterData",
        $httpTimeout
    );

    $invData = $inv['Body']['Data'] ?? null;
    if (!$invData) throw new RuntimeException('Missing inverter data');

    $powerGeneration = (int) ($invData['PAC']['Value'] ?? 0);
    $voltage   = $invData['UAC']['Value'] ?? null;
    $frequency = $invData['FAC']['Value'] ?? null;

    // Inverter daily energy
    $energy = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
        "?Scope=Device&DeviceID={$inverterId}&DataCollection=EnergyReal_WAC_Sum_Day",
        $httpTimeout
    );

    $energyToday = (int) (
        $energy['Body']['Data']['EnergyReal_WAC_Sum_Day']['Value'] ?? 0
    );

    // Smart Meter
    $meter = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetMeterRealtimeData.cgi" .
        "?Scope=Device&DeviceId=0",
        $httpTimeout
    );

    $m = $meter['Body']['Data'] ?? null;
    if (!$m) throw new RuntimeException('Missing meter data');

    $rawGridPower = (int) ($m['PowerReal_P_Sum']['Value'] ?? 0);
    $powerConsumption = max(0, $rawGridPower);
    $netGridPower     = -$rawGridPower;
    $energyConsumedToday = (int) ($m['EnergyReal_WAC_Sum_Consumed_Day']['Value'] ?? 0);

    // -------------------------------------------------------------------------
    // BATTERY SUPPORT (OPTIONAL)
    // -------------------------------------------------------------------------
    $batteryPower = null;
    $batterySOC   = null;

    if (ENABLE_BATTERY) {

        $storage = httpGetJson(
            "http://{$ipAddress}/solar_api/v1/GetStorageRealtimeData.cgi",
            $httpTimeout
        );

        $s = $storage['Body']['Data'] ?? null;

        if (is_array($s)) {

            // Fronius: +ve = discharge, -ve = charge
            // PVOutput: +ve = charge, -ve = discharge
            if (isset($s['P'])) {
                $batteryPower = -(float) $s['P'];
            }

            if (isset($s['SOC'])) {
                $soc = (float) $s['SOC'];
                if ($soc >= 0 && $soc <= 100) {
                    $batterySOC = (int) round($soc);
                }
            }

            logMsg('Battery data: ' . json_encode($s), true);
        }
    }

    // -------------------------------------------------------------------------
    // PVOUTPUT PAYLOAD
    // -------------------------------------------------------------------------
    $data = [
        'd'  => $date,
        't'  => $time,
        'v2' => $powerGeneration,
        'v3' => $energyToday,
        'v4' => $powerConsumption,
        'v8' => $netGridPower,
        'v9' => $energyConsumedToday,
    ];

    if ($voltage !== null)   $data['v6'] = round($voltage, 1);
    if ($frequency !== null) $data['v7'] = round($frequency, 2);

    if (ENABLE_BATTERY && $batteryPower !== null) {
        $data['v10'] = (int) round($batteryPower);
    }

    if (ENABLE_BATTERY && $batterySOC !== null) {
        $data['v11'] = $batterySOC;
    }

    logMsg('PVOutput payload: ' . json_encode($data), true);

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

    if (@file_get_contents('https://pvoutput.org/service/r2/addstatus.jsp', false, $context) === false) {
        throw new RuntimeException('PVOutput upload failed');
    }

} catch (Throwable $e) {
    logMsg('ERROR: ' . $e->getMessage());
    exit(1);
}
