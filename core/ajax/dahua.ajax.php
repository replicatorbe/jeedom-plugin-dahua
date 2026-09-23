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
    /*
     * L'autochargeur de Jeedom ne sait résoudre que la classe portant le nom du
     * plugin : dahuaRule lui est inconnue, et c'est dahua.class.php qui
     * l'inclut. Sans ce require, un appel statique à dahuaRule échoue sur
     * « Class not found » dès qu'aucune ligne précédente n'a chargé dahua —
     * PHP résout la classe d'un appel statique AVANT d'évaluer ses arguments.
     */
    require_once __DIR__ . '/../class/dahua.class.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /* Récupère un équipement du plugin, avec contrôle de type.
     * eqLogic::byId() charge n'importe quel équipement et le caste vers la classe
     * appelante : sans ce contrôle, un id étranger provoquerait une Error fatale. */
    $getDahua = function ($_id, $_type = null) {
        $eqLogic = dahua::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'dahua') {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        if ($_type !== null && $eqLogic->getConfiguration('type') != $_type) {
            $labels = array(
                dahua::TYPE_NVR    => __('Cette action ne s\'applique qu\'à un NVR', __FILE__),
                dahua::TYPE_CAMERA => __('Cette action ne s\'applique qu\'à une caméra', __FILE__),
                dahua::TYPE_RULE   => __('Cette action ne s\'applique qu\'à une règle', __FILE__),
            );
            throw new Exception(isset($labels[$_type])
                ? $labels[$_type]
                : __('Cet équipement n\'est pas du type attendu', __FILE__));
        }
        return $eqLogic;
    };

    /* Les réponses CGI sont au format clé=valeur ; on ne garde que la valeur. */
    $value = function ($_raw) {
        if ($_raw === false) {
            return '?';
        }
        $parts = explode('=', trim($_raw), 2);
        return isset($parts[1]) ? trim($parts[1]) : trim($_raw);
    };

    if (init('action') == 'testConnection') {
        $nvr = $getDahua(init('id'), dahua::TYPE_NVR);

        $type = dahua::cgiRequest($nvr, 'magicBox.cgi?action=getDeviceType');
        if ($type === false) {
            throw new Exception(__('Le NVR ne répond pas. Vérifiez l\'adresse, le port HTTP et les identifiants.', __FILE__));
        }
        $version  = dahua::cgiRequest($nvr, 'magicBox.cgi?action=getSoftwareVersion');
        $channels = dahua::cgiRequest($nvr, 'magicBox.cgi?action=getProductDefinition&name=MaxRemoteInputChannels');

        /*
         * Capacités réellement exposées par le matériel : beaucoup de NVR n'ont
         * aucune sortie d'alarme, et coaxialControlIO ne concerne que les caméras
         * HDCVI. Autant le dire à l'utilisateur plutôt que de le laisser cliquer
         * sur des commandes qui échoueront.
         */
        $alarmOut = dahua::cgiRequest($nvr, 'configManager.cgi?action=getConfig&name=AlarmOut');
        $coaxial  = dahua::cgiRequest($nvr, 'coaxialControlIO.cgi?action=getCaps&channel=1');

        ajax::success(array(
            'type'       => $value($type),
            'version'    => $value($version),
            'channels'   => $value($channels),
            'alarmOut'   => ($alarmOut !== false && trim($alarmOut) != ''),
            'coaxial'    => ($coaxial !== false),
        ));
    }

    if (init('action') == 'discover') {
        unautorizedInDemo();
        ajax::success(array('created' => dahua::discoverCameras(init('id'))));
    }

    if (init('action') == 'snapshot') {
        $cam = $getDahua(init('id'), dahua::TYPE_CAMERA);
        // takeSnapshot() lève une exception portant la cause exacte : le message
        // générique précédent envoyait vérifier des identifiants corrects alors que
        // la caméra était simplement hors ligne.
        ajax::success(array('url' => $cam->takeSnapshot()));
    }

    if (init('action') == 'testRule') {
        unautorizedInDemo();
        $rule = $getDahua(init('id'), dahua::TYPE_RULE);
        // test() rejoue le déclenchement complet, temporisation et condition
        // d'armement comprises : un test qui les contournerait ne prouverait rien.
        dahuaRule::test($rule);
        $detail = $rule->getCmd('info', 'detail');
        ajax::success(array('detail' => is_object($detail) ? $detail->execCmd() : ''));
    }

    if (init('action') == 'resetRule') {
        unautorizedInDemo();
        $rule = $getDahua(init('id'), dahua::TYPE_RULE);
        dahuaRule::reset($rule);
        ajax::success(true);
    }

    /*
     * Dernière détection reçue de chaque caméra, par type. Sans paramètre : le
     * relevé ne dépend pas de la règle ouverte, et la page s'en sert aussi pour
     * les conditions pas encore enregistrées. Lecture seule, d'où l'absence
     * de unautorizedInDemo().
     */
    if (init('action') == 'lastSeen') {
        ajax::success(dahuaRule::lastSeen());
    }

    if (init('action') == 'daemonStatus') {
        $info = dahua::deamon_info();
        if ($info['state'] != 'ok') {
            ajax::success(array('daemon' => 'nok', 'nvrs' => array()));
        }
        $answer = dahua::sendToDaemon(array('cmd' => 'status'));
        ajax::success(array(
            'daemon' => 'ok',
            'nvrs'   => (is_array($answer) && isset($answer['result'])) ? $answer['result'] : array(),
        ));
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

} catch (Throwable $e) {
    // Throwable et non Exception : en PHP 8 une Error (méthode inexistante,
    // erreur de type) n'hérite pas d'Exception et donnerait un HTTP 500 muet.
    ajax::error(displayException($e), $e->getCode());
}
