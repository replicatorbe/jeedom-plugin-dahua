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
 * Point d'entrée appelé exclusivement par le démon dahuad.
 *   GET  ?apikey=…&test=1        → vérification de joignabilité au démarrage du démon
 *   GET  ?apikey=…&action=config → configuration des NVR à écouter
 *   POST ?apikey=…  + corps JSON → remontée d'un lot d'événements
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

if (!jeedom::apiAccess(init('apikey'), 'dahua')) {
    echo __('Vous n\'êtes pas autorisé à effectuer cette action', __FILE__);
    die();
}

if (init('test') != '') {
    echo 'OK';
    die();
}

if (init('action') == 'config') {
    header('Content-Type: application/json');
    echo json_encode(dahua::getDaemonConfig());
    die();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($input)) {
    die();
}

// Le démon envoie toujours un lot, même pour un unique événement.
$events = isset($input['events']) && is_array($input['events']) ? $input['events'] : array($input);

foreach ($events as $event) {
    try {
        handleDahuaEvent($event);
    } catch (Exception $e) {
        log::add('dahua', 'error', __('Traitement de l\'événement en échec :', __FILE__)
               . ' ' . $e->getMessage() . ' — ' . json_encode($event));
    }
}
echo 'OK';

/**
 * Applique un événement remonté par le démon sur les commandes Jeedom.
 */
function handleDahuaEvent($_event) {
    $nvrId = isset($_event['nvr_id']) ? (int) $_event['nvr_id'] : 0;
    $nvr   = dahua::byId($nvrId);
    if (!is_object($nvr)) {
        log::add('dahua', 'debug', __('NVR inconnu, événement ignoré :', __FILE__) . ' ' . $nvrId);
        return;
    }

    $type = isset($_event['type']) ? $_event['type'] : 'event';
    $date = isset($_event['time']) && $_event['time'] != '' ? $_event['time'] : date('Y-m-d H:i:s');

    /* --- État de la connexion au NVR --------------------------------------- */
    if ($type == 'status') {
        $online = ($_event['status'] == 'connected') ? 1 : 0;
        $nvr->checkAndUpdateCmd('online', $online);
        if ($online) {
            log::add('dahua', 'info', $nvr->getHumanName() . ' ' . __('connecté', __FILE__));
        } else {
            log::add('dahua', 'warning', $nvr->getHumanName() . ' ' . __('déconnecté', __FILE__)
                   . (isset($_event['error']) ? ' : ' . $_event['error'] : ''));
            // Une caméra ne peut plus rien détecter si le NVR est injoignable.
            foreach (dahua::byTypeAndSearchConfiguration('dahua', array('type' => dahua::TYPE_CAMERA)) as $cam) {
                if ($cam->getConfiguration('nvr_id') == $nvr->getId()) {
                    foreach (dahua::$_channelEvents as $def) {
                        $cam->checkAndUpdateCmd($def['logicalId'], 0);
                    }
                }
            }
        }
        return;
    }

    /* --- URL d'une capture prise par le démon ------------------------------ */
    if ($type == 'snapshot') {
        $cam = dahua::byLogicalId('cam::' . $nvrId . '::' . (int) $_event['channel'], 'dahua');
        if (is_object($cam)) {
            $cam->checkAndUpdateCmd('snapshot', $_event['url'], $date);
        }
        return;
    }

    /* --- Événement DHIP ----------------------------------------------------- */
    $code   = isset($_event['code']) ? $_event['code'] : '';
    $action = isset($_event['action']) ? $_event['action'] : 'Pulse';
    if ($code == '') {
        return;
    }

    // Action=Stop remet la commande à 0 ; Start et Pulse la passent à 1.
    $value = ($action == 'Stop') ? 0 : 1;

    $channel = isset($_event['channel']) ? (int) $_event['channel'] : 0;
    $target  = null;

    if ($channel > 0) {
        $target = dahua::byLogicalId('cam::' . $nvrId . '::' . $channel, 'dahua');
    }
    if (!is_object($target)) {
        // Événement global, ou canal sans équipement créé : il revient au NVR.
        $target = $nvr;
    }

    $label = $code . ' ' . $action;
    if (isset($_event['data']['Name']) && $_event['data']['Name'] != '') {
        $label .= ' (' . $_event['data']['Name'] . ')';
    }

    // Commande binaire dédiée si l'événement est connu.
    $map = ($target->getId() == $nvr->getId()) ? dahua::$_nvrEvents : dahua::$_channelEvents;
    if (isset($map[$code])) {
        $target->checkAndUpdateCmd($map[$code]['logicalId'], $value, $date);
    }

    $target->checkAndUpdateCmd('lastevent', $label, $date);
    $target->checkAndUpdateCmd('lastevent_date', $date, $date);

    log::add('dahua', 'debug', $target->getHumanName() . ' ' . $label);
}
