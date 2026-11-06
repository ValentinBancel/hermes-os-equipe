<?php

use App\Models\AuditLog;

/*
 * Sessions : 30 min d'inactivité, 12 h maximum (ADR 0003).
 */

it('expire la session après 30 minutes d\'inactivité', function () {
    [$user] = $this->compteEnrole();

    $this->connecteAvecTotp($user)->get('/')->assertOk();

    $this->travel(31)->minutes();

    $this->get('/')->assertRedirect('/connexion');
    $this->assertGuest();
    expect(AuditLog::where('event', 'session.expiree')->value('context'))->toBe(['motif' => 'inactivite']);
});

it('prolonge la session tant qu\'il y a de l\'activité', function () {
    [$user] = $this->compteEnrole();

    $this->connecteAvecTotp($user)->get('/')->assertOk();

    foreach (range(1, 3) as $_) {
        $this->travel(20)->minutes();
        $this->get('/')->assertOk();
    }
});

it('ferme la session après 12 heures même en cas d\'activité', function () {
    [$user] = $this->compteEnrole();

    $this->connecteAvecTotp($user)->get('/')->assertOk();

    foreach (range(1, 26) as $_) {
        $this->travel(29)->minutes();
        $this->get('/');
    }

    $this->assertGuest();
    expect(AuditLog::where('event', 'session.expiree')->value('context'))->toBe(['motif' => 'duree_maximale']);
});

it('configure des cookies de session stricts', function () {
    expect(config('session.driver'))->toBe('array') // phpunit.xml ; `database` hors tests
        // Plancher de 8 h (appareil de confiance) ; les 30 min restent appliquées par SessionLimitee.
        ->and(config('session.lifetime'))->toBe(480)
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('strict')
        ->and(config('session.encrypt'))->toBeTrue()
        ->and(config('session.cookie'))->toBe('sid');
});

it('utilise des cookies Secure et une session en base par défaut (production)', function () {
    // Défauts de config/session.php quand le .env ne fixe rien (phpunit.xml les surcharge pour les tests).
    $source = file_get_contents(config_path('session.php'));

    expect($source)->toContain("env('SESSION_DRIVER', 'database')")
        ->toContain("env('SESSION_SECURE_COOKIE', true)")
        ->toContain("env('SESSION_LIFETIME', 30)")
        ->toContain("env('SESSION_SAME_SITE', 'strict')")
        ->toContain("env('SESSION_ENCRYPT', true)");
});
