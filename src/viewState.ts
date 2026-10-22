import type { Status } from './types.ts'

export type View = 'login' | 'calendar' | 'history' | 'done' | 'overview'

export type Hint = 'paused'
	| 'failure'
	| 'calendarMissing'
	| 'maintenanceMove'
	| 'maintenanceRemove'
	| 'maintenanceOther'
	| 'maintenanceFailedMove'
	| 'maintenanceFailedRemove'
	| 'backfill'

export type Severity = 'error' | 'warning' | 'info'

// Failure categories that pass by themselves: Garmin or the network is unavailable or throttles
// for a while (ADR 0004), and a later run usually works again without the person doing anything.
const TRANSIENT_FAILURES = ['NETWORK', 'SERVER_ERROR', 'BLOCKED']

/**
 * Failures only the person can fix and a paused sync are red; transient failures, running or
 * abandoned maintenance and a missing calendar are yellow; progress is blue.
 *
 * @param hint Hint to rate
 * @param status Current status, for the failure category
 */
export function hintSeverity(hint: Hint, status: Status): Severity {
	switch (hint) {
		case 'failure':
			return TRANSIENT_FAILURES.includes(status.failure?.category ?? '') ? 'warning' : 'error'
		case 'paused':
			return 'error'
		case 'backfill':
			return 'info'
		default:
			return 'warning'
	}
}

export interface ViewState {
	view: View
	hints: Hint[]
	/** Actions the page offers but disables while a move or removal of events runs. */
	locks: { calendarChange: boolean, cleanup: boolean }
	/** Whether the page reloads the status: while a history import or a move or removal runs. */
	polling: boolean
}

function viewOf(status: Status, wizardFinished: boolean): View {
	if (!status.setup.connected) {
		return 'login'
	}
	switch (status.setup.step) {
		case 'calendar':
			return 'calendar'
		case 'history':
			return 'history'
		case 'done':
			return wizardFinished ? 'done' : 'overview'
		default:
			// Connected but still at the Garmin step: only the login form can continue from here.
			return 'login'
	}
}

/**
 * Hints ordered by severity, independent of the view (docs/architecture.md, table of the UI states).
 *
 * @param status Current status
 */
function hintsOf(status: Status): Hint[] {
	const { setup } = status
	const hints: Hint[] = []

	if (status.paused !== null) {
		hints.push('paused')
	} else if (status.failure !== null) {
		// While paused, the paused hint already explains the failure.
		hints.push('failure')
	}
	if (setup.calendarMissing) {
		hints.push('calendarMissing')
	}
	if (setup.maintenanceFailed === 'move') {
		hints.push('maintenanceFailedMove')
	} else if (setup.maintenanceFailed === 'remove') {
		hints.push('maintenanceFailedRemove')
	}
	if (setup.maintenance === 'move') {
		hints.push('maintenanceMove')
	} else if (setup.maintenance === 'remove') {
		hints.push('maintenanceRemove')
	} else if (setup.maintenance !== null) {
		// A value this version does not know still locks the page; say so in general terms.
		hints.push('maintenanceOther')
	}
	if (status.sync.backfill.running) {
		hints.push('backfill')
	}
	return hints
}

/**
 * Derives everything the settings page shows from the status alone.
 *
 * @param status Current status
 * @param wizardFinished True from the history choice until the person leaves the "done" step
 */
export function deriveView(status: Status, wizardFinished: boolean): ViewState {
	const maintenanceRunning = status.setup.maintenance !== null

	return {
		view: viewOf(status, wizardFinished),
		hints: hintsOf(status),
		locks: { calendarChange: maintenanceRunning, cleanup: maintenanceRunning },
		// backfill.pending covers the time between a calendar change without moving and the first run.
		polling: status.sync.backfill.running || status.sync.backfill.pending || maintenanceRunning,
	}
}
