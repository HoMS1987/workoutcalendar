<template>
	<div class="workoutcalendar-settings">
		<h2>{{ t('workoutcalendar', 'Workout Calendar') }}</h2>

		<NcLoadingIcon v-if="!status && !loadError" :name="t('workoutcalendar', 'Loading …')" />
		<NcNoteCard v-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<template v-if="status">
			<NcNoteCard v-for="hint in viewState.hints" :key="hint" :type="hint === 'backfill' ? 'info' : 'warning'">
				{{ hintText(hint, status) }}
			</NcNoteCard>

			<template v-if="wizardStep">
				<WizardSteps :current="wizardStep" />
				<StepGarmin
					v-if="view === 'login'"
					:maskedEmail="status.setup.connected ? null : status.setup.maskedEmail"
					@status="onLogin" />
				<StepCalendar
					v-else-if="view === 'calendar'"
					@status="status = $event"
					@back="showLogin = true" />
				<StepHistory
					v-else-if="view === 'history'"
					:status="status"
					:started="historyStarted"
					@status="onHistoryStarted"
					@started="historyStarted = true"
					@next="finishHistory" />
				<StepDone
					v-else
					:status="status"
					@settings="leaveWizard" />
			</template>

			<!-- Task 6 builds the overview. -->
			<div v-else class="workoutcalendar-settings__overview" />
		</template>
	</div>
</template>

<script setup lang="ts">
import type { SetupStep, Status } from './types.ts'

import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import StepCalendar from './components/wizard/StepCalendar.vue'
import StepDone from './components/wizard/StepDone.vue'
import StepGarmin from './components/wizard/StepGarmin.vue'
import StepHistory from './components/wizard/StepHistory.vue'
import WizardSteps from './components/wizard/WizardSteps.vue'
import { ApiError, getStatus } from './api.ts'
import { apiErrorText, hintText } from './messages.ts'
import { deriveView } from './viewState.ts'

const POLL_INTERVAL_MS = 10_000

const status = ref<Status | null>(null)
const loadError = ref('')
// True from the moment the history import was chosen until the person leaves the "done" step.
const wizardFinished = ref(false)
// The history step stays visible after the start so it can show the progress.
const historyStarted = ref(false)
// "Back" from the calendar step: show the login form again without signing out.
const showLogin = ref(false)

const viewState = computed(() => status.value
	? deriveView(status.value, wizardFinished.value)
	: { view: 'login' as const, hints: [], locks: { calendarChange: false, cleanup: false }, polling: false })

// The view after local overrides: back to the login form, or the history progress.
const view = computed(() => {
	if (showLogin.value && viewState.value.view === 'calendar') {
		return 'login'
	}
	if (historyStarted.value && viewState.value.view === 'done') {
		return 'history'
	}
	return viewState.value.view
})

const wizardStep = computed<SetupStep | null>(() => {
	switch (view.value) {
		case 'login':
			return 'garmin'
		case 'calendar':
		case 'history':
		case 'done':
			return view.value
		default:
			return null
	}
})

function onLogin(next: Status) {
	showLogin.value = false
	status.value = next
}

function onHistoryStarted(next: Status) {
	wizardFinished.value = true
	status.value = next
}

function finishHistory() {
	historyStarted.value = false
	wizardFinished.value = true
}

function leaveWizard() {
	wizardFinished.value = false
}

async function load() {
	try {
		status.value = await getStatus()
	} catch (e) {
		loadError.value = apiErrorText(e instanceof ApiError ? e : new ApiError(0, 'internal'))
	}
}

// While an import or a maintenance job runs, the status is refreshed quietly.
let timer: ReturnType<typeof setInterval> | undefined
function stopPolling() {
	if (timer !== undefined) {
		clearInterval(timer)
		timer = undefined
	}
}
watch(() => viewState.value.polling, (polling) => {
	stopPolling()
	if (polling) {
		timer = setInterval(async () => {
			try {
				status.value = await getStatus()
			} catch {
				// A failed refresh keeps the last known status; the next tick tries again.
			}
		}, POLL_INTERVAL_MS)
	}
})

onMounted(load)
onBeforeUnmount(stopPolling)
</script>
