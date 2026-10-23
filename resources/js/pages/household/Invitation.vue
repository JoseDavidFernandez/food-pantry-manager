<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { setup, show as showHousehold } from '@/routes/household';
import { accept, decline } from '@/routes/invitations';

/**
 * The invitation link (RN-39). Only its recipient sees the details; any other case looks the
 * same as an unknown link.
 */
defineProps<{
    invitation: {
        id: number;
        household: string;
        role: string;
        inviter: string | null;
        status: 'pending' | 'accepted' | 'declined' | 'revoked' | 'expired';
        status_label: string;
        expires_at: string;
    } | null;
    email: string;
    hasHousehold: boolean;
    conflict: {
        household: string;
        role: string;
        can_leave: boolean;
        blocker: string | null;
        empty: boolean;
    } | null;
}>();

defineOptions({
    layout: {
        title: 'Invitación a un hogar',
        description: 'Únete a un hogar para compartir su despensa y su menú.',
    },
});
</script>

<template>
    <Head title="Invitación" />

    <div class="grid gap-4 text-sm">
        <template v-if="invitation === null">
            <p>
                Esta invitación no existe o no es para tu cuenta ({{ email }}).
                Comprueba que has iniciado sesión con el correo al que se envió.
            </p>
        </template>

        <template v-else-if="invitation.status !== 'pending'">
            <p>
                La invitación a «{{ invitation.household }}» ya no está
                disponible: <strong>{{ invitation.status_label }}</strong
                >.
            </p>
            <p
                v-if="invitation.status === 'expired'"
                class="text-muted-foreground"
            >
                Pide una nueva al administrador del hogar.
            </p>
        </template>

        <template v-else>
            <p>
                <strong>{{ invitation.inviter ?? 'Un miembro' }}</strong> te
                invita a unirte a «{{ invitation.household }}» como
                <strong>{{ invitation.role }}</strong
                >.
            </p>
            <p class="text-muted-foreground">
                Caduca el {{ invitation.expires_at }}.
            </p>
            <div
                v-if="conflict"
                class="grid gap-2 rounded-lg border border-warning-border bg-warning-soft p-3"
                role="note"
            >
                <p class="font-medium">
                    Tu cuenta ya pertenece al hogar «{{ conflict.household }}»
                    ({{ conflict.role }}).
                </p>
                <p>
                    Cada cuenta pertenece a un solo hogar, así que no puedes
                    unirte mientras sigas en él. No se ha cambiado nada y la
                    invitación seguirá disponible hasta el
                    {{ invitation.expires_at }}.
                </p>
                <p v-if="conflict.can_leave && conflict.empty">
                    Tu hogar actual está vacío: si sales de él desde Ajustes ›
                    Hogar, se eliminará (no tiene datos) y podrás volver a este
                    enlace para unirte.
                </p>
                <p v-else-if="conflict.can_leave">
                    Para unirte, sal antes de «{{ conflict.household }}» desde
                    Ajustes › Hogar (sus datos se quedan con el resto de
                    miembros) y vuelve a abrir este enlace.
                </p>
                <p v-else>
                    {{ conflict.blocker }} Si prefieres seguir en tu hogar,
                    puedes rechazar la invitación o ignorarla.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="showHousehold()"
                            >Ir a Ajustes › Hogar</Link
                        >
                    </Button>
                    <Form
                        v-bind="decline.form(invitation.id)"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            :disabled="processing"
                        >
                            Rechazar la invitación
                        </Button>
                    </Form>
                </div>
            </div>
            <div v-else class="flex flex-wrap gap-2">
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
        </template>

        <Button variant="link" class="justify-self-start px-0" as-child>
            <Link :href="hasHousehold ? dashboard() : setup()">
                {{ hasHousehold ? 'Ir a Inicio' : 'Volver' }}
            </Link>
        </Button>
    </div>
</template>
