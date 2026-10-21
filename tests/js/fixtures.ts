import type { Status } from '../../src/types.ts'

interface StatusOverrides {
	setup?: Partial<Status['setup']>
	sync?: Partial<Omit<Status['sync'], 'backfill'>> & { backfill?: Partial<Status['sync']['backfill']> }
	failure?: Status['failure']
	paused?: Status['paused']
}

/** A connected, fully set up status; overrides are merged per section. */
export function makeStatus(overrides: StatusOverrides = {}): Status {
	return {
		setup: {
			step: 'done',
			connected: true,
			maskedEmail: 'a***@example.org',
			calendar: { id: 7, name: 'Garmin-Aktivitäten', createdByApp: true },
			maintenance: null,
			maintenanceFailed: null,
			calendarMissing: false,
			...overrides.setup,
		},
		sync: {
			lastRunAt: 1_760_000_000,
			lastSuccessAt: 1_760_000_000,
			nextRunAt: 1_760_003_600,
			eventCount: 340,
			oldestActivityDate: '2023-03-02',
			...overrides.sync,
			backfill: { running: false, reachedDate: null, pending: false, ...overrides.sync?.backfill },
		},
		failure: overrides.failure ?? null,
		paused: overrides.paused ?? null,
		settings: {
			historyDays: 90,
			intervalMinutes: 60,
			jitterPercent: 10,
			deletionMode: 'keep',
			deletionCheckDays: 30,
		},
	}
}
