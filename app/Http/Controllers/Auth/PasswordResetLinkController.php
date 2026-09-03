<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ], [
            'email.required' => "L'adresse e-mail est requise.",
            'email.email' => "Veuillez saisir une adresse e-mail valide.",
        ]);

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        try {
            $status = Password::sendResetLink(
                $request->only('email')
            );
        } catch (TransportExceptionInterface $e) {
            // Misconfigured/unreachable SMTP would otherwise bubble up as a raw 500 — surface
            // it as a normal validation error instead, same as an unknown-email response.
            report($e);

            throw ValidationException::withMessages([
                'email' => ["Impossible d'envoyer l'e-mail pour le moment. Réessayez plus tard ou contactez un administrateur."],
            ]);
        }

        // Password::sendResetLink() returns a framework status key (translated to English by
        // default, since this app doesn't publish/localize Laravel's own lang files) — mapped
        // here to French rather than switching the app's global locale, which would also touch
        // every other controller's plain $request->validate() messages app-wide.
        $messages = [
            Password::RESET_LINK_SENT => 'Nous vous avons envoyé par e-mail votre lien de réinitialisation de mot de passe.',
            Password::INVALID_USER => 'Aucun compte associé à cette adresse e-mail.',
            Password::RESET_THROTTLED => 'Veuillez patienter avant de réessayer.',
        ];

        if ($status == Password::RESET_LINK_SENT) {
            return back()->with('status', $messages[$status] ?? __($status));
        }

        throw ValidationException::withMessages([
            'email' => [$messages[$status] ?? trans($status)],
        ]);
    }
}
