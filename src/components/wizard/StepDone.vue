<template>
	<div class="step">
		<h3>{{ t('workoutcalendar', 'All set') }}</h3>
		<ul class="step__summary">
			<li>{{ t('workoutcalendar', 'Garmin account: {email}', { email: status.setup.maskedEmail ?? '' }, { escape: false }) }}</li>
			<li>{{ t('workoutcalendar', 'Calendar: {name}', { name: calendarName }, { escape: false }) }}</li>
			<li>{{ historyText }}</li>
			<li v-if="status.sync.backfill.running">
				{{ t('workoutcalendar', 'The import is still running in the background.') }}
			</li>
		</ul>
		<p>{{ t('workoutcalendar', 'New activities appear in the calendar about {interval}.', { interval: intervalLabel(status.settings.intervalMinutes) }, { escape: false }) }}</p>
		<NcButton variant="primary" data-test="settings" @click="emit('settings')">
			{{ t('workoutcalendar', 'Go to settings') }}
		</NcButton>
	</div>
</template>

<script setup lang="ts">
import type { Status } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { intervalLabel } from '../../intervalLabel.ts'

const props = defineProps<{ status: Status }>()

const emit = defineEmits<{ settings: [] }>()

// A linked calendar without a name was deleted in the meantime.
const calendarName = computed(() => props.status.setup.calendar?.name ?? (props.status.setup.calendar === null ? '' : t('workoutcalendar', 'The calendar no longer exists.')))

const historyText = computed(() => {
	switch (props.status.settings.historyDays) {
		case null:
			return t('workoutcalendar', 'History: complete')
		case 365:
			return t('workoutcalendar', 'History: 1 year')
		default:
			return t('workoutcalendar', 'History: {days} days', { days: props.status.settings.historyDays }, { escape: false })
	}
})
</script>

<style scoped>
.step {
	display: flex;
	flex-direction: column;
	gap: 12px;
	align-items: flex-start;
}

.step__summary {
	list-style: disc;
	padding-inline-start: 20px;
}
</style>
