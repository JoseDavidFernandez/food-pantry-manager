<script setup lang="ts">
import { Eye } from '@lucide/vue';
import { useHouseholdAccess } from '@/composables/useHouseholdAccess';

/**
 * Viewers (RN-38) see everything but change nothing: said once, on every page.
 */
const { canWrite, roleLabel } = useHouseholdAccess();
</script>

<template>
    <p
        v-if="roleLabel && !canWrite"
        class="mx-4 mt-2 flex items-center gap-2 rounded-lg border border-warning-border bg-warning-soft px-3 py-2 text-sm sm:mx-6"
        role="note"
    >
        <Eye class="size-4 shrink-0" aria-hidden="true" />
        Modo lectura: tu rol en el hogar es {{ roleLabel }}. Puedes consultarlo
        todo, pero no modificar datos.
    </p>
</template>
