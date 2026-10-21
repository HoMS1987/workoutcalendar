<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Settings;

use OCA\WorkoutCalendar\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/** Personal settings form; the page itself is rendered by the Vue app. */
final class Personal implements ISettings
{
    public function getForm(): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, 'settings-personal', [], '');
    }

    public function getSection(): string
    {
        return Section::ID;
    }

    public function getPriority(): int
    {
        return 10;
    }
}
