<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The App Store takes the release notes from CHANGELOG.md, using the first heading that matches
 * `^## (\d+\.\d+\.\d+)` and equals the version in info.xml. Without it the release has no notes.
 */
final class ReleaseMetadataTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testPackageJsonHasTheVersionOfInfoXml(): void
    {
        $package = json_decode((string) file_get_contents(self::ROOT . '/package.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($this->infoXmlVersion(), $package['version']);
    }

    public function testPackageLockHasTheVersionOfInfoXml(): void
    {
        $lock = json_decode((string) file_get_contents(self::ROOT . '/package-lock.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($this->infoXmlVersion(), $lock['version']);
        self::assertSame($this->infoXmlVersion(), $lock['packages']['']['version']);
    }

    public function testChangelogHasADatedEntryForTheVersionOfInfoXml(): void
    {
        $changelog = self::ROOT . '/CHANGELOG.md';
        self::assertFileExists($changelog);

        $version = preg_quote($this->infoXmlVersion(), '/');
        self::assertMatchesRegularExpression(
            '/^## ' . $version . ' – \d{4}-\d{2}-\d{2}$/m',
            (string) file_get_contents($changelog),
        );
    }

    /** The App Store asks for SPDX identifiers for apps targeting Nextcloud 31 or newer. */
    public function testLicenceIsTheSpdxIdentifierOfTheLicenseFile(): void
    {
        $info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
        self::assertNotFalse($info);

        self::assertSame('AGPL-3.0-or-later', (string) $info->licence);
    }

    public function testSummaryAndDescriptionExistInEnglishAndGerman(): void
    {
        $info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
        self::assertNotFalse($info);

        foreach (['summary', 'description'] as $element) {
            $languages = [];
            foreach ($info->{$element} as $text) {
                self::assertNotSame('', trim((string) $text));
                $languages[] = (string) $text['lang'];
            }
            sort($languages);
            self::assertSame(['de', 'en'], $languages, $element);
        }
    }

    /**
     * The App Store loads screenshots from their URL at upload time and rejects files over 2 MiB,
     * so every URL has to point at a file that is checked in on main.
     */
    public function testScreenshotsPointAtCheckedInFilesOnMain(): void
    {
        $info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
        self::assertNotFalse($info);
        self::assertGreaterThan(0, count($info->screenshot));

        $prefix = 'https://raw.githubusercontent.com/HoMS1987/workoutcalendar/main/';
        foreach ($info->screenshot as $screenshot) {
            foreach ([(string) $screenshot, (string) $screenshot['small-thumbnail']] as $url) {
                self::assertStringStartsWith($prefix . 'docs/screenshots/', $url);
                $file = self::ROOT . '/' . substr($url, strlen($prefix));
                self::assertFileExists($file);
                self::assertLessThanOrEqual(2 * 1024 * 1024, filesize($file), $url);
            }
        }
    }

    private function infoXmlVersion(): string
    {
        $info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
        self::assertNotFalse($info);

        return (string) $info->version;
    }
}
