<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Integration\Settings;

use OCA\WorkoutCalendar\Settings\Personal;
use OCP\Server;
use OCP\Settings\IManager;
use PHPUnit\Framework\TestCase;

/**
 * Runs inside a real Nextcloud server (see tests/Integration/bootstrap.php).
 */
final class SettingsRegistrationTest extends TestCase
{
    public function testPersonalSectionAndFormAreRegistered(): void
    {
        $manager = Server::get(IManager::class);

        $sectionIds = [];
        foreach ($manager->getPersonalSections() as $sections) {
            foreach ($sections as $section) {
                $sectionIds[] = $section->getID();
            }
        }
        self::assertContains('workoutcalendar', $sectionIds);

        $forms = [];
        foreach ($manager->getPersonalSettings('workoutcalendar') as $settings) {
            foreach ($settings as $setting) {
                $forms[] = $setting::class;
            }
        }
        self::assertContains(Personal::class, $forms);
    }
}
