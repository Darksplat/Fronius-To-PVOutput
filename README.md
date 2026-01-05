# Fronius → PVOutput Integration

PHP scripts to upload live data from **Fronius GEN24 inverters** and **Fronius Smart Meters** to **PVOutput.org**.

These scripts are designed specifically around the realities of **GEN24 firmware**, which does **not expose daily energy counters** via the Solar API. As a result, all scripts use **power‑integration mode**, letting PVOutput calculate energy, efficiency, averages, and totals correctly.

---

## Key Design Principles

* Uses **raw power values** (never reconstructed energy)
* PVOutput performs **all energy calculations**
* Fully compatible with GEN24 firmware limitations
* Safe for long‑term unattended operation
* One script runs at a time (cron‑safe lockfiles)

---

## Available Scripts

### 1. `fronius.php` — Standard (Recommended for most users)

**Use this if you have a single GEN24 inverter and no battery.**

Uploads:

* `v2` — PV power (W)
* `v4` — Household load power (W)
* `v6` — AC voltage (V)

Energy, efficiency, export/import, and averages are calculated automatically by PVOutput.

---

### 2. `fronius-donor-enhanced.php` — Donor Features

**For PVOutput donors who want extra telemetry.**

Everything from the standard script, plus:

* `v7` — Grid frequency (Hz)
* `v8` — Net grid power (W)

All donor fields are clearly documented and optional.

---

### 3. `fronius-advanced.php` — Advanced / Multi‑Inverter

**For advanced users or future expansion.**

Adds:

* Automatic inverter ID detection
* Multi‑inverter readiness
* Averaged voltage across inverters
* Feature toggles for donor fields

Still uses site‑level power values for correctness.

---

### 4. `fronius-battery.php` — Battery‑Ready (Disabled by Default)

**Use only if a battery is installed.**

Includes:

* Battery power (`b1`)
* Battery state of charge (`b2`)

Battery support is **OFF by default** and must be explicitly enabled to avoid accidental misreporting.

---

## Why Power‑Integration Mode Is Required

GEN24 inverters do **not** expose daily PV generation energy (`E_Day`) via the Solar API. Attempting to upload energy counters results in:

* Zero generation days
* Incorrect efficiency
* Broken export/import math

All scripts therefore upload **power only**, which is an officially supported PVOutput mode.

---

## Scheduling the Script

Run **one script only**, every **5 minutes**.

### Linux / Raspberry Pi / NAS (cron)

```bash
*/5 * * * * /usr/bin/php /path/to/fronius.php
```

### Synology NAS

* Control Panel → Task Scheduler
* Create → User‑defined script
* Run every 5 minutes

```bash
/usr/bin/php /volume1/path/to/fronius.php
```

### macOS (launchd or cron)

```bash
*/5 * * * * /usr/bin/php /Users/you/path/to/fronius.php
```

### Windows (Task Scheduler)

* Create task
* Repeat every 5 minutes
* Action: Start a program
* Program:

```text
php.exe
```

* Arguments:

```text
C:\path\to\fronius.php
```

---

## Important Notes

* Do **not** run multiple scripts at once
* Ensure your inverter is reachable on the local network
* Use lowercase `true` / `false` for feature toggles
* Battery fields require a **PVOutput donation**

---

## Support & Feedback

* Issues and feature requests: **GitHub Issues**
* Testing feedback (especially battery users): welcome

This project is community‑driven and based on real‑world GEN24 behaviour.
