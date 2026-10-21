<template>
	<CollapsibleSection data-test="advanced" :title="t('workoutcalendar', 'Advanced')">
		<div class="advanced__field">
			<NcCheckboxRadioSwitch
				data-test="jitter-enabled"
				:modelValue="model.jitterEnabled"
				@update:modelValue="update({ jitterEnabled: $event })">
				{{ t('workoutcalendar', 'Vary the time between syncs randomly') }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-if="model.jitterEnabled"
				class="advanced__number"
				type="number"
				data-test="jitter"
				min="0"
				max="50"
				:label="t('workoutcalendar', 'Variation in percent (0 to 50)')"
				:modelValue="model.jitterPercent"
				@update:modelValue="update({ jitterPercent: String($event) })" />
			<p class="advanced__hint">
				{{ t('workoutcalendar', 'A small random variation keeps the requests from looking like a fixed clock.') }}
			</p>
		</div>

		<div class="advanced__field">
			<label for="workoutcalendar-deletion-mode">{{ t('workoutcalendar', 'Activities deleted in Garmin') }}</label>
			<NcSelect
				inputId="workoutcalendar-deletion-mode"
				data-test="deletion-mode"
				labelOutside
				:modelValue="selectedDeletionMode"
				:options="deletionModeOptions"
				:searchable="false"
				:clearable="false"
				@update:modelValue="onDeletionMode" />
			<NcTextField
				class="advanced__number"
				type="number"
				data-test="check-days"
				min="7"
				max="365"
				:label="t('workoutcalendar', 'Check the last days (7 to 365)')"
				:modelValue="model.deletionCheckDays"
				@update:modelValue="update({ deletionCheckDays: String($event) })" />
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
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CollapsibleSection from './CollapsibleSection.vue'
import { failureReason } from '../../messages.ts'
import { formatDateTime } from './relativeTime.ts'

const model = defineModel<SettingsDraft>({ required: true })

const props = defineProps<{ status: Status }>()

function update(change: Partial<SettingsDraft>) {
	model.value = { ...model.value, ...change }
}

const deletionModeOptions = computed<Array<{ value: DeletionMode, label: string }>>(() => [
	{ value: 'keep', label: t('workoutcalendar', 'Keep the event') },
	{ value: 'mark', label: t('workoutcalendar', 'Mark the event as deleted') },
	{ value: 'delete', label: t('workoutcalendar', 'Delete the event') },
])

const selectedDeletionMode = computed(() => deletionModeOptions.value.find((option) => option.value === model.value.deletionMode) ?? null)

function onDeletionMode(option: { value: DeletionMode } | null) {
	if (option) {
		update({ deletionMode: option.value })
	}
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

.advanced__field :deep(.v-select) {
	min-width: 260px;
}

.advanced__number {
	max-width: 260px;
}

.advanced__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
