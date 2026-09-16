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
 * Sert une capture d'image à un utilisateur connecté.
 *
 * Le dossier data/ est interdit d'accès par Apache (.htaccess), et c'est
 * souhaitable : les images d'une caméra de surveillance n'ont pas à être
 * accessibles sans authentification. Ce passe-plat les délivre après contrôle
 * de session.
 *
 * Deux sources, un seul point d'entrée :
 *   ?file=…            une capture courante de data/snapshots
 *   ?alert=…&file=…    une image du dossier d'une alerte (data/alerts/<id>)
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autochargeur du coeur ne résout que la classe portant le nom du plugin :
 * dahua serait trouvée seule, dahuaAlert non. Or la validation d'un identifiant
 * d'alerte a lieu AVANT le premier appel à dahua, donc avant que l'autochargeur
 * n'ait eu la moindre occasion de se déclencher. L'inclusion explicite de
 * dahua.class.php, qui tire dahuaAlert, est donc obligatoire ici. */
require_once __DIR__ . '/../class/dahua.class.php';
include_file('core', 'authentification', 'php');

if (!isConnect()) {
    header('HTTP/1.0 401 Unauthorized');
    die('401 - Unauthorized');
}

$file  = init('file');
$alert = init('alert');

if ($alert != '') {
    /*
     * Deux listes blanches distinctes, et non une seule expression élargie.
     *
     * Les deux nommages n'ont rien en commun : une capture courante s'appelle
     * cam<id>_<date>_<jeton>.jpg et vit à plat dans data/snapshots, une image
     * d'alerte s'appelle cam<id>_<det|live>[_t].jpg et vit dans un sous-dossier.
     * Élargir l'expression existante pour lui faire accepter un sous-dossier
     * reviendrait à y admettre une barre oblique — c'est exactement par là que
     * passe une traversée de répertoire. En les gardant séparées, aucune des
     * deux n'accepte ni '/', ni '..', et le dossier n'est jamais composé à
     * partir de la saisie : il est résolu à part par dahuaAlert::path(), qui ne
     * rend qu'un dossier réellement existant.
     */
    if (!dahuaAlert::isValidId($alert) || !dahuaAlert::isValidFile($file)) {
        header('HTTP/1.0 400 Bad Request');
        die('400 - Bad Request');
    }
    $dir = dahuaAlert::path($alert);
    if ($dir === false) {
        /* Alerte purgée : la rétention ne garde que quelques centaines de
         * dossiers. C'est le cours normal des choses, pas une erreur serveur —
         * d'où 404 et non 500. */
        header('HTTP/1.0 404 Not Found');
        die('404 - Not Found');
    }
    /*
     * Le secret de l'URL ne suffit pas à tenir lieu d'autorisation.
     *
     * isConnect() seul est nécessaire — la tuile est rendue pour des
     * utilisateurs non administrateurs, exiger isConnect('admin') la casserait.
     * Mais un profil restreint, qui n'a droit à aucun équipement dahua, lirait
     * n'importe quelle image d'alerte dès qu'il en connaît l'URL. Et si le jeton
     * de quatre octets rend celle-ci indevinable, un dossier d'alerte vit des
     * semaines là où une capture courante vit trente minutes : le même secret
     * n'a plus du tout la même durée d'exposition.
     *
     * Les droits de la règle font foi : c'est elle qui porte l'alerte.
     */
    $meta = dahuaAlert::readMeta($alert);
    $rule = is_array($meta) ? dahuaAlert::ruleOf($meta) : null;
    if (!is_object($rule) || !$rule->hasRight('r')) {
        header('HTTP/1.0 403 Forbidden');
        die('403 - Forbidden');
    }
} else {
    /*
     * Le nom est imposé par le plugin (cam<id>_<date>_<jeton>.jpg). Le valider par
     * une expression stricte ferme toute tentative de traversée de répertoire :
     * aucun '/', aucun '..' ne peut passer.
     *
     * is_string avant tout : init() rend le paramètre tel qu'il arrive, et un
     * « ?file[] » passerait un tableau à preg_match, donc une erreur fatale.
     * Le modificateur D ferme l'autre bout — sans lui, « $ » tolère un saut de
     * ligne final.
     */
    if (!is_string($file) || !preg_match('/^cam\d+_\d{8}-\d{6}_[0-9a-f]{8}\.jpg$/D', $file)) {
        header('HTTP/1.0 400 Bad Request');
        die('400 - Bad Request');
    }

    $dir = dahua::snapshotDir();
    if ($dir === false) {
        header('HTTP/1.0 500 Internal Server Error');
        die('500 - Internal Server Error');
    }
}

$path = $dir . '/' . $file;

if (!is_file($path)) {
    header('HTTP/1.0 404 Not Found');
    die('404 - Not Found');
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
