<template>
	<div class="step">
		<h3>{{ t('workoutcalendar', 'Choose a calendar') }}</h3>
		<p>{{ t('workoutcalendar', 'Every activity becomes an event with sport, duration and distance. A calendar of its own is the easiest choice, because you can show and hide it separately from your other calendars.') }}</p>

		<NcLoadingIcon v-if="loading" :name="t('workoutcalendar', 'Loading calendars …')" />
		<template v-else-if="choice">
			<CalendarPicker
				v-model="choice"
				:calendars="calendars"
				:defaultName="defaultName"
				:disabled="busy" />
			<p class="step__hint">
				{{ t('workoutcalendar', 'Nextcloud picks the color of a new calendar. You can change it later in the Calendar app.') }}
			</p>
		</template>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div class="step__buttons">
			<NcButton data-test="back" :disabled="busy" @click="emit('back')">
				{{ t('workoutcalendar', 'Back') }}
			</NcButton>
			<NcButton
				data-test="next"
				variant="primary"
				:disabled="busy || !choice"
				@click="next(false)">
				{{ t('workoutcalendar', 'Next') }}
			</NcButton>
		</div>

		<ConfirmCalendarDialog
			v-if="confirming"
			:calendarName="confirming.name"
			:existingEvents="confirming.events"
			:busy="busy"
			@cancel="confirming = null"
			@confirm="next(true)" />
	</div>
</template>

<script setup lang="ts">
import type { CalendarOption, Status } from '../../types.ts'
import type { CalendarChoice } from '../calendarChoice.ts'

import { t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import CalendarPicker from '../CalendarPicker.vue'
import ConfirmCalendarDialog from '../dialogs/ConfirmCalendarDialog.vue'
import { ApiError, chooseCalendar, getCalendars } from '../../api.ts'
import { apiErrorText } from '../../messages.ts'

const emit = defineEmits<{
	status: [status: Status]
	back: []
}>()

const loading = ref(true)
const busy = ref(false)
const error = ref('')
const calendars = ref<CalendarOption[]>([])
const defaultName = ref('')
const choice = ref<CalendarChoice | null>(null)
const confirming = ref<{ name: string, events: number } | null>(null)

function errorText(e: unknown): string {
	return apiErrorText(e instanceof ApiError ? e : new ApiError(0, 'internal'))
}

onMounted(async () => {
	try {
		const result = await getCalendars()
		calendars.value = result.calendars
		defaultName.value = result.defaultName
		choice.value = { kind: 'new', name: result.defaultName }
	} catch (e) {
		error.value = errorText(e)
	} finally {
		loading.value = false
	}
})

async function next(confirmed: boolean) {
	if (busy.value || !choice.value) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		const picked = choice.value
		const input = picked.kind === 'new' ? { name: picked.name } : { calendarId: picked.id }
		// The wizard has no events to move yet; the server ignores moveEvents during the setup.
		const result = await chooseCalendar({ ...input, confirmed, moveEvents: false })
		if (result.needsConfirmation) {
			confirming.value = { name: picked.name, events: result.existingEvents }
			return
		}
		confirming.value = null
		emit('status', result.status)
	} catch (e) {
		confirming.value = null
		error.value = errorText(e)
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.step {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
	align-items: flex-start;
}

.step :deep(.v-select) {
	width: 100%;
}

.step__hint {
	color: var(--color-text-maxcontrast);
}

.step__buttons {
	display: flex;
	gap: 8px;
}
</style>
