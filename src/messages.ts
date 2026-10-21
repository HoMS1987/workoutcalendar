import type { ApiError } from './api.ts'
import type { Status } from './types.ts'
import type { Hint } from './viewState.ts'

import { getCanonicalLocale, n, t } from '@nextcloud/l10n'

const APP = 'workoutcalendar'

// Vue escapes interpolated text itself, so t()/n() must not escape user values a second time.
const RAW = { escape: false }

// Failure categories the login route reports for Garmin itself.
const LOGIN_FAILURES = ['INVALID_CREDENTIALS', 'MFA_REQUIRED', 'BLOCKED', 'PROTOCOL_CHANGED', 'SERVER_ERROR', 'NETWORK']

export function isLoginFailure(code: string): boolean {
	return LOGIN_FAILURES.includes(code)
}

// Text for a failed Garmin sign-in, by failure category.
export function loginError(code: string): string {
	switch (code) {
		case 'INVALID_CREDENTIALS':
			return t(APP, 'Garmin rejected your email address or password. Please check them and try again.')
		case 'MFA_REQUIRED':
			return t(APP, 'Your Garmin account asks for a verification code. This is not supported yet.')
		case 'BLOCKED':
			return t(APP, 'Garmin is blocking the sign-in right now. Please try again later.')
		case 'PROTOCOL_CHANGED':
			return t(APP, 'The Garmin sign-in seems to have changed. Details are in the Nextcloud log.')
		case 'SERVER_ERROR':
		case 'NETWORK':
			return t(APP, 'Garmin cannot be reached right now. Please try again later.')
		default:
			return t(APP, 'Signing in to Garmin failed. Please try again later.')
	}
}

// Text for a failed API call.
export function apiErrorText(e: ApiError): string {
	if (e.code === 'busy') {
		return t(APP, 'A sync is running right now. Please try again in a moment.')
	}
	if (e.status === 401 || e.code === 'notLoggedIn') {
		return t(APP, 'Your session has expired. Please reload the page.')
	}
	if (e.code === 'network') {
		return t(APP, 'No connection to the server. Please check your connection and try again.')
	}
	if (e.code === 'invalidRequest') {
		return t(APP, 'This action is not possible right now.')
	}
	return t(APP, 'Something went wrong. Details are in the Nextcloud log.')
}

// Names the cause of a sync failure in words.
function failureReason(category: string): string {
	switch (category) {
		case 'TOKEN_REJECTED':
			return t(APP, 'Garmin no longer accepts the saved sign-in.')
		case 'INVALID_CREDENTIALS':
		case 'MFA_REQUIRED':
		case 'BLOCKED':
		case 'PROTOCOL_CHANGED':
		case 'SERVER_ERROR':
		case 'NETWORK':
			return loginError(category)
		default:
			return t(APP, 'Syncing with Garmin failed.')
	}
}

function formatDate(unixSeconds: number): string {
	return new Intl.DateTimeFormat(getCanonicalLocale(), { dateStyle: 'medium' }).format(new Date(unixSeconds * 1000))
}

// Short text for a hint shown next to the current view.
export function hintText(h: Hint, status: Status): string {
	switch (h) {
		case 'paused':
			return t(APP, 'Syncing is paused. Please sign in to Garmin again to continue.')
		case 'failure': {
			const failure = status.failure
			const reason = failureReason(failure?.category ?? '')
			if (failure?.since === null || failure?.since === undefined) {
				return reason
			}
			return t(APP, '{reason} This has been happening since {date}.', { reason, date: formatDate(failure.since) }, RAW)
		}
		case 'calendarMissing': {
			const name = status.setup.calendar?.name
			return name
				? t(APP, 'The calendar "{name}" no longer exists. Please choose a calendar again.', { name }, RAW)
				: t(APP, 'The calendar you used before no longer exists. Please choose a calendar again.')
		}
		case 'maintenanceMove':
			return t(APP, 'Your events are being moved to the new calendar. This can take a few minutes.')
		case 'maintenanceRemove':
			return t(APP, 'Your events are being removed from the old calendar. This can take a few minutes.')
		case 'maintenanceFailedMove':
			return t(APP, 'Moving the events was given up. Some events may still be in the old calendar.')
		case 'maintenanceFailedRemove':
			return t(APP, 'Removing the events was given up. Some events may still be in the old calendar.')
		case 'backfill': {
			const count = status.sync.eventCount
			return n(APP, 'Importing your history: {n} activity so far.', 'Importing your history: {n} activities so far.', count, { n: count }, RAW)
		}
	}
}
