<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'farm_id',
        'enterprise_id',
        'role',
        'acces_pointage',
        'acces_stock',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /** Access to a domain (pointage, stock): none, read and export, or everything. */
    public const NIVEAUX = ['aucun', 'consultation', 'saisie'];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    /**
     * This account's level on a domain ('pointage' or 'stock'): users.acces_pointage /
     * acces_stock, whatever the role — the role only says who it is (responsable or not). A
     * super_admin has saisie everywhere.
     */
    public function acces(string $domaine): string
    {
        return $this->role === 'super_admin' ? 'saisie' : ($this->{'acces_'.$domaine} ?? 'aucun');
    }

    public function peutConsulter(string $domaine): bool
    {
        return $this->acces($domaine) !== 'aucun';
    }

    public function peutSaisir(string $domaine): bool
    {
        return $this->acces($domaine) === 'saisie';
    }

    public function canAccessPointage(): bool
    {
        return $this->peutConsulter('pointage');
    }

    public function canAccessStock(): bool
    {
        return $this->peutConsulter('stock');
    }
}
