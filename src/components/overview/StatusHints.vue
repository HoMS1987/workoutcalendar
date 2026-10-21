<template>
	<div>
		<NcNoteCard v-for="hint in hints" :key="hint" :type="noteType(hint)">
			{{ hintText(hint, status) }}
		</NcNoteCard>
	</div>
</template>

<script setup lang="ts">
import type { Status } from '../../types.ts'
import type { Hint } from '../../viewState.ts'

import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { hintText } from '../../messages.ts'

defineProps<{
	status: Status
	hints: Hint[]
}>()

// Errors are red, running or abandoned maintenance and a missing calendar are yellow, progress is blue.
function noteType(hint: Hint): 'error' | 'warning' | 'info' {
	switch (hint) {
		case 'failure':
		case 'paused':
			return 'error'
		case 'backfill':
			return 'info'
		default:
			return 'warning'
	}
}
</script>
