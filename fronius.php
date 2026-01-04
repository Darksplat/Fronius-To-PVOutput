<?php
// -----------------------------------------------------------------------------
// Fronius GEN24 + Smart Meter → PVOutput uploader (STANDARD)
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
//
// This script is correct for NON-BATTERY systems.
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
$inverterId  = 1;   // Standard script assumes single inverter ID = 1
$lockFile    = __DIR__ . '/fronius.lock';
$logFile     = __DIR__ . '/fronius.log';
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
    // INVERTER REALTIME DATA (POWER, VOLTAGE)
    // -------------------------------------------------------------------------
    $inv = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
        "?Scope=Device&DeviceID={$inverterId}&DataCollection=CommonInverterData",
        $httpTimeout
    );

    $invData = $inv['Body']['Data'] ?? null;
    if (!is_array($invData)) {
        throw new RuntimeException('Missing inverter realtime data');
    }

    $pvPowerW = (int) ($invData['PAC']['Value'] ?? 0);
    $voltageV = $invData['UAC']['Value'] ?? null;

    // -------------------------------------------------------------------------
    // INVERTER DAILY ENERGY (GENERATION)
    // -------------------------------------------------------------------------
    $invEnergy = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
        "?Scope=Device&DeviceID={$inverterId}&DataCollection=EnergyReal_WAC_Sum_Day",
        $httpTimeout
    );

    $pvEnergyWh = (int) (
        $invEnergy['Body']['Data']['EnergyReal_WAC_Sum_Day']['Value'] ?? 0
    );

    // -------------------------------------------------------------------------
    // SMART METER DATA (GRID POWER + ENERGY)
    // -------------------------------------------------------------------------
    $meter = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetMeterRealtimeData.cgi?Scope=Device&DeviceId=0",
        $httpTimeout
    );

    $m = $meter['Body']['Data'] ?? null;
    if (!is_array($m)) {
        throw new RuntimeException('Missing meter data');
    }

    // Grid power (invert for this installation)
    $gridPowerW = (int) ($m['PowerReal_P_Sum']['Value'] ?? 0);
    if ($INVERT_METER_POWER === true) {
        $gridPowerW *= -1;
    }

    // Grid energy (cumulative today)
    $importWh = isset($m['EnergyReal_WAC_Sum_Consumed_Day']['Value'])
        ? (int) $m['EnergyReal_WAC_Sum_Consumed_Day']['Value']
        : null;

    $exportWh = isset($m['EnergyReal_WAC_Sum_Produced_Day']['Value'])
        ? (int) $m['EnergyReal_WAC_Sum_Produced_Day']['Value']
        : null;

    // -------------------------------------------------------------------------
    // CALCULATE HOUSE LOAD
    // -------------------------------------------------------------------------
    // Instantaneous load power
    $loadPowerW = $pvPowerW + $gridPowerW;
    if ($loadPowerW < 0) $loadPowerW = 0;

    // Cumulative load energy (today)
    $loadEnergyWh = null;
    if ($importWh !== null && $exportWh !== null) {
        $loadEnergyWh = $pvEnergyWh + $importWh - $exportWh;
        if ($loadEnergyWh < 0) $loadEnergyWh = 0;
    }

    // -------------------------------------------------------------------------
    // PVOUTPUT PAYLOAD (FREE-TIER SAFE)
    // -------------------------------------------------------------------------
    $data = [
        'd'  => $date,
        't'  => $time,
        'v1' => $pvEnergyWh,   // cumulative generation today
        'v2' => $pvPowerW,     // instantaneous generation
        'v4' => $loadPowerW,   // instantaneous consumption
        'c1' => 1,             // v1 and v3 are cumulative
    ];

    if ($loadEnergyWh !== null) {
        $data['v3'] = $loadEnergyWh;  // cumulative consumption today
    }

    if ($voltageV !== null) {
        $data['v6'] = round((float) $voltageV, 1);
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
