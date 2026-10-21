<?php

declare(strict_types=1);

// The app lives in server/apps/workoutcalendar; the server autoloader is registered first.
// That way the OCP classes come from the real server and not from vendor/nextcloud/ocp.
if (!defined('PHPUNIT_RUN')) {
    define('PHPUNIT_RUN', 1);
}

require_once __DIR__ . '/../../../../lib/base.php';
require_once __DIR__ . '/../../vendor/autoload.php';

// The autoloader from vendor/ prepends itself. The server autoloader should keep precedence.
if (isset(\OC::$composerAutoloader)) {
    \OC::$composerAutoloader->unregister();
    \OC::$composerAutoloader->register(true);
}

\OC_App::loadApp('workoutcalendar');
