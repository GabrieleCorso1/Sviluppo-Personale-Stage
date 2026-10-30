<?php

namespace App\Support;

class ProjectCalendar
{
    public static function today(): string
    {
        return now(config('projects.calendar_timezone', 'Europe/Rome'))->toDateString();
    }
}
