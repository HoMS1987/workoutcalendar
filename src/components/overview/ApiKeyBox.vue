<template>
	<NcNoteCard type="warning" data-test="api-key-box">
		<p>{{ t('workoutcalendar', 'Your Garmin API key has expired or was deleted. Workout Calendar is paused until you enter a new one in the settings.') }}</p>
		<ApiKeyForm :submitLabel="t('workoutcalendar', 'Save')" @status="emit('status', $event)" />
	</NcNoteCard>
</template>

<script setup lang="ts">
import type { Status } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ApiKeyForm from '../signin/ApiKeyForm.vue'

const emit = defineEmits<{ status: [status: Status] }>()
</script>
