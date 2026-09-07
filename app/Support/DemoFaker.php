<?php

namespace App\Support;

/**
 * A tiny, dependency-free random-data generator for `demo:seed-test-data`.
 * Not a `fake()`/FakerPHP replacement in general — `fakerphp/faker` is
 * deliberately `require-dev` only in composer.json (kept out of the
 * production footprint), and this command is meant to run in production
 * (`deploy.sh` runs `composer install --no-dev`, so `fake()` is simply
 * absent there). This class exists so the seeder needs nothing beyond
 * what's already installed everywhere.
 */
class DemoFaker
{
    private const FIRST_NAMES = [
        'James', 'Maria', 'Juan', 'Ana', 'Carlos', 'Sofia', 'Miguel', 'Elena',
        'Pedro', 'Isabel', 'Antonio', 'Carmen', 'Luis', 'Rosa', 'Manuel', 'Teresa',
        'Francisco', 'Patricia', 'Rafael', 'Gloria', 'Ricardo', 'Angela', 'Eduardo', 'Diana',
        'Roberto', 'Susana', 'Fernando', 'Beatriz', 'Alberto', 'Cristina', 'Diego', 'Laura',
        'Emilio', 'Veronica', 'Gabriel', 'Monica', 'Hector', 'Adriana', 'Ignacio', 'Paula',
    ];

    private const LAST_NAMES = [
        'Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Torres', 'Flores',
        'Ramos', 'Mendoza', 'Castro', 'Villanueva', 'Aquino', 'Del Rosario', 'Domingo', 'Gonzales',
        'Fernandez', 'Navarro', 'Pascual', 'Salazar', 'Tolentino', 'Valdez', 'Ignacio', 'Lozada',
        'Marquez', 'Nazario', 'Pineda', 'Quintos', 'Rivera', 'Sarmiento', 'Trinidad', 'Uy',
        'Velasco', 'Yap', 'Zamora', 'Abad', 'Bernardo', 'Concepcion', 'Dizon', 'Estrella',
    ];

    private const COMPANY_WORDS = [
        'Summit', 'Harbor', 'Meridian', 'Cornerstone', 'Northgate', 'Silverline', 'Riverside', 'Union',
        'Continental', 'Pioneer', 'Beacon', 'Vantage', 'Anchor', 'Crestview', 'Lakeside', 'Ironwood',
    ];

    private const COMPANY_SUFFIXES = ['Inc.', 'Holdings', 'Corp.', 'Group', 'Realty', 'Ventures', 'Enterprises'];

    private const STREET_NAMES = ['Acacia', 'Mabini', 'Rizal', 'Bonifacio', 'Kalayaan', 'Maharlika', 'Sampaguita', 'Ilang-Ilang'];

    private static int $uniqueCounter = 0;

    public static function firstName(): string
    {
        return self::pick(self::FIRST_NAMES);
    }

    public static function lastName(): string
    {
        return self::pick(self::LAST_NAMES);
    }

    public static function suffix(): string
    {
        return self::pick(['Jr.', 'Sr.', 'III']);
    }

    public static function companyName(): string
    {
        return self::pick(self::COMPANY_WORDS).' '.self::pick(self::COMPANY_SUFFIXES);
    }

    public static function email(string $localPartSeed): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $localPartSeed) ?? 'user');

        return trim($slug, '.').'.'.(++self::$uniqueCounter).'@example.test';
    }

    public static function phoneNumber(): string
    {
        return '09'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    }

    public static function address(): string
    {
        return random_int(1, 999).' '.self::pick(self::STREET_NAMES).' Street, Barangay '.random_int(1, 50).', Metro City';
    }

    public static function gender(): string
    {
        return self::pick(['male', 'female', 'prefer_not_to_say']);
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T
     */
    public static function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    public static function numberBetween(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    public static function boolean(int $chanceOfTruePercent = 50): bool
    {
        return random_int(1, 100) <= $chanceOfTruePercent;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    public static function optional(int $chanceOfPresentPercent, callable $callback): mixed
    {
        return self::boolean($chanceOfPresentPercent) ? $callback() : null;
    }

    public static function pastDate(string $earliest = '-3 years', string $latest = '-1 month'): string
    {
        return self::dateBetween($earliest, $latest);
    }

    public static function futureDate(string $earliest = '+1 month', string $latest = '+2 years'): string
    {
        return self::dateBetween($earliest, $latest);
    }

    private static function dateBetween(string $earliest, string $latest): string
    {
        $start = strtotime($earliest);
        $end = strtotime($latest);

        return date('Y-m-d', random_int($start, $end));
    }
}
