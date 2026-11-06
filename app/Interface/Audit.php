<?php

namespace App\Interface;

use App\Hermes\Action;
use App\Models\AuditLog;

/**
 * Lignes du journal d'audit en clair (ux/03 § Compte) : type, détail, résultat. Aucun secret n'y figure (Journal).
 */
final class Audit
{
    private const EVENEMENTS = [
        'connexion.reussie' => ['Connexion', 'Identifiant et mot de passe acceptés'],
        'connexion.echec' => ['Échec de connexion', 'Identifiant ou mot de passe incorrect'],
        'connexion.verrouillage' => ['Échec de connexion', 'Compte verrouillé après trop d’échecs'],
        'connexion.refusee_verrouillage' => ['Échec de connexion', 'Tentative refusée : compte verrouillé'],
        'deconnexion' => ['Connexion', 'Déconnexion'],
        'session.expiree' => ['Connexion', 'Session expirée'],
        'session.sans_totp' => ['Connexion', 'Session refusée : code TOTP manquant'],
        'totp.demande' => ['Code TOTP', 'Code demandé'],
        'totp.reussi' => ['Code TOTP', 'Code accepté'],
        'totp.echec' => ['Code TOTP', 'Code refusé'],
        'totp.code_recuperation_utilise' => ['Code TOTP', 'Code de secours utilisé'],
        'totp.enrolement_debut' => ['Code TOTP', 'Enrôlement commencé'],
        'totp.enrolement_confirme' => ['Code TOTP', 'Double authentification activée'],
        'totp.enrolement_echec' => ['Code TOTP', 'Enrôlement : code refusé'],
        'totp.reinitialise' => ['Code TOTP', 'Double authentification réinitialisée (CLI)'],
        'totp.codes_regeneres' => ['Code TOTP', 'Codes de secours régénérés'],
        'compte.cree' => ['Compte', 'Compte créé'],
        'compte.desactive' => ['Compte', 'Compte désactivé'],
        'compte.reactive' => ['Compte', 'Compte réactivé'],
        'compte.deconnecter_autres' => ['Action', 'Autres appareils déconnectés'],
        'compte.session_fermee' => ['Action', 'Session fermée'],
    ];

    /** @return array{0: string, 1: string} [type, détail] */
    public static function decrire(AuditLog $l): array
    {
        if (str_starts_with($l->event, 'action.')) {
            $action = Action::tryFrom(substr($l->event, 7));
            $args = $l->context['arguments'] ?? [];
            $cible = $args['job_id'] ?? (isset($args['titre']) ? '« '.$args['titre'].' »' : null);
            $sur = isset($args['tableau']) ? ' sur '.$args['tableau'] : '';
            $refus = match ($l->context['refus'] ?? null) {
                'totp_a_reconfirmer' => ' — refusée : code TOTP à ressaisir',
                'limite_par_minute' => ' — refusée : trop d’actions par minute',
                'validation' => ' — refusée : données invalides',
                default => '',
            };

            return ['Action', ($action?->libelle() ?? $l->event).($cible ? ' : '.$cible : '').$sur.$refus];
        }

        $connu = self::EVENEMENTS[$l->event] ?? null;
        $detail = $connu[1] ?? $l->event;
        if ($l->event === 'session.expiree' && isset($l->context['motif'])) {
            $detail .= ' ('.match ($l->context['motif']) {
                'inactivite' => 'inactivité',
                'duree_maximale' => 'durée maximale',
                'compte_desactive' => 'compte désactivé',
                default => (string) $l->context['motif'],
            }.')';
        }
        if (isset($l->context['cible_email']) && is_string($l->context['cible_email'])) {
            $detail .= ' : '.$l->context['cible_email'];
        } elseif ($l->event === 'compte.cree' && ($l->context['source'] ?? null) === 'cli') {
            $detail .= ' (CLI)';
        }
        if (($l->context['contexte'] ?? null) === 'action') {
            $detail .= ' (confirmation d’action)';
        }

        return [$connu[0] ?? '['.$l->event.']', $detail];
    }

    /** @return array{0: string, 1: string} [libellé, forme] */
    public static function resultat(string $statut): array
    {
        return match ($statut) {
            'ok' => ['OK', 's-ok'],
            'failed' => ['Échec', 's-arret'],
            'blocked' => ['Refusé', 's-arret'],
            'pending' => ['En attente', 's-attention'],
            'expired' => ['Expirée', 's-inactif'],
            default => ['['.$statut.']', 's-attention'],
        };
    }
}
