<?php

use App\Interface\Markdown;
use Illuminate\Http\Middleware\TrustProxies;

/*
 * En-têtes de sécurité, CSP avec nonce, noindex, liste d'IP (ADR 0004, architecture.md).
 */

it('répond 200 sur /up', function () {
    $this->get('/up')->assertOk();
});

it('envoie les en-têtes de sécurité sur toutes les réponses', function (string $url) {
    $reponse = $this->get($url);

    $reponse->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertHeaderMissing('X-Powered-By');

    expect($reponse->headers->get('Permissions-Policy'))->toContain('camera=()');
    expect($reponse->headers->get('Content-Security-Policy'))
        ->toMatch("/^default-src 'self'; script-src 'self' 'nonce-[A-Za-z0-9]+'; style-src 'self' 'nonce-[A-Za-z0-9]+'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'$/")
        ->not->toContain('unsafe-eval')
        ->not->toContain('unsafe-inline');
})->with(['/connexion', '/', '/up', '/nexiste-pas']);

it('change de nonce à chaque requête', function () {
    $a = $this->get('/connexion')->headers->get('Content-Security-Policy');
    $b = $this->get('/connexion')->headers->get('Content-Security-Policy');

    expect($a)->not->toBe($b);
});

it('pose le nonce de la CSP sur les scripts Livewire (mode csp_safe)', function () {
    [$user] = $this->compteEnrole();

    $reponse = $this->connecteAvecTotp($user)->get('/')->assertOk();

    preg_match("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $reponse->headers->get('Content-Security-Policy'), $m);
    $html = $reponse->getContent();

    expect(config('livewire.csp_safe'))->toBeTrue()
        ->and($html)->toContain('nonce="'.$m[1].'"');

    // Le script servi par Livewire est bien le build CSP (sans eval), minifié ou non selon APP_DEBUG.
    preg_match('#<script src="[^"]*(/livewire-[^"?]+/livewire(?:\.min)?\.js)\?#', $html, $src);
    expect($src)->toHaveKey(1);
    $script = $this->get($src[1])->assertOk()->baseResponse;
    expect($script->getFile()->getPathname())->toMatch('#dist/livewire\.csp(\.min)?\.js$#');
    // Tous les <script> portent le nonce.
    preg_match_all('/<script\b[^>]*>/', $html, $scripts);
    foreach ($scripts[0] as $balise) {
        expect($balise)->toContain('nonce="'.$m[1].'"');
    }
});

it('interdit l\'indexation par robots.txt', function () {
    expect(file_get_contents(public_path('robots.txt')))->toBe("User-agent: *\nDisallow: /\n");
});

it('n\'expose aucune route d\'inscription, de réinitialisation ou de gestion Fortify', function (string $methode, string $url) {
    $this->call($methode, $url)->assertNotFound();
})->with([
    ['GET', '/register'],
    ['POST', '/register'],
    ['GET', '/forgot-password'],
    ['POST', '/forgot-password'],
    ['POST', '/reset-password'],
    ['GET', '/login'],
    ['GET', '/two-factor-challenge'],
    ['POST', '/user/two-factor-authentication'],
    ['DELETE', '/user/two-factor-authentication'],
    ['GET', '/user/two-factor-recovery-codes'],
    ['PUT', '/user/password'],
    ['PUT', '/user/profile-information'],
    ['GET', '/passkeys/login/options'],
    ['GET', '/email/verify'],
]);

it('n\'enregistre que les routes prévues', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())
        ->reject(fn ($r) => str_contains($r, 'livewire'))
        ->reject(fn ($r) => str_contains($r, 'storage/{path}'))
        ->sort()->values()->all();

    expect($routes)->toBe([
        'GET|HEAD /',
        'GET|HEAD compte',
        'GET|HEAD compte/journal',
        'GET|HEAD connexion',
        'GET|HEAD connexion/code',
        'GET|HEAD consommation',
        'GET|HEAD consommation/sessions',
        'GET|HEAD messagerie',
        'GET|HEAD messagerie/{interlocuteur}',
        'GET|HEAD messagerie/{interlocuteur}/{conversation}',
        'GET|HEAD planifiees',
        'GET|HEAD planifiees/{id}',
        'GET|HEAD planifiees/{id}/sorties/{sortie}',
        'GET|HEAD poles',
        'GET|HEAD poles/{profil}',
        'GET|HEAD pont/fichiers/{module}',
        'GET|HEAD pont/installer.sh',
        'GET|HEAD pont/v1/actions',
        'GET|HEAD securite/codes',
        'GET|HEAD securite/mot-de-passe',
        'GET|HEAD securite/totp',
        'GET|HEAD up',
        'GET|HEAD vault',
        'POST connexion',
        'POST connexion/code',
        'POST deconnexion',
        'POST pont/v1/actions/{id}/resultat',
        'POST pont/v1/appairage',
        'POST pont/v1/releves',
        'POST securite/mot-de-passe',
        'POST securite/totp',
    ]);
});

describe('liste d\'IP', function () {
    it('est désactivée par défaut', function () {
        expect(config('hermes-os.ip_allowlist'))->toBe([]);
        $this->get('/connexion')->assertOk();
    });

    it('refuse les IP hors liste, sauf /up', function () {
        config(['hermes-os.ip_allowlist' => ['203.0.113.0/24']]);

        $this->get('/connexion', ['REMOTE_ADDR' => '198.51.100.7'])->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/connexion')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/up')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/connexion')->assertOk();
    });

    it('ne fait confiance à X-Forwarded-For que depuis un proxy déclaré (aucun par défaut)', function () {
        config(['hermes-os.ip_allowlist' => ['203.0.113.0/24']]);
        expect(config('hermes-os.trusted_proxies'))->toBe([]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.20'])
            ->withHeader('X-Forwarded-For', '203.0.113.9')
            ->get('/connexion')
            ->assertForbidden();

        TrustProxies::at(['10.0.1.0/24']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeader('X-Forwarded-For', '203.0.113.9')
            ->get('/connexion')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.20'])
            ->withHeader('X-Forwarded-For', '203.0.113.9')
            ->get('/connexion')
            ->assertOk();

        TrustProxies::flushState();
    });
});

it('ne fait aucun appel à une police ou un script externe', function () {
    $html = $this->get('/connexion')->getContent();
    $locales = preg_quote(rtrim(config('app.url'), '/'), '#');

    expect($html)->not->toMatch('#(src|href)="https?://(?!'.substr($locales, strpos($locales, '//') + 2).')#');
});

it('rend les liens externes avec noopener et remplace les images distantes par leur texte', function () {
    $html = (string) Markdown::rendre('[site](https://exemple.test/x) ![logo](https://pixel.test/p.png) [js](javascript:alert(1))');

    expect($html)->toContain('rel="noopener noreferrer"')->toContain('target="_blank"')
        ->not->toContain('<img')->not->toContain('pixel.test')->toContain('logo')->not->toContain('javascript:');
});
