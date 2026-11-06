<?php

use App\Hermes\HermesClient;
use App\Interface\Libelles;
use App\Interface\Nombres;
use App\Interface\Temps;
use Illuminate\Support\Carbon;

/*
 * Formateur de temps unique (M1), accords (m7), et absence de temps relatif sur données figées.
 */

afterEach(fn () => Carbon::setTestNow());

it('formate le temps : relatif sous 24 h, puis hier, puis date (M1)', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 14:30:00', 'Europe/Paris'));

    expect(Temps::quand(Carbon::parse('2026-10-08 14:27:00', 'Europe/Paris')))->toBe("il y a 3\u{00A0}min")
        ->and(Temps::quand(Carbon::parse('2026-10-08 14:37:00', 'Europe/Paris')))->toBe("dans 7\u{00A0}min")
        ->and(Temps::quand(Carbon::parse('2026-10-08 14:29:58', 'Europe/Paris')))->toBe('à l’instant')
        ->and(Temps::quand(Carbon::parse('2026-10-08 06:53:00', 'Europe/Paris')))->toBe("il y a 7\u{00A0}h\u{00A0}37")
        ->and(Temps::absolu(Carbon::parse('2026-10-07 18:02:00', 'Europe/Paris')))->toBe('hier 18:02')
        ->and(Temps::quand(Carbon::parse('2026-10-06 18:02:00', 'Europe/Paris')))->toBe('06/10 18:02')
        ->and(Temps::quand(Carbon::parse('2026-10-08 14:27:00', 'Europe/Paris'), fige: true))->toBe('14:27')
        ->and(Temps::quand(null))->toBe('—');
});

it('colle l’unité à son nombre par une insécable (D7)', function () {
    expect(Libelles::insecables('1 erreur depuis 24 h · 0-24 h · 11,3 M · 7 j · 15 min'))
        ->toBe("1 erreur depuis 24\u{00A0}h · 0-24\u{00A0}h · 11,3\u{00A0}M · 7\u{00A0}j · 15\u{00A0}min")
        ->and(Libelles::insecables('2 hommes, 3 jours'))->toBe('2 hommes, 3 jours')
        ->and(Libelles::insecables("24\u{00A0}h"))->toBe("24\u{00A0}h");
});

it('accorde 0 et 1 au singulier (m7)', function () {
    expect(Nombres::accord(0, 'tâche', 'tâches'))->toBe("0\u{00A0}tâche")
        ->and(Nombres::accord(1, 'tâche', 'tâches'))->toBe("1\u{00A0}tâche")
        ->and(Nombres::accord(2, 'tâche', 'tâches'))->toBe("2\u{00A0}tâches");
});

it('n’affiche aucun temps relatif hors du bandeau quand le pont est injoignable (M1)', function (string $url) {
    [$user] = $this->compteEnrole();
    config(['hermes-os.pont.demo_etat' => 'pont_injoignable']);
    app()->forgetInstance(HermesClient::class);

    $html = $this->connecteAvecTotp($user)->get($url)->assertOk()->getContent();
    $main = str($html)->after('<main')->before('</main>')->toString();
    $texte = html_entity_decode(strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $main)));

    expect($main)->not->toBe('')
        ->and($texte)->not->toMatch('/il y a \d|dans \d+[ \x{00A0}](s|min|h|j)\b/u');
})->with(['/', '/planifiees', '/planifiees/c3d4e5f6a1b2', '/poles', '/vault']);
