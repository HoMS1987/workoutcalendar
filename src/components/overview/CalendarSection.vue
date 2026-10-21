<template>
	<section class="calendar-section">
		<h3>{{ t('workoutcalendar', 'Calendar') }}</h3>
		<p>
			{{ calendarText }}
			<NcButton
				data-test="change"
				:disabled="locked || picking"
				@click="startPicking">
				{{ t('workoutcalendar', 'Change') }}
			</NcButton>
		</p>
		<p v-if="locked" class="calendar-section__hint">
			{{ t('workoutcalendar', 'The calendar cannot be changed while events are being moved or removed.') }}
		</p>

		<div v-if="picking" class="calendar-section__picker">
			<NcLoadingIcon v-if="loading" :name="t('workoutcalendar', 'Loading calendars …')" />
			<CalendarPicker
				v-else-if="choice"
				v-model="choice"
				:calendars="calendars"
				:defaultName="defaultName"
				:disabled="busy" />
			<div class="calendar-section__buttons">
				<NcButton data-test="cancel" :disabled="busy" @click="stopPicking">
					{{ t('workoutcalendar', 'Cancel') }}
				</NcButton>
				<NcButton
					data-test="continue"
					variant="primary"
					:disabled="busy || !choice"
					@click="onContinue">
					{{ t('workoutcalendar', 'Continue') }}
				</NcButton>
			</div>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<MoveEventsDialog
			v-if="asking"
			:busy="busy"
			@cancel="asking = false"
			@choose="apply($event, false)" />
		<ConfirmCalendarDialog
			v-if="confirming"
			:calendarName="confirming.name"
			:existingEvents="confirming.events"
			:busy="busy"
			@cancel="confirming = null"
			@confirm="apply(confirming.moveEvents, true)" />
	</section>
</template>

<script setup lang="ts">
import type { CalendarOption, Status } from '../../types.ts'
import type { CalendarChoice } from '../calendarChoice.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import CalendarPicker from '../CalendarPicker.vue'
import ConfirmCalendarDialog from '../dialogs/ConfirmCalendarDialog.vue'
import MoveEventsDialog from '../dialogs/MoveEventsDialog.vue'
import { ApiError, chooseCalendar, getCalendars } from '../../api.ts'
import { apiErrorText } from '../../messages.ts'

const props = defineProps<{
	status: Status
	/** True while a move or removal runs (locks.calendarChange). */
	locked: boolean
}>()

const emit = defineEmits<{ status: [status: Status] }>()

// A linked calendar without a name was deleted in the meantime.
const calendarText = computed(() => {
	const calendar = props.status.setup.calendar
	if (calendar === null) {
		return t('workoutcalendar', 'No calendar chosen')
	}
	return calendar.name ?? t('workoutcalendar', 'The calendar no longer exists.')
})

const picking = ref(false)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const calendars = ref<CalendarOption[]>([])
const defaultName = ref('')
const choice = ref<CalendarChoice | null>(null)
// The move question comes first; an existing calendar then still needs its own confirmation.
const asking = ref(false)
const confirming = ref<{ name: string, events: number, moveEvents: boolean } | null>(null)

function errorText(e: unknown): string {
	return apiErrorText(e instanceof ApiError ? e : new ApiError(0, 'internal'))
}

async function startPicking() {
	picking.value = true
	loading.value = true
	error.value = ''
	try {
		const result = await getCalendars()
		calendars.value = result.calendars
		defaultName.value = result.defaultName
		choice.value = { kind: 'new', name: result.defaultName }
	} catch (e) {
		error.value = errorText(e)
		picking.value = false
	} finally {
		loading.value = false
	}
}

function stopPicking() {
	picking.value = false
	asking.value = false
	confirming.value = null
}

// Nothing to move when the same calendar is picked again or the app has no events yet.
function onContinue() {
	const picked = choice.value
	const sameCalendar = picked?.kind === 'existing' && picked.id === props.status.setup.calendar?.id
	if (sameCalendar || props.status.sync.eventCount === 0) {
		void apply(false, false)
		return
	}
	asking.value = true
}

async function apply(moveEvents: boolean, confirmed: boolean) {
	if (busy.value || !choice.value) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		const picked = choice.value
		const input = picked.kind === 'new' ? { name: picked.name } : { calendarId: picked.id }
		const result = await chooseCalendar({ ...input, confirmed, moveEvents })
		if (result.needsConfirmation) {
			asking.value = false
			confirming.value = { name: picked.name, events: result.existingEvents, moveEvents }
			return
		}
		stopPicking()
		emit('status', result.status)
	} catch (e) {
		asking.value = false
		confirming.value = null
		error.value = errorText(e)
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.calendar-section__hint {
	color: var(--color-text-maxcontrast);
}

.calendar-section__picker {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
	align-items: flex-start;
}

.calendar-section__picker :deep(.v-select) {
	width: 100%;
}

.calendar-section__buttons {
	display: flex;
	gap: 8px;
}
</style>
