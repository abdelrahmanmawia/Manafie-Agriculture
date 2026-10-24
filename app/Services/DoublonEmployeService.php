<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Workers of the farm who look like the one being added: a name a letter or two away (KORCHI
 * HADOUM / KORCHI HADDOUM), the same name in another order or without doubled letters / "EL",
 * or a CIN a typo away (G35675 / G535675). The exact same CIN is already refused outright by the
 * unique rule; this only warns, since two real people can share a name.
 */
class DoublonEmployeService
{
    public function similaires(int $farmId, string $nom, ?string $cin, ?int $saufId = null): Collection
    {
        $nom = $this->nom($nom);
        $cin = $this->cin($cin);

        return Employee::with('enterprise:id,name')->where('farm_id', $farmId)
            ->when($saufId, fn ($q) => $q->whereKeyNot($saufId))
            ->get(['id', 'matricule', 'full_name', 'cin', 'enterprise_id', 'is_active'])
            ->filter(function (Employee $e) use ($nom, $cin) {
                $autre = $this->nom($e->full_name);
                $nomProche = $nom !== '' && (levenshtein($nom, $autre) <= 2
                    || levenshtein($this->trie($nom), $this->trie($autre)) <= 2
                    || $this->ecrase($nom) === $this->ecrase($autre));
                $autreCin = $this->cin($e->cin);
                $cinProche = $cin && $autreCin && $cin !== $autreCin
                    && (str_contains($cin, $autreCin) || str_contains($autreCin, $cin) || levenshtein($cin, $autreCin) <= 1);

                return $nomProche || $cinProche;
            })
            ->values();
    }

    private function nom(?string $s): string
    {
        $s = strtr(mb_strtoupper((string) $s), ['É' => 'E', 'È' => 'E', 'Ê' => 'E', 'À' => 'A', 'Ï' => 'I', 'Î' => 'I', 'Ô' => 'O', 'Ç' => 'C', '-' => ' ']);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z ]/', '', $s)));
    }

    private function cin(?string $s): ?string
    {
        $s = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $s));

        return strlen($s) >= 5 ? $s : null;
    }

    /** Words in alphabetical order: "MINA EL KORCHI" = "EL KORCHI MINA". */
    private function trie(string $nom): string
    {
        $mots = explode(' ', $nom);
        sort($mots);

        return implode(' ', $mots);
    }

    /** Without spaces, "EL" and doubled letters: HADDOUM = HADOUM, ELKORCHI = KORCHI. */
    private function ecrase(string $nom): string
    {
        return preg_replace('/(.)\1+/', '$1', str_replace([' ', 'EL'], '', $this->trie($nom)));
    }
}
