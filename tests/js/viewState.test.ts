import type { Status } from '../../src/types.ts'
import type { View } from '../../src/viewState.ts'

import { describe, expect, it } from 'vitest'
import { deriveView, hintSeverity } from '../../src/viewState.ts'
import { makeStatus } from './fixtures.ts'

const noCalendar = null
const calendar = { id: 7, name: 'Garmin-Aktivitäten', createdByApp: true }

describe('deriveView, one row per row of the state table', () => {
	// name, status, view, hints, locked (calendar change and cleanup), polling
	const rows: Array<[string, Status, View, string[], boolean, boolean]> = [
		['any step, not connected', makeStatus({ setup: { step: 'history', connected: false, calendar } }), 'login', [], false, false],
		['garmin, not connected, maintenance move', makeStatus({ setup: { step: 'garmin', connected: false, calendar: noCalendar, maintenance: 'move' } }), 'login', ['maintenanceMove'], true, true],
		['garmin, not connected, maintenance remove', makeStatus({ setup: { step: 'garmin', connected: false, calendar: noCalendar, maintenance: 'remove' } }), 'login', ['maintenanceRemove'], true, true],
		['garmin, not connected, maintenanceFailed move', makeStatus({ setup: { step: 'garmin', connected: false, calendar: noCalendar, maintenanceFailed: 'move' } }), 'login', ['maintenanceFailedMove'], false, false],
		['garmin, not connected, maintenanceFailed remove', makeStatus({ setup: { step: 'garmin', connected: false, calendar: noCalendar, maintenanceFailed: 'remove' } }), 'login', ['maintenanceFailedRemove'], false, false],
		['calendar, calendar not missing', makeStatus({ setup: { step: 'calendar', calendar: noCalendar } }), 'calendar', [], false, false],
		['calendar, calendar missing', makeStatus({ setup: { step: 'calendar', calendar: noCalendar, calendarMissing: true } }), 'calendar', ['calendarMissing'], false, false],
		['calendar, maintenanceFailed move', makeStatus({ setup: { step: 'calendar', calendar: noCalendar, maintenanceFailed: 'move' } }), 'calendar', ['maintenanceFailedMove'], false, false],
		['calendar, maintenanceFailed remove', makeStatus({ setup: { step: 'calendar', calendar: noCalendar, maintenanceFailed: 'remove' } }), 'calendar', ['maintenanceFailedRemove'], false, false],
		['history', makeStatus({ setup: { step: 'history' } }), 'history', [], false, false],
		['done, nothing pending', makeStatus(), 'overview', [], false, false],
		['done, maintenance move', makeStatus({ setup: { maintenance: 'move' } }), 'overview', ['maintenanceMove'], true, true],
		['done, maintenance remove', makeStatus({ setup: { maintenance: 'remove' } }), 'overview', ['maintenanceRemove'], true, true],
		['done, unknown maintenance value', makeStatus({ setup: { maintenance: 'rebuild' } }), 'overview', ['maintenanceOther'], true, true],
		['done, maintenanceFailed move', makeStatus({ setup: { maintenanceFailed: 'move' } }), 'overview', ['maintenanceFailedMove'], false, false],
		['done, maintenanceFailed remove', makeStatus({ setup: { maintenanceFailed: 'remove' } }), 'overview', ['maintenanceFailedRemove'], false, false],
	]

	it.each(rows)('%s', (_name, status, view, hints, locked, polling) => {
		const state = deriveView(status, false)

		expect(state.view).toBe(view)
		expect(state.hints).toEqual(hints)
		expect(state.locks).toEqual({ calendarChange: locked, cleanup: locked })
		expect(state.polling).toBe(polling)
	})
})

describe('deriveView, rules below the table', () => {
	it('shows the done step when the person just finished the wizard', () => {
		expect(deriveView(makeStatus(), true).view).toBe('done')
	})

	it('ignores wizardFinished for every other step', () => {
		expect(deriveView(makeStatus({ setup: { step: 'history' } }), true).view).toBe('history')
		expect(deriveView(makeStatus({ setup: { connected: false } }), true).view).toBe('login')
	})

	it('falls back to the login form when connected but still at the garmin step', () => {
		expect(deriveView(makeStatus({ setup: { step: 'garmin', connected: true } }), false).view).toBe('login')
	})

	it('adds the paused hint in any step', () => {
		expect(deriveView(makeStatus({ paused: 'INVALID_CREDENTIALS' }), false).hints).toEqual(['paused'])
	})

	it('adds the failure hint', () => {
		const failure = { category: 'NETWORK', since: 1_760_000_000, count: 3 }
		expect(deriveView(makeStatus({ failure }), false).hints).toEqual(['failure'])
	})

	it('does not warn twice when paused already explains the failure', () => {
		const failure = { category: 'INVALID_CREDENTIALS', since: 1_760_000_000, count: 3 }
		expect(deriveView(makeStatus({ failure, paused: 'INVALID_CREDENTIALS' }), false).hints).toEqual(['paused'])
	})

	it('adds the backfill hint while the import runs', () => {
		expect(deriveView(makeStatus({ sync: { backfill: { running: true } } }), false).hints).toEqual(['backfill'])
	})

	it('orders hints by severity', () => {
		const status = makeStatus({
			setup: { calendarMissing: true, maintenance: 'move', maintenanceFailed: 'remove' },
			sync: { backfill: { running: true } },
			failure: { category: 'NETWORK', since: null, count: 1 },
		})

		expect(deriveView(status, false).hints).toEqual([
			'failure',
			'calendarMissing',
			'maintenanceFailedRemove',
			'maintenanceMove',
			'backfill',
		])
	})

	it('polls only while backfill or maintenance runs', () => {
		expect(deriveView(makeStatus(), false).polling).toBe(false)
		expect(deriveView(makeStatus({ paused: 'BLOCKED', failure: { category: 'BLOCKED', since: null, count: 1 } }), false).polling).toBe(false)
		expect(deriveView(makeStatus({ sync: { backfill: { running: true } } }), false).polling).toBe(true)
		expect(deriveView(makeStatus({ setup: { maintenance: 'remove' } }), false).polling).toBe(true)
	})

	it('polls while the import for a newly chosen calendar waits for its first run', () => {
		// A calendar change without moving events starts over: the backend reports the import as pending.
		expect(deriveView(makeStatus({ sync: { backfill: { pending: true } } }), false).polling).toBe(true)
	})

	it('leaves the decision about a pending import to the backend', () => {
		// The backend already excludes runs that cannot happen (paused, calendar missing, not connected).
		const noRun = { lastRunAt: null, lastSuccessAt: null, eventCount: 0, oldestActivityDate: null }
		expect(deriveView(makeStatus({ sync: { ...noRun, backfill: { pending: false } } }), false).polling).toBe(false)
	})
})

describe('hintSeverity', () => {
	const failing = (category: string) => makeStatus({ failure: { category, since: null, count: 1 } })

	it.each(['NETWORK', 'SERVER_ERROR', 'BLOCKED'])('shows the transient failure %s as a warning', (category) => {
		// Garmin or the network is unavailable for a while; the next run usually works again.
		expect(hintSeverity('failure', failing(category))).toBe('warning')
	})

	it.each(['INVALID_CREDENTIALS', 'MFA_REQUIRED', 'TOKEN_REJECTED', 'PROTOCOL_CHANGED', 'SOMETHING_NEW'])('shows the failure %s as an error', (category) => {
		expect(hintSeverity('failure', failing(category))).toBe('error')
	})

	it('shows a paused sync as an error', () => {
		expect(hintSeverity('paused', makeStatus({ paused: 'INVALID_CREDENTIALS' }))).toBe('error')
	})

	it('keeps the colours of the other hints', () => {
		const status = makeStatus()
		expect(hintSeverity('backfill', status)).toBe('info')
		expect(hintSeverity('calendarMissing', status)).toBe('warning')
		expect(hintSeverity('maintenanceMove', status)).toBe('warning')
	})
})
