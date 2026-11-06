<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Aucun appel réseau en test (Password::uncompromised interroge Have I Been Pwned).
        Http::preventStrayRequests();
        $this->withoutVite();
    }

    /**
     * Compte avec TOTP confirmé, et son secret en clair.
     *
     * @return array{0: User, 1: string}
     */
    protected function compteEnrole(): array
    {
        $secret = app(Google2FA::class)->generateSecretKey();

        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['code-recup-1', 'code-recup-2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$user, $secret];
    }

    protected function codeTotp(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    /**
     * Session complète (mot de passe + TOTP) sans passer par les formulaires.
     */
    protected function connecteAvecTotp(User $user): static
    {
        $maintenant = now()->getTimestamp();

        return $this->actingAs($user)->withSession([
            'hermes.connecte_a' => $maintenant,
            'hermes.derniere_activite' => $maintenant,
            'hermes.totp_confirme_a' => $maintenant,
        ]);
    }
}
