<?php
// -----------------------------------------------------------------------------
// Fronius GEN24 + Smart Meter → PVOutput uploader (STANDARD)
//
// POWER-INTEGRATION MODE (SAFE FOR THIS SYSTEM)
//
// Sent to PVOutput:
//   v2 = PV Power (W)        → Site.P_PV
//   v4 = Load Power (W)      → abs(Site.P_Load)
//   v6 = Voltage (V)
//
// Energy is calculated internally by PVOutput.
// -----------------------------------------------------------------------------

// ======================= USER CONFIG =======================

$ipAddress = 'PUT_YOUR_INVERTER_IP_ADDRESS_HERE';

$pvoutputAPIKey   = 'PUT_YOUR_PVOUTPUT_API_KEY_HERE';
$pvoutputSystemId = 'PUT_YOUR_PVOUTPUT_SYSTEM_ID_HERE';

$DEBUG = false;

// ======================= INTERNAL CONFIG =======================
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

    // PowerFlow (PV + Load)
    $pf = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetPowerFlowRealtimeData.fcgi",
        $httpTimeout
    );

    $site = $pf['Body']['Data']['Site'] ?? null;
    if (!is_array($site)) throw new RuntimeException('Missing Site data');

    $pvPowerW   = (int) round((float) ($site['P_PV'] ?? 0));
    $loadPowerW = (int) round(abs((float) ($site['P_Load'] ?? 0)));

    // Inverter voltage
    $inv = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
        "?Scope=Device&DeviceID=1&DataCollection=CommonInverterData",
        $httpTimeout
    );

    $voltageV = $inv['Body']['Data']['UAC']['Value'] ?? null;

    // PVOutput payload
    $data = [
        'd'  => $date,
        't'  => $time,
        'v2' => $pvPowerW,
        'v4' => $loadPowerW,
    ];

    if ($voltageV !== null) {
        $data['v6'] = round((float) $voltageV, 1);
    }

    logMsg('PVOutput payload: ' . json_encode($data), true);

    // Send
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
