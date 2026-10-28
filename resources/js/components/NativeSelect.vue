<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { groupOptions } from '@/lib/units';
import { cn } from '@/lib/utils';
import type { Option } from '@/types/pantry';

const props = defineProps<{
    name: string;
    options: Option[];
    defaultValue?: string | null;
    placeholder?: string;
    /** Units of the food itself, shown first (unit selectors only). */
    preferred?: string[];
    class?: HTMLAttributes['class'];
}>();

// Unit options come in sections (measures, pieces, packs); any other list stays plain.
const groups = computed(() =>
    groupOptions(props.options, props.defaultValue, props.preferred),
);
</script>

<template>
    <select
        :name="props.name"
        :class="
            cn(
                'h-9 w-full min-w-0 rounded-md border border-input bg-card px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/40 aria-invalid:border-destructive max-sm:min-h-11 md:text-sm',
                props.class,
            )
        "
    >
        <option
            v-if="props.placeholder !== undefined"
            value=""
            :selected="!props.defaultValue"
        >
            {{ props.placeholder }}
        </option>
        <template v-for="group in groups" :key="group.label ?? ''">
            <optgroup v-if="group.label" :label="group.label">
                <option
                    v-for="option in group.options"
                    :key="option.value"
                    :value="option.value"
                    :selected="option.value === props.defaultValue"
                >
                    {{ option.label }}
                </option>
            </optgroup>
            <template v-else>
                <option
                    v-for="option in group.options"
                    :key="option.value"
                    :value="option.value"
                    :selected="option.value === props.defaultValue"
                >
                    {{ option.label }}
                </option>
            </template>
        </template>
    </select>
</template>
