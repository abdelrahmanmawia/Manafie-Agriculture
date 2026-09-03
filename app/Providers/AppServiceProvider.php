<?php

namespace App\Providers;

use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // StockMovement.reference_type stores these short keys (not FQCNs) to resolve
        // StockMovement::reference() — the source document behind a movement.
        Relation::morphMap([
            'manual_entry' => ManualStockEntry::class,
            'fuel_transaction' => FuelTransaction::class,
        ]);

        // The app's own locale stays 'en' (nothing else here uses Laravel's translation
        // system — UI text is hardcoded French in the React pages), so this is a targeted
        // French rewrite of the reset-password email content rather than a locale switch.
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            $expireMinutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject('Réinitialisation de votre mot de passe - Gestion Agricole')
                ->greeting('Bonjour,')
                ->line('Vous recevez cet e-mail car une demande de réinitialisation de mot de passe a été effectuée pour votre compte.')
                ->action('Réinitialiser le mot de passe', $url)
                ->line("Ce lien de réinitialisation expirera dans {$expireMinutes} minutes.")
                ->line("Si vous n'êtes pas à l'origine de cette demande, aucune action n'est requise.")
                ->salutation('Cordialement, L\'équipe Gestion Agricole');
        });
    }
}
