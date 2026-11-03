<template>
	<CollapsibleSection data-test="account" :title="t('workoutcalendar', 'Garmin account')">
		<NcNoteCard v-if="signedInAgain" type="success">
			{{ t('workoutcalendar', 'Signed in to Garmin again.') }}
		</NcNoteCard>
		<NcNoteCard v-if="reloginError" type="error">
			{{ reloginError }}
		</NcNoteCard>
		<p v-if="status.setup.authMethod === 'apiKey'" data-test="account-method">
			{{ t('workoutcalendar', 'Connected with API key') }}<template v-if="status.setup.apiKeyEnteredAt !== null">
				, {{ t('workoutcalendar', 'entered on {date}', { date: formatDate(status.setup.apiKeyEnteredAt) }, { escape: false }) }}
			</template>
		</p>
		<div class="account__buttons">
			<!-- WHY hidden after a rejected password: it would only send the same password to Garmin again. -->
			<NcButton
				v-if="status.setup.authMethod === 'password' && status.paused !== 'INVALID_CREDENTIALS'"
				data-test="relogin-password"
				:disabled="busy"
				@click="signInWithSavedPassword">
				{{ t('workoutcalendar', 'Sign in with the saved password') }}
			</NcButton>
			<NcButton data-test="relogin" :disabled="busy" @click="openMethods">
				{{ t('workoutcalendar', 'Sign in again') }}
			</NcButton>
			<NcButton data-test="logout" @click="open = 'logout'">
				{{ t('workoutcalendar', 'Sign out') }}
			</NcButton>
			<NcButton data-test="reset" variant="error" @click="open = 'reset'">
				{{ t('workoutcalendar', 'Reset app') }}
			</NcButton>
		</div>

		<div v-if="methodsOpen" class="account__methods" data-test="relogin-methods">
			<SignInMethods
				v-model:email="email"
				initial="apiKey"
				:submitLabel="t('workoutcalendar', 'Save')"
				:maskedEmail="status.setup.maskedEmail"
				:pending="setupPending"
				:nextRequestAt="status.mfa.nextRequestAt"
				@status="methodsDone" />
			<NcButton data-test="relogin-cancel" variant="tertiary" @click="methodsOpen = false">
				{{ t('workoutcalendar', 'Cancel') }}
			</NcButton>
		</div>

		<LogoutDialog
			v-if="open === 'logout'"
			:status="status"
			:locked="locked"
			:busy="busy"
			:error="error"
			@cancel="close"
			@confirm="run('logout', $event)" />
		<ResetDialog
			v-if="open === 'reset'"
			:status="status"
			:locked="locked"
			:busy="busy"
			:error="error"
			@cancel="close"
			@confirm="run('reset', $event)" />
	</CollapsibleSection>
</template>

<script setup lang="ts">
import type { Cleanup, Status } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import LogoutDialog from '../dialogs/LogoutDialog.vue'
import ResetDialog from '../dialogs/ResetDialog.vue'
import SignInMethods from '../signin/SignInMethods.vue'
import CollapsibleSection from './CollapsibleSection.vue'
import { ApiError, logout, requestMfaCode, reset } from '../../api.ts'
import { apiErrorText, formatDate, isLoginFailure, loginError } from '../../messages.ts'

const props = defineProps<{
	status: Status
	/** True while maintenance runs (locks.cleanup). */
	locked: boolean
}>()

const emit = defineEmits<{ status: [status: Status] }>()

const open = ref<'logout' | 'reset' | null>(null)
const busy = ref(false)
const error = ref('')

const signedInAgain = ref(false)
const reloginError = ref('')

const methodsOpen = ref(false)
const email = ref('')

// A code request of the email and password sign-in started here is answered inside this view.
const setupPending = computed(() => props.status.mfa.pending?.origin === 'setup' ? props.status.mfa.pending : null)

function openMethods() {
	signedInAgain.value = false
	reloginError.value = ''
	methodsOpen.value = true
}

// Any new status ends the view, except a code request that still waits for its code.
function methodsDone(next: Status) {
	if (next.mfa.pending?.origin !== 'setup') {
		methodsOpen.value = false
		signedInAgain.value = true
	}
	emit('status', next)
}

// Signs in with the saved password. Garmin may answer with a code request; the box at the top of
// the overview takes over from there.
async function signInWithSavedPassword() {
	if (busy.value) {
		return
	}
	busy.value = true
	signedInAgain.value = false
	reloginError.value = ''
	try {
		const result = await requestMfaCode()
		signedInAgain.value = result.outcome === 'signedIn'
		emit('status', result.status)
	} catch (e) {
		const apiError = e instanceof ApiError ? e : new ApiError(0, 'internal')
		reloginError.value = isLoginFailure(apiError.code) ? loginError(apiError.code) : apiErrorText(apiError)
	} finally {
		busy.value = false
	}
}

function close() {
	if (!busy.value) {
		open.value = null
		error.value = ''
	}
}

async function run(kind: 'logout' | 'reset', cleanup: Cleanup) {
	if (busy.value) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		const next = kind === 'logout' ? await logout(cleanup) : await reset(cleanup)
		open.value = null
		emit('status', next)
	} catch (e) {
		error.value = apiErrorText(e instanceof ApiError ? e : new ApiError(0, 'internal'))
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.account__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.account__methods {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-start;
	margin-top: 12px;
}
</style>
