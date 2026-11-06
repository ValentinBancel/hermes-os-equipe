<?php

namespace App\Interface;

use Illuminate\Support\HtmlString;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\Node\Inline\Text;

/**
 * Rendu Markdown des contenus Hermes (messages, documents de mission, sorties). Le texte vient du pont, déjà
 * masqué ; il reste non fiable : HTML brut échappé, liens dangereux (javascript:, data:) refusés, aucune image distante
 * possible avec la CSP (img-src 'self' data:).
 *
 * Titres décalés de 2 niveaux (A4, decisions.md § v2) : le document s'insère sous le h1 et les h2 de la page, donc
 * h1 → h3, h2 → h4, h3 → h5, h4 et au-delà → h6. Un seul h1 par page.
 */
final class Markdown
{
    public const DECALAGE_TITRES = 2;

    public static function rendre(?string $texte): HtmlString
    {
        $convertisseur = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);

        $convertisseur->getEnvironment()->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $e): void {
            foreach (iterator_to_array($e->getDocument()->iterator(), false) as $noeud) {
                if ($noeud instanceof Link && preg_match('#^https?://#i', $noeud->getUrl()) === 1) {
                    $noeud->data->set('attributes/rel', 'noopener noreferrer');
                    $noeud->data->set('attributes/target', '_blank');
                }
                if ($noeud instanceof Image) {
                    // Image distante interdite (CSP, fuite d'adresse IP) : remplacée par son texte alternatif.
                    $alt = '';
                    foreach ($noeud->children() as $enfant) {
                        if ($enfant instanceof Text) {
                            $alt .= $enfant->getLiteral();
                        }
                    }
                    $noeud->replaceWith(new Text($alt !== '' ? $alt : '[image]'));
                }
                if ($noeud instanceof Heading) {
                    $noeud->setLevel(min(6, $noeud->getLevel() + self::DECALAGE_TITRES));
                }
            }
        });

        return new HtmlString((string) $convertisseur->convert((string) $texte));
    }

    /** Premier titre « # … » d'un document, sinon null. */
    public static function titre(?string $texte): ?string
    {
        if (preg_match('/^#\s+(.+)$/m', (string) $texte, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }
}
