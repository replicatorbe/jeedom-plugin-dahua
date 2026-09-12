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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /* Vérifie que le NVR répond et retourne son identité. */
    if (init('action') == 'testConnection') {
        $nvr = dahua::byId(init('id'));
        if (!is_object($nvr)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        if ($nvr->getConfiguration('type') != dahua::TYPE_NVR) {
            throw new Exception(__('Cette action ne s\'applique qu\'à un NVR', __FILE__));
        }

        $type = dahua::cgiRequest($nvr, 'magicBox.cgi?action=getDeviceType');
        if ($type === false) {
            throw new Exception(__('Le NVR ne répond pas. Vérifiez l\'adresse, le port et les identifiants.', __FILE__));
        }
        $version  = dahua::cgiRequest($nvr, 'magicBox.cgi?action=getSoftwareVersion');
        $channels = dahua::cgiRequest($nvr, 'magicBox.cgi?action=getProductDefinition&name=MaxRemoteInputChannels');

        // Les réponses sont au format clé=valeur ; on ne garde que la valeur.
        $value = function ($_raw) {
            if ($_raw === false) {
                return '?';
            }
            $parts = explode('=', trim($_raw), 2);
            return isset($parts[1]) ? trim($parts[1]) : trim($_raw);
        };

        ajax::success(array(
            'type'     => $value($type),
            'version'  => $value($version),
            'channels' => $value($channels),
        ));
    }

    /* Crée un équipement par canal nommé sur le NVR. */
    if (init('action') == 'discover') {
        unautorizedInDemo();
        $created = dahua::discoverCameras(init('id'));
        ajax::success(array('created' => $created));
    }

    /* Capture immédiate sur le canal d'une caméra. */
    if (init('action') == 'snapshot') {
        $cam = dahua::byId(init('id'));
        if (!is_object($cam)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        $url = $cam->takeSnapshot();
        if ($url === false) {
            throw new Exception(__('Capture impossible. Vérifiez le canal et les identifiants du NVR.', __FILE__));
        }
        ajax::success(array('url' => $url));
    }

    /* État des connexions vu par le démon. */
    if (init('action') == 'daemonStatus') {
        ajax::success(dahua::sendToDaemon(array('cmd' => 'status')));
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
