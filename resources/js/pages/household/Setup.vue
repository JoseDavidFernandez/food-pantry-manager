<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Home, Mail } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { store } from '@/routes/household';
import { accept, decline } from '@/routes/invitations';

/**
 * Before using the app (RN-01): create a household and become its admin, or accept an
 * invitation addressed to this account's email (RN-39).
 */
defineProps<{
    suggestedName: string;
    invitations: {
        id: number;
        household: string;
        role: string;
        inviter: string | null;
        expires_at: string;
    }[];
}>();

defineOptions({
    layout: {
        title: 'Tu hogar',
        description:
            'Los alimentos, recetas, menús y compras se comparten en un hogar. Crea el tuyo o únete al que te han invitado.',
    },
});
</script>

<template>
    <Head title="Tu hogar" />

    <div class="grid gap-8">
        <section
            v-if="invitations.length > 0"
            class="grid gap-3"
            aria-labelledby="invitations-title"
        >
            <h2
                id="invitations-title"
                class="flex items-center gap-2 text-sm font-semibold"
            >
                <Mail class="size-4" aria-hidden="true" />
                Invitaciones para ti
            </h2>
            <ul class="grid gap-3">
                <li
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="grid gap-3 rounded-xl border bg-card p-4"
                >
                    <p class="text-sm">
                        <strong>{{
                            invitation.inviter ?? 'Un miembro'
                        }}</strong>
                        te invita a «{{ invitation.household }}» como
                        <strong>{{ invitation.role }}</strong
                        >.
                        <span class="block text-xs text-muted-foreground"
                            >Caduca el {{ invitation.expires_at }}</span
                        >
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Form
                            v-bind="accept.form(invitation.id)"
                            v-slot="{ processing }"
                        >
                            <Button type="submit" :disabled="processing">
                                <Spinner v-if="processing" />
                                Unirme a «{{ invitation.household }}»
                            </Button>
                        </Form>
                        <Form
                            v-bind="decline.form(invitation.id)"
                            v-slot="{ processing }"
                        >
                            <Button
                                type="submit"
                                variant="outline"
                                :disabled="processing"
                            >
                                Rechazar
                            </Button>
                        </Form>
                    </div>
                </li>
            </ul>
        </section>

        <section class="grid gap-3" aria-labelledby="create-title">
            <h2
                id="create-title"
                class="flex items-center gap-2 text-sm font-semibold"
            >
                <Home class="size-4" aria-hidden="true" />
                Crear un hogar
            </h2>
            <p class="text-sm text-muted-foreground">
                Serás su administrador y podrás invitar a otras personas.
            </p>
            <Form
                v-bind="store.form()"
                v-slot="{ errors, processing }"
                class="grid gap-3"
            >
                <div class="grid gap-2">
                    <Label for="household-name">Nombre del hogar</Label>
                    <Input
                        id="household-name"
                        name="name"
                        :default-value="suggestedName"
                        required
                        maxlength="100"
                    />
                    <InputError :message="errors.name" />
                </div>
                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Crear hogar
                </Button>
            </Form>
        </section>

        <p class="text-center text-sm text-muted-foreground">
            ¿Te han invitado y no ves la invitación? Pide que la envíen al
            correo de esta cuenta o abre el enlace que recibiste.
            <Link
                :href="logout()"
                as="button"
                class="mt-2 block w-full underline underline-offset-4"
                >Cerrar sesión</Link
            >
        </p>
    </div>
</template>
