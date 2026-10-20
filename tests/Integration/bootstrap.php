<?php

declare(strict_types=1);

// Die App liegt in server/apps/workoutcalendar, der Server-Autoloader wird zuerst registriert.
// So stammen die OCP-Klassen aus dem echten Server und nicht aus vendor/nextcloud/ocp.
if (!defined('PHPUNIT_RUN')) {
    define('PHPUNIT_RUN', 1);
}

require_once __DIR__ . '/../../../../lib/base.php';
require_once __DIR__ . '/../../vendor/autoload.php';

// Der Autoloader aus vendor/ registriert sich vorn. Der Server-Autoloader soll Vorrang behalten.
if (isset(\OC::$composerAutoloader)) {
    \OC::$composerAutoloader->unregister();
    \OC::$composerAutoloader->register(true);
}

\OC_App::loadApp('workoutcalendar');
