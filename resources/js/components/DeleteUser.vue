<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
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
import { Label } from '@/components/ui/label';

defineProps<{
    /** Why the account cannot be deleted now (RN-38), or null. */
    blocker?: string | null;
}>();

const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Eliminar la cuenta"
            description="Elimina tu cuenta. Los datos de tu hogar (alimentos, recetas, menús y listas) se quedan en el hogar para los demás miembros."
        />
        <div
            class="space-y-4 rounded-lg border border-destructive/30 bg-destructive/5 p-4"
        >
            <div class="relative space-y-0.5 text-destructive">
                <p class="font-medium">Atención</p>
                <p class="text-sm">Esta acción no se puede deshacer.</p>
            </div>
            <p
                v-if="blocker"
                class="text-sm"
                role="note"
                data-test="delete-user-blocker"
            >
                {{ blocker }}
            </p>
            <Dialog v-else>
                <DialogTrigger as-child>
                    <Button variant="destructive" data-test="delete-user-button"
                        >Eliminar la cuenta</Button
                    >
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        @error="() => passwordInput?.focus()"
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle
                                >¿Seguro que quieres eliminar tu
                                cuenta?</DialogTitle
                            >
                            <DialogDescription>
                                Al eliminar la cuenta se borrarán también, de
                                forma permanente, todos sus datos. Escribe tu
                                contraseña para confirmar que quieres
                                eliminarla.
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="password" class="sr-only"
                                >Contraseña</Label
                            >
                            <PasswordInput
                                id="password"
                                name="password"
                                ref="passwordInput"
                                placeholder="Contraseña"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    variant="secondary"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    Cancelar
                                </Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                Eliminar la cuenta
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
