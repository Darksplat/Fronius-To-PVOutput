<?php
// -----------------------------------------------------------------------------
// Fronius GEN24 + Smart Meter → PVOutput uploader (DONOR ENHANCED)
//
// MODE: Power-integration (correct for GEN24)
//
// Sent to PVOutput:
//   v2 = PV Power (W)              → Site.P_PV
//   v4 = Load Power (W)            → abs(Site.P_Load)
//   v6 = Voltage (V)
//   v7 = Grid Frequency (Hz)       → Smart Meter
//   v8 = Net Grid Power (W)        → Site.P_Grid
//
// Energy, efficiency, averages are calculated by PVOutput.
// -----------------------------------------------------------------------------

// ======================= USER CONFIG =======================

$ipAddress = 'PUT_YOUR_INVERTER_IP_ADDRESS_HERE';

$pvoutputAPIKey   = 'PUT_YOUR_PVOUTPUT_API_KEY_HERE';
$pvoutputSystemId = 'PUT_YOUR_PVOUTPUT_SYSTEM_ID_HERE';

$DEBUG = false;

// ======================= INTERNAL CONFIG =======================
$lockFile    = __DIR__ . '/fronius-donor.lock';
$logFile     = __DIR__ . '/fronius-donor.log';
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
    // POWER FLOW DATA (PV, LOAD, GRID)
    // -------------------------------------------------------------------------
    $pf = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetPowerFlowRealtimeData.fcgi",
        $httpTimeout
    );

    $site = $pf['Body']['Data']['Site'] ?? null;
    if (!is_array($site)) throw new RuntimeException('Missing PowerFlow Site data');

    $pvPowerW   = (int) round((float) ($site['P_PV']   ?? 0));
    $loadPowerW = (int) round(abs((float) ($site['P_Load'] ?? 0)));
    $gridPowerW = (int) round((float) ($site['P_Grid'] ?? 0));

    // -------------------------------------------------------------------------
    // INVERTER DATA (VOLTAGE)
    // -------------------------------------------------------------------------
    $inv = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
        "?Scope=Device&DeviceID=1&DataCollection=CommonInverterData",
        $httpTimeout
    );

    $invData  = $inv['Body']['Data'] ?? [];
    $voltageV = $invData['UAC']['Value'] ?? null;

    // -------------------------------------------------------------------------
    // SMART METER DATA (FREQUENCY)
    // -------------------------------------------------------------------------
    $meter = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetMeterRealtimeData.cgi?Scope=Device&DeviceId=0",
        $httpTimeout
    );

    $m = $meter['Body']['Data']['0'] ?? [];
    $frequencyHz = $m['Frequency_Phase_Average'] ?? null;

    // -------------------------------------------------------------------------
    // PVOUTPUT PAYLOAD
    // -------------------------------------------------------------------------
    $data = [
        'd'  => $date,
        't'  => $time,
        'v2' => $pvPowerW,
        'v4' => $loadPowerW,
        'v8' => $gridPowerW,
    ];

    if ($voltageV !== null) {
        $data['v6'] = round((float) $voltageV, 1);
    }

    if ($frequencyHz !== null) {
        $data['v7'] = round((float) $frequencyHz, 2);
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
