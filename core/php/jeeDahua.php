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
 *   GET  ?apikey=…&test=1        → vérification de joignabilité au démarrage
 *   GET  ?apikey=…&action=config → configuration des NVR à écouter
 *   POST ?apikey=…  + corps JSON → remontée d'un lot d'événements
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autochargeur de Jeedom ne résout que la classe portant le nom du plugin.
 * dahua.class.php inclut dahuaRule : sans cet appel explicite, une évolution qui
 * sortirait de la boucle d'événements plus tôt ferait échouer checkHold() sur
 * une classe introuvable, et l'erreur serait avalée par le catch. */
require_once __DIR__ . '/../class/dahua.class.php';

if (!jeedom::apiAccess(init('apikey'), 'dahua')) {
    /* 401 et non 200 : le démon ne dispose que du code HTTP pour savoir si son lot
     * a été pris. Répondre 200 sur un refus lui fait jeter des événements que
     * Jeedom n'a jamais enregistrés, sans la moindre trace d'un côté ni de l'autre. */
    http_response_code(401);
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

$events = isset($input['events']) && is_array($input['events']) ? $input['events'] : array($input);

foreach ($events as $event) {
    try {
        handleDahuaEvent($event);
    } catch (Throwable $e) {
        log::add('dahua', 'error', __('Traitement de l\'événement en échec :', __FILE__)
               . ' ' . $e->getMessage() . ' — ' . json_encode($event));
    }
}

/*
 * Fait retomber les règles dont la durée de maintien est écoulée. Le cron minute
 * du plugin s'en charge aussi, mais il est bien trop lent pour une alarme : sur
 * une installation active, c'est ce passage-ci qui fait presque tout le travail.
 */
try {
    dahuaRule::checkHold();
} catch (Throwable $e) {
    log::add('dahua', 'error', __('Retour des règles en échec :', __FILE__) . ' ' . $e->getMessage());
}

echo 'OK';

/*
 * Retient la date fournie par le NVR seulement si elle est plausible.
 * Une horloge NVR non synchronisée, ou un décalage de fuseau, produirait sinon
 * des points d'historique dans le futur et ferait rejeter silencieusement des
 * événements par le coeur (qui compare la date à collectDate).
 */
function dahuaEventDate($_event) {
    if (!isset($_event['time']) || $_event['time'] == '') {
        return null;
    }
    $ts = strtotime($_event['time']);
    if ($ts === false || abs($ts - time()) > 300) {
        return null;                                  // le coeur horodatera lui-même
    }
    return $_event['time'];
}

/*
 * Tags offerts aux actions. #date# et #time# sont volontairement absents : le
 * coeur les résout déjà lui-même, les redéfinir casserait le comportement attendu.
 */
function dahuaCameraTags($_nvr, $_cam, $_channel, $_state) {
    return array(
        '#camera#'  => $_cam->getName(),
        '#channel#' => (string) $_channel,
        '#nvr#'     => $_nvr->getName(),
        '#state#'   => $_state,
    );
}

/*
 * Une caméra vient d'être perdue.
 *
 * Deux garde-fous pour tenir la promesse « une seule notification » :
 *  - l'appelant n'entre ici que sur une vraie bascule 1 -> 0 ;
 *  - 'fired' retient qu'une perte a déjà été signalée, et survit au redémarrage
 *    du démon, qui resonde et republie tout ce qu'il sait. Sans lui, chaque
 *    relance du démon renotifierait toutes les caméras déjà perdues.
 */
function dahuaCameraLost($_nvr, $_cam, $_channel) {
    $state = dahua::camState($_cam->getId());
    if ($state['fired'] > 0) {
        return;
    }
    log::add('dahua', 'warning', $_cam->getHumanName() . ' ' . __('hors ligne', __FILE__));
    dahua::saveCamState($_cam->getId(), array('fired' => time()));
    dahua::runActions($_nvr, 'camera_lost_actions',
        dahuaCameraTags($_nvr, $_cam, $_channel, __('hors ligne', __FILE__)), false);
}

/*
 * Retour en ligne. On ne joue les actions de retour que si une perte a
 * réellement été signalée : sinon la création des commandes sur une
 * installation existante annoncerait le retour de caméras jamais tombées.
 */
function dahuaCameraBack($_nvr, $_cam, $_channel) {
    $state = dahua::camState($_cam->getId());
    log::add('dahua', 'info', $_cam->getHumanName() . ' ' . __('de nouveau en ligne', __FILE__));
    if ($state['fired'] == 0) {
        return;
    }
    dahua::saveCamState($_cam->getId(), array('fired' => 0));
    dahua::runActions($_nvr, 'camera_back_actions',
        dahuaCameraTags($_nvr, $_cam, $_channel, __('en ligne', __FILE__)), false);
}

function handleDahuaEvent($_event) {
    $nvrId = isset($_event['nvr_id']) ? (int) $_event['nvr_id'] : 0;
    $nvr   = dahua::byId($nvrId);
    if (!is_object($nvr) || $nvr->getEqType_name() != 'dahua') {
        log::add('dahua', 'debug', __('NVR inconnu, événement ignoré :', __FILE__) . ' ' . $nvrId);
        return;
    }

    $type = isset($_event['type']) ? $_event['type'] : 'event';
    $date = dahuaEventDate($_event);

    /* --- État de la connexion au NVR --------------------------------------- */
    if ($type == 'status') {
        $online = (isset($_event['status']) && $_event['status'] == 'connected') ? 1 : 0;
        $nvr->checkAndUpdateCmd('online', $online);
        if (isset($_event['transport'])) {
            dahua::rememberTransport($nvr->getId(), $_event['transport']);
        }
        if ($online) {
            log::add('dahua', 'info', $nvr->getHumanName() . ' ' . __('connecté', __FILE__));
            /*
             * Les états caméra restent à 0 jusqu'à la première sonde : un NVR
             * joignable ne prouve rien sur ses caméras. Le démon sonde aussitôt
             * après connexion, l'attente est donc de l'ordre de la seconde.
             */
            $nvr->publishCamStatus();
            return;
        }

        log::add('dahua', 'warning', $nvr->getHumanName() . ' ' . __('déconnecté', __FILE__)
               . (isset($_event['error']) ? ' : ' . $_event['error'] : ''));

        /*
         * Une caméra ne peut plus rien détecter si le NVR est injoignable. On ne
         * réécrit que les commandes réellement à 1 : un checkAndUpdateCmd inutile
         * écrit quand même en cache et met à jour lastCommunication.
         */
        foreach (dahua::byTypeAndSearchConfiguration('dahua', array('type' => dahua::TYPE_CAMERA), true) as $cam) {
            if ($cam->getConfiguration('nvr_id') != $nvr->getId()) {
                continue;
            }
            foreach (dahua::$_channelEvents as $def) {
                $cmd = $cam->getCmd('info', $def['logicalId']);
                if (is_object($cmd) && $cmd->execCmd() == 1) {
                    $cmd->event(0);
                }
            }
            /*
             * La joignabilité suit le même sort : afficher « en ligne » une caméra
             * que Jeedom ne peut plus vérifier est le pire des deux mensonges pour
             * une supervision. Distinguer « caméra morte » de « NVR muet » se fait
             * en regardant l'état du NVR, qui est juste à côté dans la tuile.
             *
             * Aucune action n'est jouée sur ce chemin, et c'est délibéré : une
             * déconnexion du NVR n'est pas la perte de huit caméras. L'utilisateur
             * est prévenu une fois, par le NVR. Sans cette réserve, le moindre
             * hoquet réseau enverrait huit notifications, puis huit retours.
             */
            $cmd = $cam->getCmd('info', 'online');
            if (is_object($cmd) && $cmd->execCmd() == 1) {
                $cmd->event(0);
            }
        }
        $nvr->publishCamStatus();
        return;
    }

    /* --- Capture prise par le démon ---------------------------------------- */
    if ($type == 'snapshot') {
        if (!isset($_event['channel'], $_event['url'])) {
            return;
        }
        $cam = dahua::byLogicalId('cam::' . $nvrId . '::' . (int) $_event['channel'], 'dahua');
        if (is_object($cam)) {
            $cam->checkAndUpdateCmd('snapshot', $_event['url'], $date);
            /*
             * Cette capture est peut-être celle qu'une alerte toute fraîche
             * attendait. Le démon capture une à deux secondes après l'événement,
             * alors que la règle, elle, s'est déclenchée pendant le POST de ce
             * même événement : l'image de la caméra qui a complété la
             * corrélation arrive donc TOUJOURS après l'ouverture du dossier.
             * Sans ce rattrapage, c'est justement elle qui manquerait.
             */
            try {
                dahuaAlert::catchUpDetection($cam->getId(), $_event['url']);
            } catch (Throwable $e) {
                log::add('dahua', 'error', __('Rattrapage d\'image d\'alerte en échec :', __FILE__)
                       . ' ' . $e->getMessage());
            }
        }
        return;
    }

    /* --- Capture fraîche d'un dossier d'alerte ------------------------------ */
    /*
     * Compte rendu asynchrone d'une capture demandée au déclenchement d'une
     * règle : le fichier a déjà été écrit (ou non) par le fils du démon, il ne
     * reste qu'à l'inscrire dans la description de l'alerte.
     *
     * Comme la sonde de joignabilité juste en dessous, ce bloc DOIT rester avant
     * le contrôle « $code == '' » plus bas : un compte rendu de capture ne porte
     * aucun code d'événement et s'y ferait jeter sans la moindre trace.
     */
    if ($type == 'alertshot') {
        if (!isset($_event['alert'], $_event['camera_id'])) {
            return;
        }
        $alertId = (string) $_event['alert'];
        /* Liste blanche avant toute chose : cet identifiant, venu du réseau,
         * compose un chemin sur disque. dahuaAlert le valide, jamais on ne
         * l'assainit. */
        if (!dahuaAlert::isValidId($alertId)) {
            log::add('dahua', 'debug', __('Identifiant d\'alerte invalide, compte rendu ignoré :', __FILE__)
                   . ' ' . $alertId);
            return;
        }
        $cameraId = (int) $_event['camera_id'];
        $ok       = isset($_event['ok']) ? (bool) $_event['ok'] : false;
        $error    = isset($_event['error']) ? (string) $_event['error'] : '';
        dahuaAlert::noteCapture($alertId, $cameraId, $ok, $error);

        /*
         * La tuile est republiée par noteCapture() elle-même, sous le verrou de
         * l'alerte. Le faire ici serait une course perdue d'avance : chaque
         * caméra poste son compte rendu depuis un fils distinct, donc dans une
         * requête HTTP distincte, et deux workers qui reliraient puis
         * publieraient chacun de leur côté écriraient la commande dans un ordre
         * quelconque — la tuile se figerait alors sur la version la moins
         * complète, sans que rien ne le signale.
         */

        $cam = dahua::byId($cameraId);
        $who = is_object($cam) ? $cam->getHumanName() : (__('caméra', __FILE__) . ' ' . $cameraId);
        /*
         * L'échec est journalisé, et pas seulement le succès : c'est le seul
         * endroit où l'utilisateur pourra comprendre qu'une caméra n'a pas
         * répondu au déclenchement. Côté dossier d'alerte, une image manquante
         * est parfaitement muette.
         */
        $issue = $ok
            ? __('capture fraîche enregistrée', __FILE__)
            : __('capture en échec', __FILE__) . (($error != '') ? ' : ' . $error : '');
        log::add('dahua', 'debug', __('Alerte', __FILE__) . ' ' . $alertId . ' — ' . $who . ' — ' . $issue);
        return;
    }

    /* --- Sonde de joignabilité des caméras ---------------------------------- */
    /*
     * Ce n'est pas un événement du NVR : une caméra PoE qui décroche ne produit
     * ni VideoLoss ni NetMonitorAbort, elle se tait. Le démon interroge donc
     * périodiquement l'état des canaux et pousse le relevé complet ici.
     *
     * Ce bloc DOIT rester avant le contrôle « $code == '' » plus bas : une sonde
     * ne porte pas de code d'événement et s'y ferait jeter sans la moindre trace.
     */
    if ($type == 'camera_status') {
        if (!isset($_event['channels']) || !is_array($_event['channels'])) {
            return;
        }
        $changed = false;
        foreach ($_event['channels'] as $channel => $up) {
            // 1-based, comme snapshot.cgi et ptz.cgi — et non l'Index 0-based des événements.
            $cam = dahua::byLogicalId('cam::' . $nvrId . '::' . (int) $channel, 'dahua');
            if (!is_object($cam)) {
                continue;
            }
            $cmd = $cam->getCmd('info', 'online');
            if (!is_object($cmd)) {
                log::add('dahua', 'warning', $cam->getHumanName() . ' '
                       . __('sans commande de joignabilité : enregistrez l\'équipement', __FILE__));
                continue;
            }
            /*
             * Le front est calculé AVANT écriture, et jamais déduit du retour de
             * checkAndUpdateCmd() : le coeur y répond « true » à valeur inchangée
             * dès qu'on lui passe une date plus récente (eqLogic.class.php:693).
             * S'en servir déclencherait les actions à CHAQUE sonde.
             */
            $before = (string) $cmd->execCmd();
            $value  = ((int) $up == 1) ? 1 : 0;
            $cam->checkAndUpdateCmd('online', $value, $date);
            if ((string) $value !== $before) {
                $changed = true;
            }
            if ($before === '') {
                continue;                             // première observation : pas une bascule
            }
            if ((int) $before === 1 && $value === 0) {
                dahuaCameraLost($nvr, $cam, (int) $channel);
            } elseif ((int) $before === 0 && $value === 1) {
                dahuaCameraBack($nvr, $cam, (int) $channel);
            }
        }
        if ($changed) {
            $nvr->publishCamStatus();
        }
        return;
    }

    /* --- Événement DHIP ou CGI --------------------------------------------- */
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
        if (!is_object($target)) {
            log::add('dahua', 'debug', $nvr->getHumanName() . ' ' . __('canal', __FILE__) . ' ' . $channel
                   . ' ' . __('sans équipement : lancez la découverte des caméras', __FILE__));
        }
    }
    $isCamera = is_object($target);
    if (!$isCamera) {
        $target = $nvr;                               // événement global, ou canal non découvert
    }

    $label = $code . ' ' . $action;
    if (isset($_event['data']['Name']) && $_event['data']['Name'] != '') {
        $label .= ' (' . $_event['data']['Name'] . ')';
    }

    $map = $isCamera ? dahua::$_channelEvents : dahua::$_nvrEvents;
    if (isset($map[$code])) {
        $target->checkAndUpdateCmd($map[$code]['logicalId'], $value, $date);
    }

    $target->checkAndUpdateCmd('lastevent', $label, $date);
    $target->checkAndUpdateCmd('lastevent_date', ($date !== null) ? $date : date('Y-m-d H:i:s'), $date);

    log::add('dahua', 'debug', $target->getHumanName() . ' ' . $label);

    /*
     * Corrélation. On transmet la date de l'ÉVÉNEMENT et non l'heure de
     * traitement : si Jeedom a été lent ou indisponible, le démon envoie d'un
     * seul coup tout ce qu'il avait accumulé, et prendre time() ferait passer
     * pour simultanées des détections séparées d'une minute.
     */
    if ($isCamera) {
        dahuaRule::onEvent($target, $channel, $code, $action,
                           ($date !== null) ? strtotime($date) : time());
    }
}
