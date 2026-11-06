<?php

namespace App\Hermes;

use App\Hermes\Exceptions\PontErreur;
use App\Hermes\Exceptions\PontInjoignable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Client du pont hôte `hermes-os-pont` : HTTP sur socket Unix, chaque requête signée
 * (X-Pont-Horodatage + X-Pont-Signature = HMAC-SHA256 de « horodatage\nMÉTHODE\nchemin?requête\ncorps »).
 *
 * Lectures : cache court (3-5 s, évite de marteler les SQLite de Hermes pendant le wire:poll).
 * Si le pont ne répond plus, la dernière réponse connue est rendue avec `perimee = true`
 * (l'interface affiche son âge) ; sans donnée connue, PontInjoignable.
 */
final class PontClient implements HermesClient
{
    use RoutesDuPont;

    private const PREFIXE_CACHE = 'hermes-os:pont:';

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly string $socket,
        private readonly ?string $secret,
        private readonly int $cacheSecondes = 4,
        private readonly int $delaiLecture = 5,
        private readonly int $delaiAction = 35,
    ) {}

    public function pilote(): string
    {
        return 'pont';
    }

    public function lire(string $chemin, array $requete = []): Lecture
    {
        $cible = $requete === [] ? $chemin : $chemin.'?'.http_build_query($requete, '', '&', PHP_QUERY_RFC3986);
        $cle = self::PREFIXE_CACHE.$this->generation().':'.hash('sha256', $cible);

        $frais = $this->cache->get($cle);
        if (is_array($frais)) {
            return $this->versLecture($frais);
        }

        try {
            $reponse = $this->envoyer('GET', $cible, '', $this->delaiLecture);
        } catch (PontInjoignable $e) {
            $derniere = $this->cache->get(self::PREFIXE_CACHE.'derniere:'.hash('sha256', $cible));
            if (is_array($derniere)) {
                return $this->versLecture($derniere, perimee: true, raison: $e->getMessage());
            }

            throw $e;
        }

        $entree = ['donnees' => $this->decoder($reponse), 'a' => CarbonImmutable::now()->getTimestamp()];
        $this->cache->put($cle, $entree, $this->cacheSecondes);
        $this->cache->put(self::PREFIXE_CACHE.'derniere:'.hash('sha256', $cible), $entree, now()->addDay());

        return $this->versLecture($entree);
    }

    public function action(Action $action, array $donnees): ResultatAction
    {
        $corps = json_encode($donnees, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $reponse = $this->envoyer('POST', '/v1/actions/'.$action->value, $corps, $this->delaiAction);

        $json = $reponse->json();
        if (is_array($json) && array_key_exists('ok', $json)) {
            // 200 (code de sortie de la CLI), 503 (CLI absente) ou 504 (délai) : résultat d'exécution.
            return ResultatAction::depuisReponse($action, $json);
        }

        $json = $this->decoder($reponse);

        if ($action->messagerie()) {
            // La messagerie répond 202/200 sans champ `ok` : {conversation, statut}.
            return new ResultatAction($action, true, 0, is_string($json['conversation'] ?? null) ? $json['conversation'] : '');
        }

        throw new PontInjoignable('Réponse inattendue du pont.');
    }

    public function oublierCache(): void
    {
        // Pas de cache par étiquettes avec le store `database` : on change de génération.
        $this->cache->forever(self::PREFIXE_CACHE.'generation', $this->generation() + 1);
    }

    private function generation(): int
    {
        return (int) $this->cache->get(self::PREFIXE_CACHE.'generation', 0);
    }

    /**
     * Signature HMAC d'une requête (exposée pour les tests et l'outil de diagnostic).
     */
    public static function signer(string $secret, string $horodatage, string $methode, string $cible, string $corps): string
    {
        return hash_hmac('sha256', $horodatage."\n".strtoupper($methode)."\n".$cible."\n".$corps, $secret);
    }

    private function envoyer(string $methode, string $cible, string $corps, int $delai): Response
    {
        if ($this->secret === null || strlen($this->secret) < 32) {
            throw new PontInjoignable('Secret du pont absent ou trop court (HERMES_PONT_SECRET).');
        }

        $horodatage = (string) CarbonImmutable::now()->getTimestamp();
        $requete = $this->http
            ->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $this->socket]])
            ->withHeaders([
                'X-Pont-Horodatage' => $horodatage,
                'X-Pont-Signature' => self::signer($this->secret, $horodatage, $methode, $cible, $corps),
                'Accept' => 'application/json',
            ])
            ->connectTimeout(2)
            ->timeout($delai);

        try {
            $reponse = $methode === 'POST'
                ? $requete->withBody($corps, 'application/json')->post('http://pont'.$cible)
                : $requete->get('http://pont'.$cible);
        } catch (ConnectionException $e) {
            Log::warning('hermes-os-pont injoignable', ['chemin' => parse_url($cible, PHP_URL_PATH)]);

            throw new PontInjoignable('Pont injoignable.', previous: $e);
        }

        if ($reponse->status() === 401) {
            Log::error('hermes-os-pont : signature refusée (secret ou horloge)', ['chemin' => parse_url($cible, PHP_URL_PATH)]);

            throw new PontInjoignable('Signature refusée par le pont.');
        }

        if ($reponse->serverError() && ! ($methode === 'POST' && is_array($reponse->json()) && array_key_exists('ok', $reponse->json()))) {
            throw new PontInjoignable('Le pont ne peut pas lire Hermes ('.$reponse->status().').');
        }

        return $reponse;
    }

    /**
     * @return array<string, mixed>
     */
    private function decoder(Response $reponse): array
    {
        $json = $reponse->json();

        if ($reponse->successful() && is_array($json)) {
            return $json;
        }

        if ($reponse->clientError()) {
            $message = is_array($json) && is_string($json['erreur'] ?? null) ? $json['erreur'] : 'Requête refusée.';

            throw new PontErreur($reponse->status(), $message);
        }

        throw new PontInjoignable('Réponse illisible du pont.');
    }

    /**
     * @param  array{donnees: array<string, mixed>, a: int}  $entree
     */
    private function versLecture(array $entree, bool $perimee = false, ?string $raison = null): Lecture
    {
        return new Lecture($entree['donnees'], CarbonImmutable::createFromTimestamp($entree['a']), $perimee, $raison);
    }
}
