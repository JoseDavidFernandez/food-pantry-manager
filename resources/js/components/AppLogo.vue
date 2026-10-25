<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <AppLogoIcon class="size-8 shrink-0" />
    <div class="ml-1 grid flex-1 text-left">
        <span class="truncate text-[15px] leading-none font-display">{{
            name
        }}</span>
    </div>
</template>
