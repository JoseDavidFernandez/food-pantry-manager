import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';

/**
 * What the user's role allows (RN-38), to hide what the server would refuse anyway. The server
 * is the protection: this only avoids offering actions that cannot be done.
 */
export function useHouseholdAccess(): {
    canWrite: ComputedRef<boolean>;
    canManage: ComputedRef<boolean>;
    roleLabel: ComputedRef<string | null>;
} {
    const page = usePage();
    const household = computed(() => page.props.auth.household);

    return {
        canWrite: computed(() => household.value?.can.write === true),
        canManage: computed(() => household.value?.can.manage === true),
        roleLabel: computed(() => household.value?.role_label ?? null),
    };
}
