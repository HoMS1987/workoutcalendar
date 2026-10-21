export type SetupStep = 'garmin' | 'calendar' | 'history' | 'done'
export type Cleanup = 'none' | 'events' | 'calendar'
export type RunOutcome = 'done' | 'skipped' | 'paused' | 'notConnected' | 'calendarMissing' | 'notReady'
export type DeletionMode = 'keep' | 'mark' | 'delete'

export interface CalendarOption {
	id: number
	name: string
	color: string | null
}

export interface Settings {
	historyDays: number | null
	intervalMinutes: number
	jitterPercent: number
	deletionMode: DeletionMode
	deletionCheckDays: number
}

/** Mirrors StatusService::status. Times are Unix seconds. */
export interface Status {
	setup: {
		step: SetupStep
		connected: boolean
		maskedEmail: string | null
		calendar: { id: number, name: string | null, createdByApp: boolean } | null
		maintenance: string | null
		maintenanceFailed: string | null
		calendarMissing: boolean
	}
	sync: {
		lastRunAt: number | null
		lastSuccessAt: number | null
		nextRunAt: number | null
		eventCount: number
		oldestActivityDate: string | null
		/** pending: the import for the current calendar is requested but has not had its first run yet. */
		backfill: { running: boolean, reachedDate: string | null, pending: boolean }
	}
	failure: { category: string, since: number | null, count: number } | null
	paused: string | null
	settings: Settings
}
