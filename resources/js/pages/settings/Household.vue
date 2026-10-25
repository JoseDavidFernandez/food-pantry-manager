<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { Copy, LogOut, UserMinus } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { leave, show, update } from '@/routes/household';
import { decline as declineInvitation } from '@/routes/invitations';
import {
    destroy as revokeInvitation,
    store as storeInvitation,
} from '@/routes/household/invitations';
import {
    destroy as removeMember,
    update as updateMember,
} from '@/routes/household/members';
import type { Option } from '@/types/pantry';

/**
 * The household (RN-38, RN-39): members and roles for everyone; invitations, roles, removal and
 * the name only for admins (the server checks it again).
 */
const props = defineProps<{
    household: { id: number; name: string };
    members: {
        id: number;
        name: string;
        email: string;
        role: string;
        is_self: boolean;
    }[];
    invitations: {
        id: number;
        email: string;
        role: string;
        status: string;
        status_label: string;
        inviter: string | null;
        expires_at: string;
    }[];
    receivedInvitations: {
        id: number;
        household: string;
        role: string;
        inviter: string | null;
        expires_at: string;
    }[];
    leaveBlocker: string | null;
    leaveDeletesEmpty: boolean;
    roles: Option[];
    myRole: string | null;
    canManage: boolean;
    mailSimulated: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Hogar', href: show() }],
    },
});

const page = usePage();
const createdLink = computed(
    () =>
        (page.flash as { invitationLink?: { email: string; url: string } })
            ?.invitationLink ?? null,
);
const copied = ref(false);

async function copyLink(url: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(url);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}

const roleLabel = (value: string): string =>
    props.roles.find((role) => role.value === value)?.label ?? value;

function changeRole(memberId: number, role: string): void {
    router.patch(
        updateMember.url(memberId),
        { role },
        { preserveScroll: true },
    );
}

const pending = computed(() =>
    props.invitations.filter((i) => i.status === 'pending'),
);
const past = computed(() =>
    props.invitations.filter((i) => i.status !== 'pending'),
);
</script>

<template>
    <Head title="Hogar" />

    <div class="flex flex-col space-y-10">
        <section class="space-y-4">
            <Heading
                variant="small"
                title="Tu hogar"
                :description="`Tu rol: ${roleLabel(myRole ?? '')}. Todos los miembros comparten alimentos, recetas, menús y compras.`"
            />
            <Form
                v-if="canManage"
                v-bind="update.form()"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
                class="grid gap-2 sm:max-w-md"
            >
                <Label for="household-name">Nombre del hogar</Label>
                <div class="flex gap-2">
                    <Input
                        id="household-name"
                        name="name"
                        :default-value="household.name"
                        required
                        maxlength="100"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" />
                        Guardar
                    </Button>
                </div>
                <InputError :message="errors.name" />
            </Form>
            <p v-else class="text-sm">
                <strong>{{ household.name }}</strong>
            </p>
        </section>

        <section class="space-y-4" aria-labelledby="members-title">
            <h2 id="members-title" class="text-base font-medium">Miembros</h2>
            <ul class="divide-y border-y">
                <li
                    v-for="member in members"
                    :key="member.id"
                    class="flex flex-wrap items-center gap-3 py-3"
                >
                    <span class="grid min-w-0 flex-1">
                        <span class="font-medium break-words"
                            >{{ member.name }}
                            <span
                                v-if="member.is_self"
                                class="font-normal text-muted-foreground"
                                >(tú)</span
                            ></span
                        >
                        <span class="text-xs break-all text-muted-foreground">{{
                            member.email
                        }}</span>
                    </span>
                    <!-- An admin never changes their own role from here (the server also protects the
                         last admin); their own row only shows it. -->
                    <template v-if="canManage && !member.is_self">
                        <Label :for="`role-${member.id}`" class="sr-only"
                            >Rol de {{ member.name }}</Label
                        >
                        <select
                            :id="`role-${member.id}`"
                            :value="member.role"
                            class="h-10 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring"
                            @change="
                                changeRole(
                                    member.id,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option
                                v-for="role in roles"
                                :key="role.value"
                                :value="role.value"
                            >
                                {{ role.label }}
                            </option>
                        </select>
                        <Dialog>
                            <DialogTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Retirar a ${member.name} del hogar`"
                                >
                                    <UserMinus />
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle
                                        >¿Retirar a
                                        {{ member.name }}?</DialogTitle
                                    >
                                    <DialogDescription>
                                        Dejará de ver y modificar los datos del
                                        hogar. Los datos se quedan en el hogar.
                                    </DialogDescription>
                                </DialogHeader>
                                <Form
                                    v-bind="removeMember.form(member.id)"
                                    :options="{ preserveScroll: true }"
                                    v-slot="{ processing }"
                                >
                                    <DialogFooter class="gap-2">
                                        <DialogClose as-child>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                >Cancelar</Button
                                            >
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            :disabled="processing"
                                        >
                                            Retirar del hogar
                                        </Button>
                                    </DialogFooter>
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </template>
                    <Badge v-else variant="outline">{{
                        roleLabel(member.role)
                    }}</Badge>
                </li>
            </ul>
        </section>

        <section
            v-if="canManage"
            class="space-y-4"
            aria-labelledby="invite-title"
        >
            <h2 id="invite-title" class="text-base font-medium">
                Invitar a alguien
            </h2>
            <p class="max-w-prose text-sm text-pretty text-muted-foreground">
                Se unirá con su propia cuenta, registrada con ese correo. La
                invitación caduca a los 7 días.
                <template v-if="mailSimulated">
                    En este entorno el correo no se envía de verdad: comparte el
                    enlace que aparecerá al crearla.
                </template>
            </p>
            <Form
                v-bind="storeInvitation.form()"
                reset-on-success
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
                class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem_auto] sm:items-end"
            >
                <div class="grid gap-2">
                    <Label for="invite-email">Correo electrónico</Label>
                    <Input
                        id="invite-email"
                        name="email"
                        type="email"
                        required
                        autocomplete="off"
                        placeholder="correo@ejemplo.com"
                    />
                    <InputError :message="errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="invite-role">Rol</Label>
                    <NativeSelect
                        id="invite-role"
                        name="role"
                        :options="roles"
                        default-value="editor"
                    />
                    <InputError :message="errors.role" />
                </div>
                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Invitar
                </Button>
            </Form>

            <div
                v-if="createdLink"
                class="grid gap-2 rounded-md bg-muted p-3 text-sm"
                role="status"
            >
                <p>
                    Enlace de la invitación para
                    <strong>{{ createdLink.email }}</strong
                    >. Solo se muestra ahora:
                </p>
                <div class="flex gap-2">
                    <Label for="invite-link" class="sr-only"
                        >Enlace de la invitación</Label
                    >
                    <Input
                        id="invite-link"
                        :default-value="createdLink.url"
                        readonly
                        class="font-mono text-xs"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        @click="copyLink(createdLink.url)"
                    >
                        <Copy />
                        {{ copied ? 'Copiado' : 'Copiar' }}
                    </Button>
                </div>
            </div>

            <div class="space-y-2">
                <h3 class="text-sm font-medium">Invitaciones pendientes</h3>
                <p
                    v-if="pending.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No hay invitaciones pendientes.
                </p>
                <ul v-else class="divide-y border-y">
                    <li
                        v-for="invitation in pending"
                        :key="invitation.id"
                        class="flex flex-wrap items-center gap-3 py-3 text-sm"
                    >
                        <span class="grid min-w-0 flex-1">
                            <span class="break-all">{{
                                invitation.email
                            }}</span>
                            <span class="text-xs text-muted-foreground"
                                >{{ invitation.role }} · caduca el
                                {{ invitation.expires_at }}</span
                            >
                        </span>
                        <Form
                            v-bind="revokeInvitation.form(invitation.id)"
                            :options="{ preserveScroll: true }"
                            v-slot="{ processing }"
                        >
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                :disabled="processing"
                                :aria-label="`Revocar la invitación de ${invitation.email}`"
                            >
                                Revocar
                            </Button>
                        </Form>
                    </li>
                </ul>
            </div>

            <details v-if="past.length > 0" class="text-sm">
                <summary class="cursor-pointer font-medium">
                    Invitaciones anteriores ({{ past.length }})
                </summary>
                <ul class="mt-2 divide-y border-y">
                    <li
                        v-for="invitation in past"
                        :key="invitation.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2"
                    >
                        <span class="break-all">{{ invitation.email }}</span>
                        <Badge variant="outline">{{
                            invitation.status_label
                        }}</Badge>
                    </li>
                </ul>
            </details>
        </section>

        <section
            v-if="receivedInvitations.length > 0"
            class="space-y-3"
            aria-labelledby="received-title"
        >
            <h2 id="received-title" class="text-base font-medium">
                Invitaciones a otros hogares
            </h2>
            <p class="max-w-prose text-sm text-pretty text-muted-foreground">
                Cada cuenta pertenece a un solo hogar: para aceptar una, primero
                tienes que salir de este. Mientras tanto siguen disponibles
                hasta que caduquen; no se ha cambiado nada.
            </p>
            <ul class="divide-y border-y text-sm">
                <li
                    v-for="invitation in receivedInvitations"
                    :key="invitation.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-3"
                >
                    <span>
                        <strong>{{
                            invitation.inviter ?? 'Un miembro'
                        }}</strong>
                        te invita a «{{ invitation.household }}» como
                        {{ invitation.role }}
                        <span class="block text-xs text-muted-foreground"
                            >Caduca el {{ invitation.expires_at }}</span
                        >
                    </span>
                    <Form
                        v-bind="declineInvitation.form(invitation.id)"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            :disabled="processing"
                        >
                            Rechazar
                        </Button>
                    </Form>
                </li>
            </ul>
        </section>

        <section class="space-y-3" aria-labelledby="leave-title">
            <h2 id="leave-title" class="text-base font-medium">
                Salir del hogar
            </h2>
            <p class="max-w-prose text-sm text-pretty text-muted-foreground">
                Dejarás de ver sus datos y tendrás que crear un hogar o aceptar
                otra invitación. Los datos se quedan en el hogar.
            </p>
            <p
                v-if="leaveBlocker"
                class="rounded-lg border border-warning-border bg-warning-soft p-3 text-sm"
                role="note"
            >
                {{ leaveBlocker }}
            </p>
            <Dialog v-else>
                <DialogTrigger as-child>
                    <Button variant="outline">
                        <LogOut />
                        Salir del hogar
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle
                            >¿Salir de «{{ household.name }}»?</DialogTitle
                        >
                        <DialogDescription v-if="leaveDeletesEmpty">
                            Eres su único miembro y el hogar está vacío: se
                            eliminará al salir. Después podrás crear otro o
                            aceptar una invitación.
                        </DialogDescription>
                        <DialogDescription v-else>
                            Los datos se quedan en el hogar. Para volver, un
                            administrador tendrá que invitarte.
                        </DialogDescription>
                    </DialogHeader>
                    <Form v-bind="leave.form()" v-slot="{ processing }">
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button type="button" variant="outline"
                                    >Cancelar</Button
                                >
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                            >
                                Salir del hogar
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </section>
    </div>
</template>
