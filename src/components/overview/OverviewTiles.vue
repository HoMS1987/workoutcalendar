<template>
	<div class="tiles">
		<div class="tiles__grid">
			<div class="tiles__tile" data-test="tile-connection">
				<span class="tiles__label">{{ t('workoutcalendar', 'Connection') }}</span>
				<strong>{{ hasProblem ? t('workoutcalendar', 'Needs attention') : t('workoutcalendar', 'Connected') }}</strong>
				<span v-if="status.setup.maskedEmail" class="tiles__detail">{{ status.setup.maskedEmail }}</span>
			</div>
			<div class="tiles__tile" data-test="tile-events">
				<span class="tiles__label">{{ t('workoutcalendar', 'In the calendar') }}</span>
				<strong>{{ n('workoutcalendar', '{n} activity', '{n} activities', status.sync.eventCount, { n: status.sync.eventCount }, { escape: false }) }}</strong>
				<span v-if="status.sync.oldestActivityDate" class="tiles__detail">
					{{ t('workoutcalendar', 'since {date}', { date: formatMonthYear(status.sync.oldestActivityDate) }, { escape: false }) }}
				</span>
			</div>
			<div class="tiles__tile" data-test="tile-last">
				<span class="tiles__label">{{ t('workoutcalendar', 'Last sync') }}</span>
				<strong>{{ status.sync.lastSuccessAt === null ? t('workoutcalendar', 'Not yet') : formatRelative(status.sync.lastSuccessAt) }}</strong>
			</div>
			<div class="tiles__tile" data-test="tile-next">
				<span class="tiles__label">{{ t('workoutcalendar', 'Next sync') }}</span>
				<strong>{{ nextText }}</strong>
			</div>
		</div>

		<div class="tiles__action">
			<NcButton
				data-test="sync-now"
				variant="secondary"
				:disabled="busy"
				@click="syncNowClicked">
				{{ t('workoutcalendar', 'Sync now') }}
			</NcButton>
			<span v-if="result"
				class="tiles__result"
				role="status"
				data-test="sync-result">{{ result }}</span>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { RunOutcome, Status } from '../../types.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { ApiError, syncNow } from '../../api.ts'
import { apiErrorText } from '../../messages.ts'
import { formatMonthYear, formatRelative } from './relativeTime.ts'

const props = defineProps<{ status: Status }>()

const emit = defineEmits<{ status: [status: Status] }>()

const busy = ref(false)
const result = ref('')

const hasProblem = computed(() => props.status.failure !== null || props.status.paused !== null)

const nextText = computed(() => {
	if (props.status.paused !== null) {
		return t('workoutcalendar', 'Paused')
	}
	const next = props.status.sync.nextRunAt
	return next === null ? t('workoutcalendar', 'Not scheduled') : formatRelative(next)
})

function outcomeText(outcome: RunOutcome): string {
	switch (outcome) {
		case 'done':
			return t('workoutcalendar', 'Sync finished.')
		case 'skipped':
			return t('workoutcalendar', 'A sync is already running. Please try again in a moment.')
		case 'paused':
			return t('workoutcalendar', 'Syncing is paused. Please sign in to Garmin again to continue.')
		case 'notConnected':
			return t('workoutcalendar', 'The app is not connected to Garmin.')
		case 'calendarMissing':
			return t('workoutcalendar', 'The calendar no longer exists. Please choose a calendar again.')
		case 'notReady':
			return t('workoutcalendar', 'Setup is not finished yet.')
	}
}

async function syncNowClicked() {
	if (busy.value) {
		return
	}
	busy.value = true
	result.value = ''
	try {
		const response = await syncNow()
		result.value = outcomeText(response.outcome)
		emit('status', response.status)
	} catch (e) {
		// A busy answer (409) only explains itself; the status stays as it is.
		result.value = apiErrorText(e instanceof ApiError ? e : new ApiError(0, 'internal'))
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.tiles {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.tiles__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 12px;
}

.tiles__tile {
	display: flex;
	flex-direction: column;
	gap: 4px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.tiles__label,
.tiles__detail,
.tiles__result {
	color: var(--color-text-maxcontrast);
}

.tiles__action {
	display: flex;
	align-items: center;
	gap: 12px;
}
</style>
