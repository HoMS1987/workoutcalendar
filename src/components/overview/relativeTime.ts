import { getCanonicalLocale } from '@nextcloud/l10n'

/**
 * "5 minutes ago" or "in 20 minutes" for a Unix timestamp in seconds.
 *
 * @param unixSeconds Timestamp to describe
 * @param nowMs Reference time, defaults to now
 */
export function formatRelative(unixSeconds: number, nowMs: number = Date.now()): string {
	const formatter = new Intl.RelativeTimeFormat(getCanonicalLocale(), { numeric: 'auto' })
	const diffSeconds = unixSeconds - nowMs / 1000
	const abs = Math.abs(diffSeconds)
	if (abs < 45) {
		return formatter.format(0, 'second')
	}
	if (abs < 3600) {
		return formatter.format(Math.round(diffSeconds / 60), 'minute')
	}
	if (abs < 86_400) {
		return formatter.format(Math.round(diffSeconds / 3600), 'hour')
	}
	return formatter.format(Math.round(diffSeconds / 86_400), 'day')
}

/**
 * Month and year of an ISO date such as "2023-03-02", independent of the browser time zone.
 *
 * @param isoDate Date as YYYY-MM-DD
 */
export function formatMonthYear(isoDate: string): string {
	return new Intl.DateTimeFormat(getCanonicalLocale(), { year: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(isoDate))
}

/**
 * Date and time for a Unix timestamp in seconds.
 *
 * @param unixSeconds Timestamp to format
 */
export function formatDateTime(unixSeconds: number): string {
	return new Intl.DateTimeFormat(getCanonicalLocale(), { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(unixSeconds * 1000))
}
