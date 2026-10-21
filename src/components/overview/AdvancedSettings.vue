<template>
	<CollapsibleSection data-test="advanced" :title="t('workoutcalendar', 'Advanced')">
		<div class="advanced__field">
			<label class="advanced__inline">
				<input
					type="checkbox"
					data-test="jitter-enabled"
					:checked="model.jitterEnabled"
					@change="update({ jitterEnabled: ($event.target as HTMLInputElement).checked })">
				{{ t('workoutcalendar', 'Vary the time between syncs randomly') }}
			</label>
			<label v-if="model.jitterEnabled" class="advanced__inline">
				{{ t('workoutcalendar', 'Variation in percent (0 to 50)') }}
				<input
					type="number"
					data-test="jitter"
					min="0"
					max="50"
					:value="model.jitterPercent"
					@input="update({ jitterPercent: ($event.target as HTMLInputElement).value })">
			</label>
			<p class="advanced__hint">
				{{ t('workoutcalendar', 'A small random variation keeps the requests from looking like a fixed clock.') }}
			</p>
		</div>

		<div class="advanced__field">
			<label for="workoutcalendar-deletion-mode">{{ t('workoutcalendar', 'Activities deleted in Garmin') }}</label>
			<select
				id="workoutcalendar-deletion-mode"
				data-test="deletion-mode"
				:value="model.deletionMode"
				@change="update({ deletionMode: ($event.target as HTMLSelectElement).value as DeletionMode })">
				<option value="keep">
					{{ t('workoutcalendar', 'Keep the event') }}
				</option>
				<option value="mark">
					{{ t('workoutcalendar', 'Mark the event as deleted') }}
				</option>
				<option value="delete">
					{{ t('workoutcalendar', 'Delete the event') }}
				</option>
			</select>
			<label class="advanced__inline">
				{{ t('workoutcalendar', 'Check the last days (7 to 365)') }}
				<input
					type="number"
					data-test="check-days"
					min="7"
					max="365"
					:value="model.deletionCheckDays"
					@input="update({ deletionCheckDays: ($event.target as HTMLInputElement).value })">
			</label>
		</div>

		<div class="advanced__field" data-test="last-error">
			<strong>{{ t('workoutcalendar', 'Last error') }}</strong>
			<p v-if="status.failure === null" class="advanced__hint">
				{{ t('workoutcalendar', 'No error so far.') }}
			</p>
			<p v-else>
				{{ failureText }}
			</p>
		</div>
	</CollapsibleSection>
</template>

<script setup lang="ts">
import type { DeletionMode, Status } from '../../types.ts'
import type { SettingsDraft } from './settingsDraft.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import CollapsibleSection from './CollapsibleSection.vue'
import { failureReason } from '../../messages.ts'
import { formatDateTime } from './relativeTime.ts'

const model = defineModel<SettingsDraft>({ required: true })

const props = defineProps<{ status: Status }>()

function update(change: Partial<SettingsDraft>) {
	model.value = { ...model.value, ...change }
}

const failureText = computed(() => {
	const failure = props.status.failure
	if (failure === null) {
		return ''
	}
	if (failure.since === null) {
		return t('workoutcalendar', '{reason} Failed attempts so far: {count}.', { reason: failureReason(failure.category), count: failure.count }, { escape: false })
	}
	return t('workoutcalendar', '{reason} Failing since {time}. Failed attempts so far: {count}.', { reason: failureReason(failure.category), time: formatDateTime(failure.since), count: failure.count }, { escape: false })
})
</script>

<style scoped>
.advanced__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	align-items: flex-start;
}

.advanced__inline {
	display: flex;
	align-items: center;
	gap: 8px;
}

.advanced__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
