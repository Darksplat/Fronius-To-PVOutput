# Changelog

All notable changes to this project are documented in this file.

The format is based on **Keep a Changelog**, and this project follows **Semantic Versioning**.

---

## [1.0.0] – Initial stable release

### Added

* Fronius GEN24 inverter support (tested on Symo GEN24 10.0 3-phase)
* Fronius Smart Meter 63A-3 support
* Correct PVOutput field mapping:

  * v2 – Instantaneous generation (W)
  * v3 – Daily generation (Wh)
  * v4 – Instantaneous consumption (W)
  * v8 – Net grid import/export (W)
  * v9 – Daily consumption (Wh)
  * v6 – AC voltage (V)
  * v7 – AC frequency (Hz)
* Cron-safe lockfile to prevent duplicate uploads
* Graceful handling of API and network failures
* Local error logging

### Notes

* Battery systems are not supported in this release
* Designed for 5-minute execution intervals
