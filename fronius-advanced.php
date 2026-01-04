<?php
// -----------------------------------------------------------------------------
// Fronius GEN24 + Smart Meter → PVOutput uploader (ADVANCED)
//
// - Auto-detects all running inverters
// - Aggregates generation across multiple inverters
// - Uses Smart Meter to derive household consumption
//
// PVOutput FREE-TIER fields used ONLY:
//   v1 = Energy Generation (Wh, cumulative today)
//   v2 = Power Generation (W)
//   v3 = Energy Consumption (Wh, cumulative today)
//   v4 = Power Consumption (W)
//   v6 = Voltage (V)
//   c1 = Cumulative flag
//
// NOT USED (donation-only):
//   v7–v12, v8, v9, any battery fields (b1–b6)
// -----------------------------------------------------------------------------

// ======================= USER CONFIG =======================

// Fronius inverter IP or hostname
$ipAddress = 'PUT_YOUR_INVERTER_IP_ADDRESS_HERE';

// PVOutput credentials
$pvoutputAPIKey   = 'PUT_YOUR_PVOUTPUT_API_KEY_HERE';
$pvoutputSystemId = 'PUT_YOUR_PVOUTPUT_SYSTEM_ID_HERE';

// Set to true to enable debug logging
$DEBUG = false;

// IMPORTANT: For your Smart Meter
// Negative PowerReal_P_Sum = importing from grid
// Positive PowerReal_P_Sum = exporting to grid
$INVERT_METER_POWER = true;

// ======================= INTERNAL CONFIG =======================
$lockFile    = __DIR__ . '/fronius-advanced.lock';
$logFile     = __DIR__ . '/fronius-advanced.log';
$httpTimeout = 10;

// ---------------- HELPERS -----------------------------------------------------
function logMsg(string $msg, bool $debugOnly = false): void
{
    global $DEBUG, $logFile;
    if ($debugOnly && $DEBUG !== true) return;

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
        throw new RuntimeException("HTTP failed: {$url}");
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON: {$url}");
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

// ---------------- MAIN --------------------------------------------------------
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

    $totalPvPowerW  = 0;
    $totalPvEnergyWh = 0;
    $voltageSamples = [];

    foreach ($devices as $deviceId => $device) {

        // StatusCode 7 = Running
        if (($device['StatusCode'] ?? 0) !== 7) {
            continue;
        }

        // Inverter realtime power
        $inv = httpGetJson(
            "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
            "?Scope=Device&DeviceID={$deviceId}&DataCollection=CommonInverterData",
            $httpTimeout
        );

        $invData = $inv['Body']['Data'] ?? null;
        if (!is_array($invData)) continue;

        $totalPvPowerW += (int) ($invData['PAC']['Value'] ?? 0);

        if (isset($invData['UAC']['Value'])) {
            $voltageSamples[] = $invData['UAC']['Value'];
        }

        // Inverter daily energy
        $invEnergy = httpGetJson(
            "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
            "?Scope=Device&DeviceID={$deviceId}&DataCollection=EnergyReal_WAC_Sum_Day",
            $httpTimeout
        );

        $totalPvEnergyWh += (int) (
            $invEnergy['Body']['Data']['EnergyReal_WAC_Sum_Day']['Value'] ?? 0
        );
    }

    if ($totalPvPowerW === 0 && $totalPvEnergyWh === 0) {
        throw new RuntimeException('No active inverter data');
    }

    $voltageV = !empty($voltageSamples)
        ? round(array_sum($voltageSamples) / count($voltageSamples), 1)
        : null;

    // -------------------------------------------------------------------------
    // SMART METER DATA
    // -------------------------------------------------------------------------
    $meter = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetMeterRealtimeData.cgi?Scope=Device&DeviceId=0",
        $httpTimeout
    );

    $m = $meter['Body']['Data'] ?? null;
    if (!is_array($m)) {
        throw new RuntimeException('Missing meter data');
    }

    $gridPowerW = (int) ($m['PowerReal_P_Sum']['Value'] ?? 0);
    if ($INVERT_METER_POWER === true) {
        $gridPowerW *= -1;
    }

    $importWh = isset($m['EnergyReal_WAC_Sum_Consumed_Day']['Value'])
        ? (int) $m['EnergyReal_WAC_Sum_Consumed_Day']['Value']
        : null;

    $exportWh = isset($m['EnergyReal_WAC_Sum_Produced_Day']['Value'])
        ? (int) $m['EnergyReal_WAC_Sum_Produced_Day']['Value']
        : null;

    // -------------------------------------------------------------------------
    // CALCULATE HOUSE LOAD
    // -------------------------------------------------------------------------
    $loadPowerW = $totalPvPowerW + $gridPowerW;
    if ($loadPowerW < 0) $loadPowerW = 0;

    $loadEnergyWh = null;
    if ($importWh !== null && $exportWh !== null) {
        $loadEnergyWh = $totalPvEnergyWh + $importWh - $exportWh;
        if ($loadEnergyWh < 0) $loadEnergyWh = 0;
    }

    // -------------------------------------------------------------------------
    // PVOUTPUT PAYLOAD (FREE-TIER SAFE)
    // -------------------------------------------------------------------------
    $data = [
        'd'  => $date,
        't'  => $time,
        'v1' => $totalPvEnergyWh,
        'v2' => $totalPvPowerW,
        'v4' => $loadPowerW,
        'c1' => 1,
    ];

    if ($loadEnergyWh !== null) {
        $data['v3'] = $loadEnergyWh;
    }

    if ($voltageV !== null) {
        $data['v6'] = $voltageV;
    }

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

    if (@file_get_contents(
        'https://pvoutput.org/service/r2/addstatus.jsp',
        false,
        $context
    ) === false) {
        throw new RuntimeException('PVOutput upload failed');
    }

} catch (Throwable $e) {
    logMsg('ERROR: ' . $e->getMessage());
    exit(1);
}
