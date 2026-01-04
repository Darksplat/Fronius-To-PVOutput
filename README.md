# Fronius‑To‑PVOutput

## Overview

**Fronius‑To‑PVOutput** is a hardened PHP script that extracts real‑time and daily energy data from a **Fronius inverter and Smart Meter** and uploads it to **PVOutput** using the official PVOutput v2 API.

The script is designed to run unattended every **5 minutes**, is safe against duplicate uploads, and has been validated against real‑world data.

### Tested Hardware

* **Fronius Symo GEN24 10.0** (3‑phase)
* **Fronius Smart Meter 63A‑3** (3‑phase)

While it should work with other Fronius GEN24 variants and compatible Smart Meters, this repository reflects the configuration above. Use at your own risk.

---

## What Data Is Sent to PVOutput

The script uses *authoritative Fronius API counters* and maps them correctly to PVOutput fields:

* **Instantaneous generation (W)**
* **Daily energy generation (Wh)** — resets at midnight
* **Instantaneous household consumption (W)**
* **Daily household consumption (Wh)** — resets at midnight
* **Net grid power (import/export, W)**
* **AC voltage (V)**
* **AC frequency (Hz)**

No lifetime counters, rolling deltas, or unsafe calculations are used.

---

## Requirements

* PHP 7.4 or later (PHP 8.x supported)
* Network access to the Fronius inverter
* Outbound HTTPS access to `pvoutput.org`
* A valid **PVOutput API key** and **System ID**

---

## Which script should I use?

This repository provides two PHP scripts for uploading Fronius data to PVOutput.

Most users should use the **standard script**. An **advanced script** is available for more complex installations.

---

### Standard script — `fronius.php`

Use this script if:

- You have **one Fronius inverter**
- You want the **simplest and most robust setup**
- You do not need inverter auto-detection

Features:

- Single inverter (manually configured)
- Hardened and cron-safe
- Correct PVOutput field mapping
- Recommended for the majority of residential systems

---

### Advanced script — `fronius-advanced.php`

Use this script if:

- You have **multiple Fronius inverters**, or may add more later
- You want **automatic inverter ID detection**
- You are comfortable with a more advanced configuration

Features:

- Automatically detects all running inverters via the Fronius API
- Aggregates power and daily energy across multiple devices
- Fully hardened with locking and logging
- Uses the same safe PVOutput semantics as the standard script

> The advanced script contains **no hard-coded credentials**.  
> You must supply your own inverter IP address, PVOutput API key, and System ID.

---

## Configuration

Edit the following values near the top of the script:

```php
$ipAddress = 'YOUR_INVERTER_IP_ADDRESS';

$pvoutputAPIKey   = 'YOUR_API_KEY_HERE';
$pvoutputSystemId = 'YOUR_SYSTEM_ID_HERE';

$inverterId = 1;
```

> Tip: The inverter ID is usually `1`. You can confirm this via the Fronius API endpoint `GetInverterInfo.cgi`.

---

## Scheduling the Script

The script runs correctly on **Synology NAS, Linux servers, Raspberry Pi (Zero / 2 / 3 / 4 / 5), macOS, and other Unix-like systems** where PHP is available.

The script **must** be run every **5 minutes** for PVOutput to calculate import/export and self‑consumption correctly.

### Synology NAS (DSM)

1. Open **Control Panel → Task Scheduler**
2. Click **Create → Scheduled Task → User‑defined script**
3. Give the task a descriptive name and enable it
4. Set the schedule to run **every 5 minutes**
5. Set the time, make the last run time 23:55
6. In **Task Settings**, choose **Run command** and enter:

```bash
/usr/bin/php /volume1/PATH/TO/fronius.php
```

6. Save the task

The built‑in lockfile prevents overlapping executions.

---

### Linux / Unix (cron)

Edit your crontab:

```bash
crontab -e
```

Add:

```bash
*/5 * * * * /usr/bin/php /path/to/fronius.php
```

No additional locking or sleeps are required.

---

### Windows (Task Scheduler)

Windows is **not recommended**, but it is possible.

1. Open **Task Scheduler**
2. Create a new task
3. Enable **Run with highest privileges**
4. Set a trigger to run daily and repeat **every 5 minutes**
5. Action: **Start a program**
6. Program/script:

```text
C:\Path\To\php.exe
```

7. Add arguments:

```text
C:\Path\To\fronius.php
```

> Do **not** run this via a web server or browser. Run it as a CLI PHP script.

---

## Logging & Reliability

* The script uses a **lockfile** to prevent duplicate uploads
* Errors are written to `fronius.log` in the script directory
* Network and API failures are handled gracefully
* Partial or corrupt data is never submitted to PVOutput

This makes the script suitable for long‑term unattended use.

---

## Notes & Limitations

* Battery support is **not enabled** (GEN24 battery can be added later)
* Only officially documented Fronius API fields are used
* PVOutput free accounts are subject to standard rate limits

---

## Feedback & Contributions

Constructive feedback and pull requests are welcome, particularly from users with:

* Other GEN24 models
* Different Fronius Smart Meters
* Battery‑equipped systems

---

## Disclaimer

This project is provided **as‑is**, without warranty of any kind. You are responsible for verifying correctness against your own system before relying on the data.

If you are unsure, test with PVOutput set to **private** until you are confident everything behaves correctly.
