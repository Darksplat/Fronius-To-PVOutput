<?php
// -----------------------------------------------------------------------------
// Fronius GEN24 + Smart Meter → PVOutput uploader (STANDARD, FREE-TIER SAFE)
//
// PVOutput FREE fields used only:
//   v1 = Energy Generation (Wh)
//   v2 = Power Generation (W)
//   v3 = Energy Consumption (Wh)
//   v4 = Power Consumption (W)
//   v6 = Voltage (V)
//
// Not used (donation-only):
//   v7–v12, v8, v9, any battery fields (b1–b6)
//
// Notes:
// - Consumption POWER is calculated as: load_W = pv_W + grid_W
// - Consumption ENERGY is calculated as: load_Wh = pv_Wh + import_Wh - export_Wh
// - This is correct for non-battery systems. For batteries use fronius-battery.php.
// -----------------------------------------------------------------------------

// ======================= USER CONFIG =======================

// Fronius inverter IP or hostname
$ipAddress = 'PUT_YOUR_INVERTER_IP_ADDRESS_HERE';

// PVOutput credentials
$pvoutputAPIKey   = 'PUT_YOUR_PVOUTPUT_API_KEY_HERE';
$pvoutputSystemId = 'PUT_YOUR_PVOUTPUT_SYSTEM_ID_HERE';

// Set to true to enable debug logging
$DEBUG = false;

// If your meter power sign is reversed, set this to true.
// Expected (typical): PowerReal_P_Sum > 0 = importing from grid, < 0 = exporting to grid.
$INVERT_METER_POWER = false;

// ======================= INTERNAL CONFIG =======================
$inverterId  = 1;   // Standard script assumes a single inverter with ID 1
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
    // INVERTER REALTIME (POWER, VOLTAGE)
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
    // SMART METER (GRID POWER + IMPORT/EXPORT ENERGY)
    // -------------------------------------------------------------------------
    $meter = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetMeterRealtimeData.cgi?Scope=Device&DeviceId=0",
        $httpTimeout
    );

    $m = $meter['Body']['Data'] ?? null;
    if (!is_array($m)) {
        throw new RuntimeException('Missing meter data');
    }

    // Grid power (W): +import / -export (typical)
    $gridPowerW = (int) ($m['PowerReal_P_Sum']['Value'] ?? 0);
    if ($INVERT_METER_POWER === true) {
        $gridPowerW *= -1;
    }

    // Import/export energy (Wh) for today (if available)
    $importWh = isset($m['EnergyReal_WAC_Sum_Consumed_Day']['Value'])
        ? (int) $m['EnergyReal_WAC_Sum_Consumed_Day']['Value']
        : null;

    $exportWh = isset($m['EnergyReal_WAC_Sum_Produced_Day']['Value'])
        ? (int) $m['EnergyReal_WAC_Sum_Produced_Day']['Value']
        : null;

    // -------------------------------------------------------------------------
    // CALCULATE CONSUMPTION (LOAD)
    // -------------------------------------------------------------------------
    // Power: load_W = pv_W + grid_W
    // (grid_W positive import adds to load; grid_W negative export reduces available PV for load)
    $loadPowerW = $pvPowerW + $gridPowerW;
    if ($loadPowerW < 0) $loadPowerW = 0; // clamp to 0 for safety

    // Energy: load_Wh = pv_Wh + import_Wh - export_Wh (if we have both)
    $loadEnergyWh = null;
    if ($importWh !== null && $exportWh !== null) {
        $loadEnergyWh = $pvEnergyWh + $importWh - $exportWh;
        if ($loadEnergyWh < 0) $loadEnergyWh = 0;
    } elseif ($importWh !== null) {
        // Fallback (less ideal): at least report grid import as a proxy
        // Keeping it non-null helps users who do not have export/day available.
        $loadEnergyWh = $importWh;
    }

    // -------------------------------------------------------------------------
    // PVOUTPUT PAYLOAD (FREE-TIER SAFE)
    // -------------------------------------------------------------------------
    $data = [
        'd'  => $date,
        't'  => $time,
        'v1' => $pvEnergyWh,
        'v2' => $pvPowerW,
        'v4' => $loadPowerW,
    ];

    if ($loadEnergyWh !== null) {
        $data['v3'] = $loadEnergyWh;
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

    $result = @file_get_contents('https://pvoutput.org/service/r2/addstatus.jsp', false, $context);
    if ($result === false) {
        throw new RuntimeException('PVOutput upload failed');
    }

} catch (Throwable $e) {
    logMsg('ERROR: ' . $e->getMessage());
    exit(1);
}
