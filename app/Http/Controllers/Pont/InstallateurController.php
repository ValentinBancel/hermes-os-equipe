<?php

namespace App\Http\Controllers\Pont;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Le dépôt est privé : la console sert l'installeur et les modules du pont (L4, R12), en liste blanche.
 * Les modules sont copiés dans l'image au build (dossier pont/ du dépôt, sans ses tests).
 */
final class InstallateurController extends Controller
{
    public const MODULES = [
        'hermes_os_pont.py',
        'hermes_os_distant.py',
        'hermes_os_messagerie.py',
        'hermes_os_coffre.py',
        'hermes_os_coffre_agent.py',
    ];

    public function installeur(): Response
    {
        // L'URL est injectée dans un script shell (secu m3) : HTTPS, ou HTTP en bouclage seulement, et aucun caractère
        // qui puisse sortir des apostrophes ou être interprété par le shell. Sinon : 500, rien n'est rendu.
        $url = rtrim((string) config('app.url'), '/');
        abort_unless(self::urlSure($url), 500);

        $script = strtr((string) file_get_contents(resource_path('pont/installer.sh')), [
            '__CONSOLE__' => $url,
            '__CURL_PROTO__' => str_starts_with($url, 'https://') ? '=https' : '=https,http',
            '__MODULES__' => implode(' ', self::MODULES),
        ]);

        return response($script, 200, ['Content-Type' => 'text/x-shellscript; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function urlSure(string $url): bool
    {
        $chemin = '(:[0-9]{1,5})?(/[A-Za-z0-9._~\/-]*)?$#';

        return preg_match('#^https://[A-Za-z0-9.-]+'.$chemin, $url) === 1
            || preg_match('#^http://(localhost|127\.0\.0\.1|\[::1\])'.$chemin, $url) === 1;
    }

    public function fichier(string $module): Response
    {
        $chemin = base_path('pont/'.$module);

        abort_unless(in_array($module, self::MODULES, true) && is_file($chemin), 404);

        return response((string) file_get_contents($chemin), 200, [
            'Content-Type' => 'text/x-python; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
