<?php

namespace App\Interface;

/**
 * Découpe la question d'un agent (« À décider » de l'accueil) : une question, deux phrases de contexte, le reste replié.
 * Format demandé aux agents (SOUL, contexte commun) : « QUESTION : … », « CONTEXTE : … », ligne vide, détail.
 * Message libre (ancien format) : les deux premières phrases servent d'aperçu, le message entier est replié.
 */
final class QuestionAgent
{
    private const APERCU_MAX = 280;

    /**
     * @return array{question: ?string, contexte: ?string, detail: ?string}
     */
    public static function depuis(string $corps): array
    {
        $corps = trim(str_replace("\r\n", "\n", $corps));

        // La ligne « QUESTION : » peut suivre un préambule (« J'ai deux devis.\nQUESTION : … ») : même règle que le pont
        // (hermes_os_messagerie.py, recherche ligne par ligne). Le préambule n'est pas perdu : il ouvre le détail.
        if (preg_match('/^\W*QUESTION\s*:[ \t]*(.+?)[ \t]*(?:\n|$)/imu', $corps, $q, PREG_OFFSET_CAPTURE)) {
            [[$ligne, $debut], [$question]] = $q;
            $preambule = trim(substr($corps, 0, $debut));
            $reste = trim(substr($corps, $debut + strlen($ligne)));
            $contexte = null;
            if (preg_match('/^\W*CONTEXTE\s*:\s*(.+?)(?:\n\s*\n|$)/isu', $reste, $c)) {
                $contexte = trim(preg_replace('/\s+/u', ' ', $c[1]));
                $reste = trim(mb_substr($reste, mb_strlen($c[0])));
            }

            $detail = trim($preambule."\n\n".$reste);

            return ['question' => trim($question, ' *_`'), 'contexte' => $contexte, 'detail' => $detail !== '' ? $detail : null];
        }

        // Ancien format : aperçu de deux phrases, message complet replié s'il dépasse.
        $aplati = trim(preg_replace('/\s+/u', ' ', $corps));
        preg_match_all('/[^.!?]+[.!?]+(?=\s|$)/u', $aplati, $phrases);
        $apercu = implode(' ', array_map('trim', array_slice($phrases[0], 0, 2))) ?: $aplati;
        if (mb_strlen($apercu) > self::APERCU_MAX) {
            $apercu = rtrim(mb_substr($apercu, 0, self::APERCU_MAX - 1)).'…';
        }

        return ['question' => null, 'contexte' => $apercu, 'detail' => $apercu === $aplati ? null : $corps];
    }
}
