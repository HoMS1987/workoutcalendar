import type { Status } from './types.ts'
import type { Hint } from './viewState.ts'

import { getCanonicalLocale, n, t } from '@nextcloud/l10n'
import { ApiError } from './api.ts'
import { formatMonthYear } from './components/overview/relativeTime.ts'

const APP = 'workoutcalendar'

// Vue escapes interpolated text itself, so t()/n() must not escape user values a second time.
const RAW = { escape: false }

// Failure categories the login route reports for Garmin itself.
const LOGIN_FAILURES = ['INVALID_CREDENTIALS', 'MFA_REQUIRED', 'MFA_CODE_INVALID', 'MFA_EXPIRED', 'MFA_CREDENTIALS_REJECTED', 'BLOCKED', 'PROTOCOL_CHANGED', 'SERVER_ERROR', 'NETWORK']

export function isLoginFailure(code: string): boolean {
	return LOGIN_FAILURES.includes(code)
}

/**
 * Text for a failed Garmin sign-in, by failure category.
 *
 * @param code Failure category from the login route
 */
export function loginError(code: string): string {
	switch (code) {
		case 'INVALID_CREDENTIALS':
			return t(APP, 'Garmin rejected your email address or password. Please check them and try again.')
		case 'MFA_REQUIRED':
			return t(APP, 'Your Garmin account asks for a verification code.')
		case 'MFA_CODE_INVALID':
			return t(APP, 'The code is not correct. Please check it and try again.')
		case 'MFA_EXPIRED':
			return t(APP, 'The code request has expired. Please request a new code.')
		case 'MFA_CREDENTIALS_REJECTED':
			return t(APP, 'Garmin rejected the saved password. Please sign out and sign in with your current password.')
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

export type MfaKind = 'email' | 'sms' | 'app' | 'other' | 'missing'

/**
 * Sorts the verification method Garmin reported into the kinds the texts distinguish.
 *
 * @param method Method name from the status, or null if Garmin did not report one
 */
export function mfaKind(method: string | null): MfaKind {
	if (method === null) {
		return 'missing'
	}
	if (/^email$/i.test(method)) {
		return 'email'
	}
	if (/^sms$/i.test(method)) {
		return 'sms'
	}
	return /totp|authenticator/i.test(method) ? 'app' : 'other'
}

/**
 * Tells the person where the verification code comes from.
 *
 * @param method Method name from the status, or null if unknown
 */
export function mfaPromptText(method: string | null): string {
	switch (mfaKind(method)) {
		case 'email':
			return t(APP, 'Garmin has sent you a code by email.')
		case 'sms':
			return t(APP, 'Garmin has sent you a code by SMS.')
		case 'app':
			return t(APP, 'Enter the code from your authenticator app.')
		case 'other':
			return t(APP, 'Enter the code Garmin has sent you.')
		default:
			return t(APP, 'Garmin has sent you a code by email or SMS.')
	}
}

/**
 * Text for a failed API call.
 *
 * @param e Error of the failed call
 */
export function apiErrorText(e: ApiError): string {
	if (e.code === 'busy') {
		return t(APP, 'A sync is running right now. Please try again in a moment.')
	}
	if (e.status === 401 || e.code === 'notLoggedIn') {
		return t(APP, 'Your session has expired. Please reload the page.')
	}
	if (e.status === 429) {
		return t(APP, 'Too many attempts. Please try again later.')
	}
	if (e.code === 'network') {
		return t(APP, 'No connection to the server. Please check your connection and try again.')
	}
	if (e.code === 'invalidRequest') {
		return t(APP, 'This action is not possible right now.')
	}
	return t(APP, 'Something went wrong. Details are in the Nextcloud log.')
}

/** Why the quiet status refresh fails: the session ended or Nextcloud cannot be reached. */
export type PollProblem = 'session' | 'network'

const PROXY_UNAVAILABLE = [502, 503, 504]

/**
 * Classifies a failed status refresh; other failures are not shown.
 *
 * @param e Whatever the refresh threw
 */
export function pollProblem(e: unknown): PollProblem | null {
	if (!(e instanceof ApiError)) {
		return null
	}
	if (e.status === 401 || e.code === 'notLoggedIn') {
		return 'session'
	}
	// WHY: 502, 503 and 504 come from a proxy or the maintenance mode, not from the app: Nextcloud
	// itself is out of reach for a while, just like without a network.
	return e.code === 'network' || PROXY_UNAVAILABLE.includes(e.status) ? 'network' : null
}

/**
 * Text for a failed status refresh.
 *
 * @param problem Kind of the refresh failure
 */
export function pollProblemText(problem: PollProblem): string {
	return problem === 'session'
		? t(APP, 'Your session has expired. Please reload the page.')
		: t(APP, 'The connection to Nextcloud was lost. The page keeps trying to reconnect.')
}

/**
 * Names the cause of a sync failure in words.
 *
 * @param category Failure category of the last sync
 */
export function failureReason(category: string): string {
	switch (category) {
		case 'TOKEN_REJECTED':
			return t(APP, 'Garmin no longer accepts the saved sign-in.')
		case 'INVALID_CREDENTIALS':
		case 'MFA_REQUIRED':
		case 'MFA_CODE_INVALID':
		case 'MFA_EXPIRED':
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

/**
 * Short text for a hint shown next to the current view.
 *
 * @param h Hint to describe
 * @param status Current status, for counts and dates in the text
 */
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
		case 'maintenanceOther':
			return t(APP, 'Events are being reorganised in the background.')
		case 'maintenanceFailedMove':
			return t(APP, 'Moving the events was given up. Some events may still be in the old calendar.')
		case 'maintenanceFailedRemove':
			return t(APP, 'Removing the events was given up. Some events may still be in the old calendar.')
		case 'backfill': {
			const count = status.sync.eventCount
			const reached = status.sync.backfill.reachedDate
			if (reached === null) {
				return n(APP, 'Importing your history: {n} activity so far.', 'Importing your history: {n} activities so far.', count, { n: count }, RAW)
			}
			const date = formatMonthYear(reached)
			return n(APP, 'Importing your history: {n} activity so far, back to {date}.', 'Importing your history: {n} activities so far, back to {date}.', count, { n: count, date }, RAW)
		}
	}
}
