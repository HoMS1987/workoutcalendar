<template>
	<div class="step">
		<h3>{{ t('workoutcalendar', 'All set') }}</h3>
		<ul class="step__summary">
			<li>{{ t('workoutcalendar', 'Garmin account: {email}', { email: status.setup.maskedEmail ?? '' }, { escape: false }) }}</li>
			<li>{{ t('workoutcalendar', 'Calendar: {name}', { name: status.setup.calendar?.name ?? '' }, { escape: false }) }}</li>
			<li>{{ historyText }}</li>
			<li v-if="status.sync.backfill.running">
				{{ t('workoutcalendar', 'The import is still running in the background.') }}
			</li>
		</ul>
		<p>{{ n('workoutcalendar', 'New activities appear in the calendar about every minute.', 'New activities appear in the calendar about every {interval} minutes.', status.settings.intervalMinutes, { interval: status.settings.intervalMinutes }, { escape: false }) }}</p>
		<NcButton variant="primary" data-test="settings" @click="emit('settings')">
			{{ t('workoutcalendar', 'Go to settings') }}
		</NcButton>
	</div>
</template>

<script setup lang="ts">
import type { Status } from '../../types.ts'

import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'

const props = defineProps<{ status: Status }>()

const emit = defineEmits<{ settings: [] }>()

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
