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
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');

if (!isConnect()) {
    header('HTTP/1.0 401 Unauthorized');
    die('401 - Unauthorized');
}

$file = init('file');

/*
 * Le nom est imposé par le plugin (cam<id>_<date>_<jeton>.jpg). Le valider par
 * une expression stricte ferme toute tentative de traversée de répertoire :
 * aucun '/', aucun '..' ne peut passer.
 */
if (!preg_match('/^cam\d+_\d{8}-\d{6}_[0-9a-f]{8}\.jpg$/', $file)) {
    header('HTTP/1.0 400 Bad Request');
    die('400 - Bad Request');
}

$dir = dahua::snapshotDir();
if ($dir === false) {
    header('HTTP/1.0 500 Internal Server Error');
    die('500 - Internal Server Error');
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
