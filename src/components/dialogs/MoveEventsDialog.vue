<template>
	<NcDialog
		:name="t('workoutcalendar', 'Move the existing events?')"
		:buttons="buttons"
		:closeOnClickOutside="false"
		:noClose="busy"
		size="small"
		@closing="emit('cancel')">
		<p>{{ t('workoutcalendar', 'Should the existing events move to the new calendar?') }}</p>
		<p>{{ t('workoutcalendar', 'If not, they stay in the old calendar, but the app no longer maintains them. The new calendar is filled like during the setup, including the history import.') }}</p>
	</NcDialog>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'

const props = defineProps<{ busy?: boolean }>()

const emit = defineEmits<{
	cancel: []
	choose: [moveEvents: boolean]
}>()

const buttons = computed(() => [
	{
		label: t('workoutcalendar', 'No, leave them'),
		variant: 'secondary' as const,
		disabled: props.busy,
		callback: (): false | void => {
			emit('choose', false)
			// The parent closes the dialog once the request is done.
			return false
		},
	},
	{
		label: t('workoutcalendar', 'Yes, move them'),
		variant: 'primary' as const,
		disabled: props.busy,
		callback: (): false | void => {
			emit('choose', true)
			return false
		},
	},
])
</script>
