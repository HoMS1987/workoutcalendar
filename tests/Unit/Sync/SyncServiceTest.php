<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Sync;

use OCA\WorkoutCalendar\Calendar\ActivityEventMapper;
use OCA\WorkoutCalendar\Garmin\Auth\Authenticator;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\DiTokenService;
use OCA\WorkoutCalendar\Garmin\Auth\LoginStrategy;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use OCA\WorkoutCalendar\Sync\DeletionMode;
use OCA\WorkoutCalendar\Sync\SyncService;
use OCA\WorkoutCalendar\Sync\SyncSettings;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\Jwt;
use OCA\WorkoutCalendar\Tests\Support\MemoryCalendarTarget;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use OCA\WorkoutCalendar\Tests\Support\MemoryTokenStore;
use PHPUnit\Framework\TestCase;

final class SyncServiceTest extends TestCase
{
    private const NOW = '2026-10-20 12:00:00';
    private const API = 'https://connectapi.garmin.com/activitylist-service/activities/search/activities?';

    private FakeHttpClient $http;
    private MemoryCalendarTarget $calendar;
    private MemoryLogger $logger;
    private SyncState $state;
    private \DateTimeImmutable $now;
    /** Wenn gesetzt: keine gespeicherten Tokens, der Login wirft diesen Fehler. */
    private ?FailureCategory $loginFailure = null;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->calendar = new MemoryCalendarTarget();
        $this->logger = new MemoryLogger();
        $this->state = new SyncState();
        $this->now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    private function sync(SyncSettings $settings = new SyncSettings()): \OCA\WorkoutCalendar\Sync\SyncResult
    {
        $tokens = new MemoryTokenStore($this->loginFailure !== null
            ? null
            : new GarminTokens(Jwt::make(['exp' => $this->now->getTimestamp() + 3600]), 'r', 'C'));
        $strategies = [];
        if ($this->loginFailure !== null) {
            $category = $this->loginFailure;
            $strategies[] = new class ($category) implements LoginStrategy {
                public function __construct(private readonly FailureCategory $category)
                {
                }

                public function name(): string
                {
                    return 'fake';
                }

                public function login(\OCA\WorkoutCalendar\Garmin\Auth\Credentials $credentials): GarminTokens
                {
                    throw new GarminException($this->category, 'fake.login', 'abgelehnt');
                }
            };
        }
        $client = new GarminClient(
            $this->http,
            new Authenticator($strategies, $this->logger),
            new DiTokenService($this->http),
            $tokens,
            new Credentials('a@b.de', 'pw'),
            $this->logger,
            fn (): int => $this->now->getTimestamp(),
        );

        return (new SyncService($client, new ActivityEventMapper(), $this->calendar, $this->logger))->run($settings, $this->state, $this->now);
    }

    /** @return array<string, mixed> */
    private static function activity(int $id, string $start, string $name = 'Abendlauf'): array
    {
        return [
            'activityId' => $id,
            'activityName' => $name,
            'startTimeGMT' => $start,
            'startTimeLocal' => $start,
            'duration' => 1800.0,
            'distance' => 5000.0,
            'activityType' => ['typeKey' => 'running'],
        ];
    }

    /** @param list<array<string, mixed>> $activities */
    private static function page(array $activities): HttpResponse
    {
        return FakeHttpClient::json(200, $activities);
    }

    /**
     * Eine volle Seite: $count Aktivitäten, eine pro Tag rückwärts ab $newestDate.
     *
     * @return list<array<string, mixed>>
     */
    private static function days(int $firstId, string $newestDate, int $count): array
    {
        $date = new \DateTimeImmutable($newestDate);
        $activities = [];
        for ($i = 0; $i < $count; $i++) {
            $activities[] = self::activity($firstId + $i, $date->modify("-$i days")->format('Y-m-d') . ' 07:00:00');
        }

        return $activities;
    }

    /** @return list<string> */
    private function urls(): array
    {
        return array_map(static fn ($r): string => str_replace(self::API, '', $r->url), $this->http->requests);
    }

    private function initialized(): void
    {
        $this->state->initialized = true;
        $this->state->backfillDone = true;
        $this->state->lastSuccessAt = $this->now->getTimestamp() - 1800;
    }

    private function storeEvent(int $id, string $start): void
    {
        $this->calendar->create((new ActivityEventMapper())->map(self::activity($id, $start)));
        $this->calendar->writes = [];
    }

    public function testFirstRunImportsRecentWindowAndHistory(): void
    {
        $this->http->queue(
            self::page([self::activity(1, '2026-10-19 07:00:00'), self::activity(2, '2026-10-01 18:00:00')]),
            self::page([self::activity(3, '2026-08-15 09:00:00')]),
        );

        $result = $this->sync();

        self::assertSame([
            'startDate=2026-09-19&start=0&limit=20',
            'startDate=2026-07-22&endDate=2026-09-20&start=0&limit=20',
        ], $this->urls());
        self::assertSame(['create:1', 'create:2', 'create:3'], $this->calendar->writes);
        self::assertSame(3, $result->created);
        self::assertSame(1, $result->backfillPages);
        self::assertNull($result->failure);
        self::assertTrue($this->state->initialized);
        self::assertTrue($this->state->backfillDone);
        self::assertSame($this->now->getTimestamp(), $this->state->lastSuccessAt);
    }

    public function testUnchangedActivitiesAreNotWrittenAgain(): void
    {
        $this->initialized();
        $this->storeEvent(1, '2026-10-19 07:00:00');
        $this->http->queue(self::page([self::activity(1, '2026-10-19 07:00:00')]));

        $result = $this->sync();

        self::assertSame([], $this->calendar->writes);
        self::assertSame(1, $result->unchanged);
        self::assertSame([], $this->logger->byLevel('info'), 'Läufe ohne Änderung loggen nur auf debug');
    }

    public function testChangedActivityIsUpdated(): void
    {
        $this->initialized();
        $this->storeEvent(1, '2026-10-19 07:00:00');
        $this->http->queue(self::page([self::activity(1, '2026-10-19 07:00:00', 'Umbenannt')]));

        $result = $this->sync();

        self::assertSame(['update:1'], $this->calendar->writes);
        self::assertSame(1, $result->updated);
        self::assertCount(1, $this->logger->byLevel('info'));
    }

    public function testRecentWindowGrowsWhenLastSuccessIsOlder(): void
    {
        $this->initialized();
        $this->state->lastSuccessAt = $this->now->modify('-60 days')->getTimestamp();
        $this->http->queue(self::page([]));

        $this->sync();

        self::assertSame(['startDate=2026-08-20&start=0&limit=20'], $this->urls());
    }

    public function testRecentWindowIsPaginated(): void
    {
        $this->initialized();
        $this->http->queue(self::page(self::days(1, '2026-10-20', 20)), self::page([self::activity(99, '2026-09-25 07:00:00')]));

        $result = $this->sync();

        self::assertSame(['startDate=2026-09-19&start=0&limit=20', 'startDate=2026-09-19&start=20&limit=20'], $this->urls());
        self::assertSame(21, $result->created);
    }

    public function testActivityDeletedInGarminIsMarkedOnce(): void
    {
        $this->initialized();
        $this->storeEvent(1, '2026-10-19 07:00:00');
        $this->storeEvent(9, '2026-10-05 07:00:00');
        $this->http->queue(self::page([self::activity(1, '2026-10-19 07:00:00')]), self::page([self::activity(1, '2026-10-19 07:00:00')]));

        $first = $this->sync();
        $second = $this->sync();

        self::assertSame(['mark:9'], $this->calendar->writes);
        self::assertSame(1, $first->markedDeleted);
        self::assertSame(0, $second->markedDeleted);
    }

    public function testDeleteModeRemovesEvent(): void
    {
        $this->initialized();
        $this->storeEvent(9, '2026-10-05 07:00:00');
        $this->http->queue(self::page([]));

        $result = $this->sync(new SyncSettings(deletionMode: DeletionMode::Delete));

        self::assertSame(['delete:9'], $this->calendar->writes);
        self::assertSame(1, $result->deleted);
    }

    public function testKeepModeLeavesEventUntouched(): void
    {
        $this->initialized();
        $this->storeEvent(9, '2026-10-05 07:00:00');
        $this->http->queue(self::page([]));

        $this->sync(new SyncSettings(deletionMode: DeletionMode::Keep));

        self::assertSame([], $this->calendar->writes);
    }

    public function testDeletionCheckIgnoresEventsBeforeCheckWindow(): void
    {
        $this->initialized();
        $this->storeEvent(9, '2026-09-19 07:00:00');
        $this->http->queue(self::page([]));

        $this->sync();

        self::assertSame([], $this->calendar->writes, 'Liegt vor now - 30 Tage, Garmin-Abfrage deckt das nicht sicher ab');
    }

    public function testMarkedEventIsRestoredWhenActivityReappears(): void
    {
        $this->initialized();
        $this->storeEvent(9, '2026-10-05 07:00:00');
        $this->calendar->marked['9'] = true;
        $this->http->queue(self::page([self::activity(9, '2026-10-05 07:00:00')]));

        $result = $this->sync();

        self::assertSame(['update:9'], $this->calendar->writes);
        self::assertSame(1, $result->updated);
    }

    public function testCompleteHistoryRunsInStagesAcrossRuns(): void
    {
        $settings = new SyncSettings(historyDays: null);
        $this->http->queue(self::page([]));
        $newest = new \DateTimeImmutable('2026-09-20');
        for ($page = 0; $page < SyncSettings::BACKFILL_PAGES_PER_RUN; $page++) {
            $this->http->queue(self::page(self::days(100 + 20 * $page, $newest->modify('-' . (20 * $page) . ' days')->format('Y-m-d'), 20)));
        }

        $first = $this->sync($settings);

        self::assertSame(SyncSettings::BACKFILL_PAGES_PER_RUN, $first->backfillPages);
        self::assertFalse($this->state->backfillDone);
        self::assertSame('endDate=2026-09-20&start=0&limit=20', $this->urls()[1], 'Komplette Historie: ohne startDate');
        self::assertSame('endDate=2026-09-01&start=0&limit=20', $this->urls()[2], 'Nächste Seite endet am ältesten Datum der vorherigen');
        $before = $this->state->backfillBefore;

        $this->http->requests = [];
        $this->http->queue(self::page([]), self::page([self::activity(500, '2025-01-01 07:00:00')]));
        $second = $this->sync($settings);

        self::assertSame("endDate=$before&start=0&limit=20", $this->urls()[1]);
        self::assertSame(1, $second->backfillPages);
        self::assertTrue($this->state->backfillDone);
    }

    public function testBackfillMakesProgressWhenPageCoversSingleDay(): void
    {
        $settings = new SyncSettings(historyDays: null);
        $sameDay = [];
        for ($i = 0; $i < SyncSettings::PAGE_SIZE; $i++) {
            $sameDay[] = self::activity(200 + $i, '2026-09-20 0' . ($i % 10) . ':00:00');
        }
        $this->http->queue(self::page([]), self::page($sameDay), self::page([]));

        $this->sync($settings);

        self::assertSame('endDate=2026-09-19&start=0&limit=20', $this->urls()[2]);
    }

    public function testShortHistoryNeedsNoBackfill(): void
    {
        $this->http->queue(self::page([]));

        $result = $this->sync(new SyncSettings(historyDays: 7));

        self::assertCount(1, $this->http->requests);
        self::assertSame(0, $result->backfillPages);
        self::assertTrue($this->state->backfillDone);
    }

    public function testGarminFailureIsRecordedAndNothingIsDeleted(): void
    {
        $this->initialized();
        $lastSuccess = $this->state->lastSuccessAt;
        $this->storeEvent(9, '2026-10-05 07:00:00');
        $this->http->queue(new HttpResponse(429, [], 'Too Many Requests'));

        $result = $this->sync();

        self::assertSame(FailureCategory::Blocked, $result->failure);
        self::assertSame([], $this->calendar->writes);
        self::assertSame($lastSuccess, $this->state->lastSuccessAt);
        self::assertSame(1, $this->state->failureCount);
        self::assertCount(1, $this->logger->byLevel('warning'));
    }

    public function testNetworkFailureIsRecorded(): void
    {
        $this->initialized();
        $this->http->queue(new NetworkException('Could not resolve host'));

        self::assertSame(FailureCategory::Network, $this->sync()->failure);
        self::assertSame('NETWORK', $this->state->failureCategory);
    }

    public function testSuccessAfterFailureClearsFailureState(): void
    {
        $this->initialized();
        $this->http->queue(new HttpResponse(429, [], ''), self::page([]));

        $this->sync();
        $this->sync();

        self::assertSame(0, $this->state->failureCount);
        self::assertSame('Garmin-Sync wieder erfolgreich', $this->logger->byLevel('info')[0]['message']);
    }

    public function testBackfillFailureKeepsRecentProgress(): void
    {
        $this->http->queue(self::page([self::activity(1, '2026-10-19 07:00:00')]), new HttpResponse(503, [], ''));

        $result = $this->sync();

        self::assertSame(1, $result->created);
        self::assertSame(FailureCategory::ServerError, $result->failure);
        self::assertSame($this->now->getTimestamp(), $this->state->lastSuccessAt);
        self::assertFalse($this->state->backfillDone);
        self::assertSame('2026-09-20', $this->state->backfillBefore);
    }

    public function testUnreadableActivityIsSkipped(): void
    {
        $this->initialized();
        $this->http->queue(self::page([self::activity(1, '2026-10-19 07:00:00'), ['activityName' => 'ohne ID']]));

        $result = $this->sync();

        self::assertSame(1, $result->created);
        self::assertSame(1, $result->skipped);
        self::assertNull($result->failure);
    }

    public function testRunTimesAreRecorded(): void
    {
        $this->initialized();
        $this->http->queue(self::page([]));

        $this->sync(new SyncSettings(jitterPercent: 0));

        self::assertSame($this->now->getTimestamp(), $this->state->lastRunAt);
        self::assertSame($this->now->getTimestamp() + 30 * 60, $this->state->nextRunAt);
    }

    public function testInvalidCredentialsPauseTheSync(): void
    {
        $this->loginFailure = FailureCategory::InvalidCredentials;

        $this->sync();

        self::assertSame('INVALID_CREDENTIALS', $this->state->pausedFor);
    }

    public function testMfaRequiredPausesTheSync(): void
    {
        $this->loginFailure = FailureCategory::MfaRequired;

        $this->sync();

        self::assertSame('MFA_REQUIRED', $this->state->pausedFor);
    }

    public function testNetworkErrorDoesNotPause(): void
    {
        $this->initialized();
        $this->http->queue(new NetworkException('Could not resolve host'));

        $this->sync();

        self::assertNull($this->state->pausedFor);
    }

    public function testRunningBackfillSchedulesNextRunSoon(): void
    {
        $settings = new SyncSettings(historyDays: null);
        $this->http->queue(self::page([]));
        for ($page = 0; $page < SyncSettings::BACKFILL_PAGES_PER_RUN; $page++) {
            $this->http->queue(self::page(self::days(100 + 20 * $page, (new \DateTimeImmutable('2026-09-20'))->modify('-' . (20 * $page) . ' days')->format('Y-m-d'), 20)));
        }

        $this->sync($settings);

        self::assertFalse($this->state->backfillDone);
        self::assertSame($this->now->getTimestamp() + SyncSettings::BACKFILL_NEXT_RUN_SECONDS, $this->state->nextRunAt);
    }

    public function testFinishedBackfillUsesInterval(): void
    {
        $this->http->queue(self::page([]));

        $this->sync(new SyncSettings(historyDays: 7));

        $delta = $this->state->nextRunAt - $this->now->getTimestamp();
        self::assertGreaterThanOrEqual(1440, $delta);
        self::assertLessThanOrEqual(2160, $delta);
    }

    public function testOldestActivityDateTracksImportedActivities(): void
    {
        $this->initialized();
        $this->http->queue(self::page([self::activity(1, '2026-10-01 07:00:00'), self::activity(2, '2026-09-15 07:00:00')]));
        $this->sync();
        self::assertSame('2026-09-15', $this->state->oldestActivityDate);

        $this->http->queue(self::page([self::activity(3, '2026-10-18 07:00:00')]));
        $this->sync();
        self::assertSame('2026-09-15', $this->state->oldestActivityDate);
    }
}
