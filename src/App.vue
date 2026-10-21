<template>
	<div class="workoutcalendar-settings">
		<h2>{{ t('workoutcalendar', 'Workout Calendar') }}</h2>

		<NcLoadingIcon v-if="!status && !loadError" :name="t('workoutcalendar', 'Loading …')" />
		<NcNoteCard v-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<NcNoteCard v-if="pollFailure"
			type="warning"
			data-test="connection-lost">
			{{ pollProblemText(pollFailure) }}
		</NcNoteCard>

		<template v-if="status">
			<template v-if="wizardStep">
				<StatusHints :status="status" :hints="wizardHints" />
				<WizardSteps :current="wizardStep" />
				<StepGarmin
					v-if="view === 'login'"
					:maskedEmail="status.setup.connected ? null : status.setup.maskedEmail"
					@status="onLogin" />
				<StepCalendar
					v-else-if="view === 'calendar'"
					@status="setStatus"
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

			<OverviewPage
				v-else
				class="workoutcalendar-settings__overview"
				:status="status"
				:hints="viewState.hints"
				:locks="viewState.locks"
				@status="setStatus" />
		</template>
	</div>
</template>

<script setup lang="ts">
import type { PollProblem } from './messages.ts'
import type { SetupStep, Status } from './types.ts'

import { t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import OverviewPage from './components/overview/OverviewPage.vue'
import StatusHints from './components/overview/StatusHints.vue'
import StepCalendar from './components/wizard/StepCalendar.vue'
import StepDone from './components/wizard/StepDone.vue'
import StepGarmin from './components/wizard/StepGarmin.vue'
import StepHistory from './components/wizard/StepHistory.vue'
import WizardSteps from './components/wizard/WizardSteps.vue'
import { ApiError, getStatus } from './api.ts'
import { apiErrorText, pollProblem, pollProblemText } from './messages.ts'
import { usePolling } from './usePolling.ts'
import { deriveView } from './viewState.ts'

const status = ref<Status | null>(null)
// Counts status updates so a slow poll cannot overwrite a newer status from an action.
let statusVersion = 0
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

// The history step and the final step show the import progress themselves.
const wizardHints = computed(() => viewState.value.hints.filter((hint) => hint !== 'backfill' || (view.value !== 'history' && view.value !== 'done')))

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

function setStatus(next: Status) {
	statusVersion++
	status.value = next
}

function onLogin(next: Status) {
	showLogin.value = false
	setStatus(next)
}

function onHistoryStarted(next: Status) {
	wizardFinished.value = true
	setStatus(next)
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
		setStatus(await getStatus())
	} catch (e) {
		loadError.value = apiErrorText(e instanceof ApiError ? e : new ApiError(0, 'internal'))
	}
}

// Set while the quiet refresh fails for a reason the person should know; a later success clears it.
const pollFailure = ref<PollProblem | null>(null)

// While an import or a maintenance job runs, the status is refreshed quietly.
// An expired session rarely comes back by itself, so the refresh then slows down.
usePolling(async () => {
	const version = statusVersion
	let next: Status
	try {
		next = await getStatus()
	} catch (e) {
		pollFailure.value = pollProblem(e) ?? pollFailure.value
		throw e
	}
	pollFailure.value = null
	if (version === statusVersion) {
		setStatus(next)
	}
}, computed(() => viewState.value.polling), computed(() => pollFailure.value === 'session' ? 60_000 : 10_000))

onMounted(load)
</script>
