<template>
	<div class="overview">
		<OverviewTiles :status="status" @status="emit('status', $event)" />
		<StatusHints :status="status" :hints="hints" />

		<SyncSettings v-model="draft" />
		<CalendarSection :status="status" :locked="locks.calendarChange" @status="emit('status', $event)" />
		<AdvancedSettings v-model="draft" :status="status" />

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<div class="overview__save">
			<NcButton
				data-test="save"
				variant="primary"
				:disabled="saving || !dirty"
				@click="save">
				{{ t('workoutcalendar', 'Save') }}
			</NcButton>
		</div>

		<AccountSection :status="status" :locked="locks.cleanup" @status="emit('status', $event)" />
	</div>
</template>

<script setup lang="ts">
import type { Status } from '../../types.ts'
import type { ViewState } from '../../viewState.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AccountSection from './AccountSection.vue'
import AdvancedSettings from './AdvancedSettings.vue'
import CalendarSection from './CalendarSection.vue'
import OverviewTiles from './OverviewTiles.vue'
import StatusHints from './StatusHints.vue'
import SyncSettings from './SyncSettings.vue'
import { ApiError, chooseHistory, updateSettings } from '../../api.ts'
import { apiErrorText } from '../../messages.ts'
import { draftFromSettings, historyChanged, payloadChanged, payloadFromDraft } from './settingsDraft.ts'

const props = defineProps<{
	status: Status
	hints: ViewState['hints']
	locks: ViewState['locks']
}>()

const emit = defineEmits<{ status: [status: Status] }>()

// The form keeps what the person typed while the status is refreshed in the background.
const draft = ref(draftFromSettings(props.status.settings))
const saving = ref(false)
const error = ref('')

const payload = computed(() => payloadFromDraft(draft.value, props.status.settings))
const dirty = computed(() => payloadChanged(payload.value, props.status.settings) || historyChanged(draft.value, props.status.settings))

// A new status (poll, sync, other actions) refreshes the form unless the person is editing it.
watch(() => props.status.settings, (next, previous) => {
	if (!dirtyAgainst(previous)) {
		draft.value = draftFromSettings(next)
	}
})

function dirtyAgainst(settings: Status['settings']): boolean {
	return payloadChanged(payloadFromDraft(draft.value, settings), settings) || historyChanged(draft.value, settings)
}

async function save() {
	if (saving.value) {
		return
	}
	saving.value = true
	error.value = ''
	try {
		const before = props.status.settings
		let last: Status | null = null
		if (payloadChanged(payload.value, before)) {
			last = await updateSettings(payload.value)
			// This request is done even if the history request fails afterwards.
			emit('status', last)
		}
		// The history has its own route: a longer period starts an import.
		if (historyChanged(draft.value, before)) {
			// The select only offers these values.
			last = await chooseHistory(draft.value.historyDays as 30 | 90 | 365 | null)
			emit('status', last)
		}
		if (last) {
			draft.value = draftFromSettings(last.settings)
		}
	} catch (e) {
		error.value = apiErrorText(e instanceof ApiError ? e : new ApiError(0, 'internal'))
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.overview {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 720px;
}
</style>
