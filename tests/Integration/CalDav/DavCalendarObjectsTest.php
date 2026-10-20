<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Integration\CalDav;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\Calendar;
use OCA\WorkoutCalendar\CalDav\CalendarInfo;
use OCA\WorkoutCalendar\CalDav\DavCalendarObjects;
use OCA\WorkoutCalendar\CalDav\DavCalendarTarget;
use OCA\WorkoutCalendar\Calendar\CalendarEvent;
use OCA\WorkoutCalendar\Calendar\DeletedMarker;
use OCA\WorkoutCalendar\Calendar\IcsReader;
use OCA\WorkoutCalendar\Calendar\IcsSerializer;
use OCA\WorkoutCalendar\Sync\StoredEvent;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Läuft innerhalb eines echten Nextcloud-Servers (siehe tests/Integration/bootstrap.php). */
final class DavCalendarObjectsTest extends TestCase
{
    private const ACCOUNT = '0123456789abcdef';

    private CalDavBackend $backend;
    private DavCalendarObjects $objects;
    /** @var list<IUser> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->backend = Server::get(CalDavBackend::class);
        $this->objects = new DavCalendarObjects($this->backend);
    }

    protected function tearDown(): void
    {
        // Das Löschen der Person entfernt über die DAV-App auch ihre Kalender.
        foreach ($this->users as $user) {
            $user->delete();
        }
    }

    private function user(string $prefix): string
    {
        $uid = $prefix . bin2hex(random_bytes(4));
        $user = Server::get(IUserManager::class)->createUser($uid, 'Wc-' . bin2hex(random_bytes(16)) . '!');
        self::assertInstanceOf(IUser::class, $user);
        $this->users[] = $user;

        return $uid;
    }

    private static function event(string $id, string $start): CalendarEvent
    {
        $begin = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));

        return new CalendarEvent(
            "garmin-$id",
            'Laufen – 7,6 km',
            'Dauer: 42:10 min',
            $begin,
            $begin->modify('+42 minutes'),
            "https://connect.garmin.com/modern/activity/$id",
            $id,
            ['Garmin', 'Laufen'],
        );
    }

    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    /** @return list<int> */
    private function ownIds(string $uid): array
    {
        return array_map(static fn (CalendarInfo $c): int => $c->id, $this->objects->ownCalendars($uid));
    }

    public function testRoundTripAgainstRealBackend(): void
    {
        $anna = $this->user('wc-anna-');
        $bert = $this->user('wc-bert-');
        $reader = new IcsReader();
        $serializer = new IcsSerializer();
        $now = self::utc('2026-10-20 08:00:00');

        $id = $this->objects->createCalendar($anna, 'workoutcalendar', 'Garmin-Aktivitäten');
        self::assertContainsEquals(new CalendarInfo($id, 'workoutcalendar', 'Garmin-Aktivitäten', null), $this->objects->ownCalendars($anna));

        $ics = $serializer->serialize(self::event('42', '2026-10-10 06:00:00'), $now, self::ACCOUNT);
        $this->objects->create($id, 'garmin-42.ics', $ics);
        $this->objects->create($id, 'garmin-43.ics', $serializer->serialize(self::event('43', '2026-09-01 06:00:00'), $now, self::ACCOUNT));

        $uris = $this->objects->uris($id);
        sort($uris);
        self::assertSame(['garmin-42.ics', 'garmin-43.ics'], $uris);
        self::assertSame(['garmin-42.ics' => $ics], $this->objects->get($id, ['garmin-42.ics', 'fehlt.ics']));

        $this->objects->update($id, 'garmin-42.ics', (new DeletedMarker())->apply($ics, $now));
        self::assertTrue($reader->isMarkedDeleted($this->objects->get($id, ['garmin-42.ics'])['garmin-42.ics']));

        self::assertSame(['garmin-42.ics'], $this->objects->urisInRange($id, self::utc('2026-10-01'), self::utc('2026-10-20')));

        // Zweimal löschen: Beim zweiten Mal liegt garmin-42-deleted.ics schon im Papierkorb.
        $this->objects->delete($id, 'garmin-42.ics');
        $this->objects->create($id, 'garmin-42.ics', $ics);
        $this->objects->delete($id, 'garmin-42.ics');
        self::assertSame(['garmin-43.ics'], $this->objects->uris($id));

        // Ein mit Anna geteilter Kalender von Bert gehört nicht zu Annas Kalendern.
        $shared = (int)$this->backend->createCalendar("principals/users/$bert", 'bert-sport', ['{DAV:}displayname' => 'Bert Sport']);
        $calendar = new Calendar(
            $this->backend,
            $this->backend->getCalendarById($shared) ?? [],
            Server::get(IFactory::class)->get('dav'),
            Server::get(IConfig::class),
            Server::get(LoggerInterface::class),
        );
        $this->backend->updateShares($calendar, [['href' => "principal:principals/users/$anna", 'readOnly' => false]], []);
        self::assertContains(
            $shared,
            array_map(static fn (array $c): int => (int)$c['id'], $this->backend->getCalendarsForUser("principals/users/$anna")),
            'Vorbedingung: Die Freigabe ist bei Anna angekommen',
        );
        self::assertNotContains($shared, $this->ownIds($anna));
        self::assertContains($shared, $this->ownIds($bert));
        self::assertNotContains($id, $this->ownIds($bert));

        $this->objects->deleteCalendar($id);
        self::assertNotContains($id, $this->ownIds($anna));
        self::assertContains('workoutcalendar', $this->objects->usedCalendarUris($anna));
    }

    public function testTargetAgainstRealBackend(): void
    {
        $anna = $this->user('wc-anna-');
        $id = $this->objects->createCalendar($anna, 'workoutcalendar', 'Garmin-Aktivitäten');
        $target = new DavCalendarTarget(
            $this->objects,
            $id,
            self::ACCOUNT,
            new IcsSerializer(),
            new IcsReader(),
            new DeletedMarker(),
            static fn (): \DateTimeImmutable => self::utc('2026-10-20 08:00:00'),
        );

        $target->create(self::event('42', '2026-10-10 06:00:00'));
        $target->create(self::event('43', '2026-09-30 23:50:00'));
        $target->markDeleted('42');

        self::assertEquals(
            ['42' => new StoredEvent('42', self::event('42', '2026-10-10 06:00:00')->fingerprint(), true)],
            $target->find(['42']),
        );
        self::assertSame(['42'], array_map('strval', array_keys($target->inRange(self::utc('2026-10-01'), self::utc('2026-10-20')))));

        $target->delete('42');
        self::assertSame([], $target->find(['42']));
    }
}
