import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../src/api.ts'
import { apiErrorText, hintText, isLoginFailure, loginError } from '../../src/messages.ts'
import { makeStatus } from './fixtures.ts'

type Vars = Record<string, string | number>

// Like the real t(): placeholder values are HTML-escaped unless { escape: false } is passed.
const escapeHtml = (value: string) => value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
function fill(text: string, vars: Vars | undefined, options: { escape?: boolean } | undefined) {
	return text.replace(/\{(\w+)\}/g, (_m, key: string) => {
		const value = String(vars?.[key] ?? '')
		return options?.escape === false ? value : escapeHtml(value)
	})
}

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars?: Vars, options?: { escape?: boolean }) => fill(text, vars, options),
	n: (_app: string, singular: string, plural: string, count: number, vars?: Vars, options?: { escape?: boolean }) => fill(count === 1 ? singular : plural, { ...vars, n: count }, options),
	// Like the real functions: getLocale() is not a valid BCP 47 tag, getCanonicalLocale() is.
	getLocale: () => 'en_US',
	getCanonicalLocale: () => 'en-US',
}))

describe('loginError', () => {
	it.each([
		['INVALID_CREDENTIALS', 'Garmin rejected your email address or password. Please check them and try again.'],
		['MFA_REQUIRED', 'Your Garmin account asks for a verification code. This is not supported yet.'],
		['BLOCKED', 'Garmin is blocking the sign-in right now. Please try again later.'],
		['PROTOCOL_CHANGED', 'The Garmin sign-in seems to have changed. Details are in the Nextcloud log.'],
		['SERVER_ERROR', 'Garmin cannot be reached right now. Please try again later.'],
		['NETWORK', 'Garmin cannot be reached right now. Please try again later.'],
	])('has a text for %s', (code, text) => {
		expect(loginError(code)).toBe(text)
	})

	it('falls back to a generic text for unknown codes', () => {
		expect(loginError('SOMETHING_NEW')).toBe('Signing in to Garmin failed. Please try again later.')
	})
})

describe('apiErrorText', () => {
	it.each([
		[409, 'busy', 'A sync is running right now. Please try again in a moment.'],
		[401, 'internal', 'Your session has expired. Please reload the page.'],
		[403, 'notLoggedIn', 'Your session has expired. Please reload the page.'],
		[0, 'network', 'No connection to the server. Please check your connection and try again.'],
		[400, 'invalidRequest', 'This action is not possible right now.'],
		[500, 'internal', 'Something went wrong. Details are in the Nextcloud log.'],
		[418, 'whatever', 'Something went wrong. Details are in the Nextcloud log.'],
	])('maps %i %s', (status, code, text) => {
		expect(apiErrorText(new ApiError(status, code))).toBe(text)
	})
})

describe('hintText', () => {
	it('names the calendar when it is missing', () => {
		const status = makeStatus({ setup: { calendarMissing: true, calendar: { id: 1, name: 'Fitness', createdByApp: false } } })
		expect(hintText('calendarMissing', status)).toContain('Fitness')
	})

	it('shows the calendar hint without a name when calendar.name is null', () => {
		const status = makeStatus({ setup: { calendarMissing: true, calendar: null } })
		const text = hintText('calendarMissing', status)

		expect(text.length).toBeGreaterThan(10)
		expect(text).not.toMatch(/\{\w+\}/)
		expect(text).not.toContain('null')
	})

	it('names the failure category and the date', () => {
		const status = makeStatus({ failure: { category: 'NETWORK', since: Date.UTC(2026, 9, 3, 12) / 1000, count: 2 } })

		expect(hintText('failure', status)).toBe('Garmin cannot be reached right now. Please try again later. This has been happening since Oct 3, 2026.')
	})

	it('falls back to a generic text for unknown failure categories', () => {
		const status = makeStatus({ failure: { category: 'SOMETHING_NEW', since: null, count: 1 } })
		const text = hintText('failure', status)

		expect(text).toContain('Syncing with Garmin failed')
		expect(text).not.toContain('SOMETHING_NEW')
	})

	it('does not escape the calendar name a second time', () => {
		const status = makeStatus({ setup: { calendarMissing: true, calendar: { id: 1, name: 'Garmin & Co', createdByApp: false } } })
		expect(hintText('calendarMissing', status)).toBe('The calendar "Garmin & Co" no longer exists. Please choose a calendar again.')
	})

	it('has the exact text for every other hint', () => {
		const status = makeStatus({ sync: { eventCount: 340, backfill: { running: true, reachedDate: '2023-03-02' } } })
		expect(hintText('paused', status)).toBe('Syncing is paused. Please sign in to Garmin again to continue.')
		expect(hintText('maintenanceMove', status)).toBe('Your events are being moved to the new calendar. This can take a few minutes.')
		expect(hintText('maintenanceRemove', status)).toBe('Your events are being removed from the old calendar. This can take a few minutes.')
		expect(hintText('maintenanceFailedMove', status)).toBe('Moving the events was given up. Some events may still be in the old calendar.')
		expect(hintText('maintenanceFailedRemove', status)).toBe('Removing the events was given up. Some events may still be in the old calendar.')
		expect(hintText('backfill', status)).toBe('Importing your history: 340 activities so far.')
		expect(hintText('backfill', makeStatus({ sync: { eventCount: 1 } }))).toBe('Importing your history: 1 activity so far.')
	})
})

describe('isLoginFailure', () => {
	it('knows the Garmin categories and nothing else', () => {
		expect(isLoginFailure('INVALID_CREDENTIALS')).toBe(true)
		expect(isLoginFailure('busy')).toBe(false)
	})
})
