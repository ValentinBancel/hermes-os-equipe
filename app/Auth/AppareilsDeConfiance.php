<?php

namespace App\Auth;

use App\Models\AppareilDeConfiance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Appareils de confiance (ADR 0003, amendement) : la connexion saute le code TOTP seulement si les DEUX conditions
 * tiennent — cookie d'appareil valide (30 jours, révocable) ET adresse IP dans un réseau de confiance du compte.
 * Le mot de passe reste exigé. Les actions sensibles redemandent toujours le code (TOTP < 15 min inchangé).
 */
final class AppareilsDeConfiance
{
    public const COOKIE = 'appareil';

    public const CLE_SESSION = 'hermes.appareil_confiance';

    /** Le réseau courant est-il déclaré de confiance pour ce compte ? */
    public function ipDeConfiance(User $user, ?string $ip): bool
    {
        $plages = $user->reseauxDeConfiance()->pluck('plage')->all();

        return $ip !== null && $ip !== '' && $plages !== [] && IpUtils::checkIp($ip, $plages);
    }

    /** Plage à enregistrer pour une IP : /32 en IPv4, /64 en IPv6 (le préfixe d'une box, le suffixe change). */
    public function plagePour(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip.'/32';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->prefixe64($ip).'/64';
        }

        return null;
    }

    private function prefixe64(string $ip): string
    {
        $octets = (string) inet_pton($ip);

        return (string) inet_ntop(substr($octets, 0, 8).str_repeat("\0", 8));
    }

    /** Enregistre ce navigateur comme appareil de confiance et pose le cookie (chiffré, HttpOnly, SameSite=Strict). */
    public function creer(User $user, Request $request, string $libelle): AppareilDeConfiance
    {
        $jeton = bin2hex(random_bytes(32));
        $jours = (int) config('hermes-os.auth.appareil.jours_confiance');

        $appareil = $user->appareilsDeConfiance()->create([
            'jeton_hash' => hash('sha256', $jeton),
            'libelle' => mb_substr($libelle, 0, 100),
            'ip_creation' => $request->ip(),
            'expire_le' => now()->addDays($jours),
        ]);

        Cookie::queue(Cookie::make(self::COOKIE, $appareil->getKey().'|'.$jeton, $jours * 24 * 60,
            '/', null, (bool) config('session.secure'), true, false, 'strict'));

        return $appareil;
    }

    /** Appareil reconnu par le cookie de la requête, pour ce compte, non expiré, depuis un réseau de confiance. */
    public function reconnaitre(Request $request, User $user): ?AppareilDeConfiance
    {
        $appareil = $this->depuisCookie($request, $user);
        if ($appareil === null || ! $this->ipDeConfiance($user, $request->ip())) {
            return null;
        }

        $appareil->forceFill(['utilise_le' => now(), 'derniere_ip' => $request->ip()])->save();

        return $appareil;
    }

    /** Appareil désigné par le cookie (sans contrôle d'IP), s'il est valide pour ce compte. */
    public function depuisCookie(Request $request, User $user): ?AppareilDeConfiance
    {
        $valeur = $request->cookie(self::COOKIE);
        if (! is_string($valeur) || ! preg_match('/^(\d{1,12})\|([a-f0-9]{64})$/', $valeur, $m)) {
            return null;
        }

        $appareil = $user->appareilsDeConfiance()->whereKey((int) $m[1])->where('expire_le', '>', now())->first();

        return $appareil !== null && hash_equals($appareil->jeton_hash, hash('sha256', $m[2])) ? $appareil : null;
    }

    /**
     * Motif d'invalidation d'une session ouverte par appareil de confiance (null = toujours valide) : vérifié à chaque
     * requête, pour qu'une révocation, une expiration ou un changement de réseau ferme la session aussitôt.
     */
    public function motifInvalidite(Request $request, User $user, mixed $id): ?string
    {
        $appareil = is_int($id) ? $user->appareilsDeConfiance()->whereKey($id)->first() : null;

        return match (true) {
            $appareil === null => 'revoque',
            $appareil->expire_le->isPast() => 'expire',
            ! $this->ipDeConfiance($user, $request->ip()) => 'hors_reseau',
            default => null,
        };
    }

    /** Révoque tous les appareils du compte (changement de mot de passe, réinitialisation TOTP, bouton « tout révoquer »). */
    public function revoquerTout(User $user): int
    {
        return $user->appareilsDeConfiance()->delete();
    }
}
