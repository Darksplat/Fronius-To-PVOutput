Fronius-To-PVOutput
Overview
This repository contains PHP scripts that extract data from a Fronius GEN24 inverter and Fronius Smart Meter and upload it to PVOutput.org.
The scripts are designed to run at regular intervals (typically every 5 minutes) on any system that can:
Access the Fronius inverter locally, and
Make outbound HTTPS requests to PVOutput.
They have been tested against:
Fronius Symo GEN24 10.0 (3-phase)
Fronius Smart Meter 63A-3
Use at your own risk. Feedback and improvements are welcome.
Script Selection Guide
This repository provides four scripts, each intended for a specific use case.
Script	Intended use
fronius.php	Standard, single-inverter, PVOutput free-tier
fronius-donor-enhanced.php	Single-inverter with PVOutput donor extended values
fronius-advanced.php	Multiple inverters, PVOutput free-tier
fronius-battery.php	Battery systems (PVOutput donation required for battery fields)
Only one script should be used at a time.
What data is uploaded to PVOutput
Common to all scripts
Solar generation (power and daily energy)
Household consumption (power and daily energy)
Voltage
Correct cumulative handling (c1 = 1)
Donor-only enhancements
Grid frequency (v7)
Net grid power (v8)
Battery power and state of charge (b1, b2) — battery script only
No script uses undocumented or unsafe PVOutput fields.
Requirements
PHP 7.4 or newer
Network access to the Fronius inverter
A PVOutput account (free or donation, depending on script)
Ability to run scheduled tasks (cron, Task Scheduler, etc.)
Installation
Clone or download this repository.
Choose one script based on the table above.
Edit the script and set:
Your inverter IP address
Your PVOutput API key
Your PVOutput system ID
Save the file.
Schedule the script to run every 5 minutes.
Scheduling the Script
You may schedule any one of the following scripts:
fronius.php
fronius-donor-enhanced.php
fronius-advanced.php
fronius-battery.php
The scheduling method is identical regardless of which script you choose.
Synology NAS (DSM)
Open Control Panel → Task Scheduler
Create a User-defined script
Set the schedule to run every 5 minutes
Use the following command (example shown for fronius.php):
/usr/bin/php /volume1/PATH_TO_SCRIPT/fronius.php
Replace fronius.php with the script you are using.
Raspberry Pi / Linux / macOS (cron)
Edit the crontab:
crontab -e
Add one of the following lines (example shown for fronius.php):
*/5 * * * * /usr/bin/php /path/to/fronius.php
Ensure:
PHP path is correct (which php)
Script has read permissions
Windows (Task Scheduler)
Open Task Scheduler
Create a new task
Set trigger to repeat every 5 minutes
Action:
Program: php.exe
Arguments: C:\path\to\fronius.php
You must have PHP installed and accessible on the system path.
Notes on Data Interpretation
PVOutput Live view shows interval energy, not daily totals
Daily totals appear in Daily / Summary views
This behaviour is correct and expected
The scripts use cumulative daily values as required by PVOutput
Battery Systems
If you have a battery:
Use fronius-battery.php
Set:
$ENABLE_BATTERY = true;
$PVOUTPUT_DONOR = true;
Battery data is uploaded using PVOutput battery fields (b1, b2)
Battery support is intentionally isolated from the standard scripts.
Support and Feedback
Issues and testing feedback should be posted via GitHub Issues
Please include:
Script used
Inverter model
Smart Meter model
Whether you are a PVOutput donor
Disclaimer
This software is provided “as is”, without warranty of any kind.
You are responsible for validating all data before relying on it.
