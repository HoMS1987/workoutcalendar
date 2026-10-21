import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../src/api.ts'
import { apiErrorText, hintText, loginError } from '../../src/messages.ts'
import { makeStatus } from './fixtures.ts'

vi.mock('@nextcloud/l10n', () => {
	const fill = (text: string, vars?: Record<string, string | number>) => text.replace(/\{(\w+)\}/g, (_m, key: string) => String(vars?.[key] ?? ''))
	return {
		t: (_app: string, text: string, vars?: Record<string, string | number>) => fill(text, vars),
		n: (_app: string, singular: string, plural: string, count: number, vars?: Record<string, string | number>) => fill(count === 1 ? singular : plural, { ...vars, n: count }),
		getLanguage: () => 'en',
	}
})

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
		const text = hintText('failure', status)

		expect(text).toContain('Garmin cannot be reached right now')
		expect(text).toContain('2026')
	})

	it('falls back to a generic text for unknown failure categories', () => {
		const status = makeStatus({ failure: { category: 'SOMETHING_NEW', since: null, count: 1 } })
		const text = hintText('failure', status)

		expect(text).toContain('Syncing with Garmin failed')
		expect(text).not.toContain('SOMETHING_NEW')
	})

	it('has a plain text for every other hint', () => {
		const status = makeStatus({ sync: { backfill: { running: true, reachedDate: '2023-03-02' } }, paused: 'BLOCKED' })
		for (const hint of ['paused', 'maintenanceMove', 'maintenanceRemove', 'maintenanceFailedMove', 'maintenanceFailedRemove', 'backfill'] as const) {
			const text = hintText(hint, status)
			expect(text.length).toBeGreaterThan(10)
			expect(text).not.toMatch(/\{\w+\}/)
		}
	})
})
