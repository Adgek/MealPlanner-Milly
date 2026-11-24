<?php

/*
|--------------------------------------------------------------------------
| Console commands and scheduled work
|--------------------------------------------------------------------------
|
| The `scheduler` container runs `php artisan schedule:work`, which reads this
| file every minute. It evaluates the schedule against app.schedule_timezone —
| the household's 'time_zone' setting, falling back to HOUSEHOLD_TIMEZONE before
| the database can be read — so a time written here is the time on the kitchen
| wall.
|
| Nothing is scheduled yet, so the scheduler logs "No scheduled commands are
| ready to run." The jobs that belong here:
|
|   - MM-9  post the plan the evening before each shopping day, the grocery list,
|           the upstream prep nudges and the daily dinner reminder
|   - MM-13 the reminder times themselves
|   - MM-10 the nightly mysqldump backup
|
| Read the times from Settings (MM-4) rather than hard-coding them, and prefer
| Schedule::command(...) over closures so each job can be tested on its own.
|
| Example, once MM-9 has a command to run:
|
|   use Illuminate\Support\Facades\Schedule;
|
|   Schedule::command('milly:post-plan')->dailyAt('19:00');
|
*/
