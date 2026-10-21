<template>
	<fieldset class="cleanup" :disabled="busy">
		<legend class="cleanup__legend">
			{{ t('workoutcalendar', 'What should happen to the events?') }}
		</legend>
		<NcCheckboxRadioSwitch
			v-model="model"
			type="radio"
			name="workoutcalendar-cleanup"
			value="none">
			{{ t('workoutcalendar', 'Keep the events') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch
			v-model="model"
			type="radio"
			name="workoutcalendar-cleanup"
			value="events"
			:disabled="locked">
			{{ t('workoutcalendar', 'Remove the app\'s events ({count})', { count: eventCount }, { escape: false }) }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch
			v-if="createdByApp"
			v-model="model"
			type="radio"
			name="workoutcalendar-cleanup"
			value="calendar"
			:disabled="locked">
			{{ t('workoutcalendar', 'Delete calendar “{name}”', { name: calendarName }, { escape: false }) }}
		</NcCheckboxRadioSwitch>
		<p v-if="model === 'calendar'" class="cleanup__hint">
			{{ t('workoutcalendar', 'The whole calendar is deleted, including events that do not come from this app.') }}
		</p>
		<p v-if="locked" class="cleanup__hint">
			{{ t('workoutcalendar', 'Removing events is not possible while events are being moved or removed.') }}
		</p>
	</fieldset>
</template>

<script setup lang="ts">
import type { Cleanup } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { watch } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

const model = defineModel<Cleanup>({ required: true })

const props = defineProps<{
	eventCount: number
	calendarName: string
	createdByApp: boolean
	/** True while maintenance runs: only "keep" is possible then. */
	locked: boolean
	busy?: boolean
}>()

// A lock that starts while the dialog is open falls back to the harmless choice.
watch(() => props.locked, (locked) => {
	if (locked) {
		model.value = 'none'
	}
})
</script>

<style scoped>
.cleanup {
	border: 0;
	padding: 0;
	margin: 12px 0 0;
}

.cleanup__legend {
	font-weight: bold;
	padding: 0;
}

.cleanup__hint {
	color: var(--color-text-maxcontrast);
}
</style>
