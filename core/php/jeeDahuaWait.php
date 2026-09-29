<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Processus de secours des actions différées d'une règle.
 *
 *   php jeeDahuaWait.php alert=<identifiant d'alerte>
 *
 * Lancé, détaché, par dahuaRule::deferActions() quand une règle attend la
 * capture fraîche avant d'agir. Il relit l'alerte jusqu'à ce que la capture
 * soit là ou que le délai maximal soit écoulé, puis joue les actions — sauf si
 * l'événement « capture fraîche enregistrée » l'a déjà fait, auquel cas il
 * s'en va sans rien faire : le jeton d'exécution est unique (voir
 * dahuaAlert::claimActions()).
 *
 * Il existe parce qu'aucune autre horloge n'est assez fine : le cron du coeur
 * bat à la minute, et la requête qui déclenche la règle — celle du démon —
 * est abandonnée par celui-ci au bout de quatre secondes.
 */

/* Ligne de commande seulement : ce fichier est dans la racine web, et une
 * requête HTTP n'a aucune raison de pouvoir déclencher des actions. */
if (php_sapi_name() != 'cli' || isset($_SERVER['REQUEST_METHOD']) || !isset($_SERVER['argc'])) {
    header('HTTP/1.0 404 Not Found');
    echo '<h1>404 Not Found</h1>';
    exit(1);
}

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autochargeur du coeur ne résout que la classe portant le nom du plugin :
 * dahuaRule et dahuaAlert viennent avec elle. */
require_once __DIR__ . '/../class/dahua.class.php';

$alertId = '';
foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, 'alert=') === 0) {
        $alertId = substr($argument, 6);
    }
}
/* Liste blanche : cet identifiant compose un chemin sur disque. */
if (!dahuaAlert::isValidId($alertId)) {
    exit(1);
}

try {
    dahuaRule::watch($alertId);
} catch (Throwable $e) {
    /* Le cron rattrapera l'attente échue : on le dit, sans plus. */
    log::add('dahua', 'error', __('Attente de la capture fraîche en échec :', __FILE__) . ' ' . $alertId
           . ' — ' . $e->getMessage());
    exit(1);
}
