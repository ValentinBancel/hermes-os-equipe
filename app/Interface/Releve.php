<?php

namespace App\Interface;

use App\Hermes\Exceptions\PontErreur;
use App\Hermes\Exceptions\PontInjoignable;
use App\Hermes\HermesClient;
use App\Hermes\Lecture;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Relevé d'une requête de page : toutes les lectures du pont passent par ici (une instance par requête,
 * y compris chaque rafraîchissement Livewire). Il retient la fraîcheur des données pour l'en-tête et
 * le bandeau « pont injoignable », et calcule les pastilles de navigation.
 *
 * - `fige()` : au moins une lecture périmée (dernières données connues) ou aucune donnée → l'interface
 *   passe en heures absolues (M1), grise le contenu et désactive les actions.
 * - `injoignable()` : le pont n'a pas répondu et aucune donnée n'est connue pour au moins un bloc.
 */
final class Releve
{
    private const CLE_DEPUIS = 'hermes-os:interface:pont_injoignable_depuis';

    private ?CarbonImmutable $plusAncienne = null;

    private bool $perimee = false;

    private bool $injoignable = false;

    /** @var array<string, int>|null */
    private ?array $pastilles = null;

    public function __construct(private readonly HermesClient $client) {}

    public function client(): HermesClient
    {
        return $this->client;
    }

    /**
     * Lecture protégée : null si le pont est injoignable sans donnée connue. Un refus métier 404 du pont
     * devient une page 404 ; les autres refus sont relancés.
     *
     * @param  Closure(HermesClient): Lecture  $lire
     */
    public function lire(Closure $lire): ?Lecture
    {
        try {
            $lecture = $lire($this->client);
        } catch (PontInjoignable) {
            $this->injoignable = true;
            $this->noterPanne();

            return null;
        } catch (PontErreur $e) {
            abort($e->introuvable() ? 404 : 502, $e->getMessage());
        }

        if ($lecture->perimee) {
            $this->perimee = true;
            $this->noterPanne();
        } else {
            Cache::forget(self::CLE_DEPUIS);
        }

        if ($this->plusAncienne === null || $lecture->recupereeA->lt($this->plusAncienne)) {
            $this->plusAncienne = $lecture->recupereeA;
        }

        return $lecture;
    }

    /**
     * Données de la lecture, ou tableau vide si elle a échoué.
     *
     * @param  Closure(HermesClient): Lecture  $lire
     * @return array<string, mixed>
     */
    public function donnees(Closure $lire): array
    {
        return $this->lire($lire)?->donnees ?? [];
    }

    public function fige(): bool
    {
        return $this->perimee || $this->injoignable;
    }

    public function injoignable(): bool
    {
        return $this->injoignable;
    }

    /** Heure des données affichées (la plus ancienne lecture). */
    public function donneesDe(): ?CarbonImmutable
    {
        return $this->plusAncienne;
    }

    /** Premier constat de panne (conservé entre les requêtes, effacé à la première lecture fraîche). */
    public function injoignableDepuis(): ?CarbonImmutable
    {
        $t = Cache::get(self::CLE_DEPUIS);

        return is_int($t) ? CarbonImmutable::createFromTimestamp($t) : null;
    }

    /** Indicateur de fraîcheur de l'en-tête (ux/02 § Fraîcheur). Jamais de relatif sur données figées (M1). */
    public function fraicheur(): string
    {
        if ($this->fige()) {
            return $this->plusAncienne === null
                ? 'Aucune donnée'
                : 'Données de '.Temps::absolu($this->plusAncienne);
        }

        if ($this->plusAncienne === null) {
            return '';
        }

        $age = (int) $this->plusAncienne->diffInSeconds(CarbonImmutable::now(), absolute: true);

        return $age < 5 ? 'Mis à jour à l’instant' : 'Mis à jour il y a '.Temps::duree($age);
    }

    /**
     * Pastilles de navigation : Accueil = décisions attendues, Planifiées = tâches en échec.
     *
     * @return array{accueil: int, planifiees: int}
     */
    public function pastilles(): array
    {
        if ($this->pastilles !== null) {
            return $this->pastilles;
        }

        $elements = $this->donnees(fn (HermesClient $c) => $c->aDecider())['elements'] ?? [];
        $taches = $this->donnees(fn (HermesClient $c) => $c->planifiees())['taches'] ?? [];

        return $this->pastilles = [
            'accueil' => count($elements),
            'planifiees' => count(array_filter($taches, fn (array $t) => Planification::enEchec($t))),
        ];
    }

    private function noterPanne(): void
    {
        Cache::add(self::CLE_DEPUIS, CarbonImmutable::now()->getTimestamp(), now()->addDay());
    }
}
