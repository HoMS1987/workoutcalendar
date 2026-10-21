<template>
	<section class="collapsible">
		<h3 class="collapsible__heading">
			<NcButton
				variant="tertiary"
				alignment="start"
				class="collapsible__toggle"
				data-test="toggle"
				:aria-expanded="open ? 'true' : 'false'"
				:aria-controls="open ? panelId : undefined"
				@click="open = !open">
				<template #icon>
					<span aria-hidden="true">{{ open ? '▾' : '▸' }}</span>
				</template>
				{{ title }}
			</NcButton>
		</h3>
		<div v-if="open" :id="panelId" class="collapsible__panel">
			<slot />
		</div>
	</section>
</template>

<script setup lang="ts">
import { ref, useId } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'

defineProps<{ title: string }>()

const open = ref(false)
const panelId = useId()
</script>

<style scoped>
.collapsible__heading {
	margin: 0;
}

.collapsible__toggle {
	font-weight: bold;
}

.collapsible__panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	align-items: flex-start;
	padding-bottom: 8px;
}
</style>
