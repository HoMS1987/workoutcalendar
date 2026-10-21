<template>
	<section class="sync-settings">
		<h3>{{ t('workoutcalendar', 'Synchronization') }}</h3>

		<div class="sync-settings__field">
			<label for="workoutcalendar-interval">{{ t('workoutcalendar', 'Time between two syncs') }}</label>
			<NcSelect
				inputId="workoutcalendar-interval"
				data-test="interval"
				labelOutside
				:modelValue="selectedInterval"
				:options="intervalOptions"
				:searchable="false"
				:clearable="false"
				@update:modelValue="onInterval" />
			<p class="sync-settings__hint">
				{{ t('workoutcalendar', 'How often the app asks Garmin for new activities. Shorter times show new activities sooner.') }}
			</p>
		</div>

		<div class="sync-settings__field">
			<label for="workoutcalendar-history">{{ t('workoutcalendar', 'History') }}</label>
			<NcSelect
				inputId="workoutcalendar-history"
				data-test="history"
				labelOutside
				:modelValue="selectedHistory"
				:options="historyOptions"
				:searchable="false"
				:clearable="false"
				@update:modelValue="onHistory" />
			<p class="sync-settings__hint">
				{{ t('workoutcalendar', 'How far back activities are imported. A longer period imports the missing activities in the background, a shorter one deletes nothing.') }}
			</p>
		</div>
	</section>
</template>

<script setup lang="ts">
import type { SettingsDraft } from './settingsDraft.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { intervalLabel } from '../../intervalLabel.ts'

const model = defineModel<SettingsDraft>({ required: true })

function update(change: Partial<SettingsDraft>) {
	model.value = { ...model.value, ...change }
}

// 15, 30, 60 minutes and 2, 6, 12, 24 hours; a stored value outside this list stays selectable.
const intervalOptions = computed(() => {
	const values = [15, 30, 60, 120, 360, 720, 1440]
	if (!values.includes(model.value.intervalMinutes)) {
		values.push(model.value.intervalMinutes)
		values.sort((a, b) => a - b)
	}
	return values.map((value) => ({ value, label: intervalLabel(value) }))
})

const selectedInterval = computed(() => intervalOptions.value.find((option) => option.value === model.value.intervalMinutes) ?? null)

function onInterval(option: { value: number } | null) {
	if (option) {
		update({ intervalMinutes: option.value })
	}
}

const historyValue = computed(() => model.value.historyDays === null ? 'all' : String(model.value.historyDays))

const historyOptions = computed(() => {
	const days = [30, 90, 365]
	const current = model.value.historyDays
	if (current !== null && !days.includes(current)) {
		days.push(current)
		days.sort((a, b) => a - b)
	}
	return [
		...days.map((value) => ({
			value: String(value),
			label: value === 365 ? t('workoutcalendar', '1 year') : t('workoutcalendar', '{days} days', { days: value }, { escape: false }),
		})),
		{ value: 'all', label: t('workoutcalendar', 'Complete history') },
	]
})

const selectedHistory = computed(() => historyOptions.value.find((option) => option.value === historyValue.value) ?? null)

function onHistory(option: { value: string } | null) {
	if (option) {
		update({ historyDays: option.value === 'all' ? null : Number(option.value) })
	}
}
</script>

<style scoped>
.sync-settings__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	align-items: flex-start;
	margin-bottom: 12px;
}

.sync-settings__field :deep(.v-select) {
	min-width: 260px;
}

.sync-settings__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
