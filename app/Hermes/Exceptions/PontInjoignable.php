<?php

namespace App\Hermes\Exceptions;

use RuntimeException;

/**
 * Le pont ne répond pas (socket absent, délai, signature refusée, erreur 5xx) et aucune
 * donnée connue n'est disponible en cache.
 */
class PontInjoignable extends RuntimeException {}
