import type { CalendarOption, Cleanup, RunOutcome, Settings, Status } from './types.ts'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export class ApiError extends Error {
	readonly status: number
	readonly code: string

	constructor(status: number, code: string) {
		super(code)
		this.name = 'ApiError'
		this.status = status
		this.code = code
	}
}

type Method = 'get' | 'post' | 'put'

function url(path: string): string {
	return generateUrl('/apps/workoutcalendar/api/' + path)
}

async function request<T>(method: Method, path: string, body?: object): Promise<T> {
	try {
		const response = method === 'get'
			? await axios.get(url(path))
			: await axios[method](url(path), body ?? {})
		return response.data as T
	} catch (error) {
		const response = (error as { response?: { status: number, data?: { error?: string } } }).response
		if (response) {
			throw new ApiError(response.status, response.data?.error ?? 'internal')
		}
		throw new ApiError(0, 'network')
	}
}

export function getStatus(): Promise<Status> {
	return request('get', 'status')
}

export function login(email: string, password: string): Promise<Status> {
	return request('post', 'login', { email, password })
}

export function getCalendars(): Promise<{ defaultName: string, calendars: CalendarOption[] }> {
	return request('get', 'calendars')
}

export function chooseCalendar(input: { name?: string, calendarId?: number, confirmed: boolean, moveEvents: boolean }): Promise<{
	needsConfirmation: boolean
	existingEvents: number
	calendar: { id: number, name: string } | null
	status: Status
}> {
	return request('post', 'calendar', input)
}

export function chooseHistory(historyDays: 30 | 90 | 365 | null): Promise<Status> {
	return request('post', 'history', { historyDays })
}

export function updateSettings(settings: Pick<Settings, 'intervalMinutes' | 'jitterPercent' | 'deletionMode' | 'deletionCheckDays'>): Promise<Status> {
	return request('put', 'settings', settings)
}

export function syncNow(): Promise<{ outcome: RunOutcome, status: Status }> {
	return request('post', 'sync')
}

export function logout(cleanup: Cleanup): Promise<Status> {
	return request('post', 'logout', { cleanup })
}

export function reset(cleanup: Cleanup): Promise<Status> {
	return request('post', 'reset', { cleanup })
}
