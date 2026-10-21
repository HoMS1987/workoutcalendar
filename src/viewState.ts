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

// Errors are red, running or abandoned maintenance and a missing calendar are yellow, progress is blue.
export function hintSeverity(hint: Hint): Severity {
	switch (hint) {
		case 'failure':
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
	locks: { calendarChange: boolean, cleanup: boolean }
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

// Hints ordered by severity, independent of the view (docs/architecture.md, "Zustände für die Oberfläche").
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

// Derives everything the settings page shows from the status alone.
export function deriveView(status: Status, wizardFinished: boolean): ViewState {
	const maintenanceRunning = status.setup.maintenance !== null

	return {
		view: viewOf(status, wizardFinished),
		hints: hintsOf(status),
		locks: { calendarChange: maintenanceRunning, cleanup: maintenanceRunning },
		polling: status.sync.backfill.running || maintenanceRunning,
	}
}
