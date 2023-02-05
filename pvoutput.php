<?php

// Inverter IP Address
$ipAddress = ''; // Add your IP Address of your Fronius Inverter here

// PVOutput API key
$pvoutputAPIKey = ""; // Add your PVOutput API key

// PVOutput System ID
$pvoutputSystemId = ""; // Add your PVOutput System ID

// Get current date and time
$date = date("Ymd");
$time = date("H:i");

// Get data from the inverter
$inverterData = file_get_contents("http://$ipAddress/solar_api/v1/GetInverterRealtimeData.cgi?Scope=Device&DeviceID=%%id%%&DataCollection=CommonInverterData");
$inverterData = json_decode($inverterData, true);

// Get the power value from the inverter data
$power = $inverterData["Body"]["Data"]["PAC"]["Value"];

// Get data from the meter
$meterData = file_get_contents("http://$ipAddress/solar_api/v1/GetMeterRealtimeData.cgi?Scope=Device&DeviceId=0");
$meterData = json_decode($meterData, true);

// Get the energy consumption and power consumption values from the meter data
$energy_consumption = $meterData["Body"]["Data"]["EnergyReal_WAC_Sum_Consumed"]["Value"];
$power_consumption = $meterData["Body"]["Data"]["PowerReal_P_Sum"]["Value"];

// Add data to the request
$data = [
  "d" => $date,
  "t" => $time,
  "v2" => $power,
  "v3" => $energy_consumption,
  "v4" => $power_consumption,
];

// Prepare and send the request to PVOutput
$url = "https://pvoutput.org/service/r2/addstatus.jsp";
$options = [
  "http" => [
    "header" => [
      "X-Pvoutput-Apikey: $pvoutputAPIKey",
      "X-Pvoutput-SystemId: $pvoutputSystemId",
    ],
    "method" => "POST",
    "content" => http_build_query($data),
  ],
];
$context = stream_context_create($options);
$result = file_get_contents($url, false, $context);

// Check the result
if ($result === false) {
  echo "Request failed";
} else {
  echo "Data added successfully";
}

?>
