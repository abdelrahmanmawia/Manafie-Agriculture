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
        'can_access_pointage',
        'can_access_stock',
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
        'can_access_pointage' => 'boolean',
        'can_access_stock' => 'boolean',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    /**
     * super_admin/farm_manager always have full access regardless of these flags — they only
     * ever restrict the data_entry tier, which is otherwise scoped to "everything except
     * create/delete/toggle" across both domains. A magasinier and a pointeur are both
     * data_entry underneath; these flags are what actually tells them apart.
     */
    public function canAccessPointage(): bool
    {
        return $this->role !== 'data_entry' || $this->can_access_pointage;
    }

    public function canAccessStock(): bool
    {
        return $this->role !== 'data_entry' || $this->can_access_stock;
    }
}
