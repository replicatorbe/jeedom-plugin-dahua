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

class dahua extends eqLogic {

    const TYPE_NVR    = 'nvr';
    const TYPE_CAMERA = 'camera';

    /* Un seul rechargement du démon par requête, même si dix équipements sont enregistrés. */
    private static $_reloadScheduled = false;

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
    public static function cgiRequest($_nvr, $_path, $_binary = false, $_timeout = 10) {
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

    /* ====================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
        if ($this->getConfiguration('type') == '') {
            $this->setConfiguration('type', self::TYPE_NVR);
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

        if ($this->getConfiguration('type') == self::TYPE_NVR) {
            // Recalcul inconditionnel : un équipement basculé de caméra à NVR
            // conserverait sinon un logicalId de caméra et capterait ses événements.
            if ($this->getLogicalId() != 'nvr::' . $this->getId()) {
                $this->setLogicalId('nvr::' . $this->getId());
                $this->save(true);
            }
            $this->createNvrCommands();
        } else {
            $expected = $this->cameraLogicalId();
            if ($this->getLogicalId() != $expected) {
                $this->setLogicalId($expected);
                $this->save(true);
            }
            $this->createCameraCommands();
        }
        $this->removeForeignCommands();
        self::reloadDaemonConfig();
    }

    /*
     * Supprime les commandes héritées de l'autre type après une bascule
     * NVR <-> caméra. Seules les commandes générées par le plugin sont touchées :
     * celles créées à la main par l'utilisateur sont conservées.
     */
    private function removeForeignCommands() {
        $isNvr = ($this->getConfiguration('type') == self::TYPE_NVR);

        $nvrIds = array('online', 'reconnect', 'alarmout_on', 'alarmout_off');
        foreach (self::$_nvrEvents as $def) {
            $nvrIds[] = $def['logicalId'];
        }
        $camIds = array('snapshot', 'take_snapshot', 'ptz_preset',
                        'light_on', 'light_off', 'siren_on', 'siren_off');
        foreach (self::$_channelEvents as $def) {
            $camIds[] = $def['logicalId'];
        }

        // 'lastevent' et 'lastevent_date' existent des deux côtés : jamais supprimées.
        $toRemove = array_diff($isNvr ? $camIds : $nvrIds, $isNvr ? $nvrIds : $camIds);
        foreach ($toRemove as $logicalId) {
            $cmd = $this->getCmd(null, $logicalId);
            if (is_object($cmd)) {
                $cmd->remove();
            }
        }
    }

    /* Une caméra orpheline resterait figée sur le dashboard sans jamais rien recevoir. */
    public function preRemove() {
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
        self::reloadDaemonConfig();
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
        $cmd->save();
        return $cmd;
    }

    private function createCameraCommands() {
        $order = 0;
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

    private function createNvrCommands() {
        $order = 0;
        $this->addCmdIfMissing('online', 'Connecté', 'info', 'binary', array(
            'isHistorized' => 1,
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
        $result = self::cgiRequest($nvr, 'ptz.cgi?action=start&channel=' . $channel
                . '&code=GotoPreset&arg1=0&arg2=' . (int) $_preset . '&arg3=0');
        if ($result === false) {
            throw new Exception(__('Le NVR a refusé la commande PTZ', __FILE__));
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
            return false;
        }
        $nvr = $this->getNvr();
        if (!is_object($nvr)) {
            return false;
        }
        $channel = (int) $this->getConfiguration('channel');
        $image = self::cgiRequest($nvr, 'snapshot.cgi?channel=' . $channel, true, 15);
        // Le NVR répond parfois 200 avec un message d'erreur en texte : on exige la
        // signature JPEG plutôt que de se fier au code HTTP.
        if ($image === false || strlen($image) < 1024 || substr($image, 0, 2) !== "\xFF\xD8") {
            return false;
        }

        $dir = self::snapshotDir();
        if ($dir === false) {
            return false;
        }
        // gmdate des deux côtés : le démon tourne en UTC, le tri par nom doit rester
        // cohérent quel que soit le fuseau du process qui a écrit le fichier.
        $file = 'cam' . $this->getId() . '_' . gmdate('Ymd-His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (file_put_contents($dir . '/' . $file, $image) === false) {
            return false;
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
        return $return;
    }
}

class dahuaCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();

        switch ($this->getLogicalId()) {
            case 'take_snapshot':
                $url = $eqLogic->takeSnapshot();
                if ($url === false) {
                    throw new Exception(__('Capture impossible, consultez les logs', __FILE__));
                }
                return $url;

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

            case 'alarmout_on':  return $eqLogic->alarmOutput(true);
            case 'alarmout_off': return $eqLogic->alarmOutput(false);
        }
        return true;
    }
}
