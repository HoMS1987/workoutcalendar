<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Integration\Settings;

use OCA\WorkoutCalendar\Settings\Personal;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use OCP\Settings\IManager;
use PHPUnit\Framework\TestCase;

/**
 * Runs inside a real Nextcloud server (see tests/Integration/bootstrap.php).
 */
final class SettingsRegistrationTest extends TestCase
{
    private ?IUser $user = null;

    protected function setUp(): void
    {
        // The core settings sections need a signed-in person to be built.
        $uid = 'wc-settings-' . bin2hex(random_bytes(4));
        $user = Server::get(IUserManager::class)->createUser($uid, 'Wc-' . bin2hex(random_bytes(16)) . '!');
        self::assertInstanceOf(IUser::class, $user);
        $this->user = $user;
        Server::get(IUserSession::class)->setUser($user);
    }

    protected function tearDown(): void
    {
        Server::get(IUserSession::class)->setUser(null);
        $this->user?->delete();
    }

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
