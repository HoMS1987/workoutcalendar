<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\AppInfo;

use OCA\WorkoutCalendar\CalDav\CalendarObjects;
use OCA\WorkoutCalendar\CalDav\DavCalendarObjects;
use OCA\WorkoutCalendar\Notification\Notifier;
use OCA\WorkoutCalendar\Service\CurlHttpClientProvider;
use OCA\WorkoutCalendar\Service\HttpClientProvider;
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
        $context->registerNotifierService(Notifier::class);
        $context->registerServiceAlias(HttpClientProvider::class, CurlHttpClientProvider::class);
        // DavCalendarObjects gets the container's CalDavBackend (not the legacy backend).
        $context->registerServiceAlias(CalendarObjects::class, DavCalendarObjects::class);
    }

    public function boot(IBootContext $context): void
    {
    }
}
