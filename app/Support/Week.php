<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Calendar weeks from Monday to Sunday (RN-15). "Today" is evaluated in the display
 * timezone (Europe/Madrid by default), not in UTC (CA-17).
 */
final class Week
{
    private function __construct(public readonly CarbonImmutable $start) {}

    public static function current(): self
    {
        return self::containing(CarbonImmutable::now(self::timezone())->format('Y-m-d'));
    }

    /**
     * @throws InvalidArgumentException when the value is not a Y-m-d date
     */
    public static function containing(string $date): self
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

        if ($parsed === null || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Invalid date [{$date}].");
        }

        return new self($parsed->startOfWeek(CarbonImmutable::MONDAY));
    }

    public static function timezone(): string
    {
        return (string) config('app.display_timezone', 'Europe/Madrid');
    }

    public function key(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function previous(): self
    {
        return new self($this->start->subWeek());
    }

    public function next(): self
    {
        return new self($this->start->addWeek());
    }

    public function isCurrent(): bool
    {
        return $this->key() === self::current()->key();
    }

    /**
     * @return list<array{day: int, date: string, label: string}>
     */
    public function days(): array
    {
        $days = [];

        for ($day = 1; $day <= 7; $day++) {
            $date = $this->start->addDays($day - 1);
            $days[] = [
                'day' => $day,
                'date' => $date->format('Y-m-d'),
                'label' => ucfirst(self::spanish($date, 'dddd D')),
            ];
        }

        return $days;
    }

    public function label(): string
    {
        $end = $this->start->addDays(6);
        $startText = $this->start->month === $end->month
            ? $this->start->format('j')
            : self::spanish($this->start, 'D [de] MMMM');

        return "Semana del {$startText} al ".self::spanish($end, 'D [de] MMMM [de] YYYY');
    }

    public static function spanish(CarbonImmutable $date, string $format): string
    {
        $localized = $date->locale('es');

        return ($localized instanceof CarbonImmutable ? $localized : $date)->isoFormat($format);
    }
}
