import type { Option, UnitOption } from '@/types/pantry';

export type OptionGroup = { label: string | null; options: Option[] };

const KINDS: { kind: NonNullable<UnitOption['kind']>; label: string }[] = [
    { kind: 'measure', label: 'Medidas' },
    { kind: 'piece', label: 'Piezas' },
    { kind: 'pack', label: 'Envases' },
];

/**
 * Sections of a unit selector (2026-10-28): the food's own units first, when known, then
 * measures, pieces and packs. A unit no longer offered (bandeja, caja, malla) only appears when
 * it is the current value, so existing data can still be edited. Options without a kind (a
 * location, a category) stay in one plain group.
 */
export function groupOptions(
    options: Option[],
    current?: string | null,
    preferred: string[] = [],
): OptionGroup[] {
    const units = options as UnitOption[];

    if (!units.some((option) => option.kind !== undefined)) {
        return [{ label: null, options }];
    }

    const visible = units.filter(
        (option) => option.offered !== false || option.value === current,
    );
    const own = visible.filter((option) => preferred.includes(option.value));
    const rest = visible.filter((option) => !preferred.includes(option.value));

    return [
        ...(own.length > 0
            ? [{ label: 'De este alimento', options: own }]
            : []),
        ...KINDS.map(({ kind, label }) => ({
            label,
            options: rest.filter((option) => option.kind === kind),
        })),
    ].filter((group) => group.options.length > 0);
}
