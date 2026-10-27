<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Ai\Actions\PurgeStaleUploads;
use Modules\Attendance\Services\AbsenceSweeper;
use Modules\Calls\Services\CallService;
use Modules\Courses\Actions\ExpireEnrollments;
use Modules\Groups\Services\SessionReminders;
use Modules\Learning\Services\DutyReminders;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('attendance:sweep-absences', function (AbsenceSweeper $sweeper) {
    $this->info('Marked absent: '.$sweeper->sweep());
})->purpose('Mark students absent after their group session ends and notify parents');

Artisan::command('duties:send-reminders', function (DutyReminders $reminders) {
    $this->info('Duties reminded: '.$reminders->send());
})->purpose('Remind each group of the duties due on its lesson day');

Artisan::command('sessions:send-reminders', function (SessionReminders $reminders) {
    $this->info('Groups reminded: '.$reminders->send());
})->purpose('Tell students and teachers that a session starts soon');

Artisan::command('calls:expire', function (CallService $calls) {
    $this->info('Missed calls: '.$calls->expireUnanswered());
})->purpose('Mark calls nobody answered as missed and tell the person called');

Artisan::command('courses:expire-enrollments', function (ExpireEnrollments $expire) {
    $this->info('Enrollments expired: '.$expire->handle());
})->purpose('End the access of course enrollments past their period');

Artisan::command('ai:purge-stale-uploads', function (PurgeStaleUploads $purge) {
    $this->info('Abandoned AI uploads removed: '.$purge->handle());
})->purpose('Delete chunked AI material uploads that were never completed, with their temp files');

// Needs the standard cron entry: * * * * * php artisan schedule:run
// Each task is queued once per active school and runs inside that school's database.
Schedule::command('schools:dispatch attendance:sweep-absences')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('schools:dispatch duties:send-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('schools:dispatch sessions:send-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('schools:dispatch calls:expire')->everyMinute()->withoutOverlapping();
Schedule::command('schools:dispatch ai:purge-stale-uploads')->hourly()->withoutOverlapping();
Schedule::command('schools:dispatch courses:expire-enrollments')->hourly()->withoutOverlapping();
