<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * NIP de 6 dígitos que no se adivine en los pocos intentos que permite el
 * login: sin dígitos repetidos, sin secuencias y sin la fecha de nacimiento,
 * que además va escrita en el RFC.
 */
class SecureNip implements ValidationRule
{
    public const LENGTH = 6;

    /** @param  string|null  $birthdate  Y-m-d, como se guarda en employees. */
    public function __construct(private readonly ?string $birthdate = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $nip = (string) $value;

        if (preg_match('/^\d{'.self::LENGTH.'}$/', $nip) !== 1) {
            $fail('El NIP debe tener exactamente 6 dígitos.');

            return;
        }

        if (self::isGuessable($nip, $this->birthdate)) {
            $fail('Elige un NIP más difícil de adivinar: sin números repetidos, seguidos ni tu fecha de nacimiento.');
        }
    }

    public static function isGuessable(string $nip, ?string $birthdate = null): bool
    {
        $digits = array_map('intval', str_split($nip));
        $steps = array_unique(array_map(
            fn (int $index) => $digits[$index + 1] - $digits[$index],
            array_keys(array_slice($digits, 1)),
        ));

        // Un solo paso entre dígitos: 0 = 111111, 1 = 123456, -1 = 654321.
        if (count($steps) === 1 && in_array(reset($steps), [-1, 0, 1], true)) {
            return true;
        }

        if ($birthdate === null || preg_match('/^\d{2}(\d{2})-(\d{2})-(\d{2})/', $birthdate, $date) !== 1) {
            return false;
        }

        [, $year, $month, $day] = $date;

        return in_array($nip, [$day.$month.$year, $year.$month.$day], true);
    }
}
