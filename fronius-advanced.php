<?php
// -----------------------------------------------------------------------------
// Fronius GEN24 + Smart Meter → PVOutput uploader (ADVANCED)
//
// MODE: Power-integration (correct for GEN24)
//
// Core PVOutput fields:
//   v2 = PV Power (W)              → Site.P_PV  (whole-site PV power)
//   v4 = Load Power (W)            → abs(Site.P_Load)
//   v6 = Voltage (V)               → Average UAC across detected inverters
//
// Optional (donor-only):
//   v7 = Frequency (Hz)            → Smart Meter Frequency_Phase_Average
//   v8 = Net Grid Power (W)        → Site.P_Grid
//
// Features:
//   - Auto-detect inverter IDs (no hard-coded DeviceID)
//   - Multi-inverter ready (sums/averages where appropriate)
//   - Debug logging toggle
// -----------------------------------------------------------------------------

// ======================= USER CONFIG =======================

// Fronius inverter IP or hostname
$ipAddress = 'PUT_YOUR_INVERTER_IP_ADDRESS_HERE';

// PVOutput credentials
$pvoutputAPIKey   = 'PUT_YOUR_PVOUTPUT_API_KEY_HERE';
$pvoutputSystemId = 'PUT_YOUR_PVOUTPUT_SYSTEM_ID_HERE';

// ======================= FEATURE TOGGLES =======================

// Enable debug logging (writes payloads and extra details)
$DEBUG = false;

// Donor features (set true only if your PVOutput system supports these)
$ENABLE_V7_FREQUENCY = false;  // v7
$ENABLE_V8_GRIDPOWER  = false;  // v8

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
    $context = stream_context_create(['http' => ['timeout' => $timeout]]);
    $json = @file_get_contents($url, false, $context);
    if ($json === false) throw new RuntimeException("HTTP failed: {$url}");

    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException("Invalid JSON: {$url}");

    return $data;
}

function safeFloat($v): ?float
{
    if ($v === null) return null;
    if (is_numeric($v)) return (float) $v;
    return null;
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
    // POWER FLOW (SITE TOTALS: PV, LOAD, GRID)
    // -------------------------------------------------------------------------
    $pf = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetPowerFlowRealtimeData.fcgi",
        $httpTimeout
    );

    $site = $pf['Body']['Data']['Site'] ?? null;
    if (!is_array($site)) throw new RuntimeException('Missing PowerFlow Site data');

    $pvPowerW   = (int) round((float) ($site['P_PV'] ?? 0));
    $loadPowerW = (int) round(abs((float) ($site['P_Load'] ?? 0)));
    $gridPowerW = (int) round((float) ($site['P_Grid'] ?? 0));

    // -------------------------------------------------------------------------
    // AUTO-DETECT INVERTER IDS (for voltage averaging)
    // -------------------------------------------------------------------------
    $invCommon = httpGetJson(
        "http://{$ipAddress}/solar_api/v1/GetInverterRealtimeData.cgi" .
        "?Scope=System&DataCollection=CommonInverterData",
        $httpTimeout
    );

    // Expected structure (System scope):
    // Body.Data.<key>.Values is a map of inverterId => value
    $dataSys = $invCommon['Body']['Data'] ?? null;
    if (!is_array($dataSys)) throw new RuntimeException('Missing inverter system data');

    // Find inverter IDs from PAC.Values (most reliable map)
    $pacValues = $dataSys['PAC']['Values'] ?? null;
    if (!is_array($pacValues) || count($pacValues) === 0) {
        throw new RuntimeException('Unable to detect inverter IDs (PAC.Values empty)');
    }

    $inverterIds = array_keys($pacValues);

    // -------------------------------------------------------------------------
    // VOLTAGE: average UAC across detected inverters
    // -------------------------------------------------------------------------
    $uacValues = $dataSys['UAC']['Values'] ?? null;
    $voltageV = null;

    if (is_array($uacValues) && count($uacValues) > 0) {
        $sum = 0.0;
        $cnt = 0;
        foreach ($inverterIds as $id) {
            if (isset($uacValues[$id]) && is_numeric($uacValues[$id])) {
                $sum += (float) $uacValues[$id];
                $cnt++;
            }
        }
        if ($cnt > 0) $voltageV = $sum / $cnt;
    }

    // -------------------------------------------------------------------------
    // OPTIONAL: Smart Meter Frequency (v7)
    // -------------------------------------------------------------------------
    $frequencyHz = null;
    if ($ENABLE_V7_FREQUENCY === true) {
        $meter = httpGetJson(
            "http://{$ipAddress}/solar_api/v1/GetMeterRealtimeData.cgi?Scope=Device&DeviceId=0",
            $httpTimeout
        );
        $m0 = $meter['Body']['Data']['0'] ?? null;
        if (is_array($m0) && isset($m0['Frequency_Phase_Average']) && is_numeric($m0['Frequency_Phase_Average'])) {
            $frequencyHz = (float) $m0['Frequency_Phase_Average'];
        }
    }

    // -------------------------------------------------------------------------
    // PVOUTPUT PAYLOAD
    // -------------------------------------------------------------------------
    $payload = [
        'd'  => $date,
        't'  => $time,
        'v2' => $pvPowerW,
        'v4' => $loadPowerW,
    ];

    if ($voltageV !== null) {
        $payload['v6'] = round((float) $voltageV, 1);
    }

    if ($ENABLE_V8_GRIDPOWER === true) {
        $payload['v8'] = $gridPowerW;
    }

    if ($ENABLE_V7_FREQUENCY === true && $frequencyHz !== null) {
        $payload['v7'] = round((float) $frequencyHz, 2);
    }

    logMsg('Detected inverter IDs: ' . json_encode($inverterIds), true);
    logMsg('PVOutput payload: ' . json_encode($payload), true);

    // -------------------------------------------------------------------------
    // SEND TO PVOUTPUT
    // -------------------------------------------------------------------------
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  =>
                "X-Pvoutput-Apikey: {$pvoutputAPIKey}\r\n" .
                "X-Pvoutput-SystemId: {$pvoutputSystemId}\r\n",
            'content' => http_build_query($payload),
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
