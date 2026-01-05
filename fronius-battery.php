<?php
// -----------------------------------------------------------------------------
// Fronius GEN24 + Smart Meter → PVOutput uploader (BATTERY-READY)
//
// MODE: Power-integration (correct for GEN24)
//
// Always sent:
//   v2 = PV Power (W)        → PowerFlow Site.P_PV
//   v4 = Load Power (W)      → abs(PowerFlow Site.P_Load)
//   v6 = Voltage (V)
//
// Optional (DONOR + BATTERY, DISABLED BY DEFAULT):
//   b1 = Battery Power (W)   → Storage P (charge + / discharge -)
//   b2 = Battery SOC (%)     → Storage SOC
//
// Notes:
// - Battery support is OFF by default.
// - Energy is calculated by PVOutput from power.
// -----------------------------------------------------------------------------

// ======================= USER CONFIG =======================

$ipAddress = 'PUT_YOUR_INVERTER_IP_ADDRESS_HERE';

$pvoutputAPIKey   = 'PUT_YOUR_PVOUTPUT_API_KEY_HERE';
$pvoutputSystemId = 'PUT_YOUR_PVOUTPUT_SYSTEM_ID_HERE';

// ======================= FEATURE TOGGLES =======================

// Enable debug logging
$DEBUG = false;

// ⚠️ BATTERY SUPPORT (set true ONLY if a battery is installed)
$ENABLE_BATTERY = false;

// Optional donor extras (leave false unless you want them)
$ENABLE_V7_FREQUENCY = false;  // Grid frequency
$ENABLE_V8_GRIDPOWER = false;  // Net grid power

// ======================= INTERNAL CONFIG =======================
$lockFile    = __DIR__ . '/fronius-battery.lock';
$logFile     = __DIR__ . '/fronius-battery.log';
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

    $voltageV = $inv['Body']['Data']['UAC']['Value'] ?? null;

    // -------------------------------------------------------------------------
    // OPTIONAL: SMART METER (FREQUENCY)
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
    // OPTIONAL: BATTERY DATA (DISABLED BY DEFAULT)
    // -------------------------------------------------------------------------
    $batteryPowerW = null;
    $batterySOC   = null;

    if ($ENABLE_BATTERY === true) {
        $storage = httpGetJson(
            "http://{$ipAddress}/solar_api/v1/GetStorageRealtimeData.cgi",
            $httpTimeout
        );

        $s = $storage['Body']['Data'] ?? null;
        if (is_array($s)) {
            // Fronius convention:
            //   +P = discharge, -P = charge
            // PVOutput convention:
            //   +b1 = charge, -b1 = discharge
            if (isset($s['P']) && is_numeric($s['P'])) {
                $batteryPowerW = (int) round(-(float) $s['P']);
            }

            if (isset($s['SOC']) && is_numeric($s['SOC'])) {
                $soc = (float) $s['SOC'];
                if ($soc >= 0 && $soc <= 100) {
                    $batterySOC = (int) round($soc);
                }
            }
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

    if ($ENABLE_BATTERY === true && $batteryPowerW !== null) {
        $payload['b1'] = $batteryPowerW;
        if ($batterySOC !== null) {
            $payload['b2'] = $batterySOC;
        }
    }

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
