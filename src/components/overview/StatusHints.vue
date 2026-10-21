<template>
	<div>
		<NcNoteCard v-for="hint in hints" :key="hint" :type="hintSeverity(hint)">
			{{ hintText(hint, status) }}
		</NcNoteCard>
	</div>
</template>

<script setup lang="ts">
import type { Status } from '../../types.ts'
import type { Hint } from '../../viewState.ts'

import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { hintText } from '../../messages.ts'
import { hintSeverity } from '../../viewState.ts'

defineProps<{
	status: Status
	hints: Hint[]
}>()
</script>
