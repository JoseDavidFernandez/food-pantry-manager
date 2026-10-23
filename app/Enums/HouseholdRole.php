<?php

namespace App\Enums;

/**
 * Role of a member in their household (RN-38). Permissions are checked on the server through
 * the `household.write` and `household.manage` gates (AppServiceProvider).
 */
enum HouseholdRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Editor => 'Editor',
            self::Viewer => 'Lector',
        };
    }

    /**
     * Create, edit and delete the household data: recipes, menu, stock, lists and receptions.
     */
    public function canWrite(): bool
    {
        return $this !== self::Viewer;
    }

    /**
     * Invite and manage members, their roles and the household settings.
     */
    public function canManage(): bool
    {
        return $this === self::Admin;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $role) => [
            'value' => $role->value,
            'label' => $role->label(),
        ], self::cases());
    }
}
