<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The App Store takes the release notes from CHANGELOG.md, using the first heading that matches
 * `^## (\d+\.\d+\.\d+)` and equals the version in info.xml, or `## [Unreleased]` for a pre-release.
 * Without it the release has no notes.
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

    public function testChangelogHasReleaseNotesForTheVersionOfInfoXml(): void
    {
        $changelog = self::ROOT . '/CHANGELOG.md';
        self::assertFileExists($changelog);

        self::assertTrue(self::changelogHasNotesFor((string) file_get_contents($changelog), $this->infoXmlVersion()));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function changelogCases(): iterable
    {
        $released = "# Changelog\n\n## [Unreleased]\n\n## 1.0.1 – 2026-11-03\n\n### Fixed\n\n- Margin.\n";
        $unreleased = "# Changelog\n\n## [Unreleased]\n\n### Added\n\n- Codes.\n\n## 1.0.1 – 2026-11-03\n\n- Margin.\n";

        yield 'release with a dated entry' => [$released, '1.0.1', true];
        yield 'release without a dated entry' => [$released, '1.0.2', false];
        yield 'release with an undated entry' => ["## 1.0.2\n\n- Margin.\n", '1.0.2', false];
        yield 'pre-release with notes under Unreleased' => [$unreleased, '1.1.0-rc.1', true];
        yield 'pre-release with an empty Unreleased section' => [$released, '1.1.0-rc.1', false];
        yield 'pre-release without an Unreleased section' => ["## 1.0.1 – 2026-11-03\n\n- Margin.\n", '1.1.0-rc.1', false];
        yield 'pre-release with notes as the last section' => ["## [Unreleased]\n\n- Codes.\n", '1.1.0-rc.1', true];
    }

    /**
     * A release needs `## <version> – <date>`. A pre-release (SemVer with `-`) counts as a beta in the
     * App Store, which then takes its notes from `## [Unreleased]`, so that section must not be empty.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('changelogCases')]
    public function testChangelogRules(string $changelog, string $version, bool $expected): void
    {
        self::assertSame($expected, self::changelogHasNotesFor($changelog, $version));
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

    private static function changelogHasNotesFor(string $changelog, string $version): bool
    {
        if (!str_contains($version, '-')) {
            return preg_match('/^## ' . preg_quote($version, '/') . ' – \d{4}-\d{2}-\d{2}$/m', $changelog) === 1;
        }

        if (preg_match('/^## \[Unreleased\]$(.*?)(?=^## |\z)/ms', $changelog, $match) !== 1) {
            return false;
        }

        return trim($match[1]) !== '';
    }

    private function infoXmlVersion(): string
    {
        $info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
        self::assertNotFalse($info);

        return (string) $info->version;
    }
}
