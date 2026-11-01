<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Moroccan RIB: 24 digits whose last two are a key making the whole number divisible by 97, so
 * a mistyped digit is caught. Spaces don't count. Only a new value is checked: a RIB already saved
 * before this rule ($actuel) doesn't block editing the rest of the form.
 */
class RibMarocain implements ValidationRule
{
    public function __construct(private ?string $actuel = null) {}

    public static function normaliser(?string $rib): string
    {
        return preg_replace('/\s+/', '', (string) $rib);
    }

    /** What is wrong with this RIB, or null when it is valid. */
    public static function erreur(string $rib): ?string
    {
        $rib = self::normaliser($rib);
        if (! ctype_digit($rib)) {
            return 'Le RIB ne doit contenir que des chiffres.';
        }
        if (strlen($rib) !== 24) {
            return 'Le RIB doit contenir 24 chiffres ('.strlen($rib).' saisis).';
        }
        // Digit by digit: 24 digits don't fit in an integer.
        $reste = 0;
        foreach (str_split($rib) as $chiffre) {
            $reste = ($reste * 10 + (int) $chiffre) % 97;
        }

        return $reste === 0 ? null : 'RIB incorrect : la clé ne correspond pas, vérifiez les chiffres.';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::normaliser($value) === self::normaliser($this->actuel)) {
            return;
        }
        if ($erreur = self::erreur((string) $value)) {
            $fail($erreur);
        }
    }
}
