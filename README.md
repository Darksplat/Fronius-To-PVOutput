# Fronius-To-PVOutput
Fronius To PVOutput php script


PHP Script to extract data from a Fronius Inverter and Meter and push it to PVOutput account.
The script is designed to be run every five (5) minutes from a host computer which
can access the inverter and access the PVOutput website. I use my Synology NAS.
The script was tested against a Fronius Symo GEN24 10.0 3 phase system With a
Smart Meter 63A 3 phase system
Use it at your peril. All useful feed-back will be appreciated.

To install on your system I would google how to or ask chatGPT (https://chat.openai.com/chat)
For a Snology Nas this is how
You can set up a scheduled task in your Synology DSM to run the PHP script every 5 minutes. 

Here are the steps to do it:

Open the Control Panel in your DSM and go to Task Scheduler.
Create a new task, select "User-defined script" as the task type.
Set a descriptive name for the task and enable it.
In the "Task Settings" section, select "Run command" as the action and enter the following command:

/usr/bin/php /volume1/THE DIRECTORY YOU SAVED IT/pvoutput.php

In the "Task Settings" section, set the task to run every 5 minutes.
Save the task and make sure it's enabled.

Now the PHP script will run every 5 minutes and update the energy consumption and power consumption values to the Fronius API and then to PVOutput.

Apparently you can also use cron -e but I never got it to work.

To run this script using the Windows Task Scheduler, you will need to follow these steps:

Open the Task Scheduler by searching for it in the Start Menu or by using the Windows search bar.
Click on the "Create Task" button to create a new task.
In the General tab, enter a name for the task and check the "Run with highest privileges" checkbox.
In the Triggers tab, click the "New" button to create a new trigger. Set the trigger to run the task "Daily" and set the "Start" time to when you want the task to start. Set the "Repeat task" option to "Every" 5 minutes.
In the Actions tab, click the "New" button to create a new action. Set the "Action" to "Start a program" and browse to the location where you have saved the PHP script.
In the "Add arguments (optional)" field, enter the URL of the PHP script (e.g., http://localhost/THE DIRECTORY YOU SAVED IT/index.php).
You have to save the file as index.php or it wount work.
Click on the "OK" button to save the task.
Now, the task will run every 5 minutes and the script will be executed. If you need to make changes to the script, simply edit the file and the changes will be reflected the next time the task runs.

Good luck
