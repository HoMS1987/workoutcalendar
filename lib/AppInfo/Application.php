<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap
{
    public const APP_ID = 'workoutcalendar';

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void
    {
        // Notifier und Dienste folgen in späteren Schritten.
    }

    public function boot(IBootContext $context): void
    {
    }
}
