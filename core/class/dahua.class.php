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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autochargeur de Jeedom ne sait résoudre que la classe portant le nom du
 * plugin : les classes annexes doivent être incluses explicitement. */
require_once __DIR__ . '/dahuaRule.class.php';

class dahua extends eqLogic {

    const TYPE_NVR    = 'nvr';
    const TYPE_CAMERA = 'camera';
    const TYPE_RULE   = 'rule';

    /* Un seul rechargement du démon par requête, même si dix équipements sont enregistrés. */
    private static $_reloadScheduled = false;

    /* Type de l'équipement avant enregistrement, et id avant suppression : le
     * coeur les a déjà écrasés au moment où postSave et postRemove s'exécutent. */
    private $_previousType = null;
    private $_removedId    = 0;

    /*
     * Événements DHIP rattachés à un canal, mappés vers une commande info/binaire.
     * 'visible' et 'historized' fixent les valeurs par défaut : n'afficher que les
     * détections réellement utiles évite un widget de quinze lignes par caméra.
     */
    public static $_channelEvents = array(
        'VideoMotion'          => array('logicalId' => 'motion',       'name' => 'Mouvement',           'visible' => 1, 'historized' => 1, 'generic' => 'PRESENCE'),
        'SmartMotionHuman'     => array('logicalId' => 'human',        'name' => 'Humain détecté',      'visible' => 1, 'historized' => 1, 'generic' => 'PRESENCE'),
        'SmartMotionVehicle'   => array('logicalId' => 'vehicle',      'name' => 'Véhicule détecté',    'visible' => 1, 'historized' => 1, 'generic' => 'PRESENCE'),
        'CrossLineDetection'   => array('logicalId' => 'crossline',    'name' => 'Ligne franchie',      'visible' => 0, 'historized' => 1),
        'CrossRegionDetection' => array('logicalId' => 'crossregion',  'name' => 'Zone franchie',       'visible' => 0, 'historized' => 1),
        'VideoLoss'            => array('logicalId' => 'videoloss',    'name' => 'Perte vidéo',         'visible' => 1, 'historized' => 0),
        'VideoBlind'           => array('logicalId' => 'videoblind',   'name' => 'Caméra masquée',      'visible' => 0, 'historized' => 0, 'generic' => 'SABOTAGE'),
        'FaceDetection'        => array('logicalId' => 'face',         'name' => 'Visage détecté',      'visible' => 0, 'historized' => 0),
        'ParkingDetection'     => array('logicalId' => 'parking',      'name' => 'Stationnement',       'visible' => 0, 'historized' => 0),
        'LeftDetection'        => array('logicalId' => 'left',         'name' => 'Objet abandonné',     'visible' => 0, 'historized' => 0),
        'TakenAwayDetection'   => array('logicalId' => 'takenaway',    'name' => 'Objet retiré',        'visible' => 0, 'historized' => 0),
        'WanderDetection'      => array('logicalId' => 'wander',       'name' => 'Rôdeur',              'visible' => 0, 'historized' => 0),
        'AudioAnomaly'         => array('logicalId' => 'audioanomaly', 'name' => 'Anomalie sonore',     'visible' => 0, 'historized' => 0),
        'AudioMutation'        => array('logicalId' => 'audiomutation','name' => 'Variation sonore',    'visible' => 0, 'historized' => 0),
        'FireWarning'          => array('logicalId' => 'fire',         'name' => 'Détection incendie',  'visible' => 0, 'historized' => 0, 'generic' => 'SMOKE'),
    );

    /* Événements globaux du NVR. Chaque code a sa propre commande : partager un
     * état binaire ferait qu'un événement en efface un autre encore actif. */
    public static $_nvrEvents = array(
        'StorageNotExist'  => array('logicalId' => 'storage_missing', 'name' => 'Stockage absent'),
        'StorageFailure'   => array('logicalId' => 'storage_failure', 'name' => 'Défaut de stockage'),
        'StorageLowSpace'  => array('logicalId' => 'storage_low',     'name' => 'Espace disque faible'),
        'LoginFailure'     => array('logicalId' => 'loginfailure',    'name' => 'Échec de connexion'),
        'AlarmLocal'       => array('logicalId' => 'alarm',           'name' => 'Alarme entrée locale'),
        'NetworkChange'    => array('logicalId' => 'networkchange',   'name' => 'Changement réseau'),
    );

    /* ====================================================================== DÉMON */

    public static function deamon_info() {
        $return = array(
            'log'        => __CLASS__ . 'd',        // le démon écrit dans log/dahuad
            'state'      => 'nok',
            'launchable' => 'ok',
        );

        /*
         * L'état du process est déterminé indépendamment de « launchable » : sinon,
         * supprimer le dernier NVR rendrait le démon invisible pour le coeur, qui
         * ne l'arrêterait donc jamais.
         */
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '' && @posix_getsid((int) $pid)) {
                $return['state'] = 'ok';
            } else {
                // PID mort : sans ce nettoyage le watchdog ne relancerait jamais le démon.
                @unlink($pid_file);
            }
        }

        $nvrs = self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_NVR), true);
        if (count($nvrs) == 0) {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Aucun NVR configuré et activé', __FILE__);
            return $return;
        }
        foreach ($nvrs as $nvr) {
            if ($nvr->getConfiguration('ip') == '' || $nvr->getConfiguration('username') == '') {
                $return['launchable'] = 'nok';
                $return['launchable_message'] = __('Configuration incomplète :', __FILE__) . ' ' . $nvr->getName();
                break;
            }
        }
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();

        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Le démon ne peut pas être lancé :', __FILE__) . ' ' . $deamon_info['launchable_message']);
        }

        $daemon = realpath(__DIR__ . '/../../resources/dahuad/dahuad.php');
        if ($daemon === false) {
            throw new Exception(__('Démon introuvable dans resources/dahuad/', __FILE__));
        }

        /*
         * Ni la clé API ni les identifiants du NVR ne passent par la ligne de
         * commande : elle est lisible par tout utilisateur local via `ps`, et la
         * clé API suffirait à obtenir les mots de passe sur le callback.
         * La clé est donc transmise sur l'entrée standard du démon.
         */
        $cmd  = 'php ' . escapeshellarg($daemon);
        $cmd .= ' --callback '   . escapeshellarg(self::getCallbackUrl());
        $cmd .= ' --pid '        . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/deamon.pid');
        $cmd .= ' --socketport ' . escapeshellarg(config::byKey('socketport', __CLASS__, 55060));
        $cmd .= ' --loglevel '   . escapeshellarg(log::convertLogLevel(log::getLogLevel(__CLASS__)));

        log::add(__CLASS__, 'info', __('Lancement du démon :', __FILE__) . ' ' . $cmd);
        $full = 'echo ' . escapeshellarg(jeedom::getApiKey(__CLASS__)) . ' | ' . $cmd
              . ' >> ' . log::getPathToLog(__CLASS__ . 'd') . ' 2>&1 &';
        exec($full);

        for ($i = 0; $i < 30; $i++) {
            if (self::deamon_info()['state'] == 'ok') {
                message::removeAll(__CLASS__, 'unableStartDeamon');
                return true;
            }
            sleep(1);
        }
        log::add(__CLASS__, 'error', __('Impossible de lancer le démon, consultez le log dahuad', __FILE__), 'unableStartDeamon');
        /* log::add ne pose un message qu'avec le réglage global addMessageForErrorLog,
         * inactif par défaut : sans ce message::add, un démon qui refuse de démarrer
         * (identifiants faux, port occupé) ne se voit que dans l'onglet Santé. */
        message::add(__CLASS__, __('Impossible de lancer le démon, consultez le log dahuad', __FILE__), '', 'unableStartDeamon');
        return false;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            if ($pid > 0) {
                system::kill($pid);
            }
            @unlink($pid_file);
        }
        // Filet de sécurité si le fichier PID a disparu alors que le process tourne.
        system::kill('resources/dahuad/dahuad.php');
        system::fuserk(config::byKey('socketport', __CLASS__, 55060));
    }

    public static function getCallbackUrl() {
        return network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')
             . '/plugins/dahua/core/php/jeeDahua.php';
    }

    /* ================================================== CONFIGURATION DU DÉMON */

    /*
     * Décrit au démon l'ensemble des NVR à écouter et la correspondance canal →
     * équipement. Appelé par jeeDahua.php sur action=config.
     */
    public static function getDaemonConfig() {
        $config = array(
            // Le démon est un process CLI : sans cette valeur il daterait ses
            // événements en UTC alors que Jeedom travaille en heure locale.
            'timezone'          => config::byKey('timezone', 'core', 'Europe/Paris'),
            'pulse_duration'    => (int) config::byKey('pulse_duration', __CLASS__, 5),
            'reconnect_delay'   => (int) config::byKey('reconnect_delay', __CLASS__, 15),
            'snapshot_on_event' => (int) config::byKey('snapshot_on_event', __CLASS__, 1),
            'snapshot_keep'     => (int) config::byKey('snapshot_keep', __CLASS__, 50),
            // Sonde de joignabilité des caméras. 0 désactive.
            'camera_check_interval' => (int) config::byKey('camera_check_interval', __CLASS__, 60),
            'nvrs'              => array(),
        );

        // Une seule requête pour toutes les caméras, puis regroupement en mémoire.
        $camerasByNvr = array();
        foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_CAMERA), true) as $cam) {
            $camerasByNvr[(int) $cam->getConfiguration('nvr_id')][] = $cam;
        }

        foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_NVR), true) as $nvr) {
            $channels = array();
            foreach (isset($camerasByNvr[$nvr->getId()]) ? $camerasByNvr[$nvr->getId()] : array() as $cam) {
                $channels[(int) $cam->getConfiguration('channel')] = array(
                    'eqLogic_id' => (int) $cam->getId(),
                    'name'       => $cam->getName(),
                );
            }
            $config['nvrs'][] = array(
                'eqLogic_id' => (int) $nvr->getId(),
                'name'       => $nvr->getName(),
                'ip'         => $nvr->getConfiguration('ip'),
                'port'       => (int) $nvr->getConfiguration('port', 80),
                'http_port'  => (int) $nvr->getConfiguration('http_port', 80),
                'username'   => $nvr->getConfiguration('username'),
                'password'   => $nvr->getConfiguration('password'),
                'transport'  => $nvr->getConfiguration('transport', 'auto'),
                'channels'   => $channels,
            );
        }
        return $config;
    }

    /*
     * Demande au démon de relire sa configuration. L'envoi est différé à la fin de
     * la requête : enregistrer huit caméras ne doit produire qu'un seul ordre.
     */
    public static function reloadDaemonConfig() {
        if (self::$_reloadScheduled) {
            return;
        }
        self::$_reloadScheduled = true;
        register_shutdown_function(function () {
            try {
                dahua::sendToDaemon(array('cmd' => 'reload'), false);
            } catch (Throwable $e) {
                log::add('dahua', 'debug', 'reload démon : ' . $e->getMessage());
            }
        });
    }

    /* ====================================================== JEEDOM → DÉMON */

    /*
     * $_waitAnswer = false pour les ordres dont la réponse n'apporte rien : le
     * démon traite « reload » de façon différée et Jeedom n'a pas à l'attendre.
     */
    public static function sendToDaemon($_payload, $_waitAnswer = true) {
        if (self::deamon_info()['state'] != 'ok') {
            log::add(__CLASS__, 'debug', __('Démon arrêté, ordre ignoré', __FILE__));
            return false;
        }
        $_payload['apikey'] = jeedom::getApiKey(__CLASS__);
        $socket = @stream_socket_client(
            'tcp://127.0.0.1:' . config::byKey('socketport', __CLASS__, 55060),
            $errno, $errstr, 5
        );
        if ($socket === false) {
            log::add(__CLASS__, 'error', __('Connexion au démon impossible :', __FILE__) . ' ' . $errstr);
            return false;
        }
        fwrite($socket, json_encode($_payload) . "\n");
        if (!$_waitAnswer) {
            fclose($socket);
            return true;
        }
        stream_set_timeout($socket, 10);
        $response = trim((string) fgets($socket));
        fclose($socket);
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : array('state' => 'error', 'result' => $response);
    }

    /* ============================================== AUTO-DÉCOUVERTE DES CAMÉRAS */

    public static function discoverCameras($_nvrId) {
        $nvr = self::byId($_nvrId);
        if (!is_object($nvr) || $nvr->getEqType_name() != __CLASS__
         || $nvr->getConfiguration('type') != self::TYPE_NVR) {
            throw new Exception(__('NVR introuvable', __FILE__));
        }

        $titles = self::cgiRequest($nvr, 'configManager.cgi?action=getConfig&name=ChannelTitle');
        if ($titles === false) {
            throw new Exception(__('Impossible d\'interroger le NVR, vérifiez adresse et identifiants', __FILE__));
        }

        // table.ChannelTitle[0].Name=OUESTTERRASSE
        $names = array();
        foreach (explode("\n", $titles) as $line) {
            if (preg_match('/^table\.ChannelTitle\[(\d+)\]\.Name=(.*)$/', trim($line), $m)) {
                $names[(int) $m[1]] = trim($m[2]);
            }
        }
        if (empty($names)) {
            throw new Exception(__('Aucun canal retourné par le NVR', __FILE__));
        }

        $created = 0;
        foreach ($names as $index => $label) {
            $channel = $index + 1;                   // le NVR numérote D1..Dn dans son interface
            $logicalId = 'cam::' . $nvr->getId() . '::' . $channel;
            if (is_object(self::byLogicalId($logicalId, __CLASS__))) {
                continue;                            // déjà présent, on ne réécrase rien
            }

            /*
             * eqLogic porte une contrainte d'unicité (name, object_id) : deux canaux
             * portant le même titre feraient échouer la création du second.
             */
            $name = ($label != '') ? $label : ($nvr->getName() . ' - canal ' . $channel);
            if (is_object(eqLogic::byObjectNameEqLogicName($nvr->getObject_id(), $name))) {
                $name .= ' (' . $channel . ')';
            }

            try {
                $cam = new self();
                $cam->setEqType_name(__CLASS__);
                $cam->setLogicalId($logicalId);
                $cam->setName($name);
                $cam->setObject_id($nvr->getObject_id());
                $cam->setConfiguration('type', self::TYPE_CAMERA);
                $cam->setConfiguration('nvr_id', (int) $nvr->getId());
                $cam->setConfiguration('channel', $channel);
                $cam->setIsEnable(1);
                $cam->setIsVisible(1);
                $cam->save();
                $created++;
            } catch (Throwable $e) {
                // Un canal en échec ne doit pas interrompre toute la découverte.
                log::add(__CLASS__, 'error', __('Création du canal', __FILE__) . ' ' . $channel
                       . ' : ' . $e->getMessage());
            }
        }

        self::reloadDaemonConfig();
        return $created;
    }

    /* Requête HTTP CGI authentifiée en Digest sur le NVR. */
    public static function cgiRequest($_nvr, $_path, $_binary = false, $_timeout = 10, &$_detail = null) {
        // Le port HTTP est distinct du port DHIP : sur certains firmwares le
        // protocole binaire n'écoute pas sur 80.
        $port = (int) $_nvr->getConfiguration('http_port', 80);
        $url = 'http://' . $_nvr->getConfiguration('ip') . ':' . ($port > 0 ? $port : 80)
             . '/cgi-bin/' . $_path;

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST,
            CURLOPT_USERPWD        => $_nvr->getConfiguration('username') . ':' . $_nvr->getConfiguration('password'),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $_timeout,
        ));
        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        $_detail = array('code' => $code, 'curl' => $error);

        if ($result === false || $code != 200) {
            log::add(__CLASS__, 'error', __('Requête CGI en échec :', __FILE__) . ' ' . $url
                   . ' (HTTP ' . $code . ($error != '' ? ' / ' . $error : '') . ')');
            return false;
        }
        if (!$_binary && stripos(trim($result), 'Error') === 0) {
            log::add(__CLASS__, 'debug', __('Le NVR a refusé la requête :', __FILE__) . ' ' . $_path);
            return false;
        }
        return $result;
    }

    /*
     * Traduit un échec de requête CGI en cause probable.
     * Le NVR répond « Bad Request » quand il ne peut pas servir un canal, ce qui
     * arrive surtout lorsque la caméra ne lui fournit plus de flux — cas qu'il ne
     * faut pas confondre avec une erreur de configuration.
     */
    public static function describeCgiFailure($_detail, $_what = '') {
        $code = isset($_detail['code']) ? (int) $_detail['code'] : 0;
        switch ($code) {
            case 0:
                return __('Le NVR est injoignable. Vérifiez son adresse, son port HTTP et le réseau.', __FILE__)
                     . (isset($_detail['curl']) && $_detail['curl'] != '' ? ' (' . $_detail['curl'] . ')' : '');
            case 401:
            case 403:
                return __('Le NVR a refusé les identifiants.', __FILE__);
            case 400:
                return __('Le NVR ne peut pas servir ce canal.', __FILE__) . ' '
                     . __('La caméra est probablement hors ligne ou ne fournit plus de flux : vérifiez-la dans l\'interface du NVR.', __FILE__)
                     . ($_what != '' ? ' (' . $_what . ')' : '');
            case 404:
                return __('Ce NVR ne propose pas cette fonction.', __FILE__);
        }
        return __('Le NVR a répondu HTTP', __FILE__) . ' ' . $code . '.';
    }

    /* ====================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
        if ($this->getConfiguration('type') == '') {
            $this->setConfiguration('type', self::TYPE_NVR);
        }

        /*
         * L'état antérieur n'est plus lisible dans postSave : on le retient ici.
         * Et si une règle déclenchée est en train d'être désactivée, elle doit
         * retomber MAINTENANT, pendant qu'elle est encore active — le coeur
         * refuse toute mise à jour de commande sur un équipement désactivé, la
         * commande resterait donc à 1 et les actions de fin ne seraient jamais
         * jouées.
         */
        $this->_previousType = null;
        if ($this->getId() != '') {
            $previous = self::byId($this->getId());
            if (is_object($previous)) {
                $this->_previousType = $previous->getConfiguration('type');
                if ($this->_previousType == self::TYPE_RULE && $previous->getIsEnable() == 1
                 && ($this->getIsEnable() != 1 || $this->getConfiguration('type') != self::TYPE_RULE)) {
                    dahuaRule::release($previous);
                }
            }
        }

        if ($this->getConfiguration('type') == self::TYPE_NVR) {
            if ($this->getConfiguration('port') == '') {
                $this->setConfiguration('port', 80);
            }
            if ($this->getConfiguration('http_port') == '') {
                $this->setConfiguration('http_port', 80);
            }
            if ($this->getConfiguration('transport') == '') {
                $this->setConfiguration('transport', 'auto');
            }
            /*
             * Aucune exception à la création : le coeur crée l'équipement avec son
             * seul nom (plugin.template.js), toute validation ici rendrait le bouton
             * « Ajouter » inutilisable. L'équipement incomplet est signalé par
             * deamon_info() et par l'onglet Santé.
             */
            return;
        }

        if ($this->getConfiguration('type') == self::TYPE_RULE) {
            /*
             * Les valeurs viennent du formulaire sous forme de chaînes et sont
             * comparées à des entiers par le moteur de corrélation. Aucune
             * exception ici non plus : une règle vide doit rester enregistrable,
             * le coeur crée l'équipement avant qu'on ait pu la remplir.
             */
            /*
             * Un champ laissé vide reçoit la valeur par défaut ; une valeur saisie
             * est seulement bornée. Zéro est légitime pour la temporisation —
             * « aucun délai entre deux déclenchements » — mais pas ailleurs.
             */
            $defaults = array('window'   => dahuaRule::DEFAULT_WINDOW,
                              'cooldown' => dahuaRule::DEFAULT_COOLDOWN,
                              'hold'     => dahuaRule::DEFAULT_HOLD,
                              'threshold' => dahuaRule::DEFAULT_THRESHOLD);
            $minimums = array('window' => 1, 'cooldown' => 0, 'hold' => 1, 'threshold' => 1);
            foreach ($defaults as $key => $default) {
                $raw = $this->getConfiguration($key, '');
                $value = ($raw === '' || $raw === null) ? $default : (int) $raw;
                $this->setConfiguration($key, max($minimums[$key], $value));
            }
            $scope = $this->getConfiguration('camera_scope');
            if (!in_array($scope, array(dahuaRule::SCOPE_ANY, dahuaRule::SCOPE_SAME, dahuaRule::SCOPE_DISTINCT))) {
                $this->setConfiguration('camera_scope', dahuaRule::SCOPE_ANY);
            }
            if ($this->getConfiguration('mode') != dahuaRule::MODE_COUNT) {
                $this->setConfiguration('mode', dahuaRule::MODE_ALL);
            }
            return;
        }

        // Les valeurs du formulaire sont des chaînes : « 02 » produirait un
        // logicalId « cam::1::02 » que le démon ne retrouverait jamais.
        $this->setConfiguration('nvr_id', (int) $this->getConfiguration('nvr_id'));
        $this->setConfiguration('channel', (int) $this->getConfiguration('channel'));

        if ($this->getId() != '' && (int) $this->getConfiguration('channel') > 0
         && (int) $this->getConfiguration('nvr_id') > 0) {
            $expected = $this->cameraLogicalId();
            $other = self::byLogicalId($expected, __CLASS__);
            if (is_object($other) && $other->getId() != $this->getId()) {
                throw new Exception(__('Ce canal est déjà utilisé par l\'équipement :', __FILE__)
                                  . ' ' . $other->getName());
            }
        }
    }

    private function cameraLogicalId() {
        return 'cam::' . (int) $this->getConfiguration('nvr_id') . '::' . (int) $this->getConfiguration('channel');
    }

    public function postSave() {
        $this->migrateLegacyCommands();

        /*
         * Le logicalId est recalculé inconditionnellement : un équipement
         * basculé d'un type à l'autre conserverait sinon celui de son ancien
         * type et capterait les événements d'une caméra.
         */
        switch ($this->getConfiguration('type')) {
            case self::TYPE_NVR:
                $expected = 'nvr::' . $this->getId();
                break;
            case self::TYPE_RULE:
                $expected = 'rule::' . $this->getId();
                break;
            default:
                $expected = $this->cameraLogicalId();
                break;
        }
        if ($this->getLogicalId() != $expected) {
            $this->setLogicalId($expected);
            $this->save(true);
        }
        switch ($this->getConfiguration('type')) {
            case self::TYPE_NVR:  $this->createNvrCommands();    break;
            case self::TYPE_RULE: $this->createRuleCommands();   break;
            default:              $this->createCameraCommands(); break;
        }
        $this->removeForeignCommands();

        /*
         * Un équipement qui cesse d'être une règle ne doit pas conserver son état
         * de corrélation : s'il le redevenait, il hériterait d'une temporisation
         * fantôme, voire d'une durée de maintien déjà expirée.
         */
        if ($this->_previousType == self::TYPE_RULE
         && $this->getConfiguration('type') != self::TYPE_RULE) {
            dahuaRule::forget($this->getId());
        }
        if ($this->getConfiguration('type') == self::TYPE_RULE) {
            /*
             * Une règle sans condition exploitable ne produit aucune erreur : elle
             * ne se déclenche simplement jamais. L'onglet Santé le signale, mais
             * personne ne va le consulter spontanément — un message, lui, se voit.
             */
            $messageKey = 'ruleEmpty' . $this->getId();
            if (count(dahuaRule::conditions($this)) == 0) {
                message::add(__CLASS__, $this->getHumanName() . ' '
                    . __('n\'a aucune condition complète : elle ne se déclenchera jamais. Ouvrez-la et choisissez une caméra et une détection sur chaque ligne.', __FILE__),
                    '', $messageKey);
            } else {
                message::removeAll(__CLASS__, $messageKey);
            }
            return;                                   // une règle n'intéresse pas le démon
        }
        $this->spreadObject();
        if ($this->getConfiguration('type') == self::TYPE_NVR) {
            $this->publishCamStatus();                // la tuile doit exister dès la création
        }
        self::reloadDaemonConfig();
    }

    /*
     * Un équipement sans objet parent n'apparaît sur AUCUN dashboard : le coeur
     * ne construit ses conteneurs qu'à partir des objets, et n'interroge jamais
     * le seau des équipements orphelins (eqLogic::byObjectId(null) n'est appelé
     * que par la page d'ordonnancement des widgets).
     *
     * discoverCameras() fait déjà hériter les caméras de l'objet du NVR, mais
     * seulement à leur création : renseigner l'objet du NVR après coup ne
     * remontait donc rien. On rejoue l'héritage à chaque enregistrement, sans
     * jamais écraser un choix explicite de l'utilisateur.
     */
    private function spreadObject() {
        if ($this->getConfiguration('type') != self::TYPE_NVR || $this->getObject_id() == '') {
            return;
        }
        foreach (self::camerasOf($this->getId()) as $cam) {
            if ($cam->getObject_id() == '') {
                $cam->setObject_id($this->getObject_id());
                $cam->save(true);                     // direct : pas de postSave en cascade
            }
        }
    }

    /*
     * Supprime les commandes héritées de l'autre type après une bascule
     * NVR <-> caméra. Seules les commandes générées par le plugin sont touchées :
     * celles créées à la main par l'utilisateur sont conservées.
     */
    private function removeForeignCommands() {
        // 'lastevent' et 'lastevent_date' figurent dans les deux premières
        // listes : elles survivent donc à une bascule NVR <-> caméra, et ne
        // disparaissent que si l'équipement devient une règle.
        $nvrIds = array('online', 'camstatus', 'reconnect', 'alarmout_on', 'alarmout_off',
                        'lastevent', 'lastevent_date');
        foreach (self::$_nvrEvents as $def) {
            $nvrIds[] = $def['logicalId'];
        }
        // 'online' figure dans les deux premières listes pour la même raison que
        // 'lastevent' : sans lui ici, il serait vu comme une commande de NVR sur
        // une caméra, donc créé par createCameraCommands() puis détruit par cette
        // méthode DANS LE MÊME ENREGISTREMENT — avec son historique, remove()
        // appelant emptyHistory(). Et la panne serait muette : checkAndUpdateCmd()
        // sur une commande absente retourne false sans rien journaliser.
        $camIds = array('online', 'snapshot', 'take_snapshot', 'ptz_preset',
                        'light_on', 'light_off', 'siren_on', 'siren_off',
                        'lastevent', 'lastevent_date');
        foreach (self::$_channelEvents as $def) {
            $camIds[] = $def['logicalId'];
        }
        $ruleIds = array('triggered', 'detail', 'image', 'rule_test', 'rule_reset');

        $byType = array(
            self::TYPE_NVR    => $nvrIds,
            self::TYPE_CAMERA => $camIds,
            self::TYPE_RULE   => $ruleIds,
        );
        $current = $this->getConfiguration('type');
        if (!isset($byType[$current])) {
            /*
             * Type inattendu : on ne supprime rien. Prendre une liste vide comme
             * « à conserver » effacerait TOUTES les commandes de l'équipement —
             * c'est exactement la panne « commandes du NVR effacées » déjà vécue.
             */
            log::add(__CLASS__, 'warning', __('Type d\'équipement inconnu, aucune commande supprimée :', __FILE__)
                   . ' ' . $this->getHumanName() . ' (' . $current . ')');
            return;
        }
        $keep = $byType[$current];

        $toRemove = array();
        foreach ($byType as $type => $ids) {
            if ($type != $current) {
                $toRemove = array_merge($toRemove, $ids);
            }
        }
        $toRemove = array_diff(array_unique($toRemove), $keep);
        foreach ($toRemove as $logicalId) {
            $cmd = $this->getCmd(null, $logicalId);
            if (is_object($cmd)) {
                $cmd->remove();
            }
        }
    }

    /* Une caméra orpheline resterait figée sur le dashboard sans jamais rien recevoir. */
    public function preRemove() {
        /*
         * DB::remove() met l'id à null AVANT d'appeler postRemove : sans cette
         * mémorisation, le nettoyage du cache porterait sur l'identifiant 0 et
         * laisserait l'entrée réelle orpheline.
         */
        $this->_removedId = (int) $this->getId();
        if ($this->getConfiguration('type') != self::TYPE_NVR) {
            return true;
        }
        foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_CAMERA)) as $cam) {
            if ($cam->getConfiguration('nvr_id') == $this->getId()) {
                $cam->remove();
            }
        }
        return true;
    }

    public function postRemove() {
        if ($this->getConfiguration('type') == self::TYPE_RULE) {
            dahuaRule::forget($this->_removedId);
            return;                                   // aucune incidence sur le démon
        }
        if ($this->getConfiguration('type') == self::TYPE_CAMERA) {
            /* Sans cet oubli, une caméra recréée plus tard sur le même canal
             * hériterait d'un « déjà signalée » fantôme, et sa prochaine perte
             * resterait muette. */
            self::forgetCamState($this->_removedId);
            $nvr = self::byId((int) $this->getConfiguration('nvr_id'));
            if (is_object($nvr)) {
                $nvr->publishCamStatus();             // la tuile ne doit plus la montrer
            }
        }
        self::reloadDaemonConfig();
    }

    /*
     * Le cron minute du coeur. Il ne sert qu'à faire retomber les règles dont la
     * durée de maintien est écoulée alors qu'aucun événement n'arrive plus : une
     * règle déclenchée en fin de soirée resterait sinon allumée toute la nuit.
     */
    public static function cron() {
        try {
            dahuaRule::checkHold();
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', __('Retour des règles en échec :', __FILE__) . ' ' . $e->getMessage());
        }
    }

    /* Crée les commandes manquantes sans jamais écraser la personnalisation. */
    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd(null, $_logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }
        $cmd = new dahuaCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        /*
         * La table cmd impose l'unicité du couple (eqLogic_id, name) : un nom déjà
         * pris par une autre commande ferait échouer l'enregistrement de tout
         * l'équipement. On suffixe plutôt que de laisser planter.
         */
        $name = __($_name, __FILE__);
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 1);
        $cmd->setIsHistorized(isset($_options['isHistorized']) ? $_options['isHistorized'] : 0);
        if (isset($_options['generic_type'])) {
            $cmd->setGeneric_type($_options['generic_type']);
        }
        if (isset($_options['order'])) {
            $cmd->setOrder($_options['order']);
        }
        /*
         * invertBinary n'inverse que l'affichage : au repos la commande montre une
         * coche verte, et une croix rouge seulement pendant la détection. Sans cela
         * une caméra au repos affiche quinze croix rouges.
         */
        if (!empty($_options['invert'])) {
            $cmd->setDisplay('invertBinary', 1);
        }
        if (isset($_options['configuration']) && is_array($_options['configuration'])) {
            foreach ($_options['configuration'] as $key => $value) {
                $cmd->setConfiguration($key, $value);
            }
        }
        /*
         * Widget personnalisé, posé sur les deux rendus. Le nom doit être qualifié
         * (« dahua::… ») : setTemplate() préfixe « core:: » à tout nom qui ne l'est
         * pas, et le gabarit resterait introuvable.
         */
        if (isset($_options['template'])) {
            $cmd->setTemplate('dashboard', $_options['template']);
            $cmd->setTemplate('mobile', $_options['template']);
        }
        $cmd->save();
        return $cmd;
    }

    private function createCameraCommands() {
        $order = 0;
        /*
         * Joignabilité de la caméra, alimentée par la sonde du démon et non par un
         * événement : une caméra PoE qui décroche ne produit ni VideoLoss ni
         * NetMonitorAbort, elle se tait, simplement.
         *
         * Volontairement hors de $_channelEvents, dont la clé est un code Dahua.
         * Une entrée factice dans cette table la ferait apparaître dans le
         * sélecteur de détection des règles, la soumettrait au moteur de
         * corrélation qui ne sait traiter que des impulsions, et surtout lui
         * appliquerait l'option 'invert' : une caméra en ligne s'afficherait
         * alors avec une croix rouge.
         *
         * Invisible : les huit états sont montrés ensemble par l'équipement de
         * supervision. Elle reste la source de vérité, utilisable dans un
         * scénario et historisée pour répondre à « depuis quand ? ».
         */
        $this->addCmdIfMissing('online', 'Connectée', 'info', 'binary', array(
            'isVisible'    => 0,
            'isHistorized' => 1,
            'generic_type' => 'ONLINE',
            'order'        => $order++,
        ));
        foreach (self::$_channelEvents as $def) {
            $options = array(
                'isVisible'    => $def['visible'],
                'isHistorized' => $def['historized'],
                'order'        => $order++,
                'invert'       => ($def['logicalId'] != 'videoloss'),   // 0 = normal sauf perte vidéo
            );
            if (isset($def['generic'])) {
                $options['generic_type'] = $def['generic'];
            }
            $this->addCmdIfMissing($def['logicalId'], $def['name'], 'info', 'binary', $options);
        }
        $this->addCmdIfMissing('lastevent', 'Dernier événement', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('lastevent_date', 'Date du dernier événement', 'info', 'string', array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('snapshot', 'Dernière image', 'info', 'string', array(
            'isVisible'    => 0,
            'generic_type' => 'CAMERA_URL',
            'order'        => $order++,
        ));
        $this->addCmdIfMissing('take_snapshot', 'Capturer une image', 'action', 'other', array(
            'generic_type' => 'CAMERA_TAKE',
            'order'        => $order++,
        ));
        $this->addCmdIfMissing('ptz_preset', 'Aller au preset', 'action', 'message', array(
            'isVisible'    => 0,
            'generic_type' => 'CAMERA_PRESET',
            'order'        => $order++,
        ));
        /*
         * Éclairage et sirène ne répondent que sur un matériel qui les expose
         * (caméra Dahua joignable directement, ou NVR relayant coaxialControlIO).
         * Masquées par défaut : la majorité des installations ne les supporte pas.
         */
        $this->addCmdIfMissing('light_on',  'Lumière blanche ON',  'action', 'other', array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('light_off', 'Lumière blanche OFF', 'action', 'other', array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('siren_on',  'Sirène ON',           'action', 'other', array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('siren_off', 'Sirène OFF',          'action', 'other', array('isVisible' => 0, 'order' => $order++));
    }

    private function createRuleCommands() {
        $order = 0;
        /*
         * repeatEventManagement = always : sans cela, le coeur sort avant
         * scenario::check() quand une commande reçoit deux fois la même valeur.
         * Une seconde corrélation survenue pendant la durée de maintien ne
         * réveillerait alors aucun scénario.
         */
        $this->addCmdIfMissing('triggered', 'Déclenchée', 'info', 'binary', array(
            'isHistorized'  => 1,
            'generic_type'  => 'ALARM_STATE',
            'order'         => $order++,
            'configuration' => array('repeatEventManagement' => 'always'),
        ));
        $this->addCmdIfMissing('detail', 'Détail du déclenchement', 'info', 'string', array(
            'order' => $order++,
        ));
        $this->addCmdIfMissing('image', 'Image du déclenchement', 'info', 'string', array(
            'isVisible'    => 0,
            'generic_type' => 'CAMERA_URL',
            'order'        => $order++,
        ));
        /*
         * « Tester » joue toutes les actions de la règle, y compris sur des
         * équipements auxquels l'utilisateur du dashboard n'a pas forcément
         * droit. Masquée par défaut : le bouton de la page du plugin, réservé
         * aux administrateurs, suffit à l'usage courant.
         */
        $this->addCmdIfMissing('rule_test', 'Tester', 'action', 'other', array(
            'isVisible' => 0,
            'order'     => $order++,
        ));
        $this->addCmdIfMissing('rule_reset', 'Réinitialiser', 'action', 'other', array('order' => $order++));
    }

    private function createNvrCommands() {
        $order = 0;
        /*
         * Tuile de synthèse. Une seule commande porte, en JSON, la santé du NVR et
         * l'état de chacune de ses caméras ; le widget en fait une grille.
         *
         * Pourquoi une commande unique plutôt qu'une commande par caméra :
         *  - Jeedom ne permet pas à un plugin de fournir un widget d'ÉQUIPEMENT
         *    (eqLogic::toHtml() charge toujours le gabarit du coeur), seul un
         *    widget de commande donne la main sur le rendu ;
         *  - son logicalId est fixe, donc removeForeignCommands() reste trivial et
         *    aucune commande n'est laissée orpheline quand une caméra disparaît ;
         *  - les noms de caméra deviennent des données rafraîchies à chaque sonde,
         *    et non des libellés soumis à l'unicité (eqLogic_id, name) : renommer
         *    une caméra se répercute tout seul.
         */
        $this->addCmdIfMissing('camstatus', 'Caméras', 'info', 'string', array(
            'order'    => $order++,
            'template' => 'dahua::dahua',
        ));
        /*
         * Connexion et stockage sont désormais rendus par le bandeau du widget.
         * Masquées pour ne pas afficher deux fois la même information, elles
         * restent disponibles aux scénarios et à l'historique.
         */
        $this->addCmdIfMissing('online', 'Connecté', 'info', 'binary', array(
            'isVisible'    => 0,
            'isHistorized' => 1,
            'generic_type' => 'ONLINE',
            'order'        => $order++,
        ));
        foreach (self::$_nvrEvents as $def) {
            $this->addCmdIfMissing($def['logicalId'], $def['name'], 'info', 'binary', array(
                'isVisible' => 0,
                'order'     => $order++,
            ));
        }
        $this->addCmdIfMissing('lastevent', 'Dernier événement', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('lastevent_date', 'Date du dernier événement', 'info', 'string', array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('reconnect', 'Reconnecter', 'action', 'other', array('order' => $order++));
        // Sorties d'alarme : absentes de nombreux NVR, masquées par défaut.
        $this->addCmdIfMissing('alarmout_on',  'Sortie alarme ON',   'action', 'other', array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('alarmout_off', 'Sortie alarme OFF',  'action', 'other', array('isVisible' => 0, 'order' => $order++));
    }

    /*
     * Recompose la tuile de synthèse de ce NVR et la publie.
     *
     * Appelée après toute mise à jour susceptible de la changer : sonde de
     * joignabilité, connexion ou perte du NVR, événement de stockage. Elle relit
     * l'état réel des commandes plutôt que de tenir un état parallèle : il n'y a
     * ainsi qu'une seule vérité, et une tuile ne peut pas diverger de ce que les
     * scénarios voient.
     */
    public function publishCamStatus() {
        if ($this->getConfiguration('type') != self::TYPE_NVR) {
            return;
        }
        $online = $this->getCmd('info', 'online');
        $nvrUp  = is_object($online) ? ((int) $online->execCmd() == 1) : false;

        /* Un seul défaut suffit à signaler le stockage : les trois codes décrivent
         * la même chose du point de vue de l'utilisateur — il faut aller voir. */
        $storageOk = true;
        foreach (array('storage_missing', 'storage_failure', 'storage_low') as $logicalId) {
            $cmd = $this->getCmd('info', $logicalId);
            if (is_object($cmd) && (int) $cmd->execCmd() == 1) {
                $storageOk = false;
            }
        }

        $cams = array();
        $up   = 0;
        foreach (self::camerasOf($this->getId()) as $cam) {
            $state = $cam->getCmd('info', 'online');
            $entry = array('n' => $cam->getName());
            if ($cam->getIsEnable() == 0) {
                /* Une caméra que l'utilisateur a lui-même désactivée n'est pas une
                 * panne : la compter comme perdue ferait crier la tuile à tort. */
                $entry['s'] = 'off';
            } elseif (!is_object($state) || $state->execCmd() === '') {
                $entry['s'] = 'off';                      // jamais sondée : inconnu, pas perdu
            } elseif ((int) $state->execCmd() == 1) {
                $entry['s'] = 1;
                $up++;
            } else {
                $entry['s'] = 0;
                $since = strtotime((string) $state->getValueDate());
                if ($since !== false && $since > 0) {
                    $entry['since'] = $since;
                }
            }
            $cams[] = $entry;
        }

        $payload = array(
            't'     => time(),
            'nvr'   => array(
                'on' => $nvrUp ? 1 : 0,
                'tr' => self::transport($this->getId()),
                'st' => $storageOk ? 1 : 0,
            ),
            'on'    => $up,
            'total' => count($cams),
            'c'     => $cams,
        );

        /* Les noms de caméra viennent du NVR, donc d'une source externe : ces
         * drapeaux empêchent qu'un nom porteur de balises casse le widget. */
        $this->checkAndUpdateCmd('camstatus', json_encode($payload,
            JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP));
    }

    /* ============================================ ÉTAT TRANSITOIRE (CACHE)
     *
     * Le callback est un processus PHP neuf à chaque requête : il n'a aucune
     * mémoire d'un événement au suivant. Tout ce qui doit survivre entre deux
     * appels — et à un redémarrage du démon — vit donc dans le cache, comme le
     * fait déjà le moteur de corrélation pour son état.
     */

    const TRANSPORT_CACHE = 'dahua::transport::';
    const CAMSTATE_CACHE  = 'dahua::camstate::';

    public static function transport($_nvrId) {
        return (string) cache::byKey(self::TRANSPORT_CACHE . (int) $_nvrId)->getValue('');
    }

    public static function rememberTransport($_nvrId, $_transport) {
        cache::set(self::TRANSPORT_CACHE . (int) $_nvrId, (string) $_transport, 0);
    }

    /*
     * Mémoire d'alerte d'une caméra.
     *   'fired' : date du dernier jeu d'actions « perdue », 0 si aucune en cours.
     * Elle est ce qui garantit qu'une perte ne notifie qu'UNE fois : sans elle,
     * un redémarrage du démon — qui resonde et republie tout — renotifierait
     * chaque caméra déjà connue comme perdue.
     */
    public static function camState($_camId) {
        $raw   = cache::byKey(self::CAMSTATE_CACHE . (int) $_camId)->getValue('');
        $state = ($raw != '') ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            $state = array();
        }
        return array('fired' => isset($state['fired']) ? (int) $state['fired'] : 0);
    }

    public static function saveCamState($_camId, $_state) {
        cache::set(self::CAMSTATE_CACHE . (int) $_camId, json_encode($_state), 0);
    }

    public static function forgetCamState($_camId) {
        cache::byKey(self::CAMSTATE_CACHE . (int) $_camId)->remove();
    }

    /* =================================================== ACTIONS CONFIGURABLES
     *
     * scenarioExpression::createAndExec est le primitif du coeur pour les actions
     * configurables : il accepte une commande, un scénario ou une variable, et
     * honore les options « désactivée » et « en tâche de fond » du sélecteur.
     *
     * $_tags       : substitutions (#camera#, #nvr#…) appliquées aux options, et
     *                transmises telles quelles à un scénario appelé.
     * $_guardSelf  : refuse une action visant une commande de l'équipement lui-même.
     *                Indispensable pour une règle, dont la commande « Tester »
     *                posée comme sa propre action boucherait indéfiniment. Nuisible
     *                sur un NVR, où l'action la plus naturelle après une caméra
     *                perdue est justement « Reconnecter » ce même NVR.
     */
    public static function runActions($_eqLogic, $_key, $_tags = array(), $_guardSelf = true) {
        $actions = $_eqLogic->getConfiguration($_key);
        if (!is_array($actions)) {
            return;
        }
        foreach ($actions as $action) {
            $expression = isset($action['cmd']) ? trim((string) $action['cmd']) : '';
            if ($expression == '') {
                continue;
            }
            if ($_guardSelf && self::targetsSelf($_eqLogic, $expression)) {
                log::add(__CLASS__, 'warning', $_eqLogic->getHumanName() . ' '
                       . __('action ignorée, elle vise l\'équipement lui-même :', __FILE__) . ' ' . $expression);
                continue;
            }
            $options = (isset($action['options']) && is_array($action['options']))
                     ? $action['options'] : array();
            if (!empty($_tags)) {
                foreach ($options as $key => $value) {
                    if (is_string($value)) {
                        $options[$key] = str_replace(array_keys($_tags), array_values($_tags), $value);
                    }
                }
                /* Transmis au scénario appelé. On ne remplace jamais une valeur que
                 * le sélecteur du coeur aurait déjà posée : elle vient de l'utilisateur. */
                if (!isset($options['tags']) || $options['tags'] === '') {
                    $options['tags'] = $_tags;
                }
            }
            // 'source' sert de libellé d'origine, notamment au bloc « message » du
            // coeur, qui en fait le nom de plugin affiché.
            $options['source'] = $_eqLogic->getHumanName();
            try {
                scenarioExpression::createAndExec('action', $expression, $options);
            } catch (Throwable $e) {
                // Une action en échec ne doit pas empêcher les suivantes : une
                // notification cassée ne doit pas retenir la sirène.
                log::add(__CLASS__, 'error', $_eqLogic->getHumanName() . ' '
                       . __('action en échec :', __FILE__) . ' ' . $expression
                       . ' — ' . $e->getMessage());
            }
        }
    }

    private static function targetsSelf($_eqLogic, $_expression) {
        $id = str_replace('#', '', cmd::humanReadableToCmd($_expression));
        if (!is_numeric($id)) {
            return false;
        }
        $cmd = cmd::byId($id);
        return is_object($cmd) && $cmd->getEqLogic_id() == $_eqLogic->getId();
    }

    /* Les caméras rattachées à un NVR, dans l'ordre des canaux. */
    public static function camerasOf($_nvrId) {
        $cams = array();
        foreach (self::byTypeAndSearchConfiguration('dahua', array('type' => self::TYPE_CAMERA), true) as $cam) {
            if ($cam->getConfiguration('nvr_id') == $_nvrId) {
                $cams[(int) $cam->getConfiguration('channel')] = $cam;
            }
        }
        ksort($cams);
        return $cams;
    }

    /*
     * Reprend les commandes créées par une version antérieure du plugin, dont
     * certains identifiants internes ont changé. Sans cela, la création de la
     * nouvelle commande échoue sur l'unicité du nom.
     */
    public function migrateLegacyCommands() {
        $renames = array('storage' => 'storage_failure');
        foreach ($renames as $old => $new) {
            $cmd = $this->getCmd(null, $old);
            if (is_object($cmd) && !is_object($this->getCmd(null, $new))) {
                $cmd->setLogicalId($new);
                $cmd->save();
            }
        }
    }

    public function getNvr() {
        if ($this->getConfiguration('type') == self::TYPE_NVR) {
            return $this;
        }
        return self::byId($this->getConfiguration('nvr_id'));
    }

    /* ================================================================== PTZ */

    public function ptzGotoPreset($_preset) {
        if ($this->getConfiguration('type') != self::TYPE_CAMERA) {
            throw new Exception(__('Le PTZ ne s\'applique qu\'à une caméra', __FILE__));
        }
        $nvr = $this->getNvr();
        if (!is_object($nvr)) {
            throw new Exception(__('NVR parent introuvable', __FILE__));
        }
        // Vérifié sur NVR4108 : ptz.cgi et snapshot.cgi attendent un canal 1-based,
        // contrairement à l'Index des événements qui part de 0.
        $channel = (int) $this->getConfiguration('channel');
        $detail = array();
        $result = self::cgiRequest($nvr, 'ptz.cgi?action=start&channel=' . $channel
                . '&code=GotoPreset&arg1=0&arg2=' . (int) $_preset . '&arg3=0', false, 10, $detail);
        if ($result === false) {
            throw new Exception(self::describeCgiFailure($detail, __('canal', __FILE__) . ' ' . $channel));
        }
        return true;
    }

    /* ==================================================== ÉCLAIRAGE / SIRÈNE */

    /*
     * Pilote la lumière blanche ou la sirène d'une caméra.
     * L'API coaxialControlIO n'est exposée que par les caméras qui embarquent ces
     * sorties ; un NVR sans relais répond « Bad Request ».
     */
    public function coaxialControl($_type, $_state) {
        if ($this->getConfiguration('type') != self::TYPE_CAMERA) {
            throw new Exception(__('Cette commande ne s\'applique qu\'à une caméra', __FILE__));
        }
        $nvr = $this->getNvr();
        if (!is_object($nvr)) {
            throw new Exception(__('NVR parent introuvable', __FILE__));
        }
        $channel = (int) $this->getConfiguration('channel');
        $result = self::cgiRequest($nvr, 'coaxialControlIO.cgi?action=control&channel=' . $channel
                . '&info[0].Type=' . (int) $_type . '&info[0].IO=' . ($_state ? 1 : 0));
        if ($result === false) {
            throw new Exception(__('Sortie non disponible sur ce matériel. Les caméras reliées au switch PoE interne du NVR ne sont pas joignables depuis Jeedom.', __FILE__));
        }
        return true;
    }

    /* Active ou désactive une sortie d'alarme du NVR. */
    public function alarmOutput($_state, $_index = 0) {
        $nvr = $this->getNvr();
        if (!is_object($nvr)) {
            throw new Exception(__('NVR parent introuvable', __FILE__));
        }
        // Mode 0 = toujours ouvert, 1 = toujours fermé, 2 = automatique.
        $result = self::cgiRequest($nvr, 'configManager.cgi?action=setConfig&AlarmOut['
                . (int) $_index . '].Mode=' . ($_state ? 1 : 0));
        if ($result === false) {
            throw new Exception(__('Ce matériel ne dispose pas de sortie d\'alarme.', __FILE__));
        }
        return true;
    }

    /* ============================================================== SNAPSHOT */

    public function takeSnapshot() {
        if ($this->getConfiguration('type') != self::TYPE_CAMERA) {
            throw new Exception(__('La capture ne s\'applique qu\'à une caméra.', __FILE__));
        }
        $nvr = $this->getNvr();
        if (!is_object($nvr)) {
            throw new Exception(__('NVR parent introuvable : rattachez la caméra à un NVR.', __FILE__));
        }
        $channel = (int) $this->getConfiguration('channel');
        $detail = array();
        $image = self::cgiRequest($nvr, 'snapshot.cgi?channel=' . $channel, true, 15, $detail);
        if ($image === false) {
            throw new Exception(self::describeCgiFailure($detail, __('canal', __FILE__) . ' ' . $channel));
        }
        // Le NVR répond parfois 200 avec un message d'erreur en texte : on exige la
        // signature JPEG plutôt que de se fier au code HTTP.
        if (strlen($image) < 1024 || substr($image, 0, 2) !== "\xFF\xD8") {
            throw new Exception(__('Le NVR a répondu, mais sans image exploitable pour ce canal.', __FILE__));
        }

        $dir = self::snapshotDir();
        if ($dir === false) {
            throw new Exception(__('Dossier de captures inaccessible, consultez le log.', __FILE__));
        }
        // gmdate des deux côtés : le démon tourne en UTC, le tri par nom doit rester
        // cohérent quel que soit le fuseau du process qui a écrit le fichier.
        $file = 'cam' . $this->getId() . '_' . gmdate('Ymd-His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (file_put_contents($dir . '/' . $file, $image) === false) {
            throw new Exception(__('Écriture de la capture impossible dans', __FILE__) . ' ' . $dir);
        }
        $this->purgeSnapshots($dir);

        $url = 'plugins/dahua/core/php/snapshot.php?file=' . rawurlencode($file);
        $this->checkAndUpdateCmd('snapshot', $url);
        return $url;
    }

    public static function snapshotDir() {
        $base = realpath(__DIR__ . '/../..');
        if ($base === false) {
            log::add(__CLASS__, 'error', __('Racine du plugin introuvable', __FILE__));
            return false;
        }
        $dir = $base . '/data/snapshots';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            log::add(__CLASS__, 'error', __('Création du dossier de captures impossible :', __FILE__) . ' ' . $dir);
            return false;
        }
        return $dir;
    }

    private function purgeSnapshots($_dir) {
        $keep = max(1, (int) config::byKey('snapshot_keep', __CLASS__, 50));
        $files = glob($_dir . '/cam' . $this->getId() . '_*.jpg');
        if ($files === false || count($files) <= $keep) {
            return;
        }
        sort($files);
        foreach (array_slice($files, 0, count($files) - $keep) as $old) {
            @unlink($old);
        }
    }

    /* ================================================================ SANTÉ */

    public static function health() {
        $return = array();
        $deamon = self::deamon_info();
        $return[] = array(
            'test'   => __('Démon', __FILE__),
            'result' => $deamon['state'] == 'ok' ? __('Démarré', __FILE__) : __('Arrêté', __FILE__),
            'advice' => $deamon['state'] == 'ok' ? '' : __('Démarrez le démon depuis la configuration du plugin', __FILE__),
            'state'  => $deamon['state'] == 'ok',
        );
        foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_NVR)) as $nvr) {
            if ($nvr->getConfiguration('ip') == '') {
                $return[] = array(
                    'test'   => $nvr->getName(),
                    'result' => __('Configuration incomplète', __FILE__),
                    'advice' => __('Renseignez l\'adresse et les identifiants du NVR', __FILE__),
                    'state'  => false,
                );
                continue;
            }
            $online = $nvr->getCmd('info', 'online');
            $ok = is_object($online) && $online->execCmd() == 1;
            $return[] = array(
                'test'   => $nvr->getName() . ' (' . $nvr->getConfiguration('ip') . ')',
                'result' => $ok ? __('Connecté', __FILE__) : __('Déconnecté', __FILE__),
                'advice' => $ok ? '' : __('Vérifiez l\'adresse, les identifiants et que le NVR est joignable', __FILE__),
                'state'  => $ok,
            );
        }

        /*
         * Une règle muette ne dit rien d'elle-même : elle n'échoue pas, elle ne
         * se déclenche simplement jamais. Deux pannes silencieuses ont déjà coûté
         * cher à ce plugin, celle-ci est signalée ici.
         */
        foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_RULE)) as $rule) {
            $conditions = dahuaRule::conditions($rule);
            if (count($conditions) == 0) {
                $return[] = array(
                    'test'   => $rule->getName(),
                    'result' => __('Aucune condition', __FILE__),
                    'advice' => __('Ajoutez au moins une détection à rapprocher, sinon la règle ne se déclenchera jamais', __FILE__),
                    'state'  => false,
                );
                continue;
            }
            $missing = array();
            foreach ($conditions as $condition) {
                if ($condition['source'] == dahuaRule::ANY) {
                    continue;
                }
                $camera = self::byId($condition['source']);
                if (!is_object($camera) || $camera->getConfiguration('type') != self::TYPE_CAMERA) {
                    $missing[] = $condition['source'];
                }
            }
            $return[] = array(
                'test'   => $rule->getName(),
                'result' => empty($missing)
                          ? (count($conditions) . ' ' . __('condition(s)', __FILE__))
                          : __('Caméra supprimée dans une condition', __FILE__),
                'advice' => empty($missing) ? ''
                          : __('Rouvrez la règle et choisissez une caméra existante', __FILE__),
                'state'  => empty($missing),
            );
        }
        return $return;
    }
}

class dahuaCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();

        switch ($this->getLogicalId()) {
            case 'take_snapshot':
                // takeSnapshot() lève une exception détaillant la cause d'un échec.
                return $eqLogic->takeSnapshot();

            case 'ptz_preset':
                // Le preset est une propriété de l'équipement, pas de la commande.
                $preset = (isset($_options['message']) && $_options['message'] !== '')
                        ? (int) $_options['message']
                        : (int) $eqLogic->getConfiguration('preset', 1);
                return $eqLogic->ptzGotoPreset(max(1, $preset));

            case 'reconnect':
                return dahua::sendToDaemon(array(
                    'cmd'    => 'reconnect',
                    'nvr_id' => (int) $eqLogic->getId(),
                ));

            // Type 1 = lumière blanche, Type 2 = sirène (API coaxialControlIO).
            case 'light_on':     return $eqLogic->coaxialControl(1, true);
            case 'light_off':    return $eqLogic->coaxialControl(1, false);
            case 'siren_on':     return $eqLogic->coaxialControl(2, true);
            case 'siren_off':    return $eqLogic->coaxialControl(2, false);

            case 'rule_test':    return dahuaRule::test($eqLogic);
            case 'rule_reset':   return dahuaRule::reset($eqLogic);

            case 'alarmout_on':  return $eqLogic->alarmOutput(true);
            case 'alarmout_off': return $eqLogic->alarmOutput(false);
        }
        return true;
    }
}
