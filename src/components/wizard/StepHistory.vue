<template>
	<div class="step">
		<h3>{{ t('workoutcalendar', 'How far back should the import go?') }}</h3>
		<p>{{ t('workoutcalendar', 'New activities arrive automatically from now on. This choice is only about the activities you have already recorded. You can extend the period later, a shorter one deletes nothing.') }}</p>

		<fieldset class="step__cards" :disabled="started || busy">
			<legend class="hidden-visually">
				{{ t('workoutcalendar', 'Period to import') }}
			</legend>
			<div
				v-for="option in options"
				:key="option.id"
				class="step__card"
				:class="{ 'step__card--selected': selected === option.id }">
				<NcCheckboxRadioSwitch
					v-model="selected"
					type="radio"
					name="history"
					:value="option.id"
					:disabled="started || busy">
					<strong>{{ option.title }}</strong>
					<em v-if="option.recommended" class="step__badge">{{ t('workoutcalendar', 'Recommended') }}</em>
					<span class="step__explanation">{{ option.text }}</span>
				</NcCheckboxRadioSwitch>
			</div>
		</fieldset>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="started" class="step__progress" role="status">
			<p>{{ progressText }}</p>
			<p v-if="status.sync.backfill.running" class="step__hint">
				{{ t('workoutcalendar', 'The next stage follows in about 5 minutes.') }}
			</p>
			<p v-else class="step__hint">
				{{ t('workoutcalendar', 'The import is complete.') }}
			</p>
		</div>

		<div class="step__buttons">
			<NcButton
				v-if="!started"
				data-test="start"
				variant="primary"
				:disabled="busy"
				@click="start">
				{{ t('workoutcalendar', 'Start import') }}
			</NcButton>
			<NcButton
				v-if="started"
				data-test="next"
				variant="primary"
				@click="emit('next')">
				{{ t('workoutcalendar', 'Next') }}
			</NcButton>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { Status } from '../../types.ts'

import { getCanonicalLocale, n, t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ApiError, chooseHistory } from '../../api.ts'
import { apiErrorText } from '../../messages.ts'

type HistoryId = '30' | '90' | '365' | 'all'

const props = defineProps<{
	status: Status
	/** True once the import was started (the step then shows its progress). */
	started: boolean
}>()

const emit = defineEmits<{
	status: [status: Status]
	started: []
	next: []
}>()

const options = computed<Array<{ id: HistoryId, title: string, text: string, recommended?: boolean }>>(() => [
	{ id: '30', title: t('workoutcalendar', '30 days'), text: t('workoutcalendar', 'Your last month, ready within a few minutes.') },
	{ id: '90', title: t('workoutcalendar', '90 days'), text: t('workoutcalendar', 'A good overview of the recent past, ready within a few minutes.'), recommended: true },
	{ id: '365', title: t('workoutcalendar', '1 year'), text: t('workoutcalendar', 'A full season, the import takes a little longer in the background.') },
	{ id: 'all', title: t('workoutcalendar', 'Complete history'), text: t('workoutcalendar', 'Everything Garmin has stored, the import can take a while in the background.') },
])

const selected = ref<HistoryId>('90')
const busy = ref(false)
const error = ref('')

const progressText = computed(() => {
	const { eventCount, backfill } = props.status.sync
	const count = n('workoutcalendar', '{n} activity', '{n} activities', eventCount, { n: eventCount }, { escape: false })
	if (backfill.reachedDate === null) {
		return count
	}
	const date = new Intl.DateTimeFormat(getCanonicalLocale(), { year: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(backfill.reachedDate))
	return t('workoutcalendar', '{count}, back to {date}', { count, date }, { escape: false })
})

async function start() {
	if (busy.value) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		const days = selected.value === 'all' ? null : (Number(selected.value) as 30 | 90 | 365)
		emit('status', await chooseHistory(days))
		emit('started')
	} catch (e) {
		error.value = apiErrorText(e instanceof ApiError ? e : new ApiError(0, 'internal'))
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
	max-width: 640px;
	align-items: flex-start;
}

.step__cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 12px;
	border: 0;
	padding: 0;
	margin: 0;
	width: 100%;
}

.step__card {
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	padding: 8px;
}

.step__card--selected {
	border-color: var(--color-primary-element);
}

.step__badge {
	margin-inline-start: 8px;
	color: var(--color-primary-element);
}

.step__explanation {
	display: block;
	color: var(--color-text-maxcontrast);
}

.step__hint {
	color: var(--color-text-maxcontrast);
}

.step__buttons {
	display: flex;
	gap: 8px;
}
</style>
