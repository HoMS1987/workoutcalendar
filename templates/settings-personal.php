<?php

declare(strict_types=1);

use OCA\WorkoutCalendar\AppInfo\Application;
use OCP\Util;

Util::addScript(Application::APP_ID, 'workoutcalendar-settings');
Util::addStyle(Application::APP_ID, 'workoutcalendar-settings');

?>

<div id="workoutcalendar-settings"></div>
