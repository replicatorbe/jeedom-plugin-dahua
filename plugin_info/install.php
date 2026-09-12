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

require_once __DIR__ . '/../../../core/php/core.inc.php';

function dahua_install() {
    // Répertoire de stockage des snapshots, servi par le widget.
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    config::save('socketport', 55060, 'dahua');
}

function dahua_update() {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    // Le démon est relancé par le core juste après cette fonction.
}

function dahua_remove() {
    // Le core supprime déjà /tmp/jeedom/dahua ; on s'assure qu'aucun démon ne survit.
    try {
        dahua::deamon_stop();
    } catch (Exception $e) {
        // le plugin peut être désactivé alors que la classe n'est plus chargeable
    }
}
