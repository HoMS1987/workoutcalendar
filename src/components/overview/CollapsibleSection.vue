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

// initiallyOpen is read once on mount: a later change does not fold or unfold the section.
const props = defineProps<{ title: string, initiallyOpen?: boolean }>()

const open = ref(props.initiallyOpen === true)
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
