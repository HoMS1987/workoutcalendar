<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Support;

use OCA\WorkoutCalendar\CalDav\CalendarInfo;
use OCA\WorkoutCalendar\CalDav\CalendarObjects;
use OCA\WorkoutCalendar\Calendar\IcsLines;

/** Calendars and objects in memory; behaves like `CalDavBackend` in the points under test. */
final class MemoryCalendarObjects implements CalendarObjects
{
    /** @var array<int, array{owner: string, uri: string, name: string, color: ?string, components: list<string>, trashed: bool}> */
    public array $calendars = [];
    /** @var array<int, array<string, string>> Calendar ID → URI → ICS */
    public array $objects = [];
    /** @var list<string> Executed write operations, e.g. "create:1:garmin-42.ics" */
    public array $writes = [];
    /** Calls of get(), for assertions that no ICS is read. */
    public int $getCalls = 0;
    /** Thrown when deleting a calendar, if set. */
    public ?\Throwable $deleteCalendarFailure = null;
    private int $nextId = 1;

    /** @param list<string> $components */
    public function addCalendar(string $owner, string $uri, string $name, ?string $color = null, array $components = ['VEVENT', 'VTODO'], bool $trashed = false): int
    {
        $id = $this->nextId++;
        $this->calendars[$id] = ['owner' => $owner, 'uri' => $uri, 'name' => $name, 'color' => $color, 'components' => $components, 'trashed' => $trashed];
        $this->objects[$id] = [];

        return $id;
    }

    public function put(int $calendarId, string $uri, string $ics): void
    {
        $this->objects[$calendarId][$uri] = $ics;
    }

    public function ownCalendars(string $uid): array
    {
        $result = [];
        foreach ($this->calendars as $id => $c) {
            if ($c['owner'] === $uid && !$c['trashed'] && in_array('VEVENT', $c['components'], true)) {
                $result[] = new CalendarInfo($id, $c['uri'], $c['name'], $c['color']);
            }
        }

        return $result;
    }

    public function usedCalendarUris(string $uid): array
    {
        $result = [];
        foreach ($this->calendars as $c) {
            if ($c['owner'] === $uid) {
                $result[] = $c['uri'];
            }
        }

        return $result;
    }

    public function createCalendar(string $uid, string $uri, string $name): int
    {
        if (in_array($uri, $this->usedCalendarUris($uid), true)) {
            throw new \RuntimeException("Calendar URI $uri is taken");
        }
        $this->writes[] = "createCalendar:$uri";

        return $this->addCalendar($uid, $uri, $name, null, ['VEVENT']);
    }

    public function deleteCalendar(int $calendarId): void
    {
        if ($this->deleteCalendarFailure !== null) {
            throw $this->deleteCalendarFailure;
        }
        $this->writes[] = "deleteCalendar:$calendarId";
        $this->calendars[$calendarId]['trashed'] = true;
    }

    public function uris(int $calendarId): array
    {
        return array_keys($this->objects[$calendarId] ?? []);
    }

    /** Like `calendarQuery`: events that overlap the time range (start < to, end > from). */
    public function urisInRange(int $calendarId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $result = [];
        foreach ($this->objects[$calendarId] ?? [] as $uri => $ics) {
            $start = self::time($ics, 'DTSTART');
            $end = self::time($ics, 'DTEND') ?? $start;
            if ($start !== null && $start < $to && $end > $from) {
                $result[] = (string)$uri;
            }
        }

        return $result;
    }

    public function get(int $calendarId, array $uris): array
    {
        $this->getCalls++;

        return array_intersect_key($this->objects[$calendarId] ?? [], array_flip($uris));
    }

    public function create(int $calendarId, string $uri, string $ics): void
    {
        if (isset($this->objects[$calendarId][$uri])) {
            throw new \RuntimeException("Object $uri already exists");
        }
        $this->writes[] = "create:$calendarId:$uri";
        $this->objects[$calendarId][$uri] = $ics;
    }

    public function update(int $calendarId, string $uri, string $ics): void
    {
        if (!isset($this->objects[$calendarId][$uri])) {
            throw new \RuntimeException("Object $uri is missing");
        }
        $this->writes[] = "update:$calendarId:$uri";
        $this->objects[$calendarId][$uri] = $ics;
    }

    public function delete(int $calendarId, string $uri): void
    {
        $this->writes[] = "delete:$calendarId:$uri";
        unset($this->objects[$calendarId][$uri]);
    }

    /** Reads only UTC times of the form 20261019T051203Z, which is enough for the tests. */
    private static function time(string $ics, string $name): ?\DateTimeImmutable
    {
        foreach (IcsLines::unfold($ics) as $line) {
            if (IcsLines::name($line) === $name) {
                $time = \DateTimeImmutable::createFromFormat('!Ymd\THis\Z', IcsLines::value($line), new \DateTimeZone('UTC'));

                return $time === false ? null : $time;
            }
        }

        return null;
    }
}
