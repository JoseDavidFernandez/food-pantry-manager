<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import type { Option } from '@/types/pantry';

const props = defineProps<{
    name: string;
    options: Option[];
    defaultValue?: string | null;
    placeholder?: string;
    class?: HTMLAttributes['class'];
}>();
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
        <option
            v-for="option in props.options"
            :key="option.value"
            :value="option.value"
            :selected="option.value === props.defaultValue"
        >
            {{ option.label }}
        </option>
    </select>
</template>
