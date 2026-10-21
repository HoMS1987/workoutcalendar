<template>
	<NcDialog
		:name="t('workoutcalendar', 'Reset the app?')"
		:buttons="buttons"
		:closeOnClickOutside="false"
		size="small"
		@closing="emit('cancel')">
		<p>{{ t('workoutcalendar', 'The app deletes everything it saved for you: Garmin credentials and tokens, settings, sync state and the link to the calendar. The setup starts again at step 1.') }}</p>
		<p><strong>{{ t('workoutcalendar', 'This cannot be undone.') }}</strong></p>
		<CleanupOptions
			v-model="cleanup"
			:eventCount="status.sync.eventCount"
			:calendarName="status.setup.calendar?.name ?? ''"
			:createdByApp="status.setup.calendar?.createdByApp ?? false"
			:locked="locked"
			:busy="busy" />
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</NcDialog>
</template>

<script setup lang="ts">
import type { Cleanup, Status } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import CleanupOptions from './CleanupOptions.vue'

const props = defineProps<{
	status: Status
	locked: boolean
	busy: boolean
	error: string
}>()

const emit = defineEmits<{
	cancel: []
	confirm: [cleanup: Cleanup]
}>()

const cleanup = ref<Cleanup>('none')

const buttons = computed(() => [
	{
		label: t('workoutcalendar', 'Cancel'),
		variant: 'secondary' as const,
		callback: (): false | void => {
			emit('cancel')
		},
	},
	{
		label: t('workoutcalendar', 'Reset app'),
		variant: 'error' as const,
		disabled: props.busy,
		callback: (): false | void => {
			emit('confirm', cleanup.value)
			// The parent closes the dialog once the request is done.
			return false
		},
	},
])
</script>
