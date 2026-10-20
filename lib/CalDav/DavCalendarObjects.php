<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\CalDav;

use OCA\DAV\CalDAV\CalDavBackend;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\Forbidden;

/**
 * `CalendarObjects` über das interne `CalDavBackend` der DAV-App (ADR 0005). Einzige Klasse der App,
 * die `OCA\DAV` und Sabre verwendet. Abgesichert durch den Integrationstest gegen Nextcloud 33 bis 35.
 */
final class DavCalendarObjects implements CalendarObjects
{
    private const COMPONENTS = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
    private const OWNER = '{http://owncloud.org/ns}owner-principal';
    private const DELETED_AT = '{http://nextcloud.com/ns}deleted-at';
    private const DISPLAY_NAME = '{DAV:}displayname';
    private const COLOR = '{http://apple.com/ns/ical/}calendar-color';
    /** Der Geburtstagskalender wird von der DAV-App erzeugt und neu geschrieben. */
    private const BIRTHDAY_URI = 'contact_birthdays';

    public function __construct(
        private readonly CalDavBackend $backend,
    ) {
    }

    public function ownCalendars(string $uid): array
    {
        $result = [];
        foreach ($this->owned($uid) as $row) {
            $components = $row[self::COMPONENTS] ?? null;
            if (($row[self::DELETED_AT] ?? null) !== null
                || $row['uri'] === self::BIRTHDAY_URI
                || !$components instanceof SupportedCalendarComponentSet
                || !in_array('VEVENT', $components->getValue(), true)) {
                continue;
            }
            $name = $row[self::DISPLAY_NAME] ?? null;
            $color = $row[self::COLOR] ?? null;
            $result[] = new CalendarInfo(
                (int)$row['id'],
                (string)$row['uri'],
                is_string($name) && $name !== '' ? $name : (string)$row['uri'],
                is_string($color) && $color !== '' ? $color : null,
            );
        }

        return $result;
    }

    public function usedCalendarUris(string $uid): array
    {
        return array_map(static fn (array $row): string => (string)$row['uri'], $this->owned($uid));
    }

    public function createCalendar(string $uid, string $uri, string $name): int
    {
        return (int)$this->backend->createCalendar(self::principal($uid), $uri, [
            self::DISPLAY_NAME => $name,
            self::COMPONENTS => new SupportedCalendarComponentSet(['VEVENT']),
        ]);
    }

    public function deleteCalendar(int $calendarId): void
    {
        $this->backend->deleteCalendar($calendarId, false);
    }

    public function uris(int $calendarId): array
    {
        return array_map(static fn (array $row): string => (string)$row['uri'], $this->backend->getCalendarObjects($calendarId));
    }

    public function urisInRange(int $calendarId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [[
                'name' => 'VEVENT',
                'comp-filters' => [],
                'prop-filters' => [],
                'is-not-defined' => false,
                'time-range' => [
                    'start' => \DateTime::createFromImmutable($from),
                    'end' => \DateTime::createFromImmutable($to),
                ],
            ]],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => false,
        ];

        return array_values(array_map('strval', $this->backend->calendarQuery($calendarId, $filters)));
    }

    public function get(int $calendarId, array $uris): array
    {
        $result = [];
        foreach ($this->backend->getMultipleCalendarObjects($calendarId, $uris) as $row) {
            $result[(string)$row['uri']] = (string)$row['calendardata'];
        }

        return $result;
    }

    public function create(int $calendarId, string $uri, string $ics): void
    {
        $this->backend->createCalendarObject($calendarId, $uri, $ics);
    }

    public function update(int $calendarId, string $uri, string $ics): void
    {
        $this->backend->updateCalendarObject($calendarId, $uri, $ics);
    }

    public function delete(int $calendarId, string $uri): void
    {
        try {
            $this->backend->deleteCalendarObject($calendarId, $uri);
        } catch (Forbidden) {
            // WHY: Der Papierkorb benennt das Objekt in `<name>-deleted.ics` um. Liegt dort schon eine
            // ältere Fassung desselben Termins, lehnt das Backend ab. Dann wird endgültig gelöscht.
            $this->backend->deleteCalendarObject($calendarId, $uri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
        }
    }

    /** @return list<array<string, mixed>> Kalender der Person ohne die mit ihr geteilten */
    private function owned(string $uid): array
    {
        $principal = self::principal($uid);

        return array_values(array_filter(
            $this->backend->getCalendarsForUser($principal),
            // Geteilte Kalender tragen die Person in `principaluri`, den Eigentümer aber in `owner-principal`.
            static fn (array $row): bool => ($row[self::OWNER] ?? $row['principaluri'] ?? null) === $principal,
        ));
    }

    private static function principal(string $uid): string
    {
        return "principals/users/$uid";
    }
}
