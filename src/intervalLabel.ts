import { n, t } from '@nextcloud/l10n'

/**
 * Words an interval such as "every 15 minutes", "every 2 hours" or "once a day".
 *
 * @param minutes Time between two syncs in minutes
 */
export function intervalLabel(minutes: number): string {
	if (minutes === 1440) {
		return t('workoutcalendar', 'once a day')
	}
	if (minutes >= 60 && minutes % 60 === 0) {
		const hours = minutes / 60
		return n('workoutcalendar', 'every hour', 'every {n} hours', hours, { n: hours }, { escape: false })
	}
	return n('workoutcalendar', 'every minute', 'every {n} minutes', minutes, { n: minutes }, { escape: false })
}
