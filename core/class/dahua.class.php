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

    /*
     * Événements DHIP rattachés à un canal, mappés vers une commande info/binaire de la caméra.
     * Le démon envoie Action=Start/Stop ; les événements sans Stop (Action=Pulse) sont
     * remis à 0 par le démon après 'pulse_duration' secondes.
     */
    public static $_channelEvents = array(
        'VideoMotion'          => array('logicalId' => 'motion',      'name' => 'Mouvement'),
        'SmartMotionHuman'     => array('logicalId' => 'human',       'name' => 'Humain détecté'),
        'SmartMotionVehicle'   => array('logicalId' => 'vehicle',     'name' => 'Véhicule détecté'),
        'CrossLineDetection'   => array('logicalId' => 'crossline',   'name' => 'Ligne franchie'),
        'CrossRegionDetection' => array('logicalId' => 'crossregion', 'name' => 'Zone franchie'),
        'VideoLoss'            => array('logicalId' => 'videoloss',   'name' => 'Perte vidéo'),
        'VideoBlind'           => array('logicalId' => 'videoblind',  'name' => 'Caméra masquée'),
        'FaceDetection'        => array('logicalId' => 'face',        'name' => 'Visage détecté'),
        'ParkingDetection'     => array('logicalId' => 'parking',     'name' => 'Stationnement'),
        'LeftDetection'        => array('logicalId' => 'left',        'name' => 'Objet abandonné'),
        'TakenAwayDetection'   => array('logicalId' => 'takenaway',   'name' => 'Objet retiré'),
        'WanderDetection'      => array('logicalId' => 'wander',      'name' => 'Rôdeur'),
        'AudioAnomaly'         => array('logicalId' => 'audioanomaly','name' => 'Anomalie sonore'),
        'AudioMutation'        => array('logicalId' => 'audiomutation','name' => 'Variation sonore'),
        'FireWarning'          => array('logicalId' => 'fire',        'name' => 'Détection incendie'),
    );

    /* Événements globaux du NVR (non rattachés à un canal). */
    public static $_nvrEvents = array(
        'StorageNotExist'  => array('logicalId' => 'storage',      'name' => 'Défaut de stockage'),
        'StorageFailure'   => array('logicalId' => 'storage',      'name' => 'Défaut de stockage'),
        'StorageLowSpace'  => array('logicalId' => 'storage_low',  'name' => 'Espace disque faible'),
        'LoginFailure'     => array('logicalId' => 'loginfailure', 'name' => 'Échec de connexion'),
        'AlarmLocal'       => array('logicalId' => 'alarm',        'name' => 'Alarme entrée locale'),
        'NetworkChange'    => array('logicalId' => 'networkchange','name' => 'Changement réseau'),
    );

    /* ====================================================================== DÉMON */

    public static function deamon_info() {
        $return = array(
            'log'        => __CLASS__,
            'state'      => 'nok',
            'launchable' => 'ok',
        );

        // Rien à écouter tant qu'aucun NVR n'est configuré et activé.
        if (count(self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_NVR))) == 0) {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Aucun NVR configuré', __FILE__);
        }

        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if ($return['launchable'] == 'ok' && file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '' && @posix_getsid((int) $pid)) {
                $return['state'] = 'ok';
            } else {
                // PID mort : sans ce nettoyage le watchdog ne relancerait jamais le démon.
                @unlink($pid_file);
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
         * Les identifiants du NVR ne transitent PAS par la ligne de commande : ils seraient
         * lisibles par tout utilisateur local via `ps`. Le démon les récupère au démarrage
         * sur son callback, authentifié par la clé API du plugin.
         */
        $cmd  = 'php ' . escapeshellarg($daemon);
        $cmd .= ' --callback '   . escapeshellarg(self::getCallbackUrl());
        $cmd .= ' --apikey '     . escapeshellarg(jeedom::getApiKey(__CLASS__));
        $cmd .= ' --pid '        . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/deamon.pid');
        $cmd .= ' --socketport ' . escapeshellarg(config::byKey('socketport', __CLASS__, 55060));
        $cmd .= ' --loglevel '   . escapeshellarg(log::convertLogLevel(log::getLogLevel(__CLASS__)));

        log::add(__CLASS__, 'info', __('Lancement du démon :', __FILE__) . ' ' . $cmd);
        exec($cmd . ' >> ' . log::getPathToLog(__CLASS__ . 'd') . ' 2>&1 &');

        for ($i = 0; $i < 30; $i++) {
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] == 'ok') {
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
        // Filet de sécurité si le fichier PID a disparu alors que le process tourne encore.
        system::kill('resources/dahuad/dahuad.php');
        system::fuserk(config::byKey('socketport', __CLASS__, 55060));
    }

    /* URL que le démon appelle pour lire sa configuration et pousser les événements. */
    public static function getCallbackUrl() {
        return network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')
             . '/plugins/dahua/core/php/jeeDahua.php';
    }

    /* ================================================== CONFIGURATION DU DÉMON */

    /*
     * Décrit à le démon l'ensemble des NVR à écouter et la correspondance canal → équipement.
     * Appelé par jeeDahua.php sur action=config.
     */
    public static function getDaemonConfig() {
        $config = array(
            'pulse_duration'    => (int) config::byKey('pulse_duration', __CLASS__, 5),
            'reconnect_delay'   => (int) config::byKey('reconnect_delay', __CLASS__, 15),
            'snapshot_on_event' => (int) config::byKey('snapshot_on_event', __CLASS__, 1),
            'nvrs'              => array(),
        );

        foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_NVR)) as $nvr) {
            if ($nvr->getIsEnable() != 1) {
                continue;
            }
            $channels = array();
            foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_CAMERA)) as $cam) {
                if ($cam->getIsEnable() != 1 || $cam->getConfiguration('nvr_id') != $nvr->getId()) {
                    continue;
                }
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
                'username'   => $nvr->getConfiguration('username'),
                'password'   => $nvr->getConfiguration('password'),
                'channels'   => $channels,
            );
        }
        return $config;
    }

    /* Demande au démon de relire sa configuration, sans le redémarrer. */
    public static function reloadDaemonConfig() {
        return self::sendToDaemon(array('cmd' => 'reload'));
    }

    /* ====================================================== JEEDOM → DÉMON */

    public static function sendToDaemon($payload) {
        if (self::deamon_info()['state'] != 'ok') {
            log::add(__CLASS__, 'debug', __('Démon arrêté, ordre ignoré', __FILE__));
            return false;
        }
        $payload['apikey'] = jeedom::getApiKey(__CLASS__);
        $socket = @stream_socket_client(
            'tcp://127.0.0.1:' . config::byKey('socketport', __CLASS__, 55060),
            $errno, $errstr, 5
        );
        if ($socket === false) {
            log::add(__CLASS__, 'error', __('Connexion au démon impossible :', __FILE__) . ' ' . $errstr);
            return false;
        }
        fwrite($socket, json_encode($payload) . "\n");
        stream_set_timeout($socket, 15);
        $response = trim((string) fgets($socket));
        fclose($socket);
        return $response === '' ? true : json_decode($response, true);
    }

    /* ============================================== AUTO-DÉCOUVERTE DES CAMÉRAS */

    /*
     * Interroge le NVR en HTTP CGI (auth Digest) et crée un équipement par canal nommé.
     * Retourne le nombre de caméras créées.
     */
    public static function discoverCameras($_nvrId) {
        $nvr = self::byId($_nvrId);
        if (!is_object($nvr) || $nvr->getConfiguration('type') != self::TYPE_NVR) {
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
            $channel = $index + 1;                       // le NVR numérote D1..Dn dans son interface
            $logicalId = 'cam::' . $nvr->getId() . '::' . $channel;
            if (is_object(self::byLogicalId($logicalId, __CLASS__))) {
                continue;                                // déjà présent, on ne réécrase pas
            }
            $cam = new self();
            $cam->setEqType_name(__CLASS__);
            $cam->setLogicalId($logicalId);
            $cam->setName($label != '' ? $label : ($nvr->getName() . ' - canal ' . $channel));
            $cam->setObject_id($nvr->getObject_id());
            $cam->setConfiguration('type', self::TYPE_CAMERA);
            $cam->setConfiguration('nvr_id', $nvr->getId());
            $cam->setConfiguration('channel', $channel);
            $cam->setIsEnable(1);
            $cam->setIsVisible(1);
            $cam->save();
            $created++;
        }

        if ($created > 0) {
            self::reloadDaemonConfig();
        }
        return $created;
    }

    /* Requête HTTP CGI authentifiée en Digest sur le NVR. */
    public static function cgiRequest($_nvr, $_path, $_binary = false, $_timeout = 10) {
        $url = 'http://' . $_nvr->getConfiguration('ip') . ':' . $_nvr->getConfiguration('port', 80)
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
            if ($this->getConfiguration('ip') == '') {
                throw new Exception(__('L\'adresse IP du NVR est obligatoire', __FILE__));
            }
        } else {
            if ($this->getConfiguration('nvr_id') == '') {
                throw new Exception(__('La caméra doit être rattachée à un NVR', __FILE__));
            }
            if ((int) $this->getConfiguration('channel') < 1) {
                throw new Exception(__('Le numéro de canal doit être supérieur ou égal à 1', __FILE__));
            }
        }
    }

    public function postSave() {
        if ($this->getConfiguration('type') == self::TYPE_NVR) {
            if ($this->getLogicalId() == '') {
                $this->setLogicalId('nvr::' . $this->getId());
                $this->save(true);
            }
            $this->createNvrCommands();
        } else {
            $expected = 'cam::' . $this->getConfiguration('nvr_id') . '::' . $this->getConfiguration('channel');
            if ($this->getLogicalId() != $expected) {
                $this->setLogicalId($expected);
                $this->save(true);
            }
            $this->createCameraCommands();
        }
        // Le démon doit connaître immédiatement le nouvel équipement.
        self::reloadDaemonConfig();
    }

    public function postRemove() {
        self::reloadDaemonConfig();
    }

    /* Crée les commandes manquantes sans jamais écraser la personnalisation de l'utilisateur. */
    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd(null, $_logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }
        $cmd = new dahuaCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        $cmd->setName(__($_name, __FILE__));
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 1);
        $cmd->setIsHistorized(isset($_options['isHistorized']) ? $_options['isHistorized'] : 0);
        if (isset($_options['generic_type'])) {
            $cmd->setGeneric_type($_options['generic_type']);
        }
        if (isset($_options['display'])) {
            foreach ($_options['display'] as $k => $v) {
                $cmd->setDisplay($k, $v);
            }
        }
        if (isset($_options['template'])) {
            $cmd->setTemplate('dashboard', $_options['template']);
            $cmd->setTemplate('mobile', $_options['template']);
        }
        $cmd->save();
        return $cmd;
    }

    private function createCameraCommands() {
        foreach (self::$_channelEvents as $code => $def) {
            $this->addCmdIfMissing($def['logicalId'], $def['name'], 'info', 'binary', array(
                'isHistorized' => 1,
            ));
        }
        $this->addCmdIfMissing('lastevent', 'Dernier événement', 'info', 'string');
        $this->addCmdIfMissing('lastevent_date', 'Date du dernier événement', 'info', 'string', array('isVisible' => 0));
        $this->addCmdIfMissing('snapshot', 'Dernière image', 'info', 'string', array(
            'isVisible'    => 0,
            'generic_type' => 'CAMERA_URL',
        ));
        $this->addCmdIfMissing('take_snapshot', 'Capturer une image', 'action', 'other', array(
            'generic_type' => 'CAMERA_TAKE',
        ));
        $this->addCmdIfMissing('ptz_preset', 'Aller au preset', 'action', 'other', array(
            'isVisible'    => 0,
            'generic_type' => 'CAMERA_PRESET',
        ));
    }

    private function createNvrCommands() {
        $this->addCmdIfMissing('online', 'Connecté', 'info', 'binary', array(
            'isHistorized' => 1,
        ));
        foreach (self::$_nvrEvents as $code => $def) {
            $this->addCmdIfMissing($def['logicalId'], $def['name'], 'info', 'binary', array('isVisible' => 0));
        }
        $this->addCmdIfMissing('lastevent', 'Dernier événement', 'info', 'string');
        $this->addCmdIfMissing('reconnect', 'Reconnecter', 'action', 'other');
    }

    /* Retourne le NVR parent d'une caméra. */
    public function getNvr() {
        if ($this->getConfiguration('type') == self::TYPE_NVR) {
            return $this;
        }
        return self::byId($this->getConfiguration('nvr_id'));
    }

    /* ================================================================== PTZ */

    /*
     * Rappelle un preset PTZ sur le canal de la caméra.
     * L'API CGI attend un mouvement "GotoPreset" avec le numéro de preset en arg2.
     */
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

    /* ============================================================== SNAPSHOT */

    /*
     * Capture une image du canal et la stocke dans data/snapshots.
     * Retourne l'URL relative, ou false.
     */
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
        if ($image === false || strlen($image) < 1024) {
            return false;
        }

        $dir = __DIR__ . '/../../data/snapshots';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Le jeton aléatoire évite qu'un tiers puisse deviner l'URL d'une capture.
        $file = 'cam' . $this->getId() . '_' . date('Ymd-His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($dir . '/' . $file, $image);
        $this->purgeSnapshots($dir);

        $url = 'plugins/dahua/data/snapshots/' . $file;
        $this->checkAndUpdateCmd('snapshot', $url);
        return $url;
    }

    /* Ne conserve que les N dernières captures pour éviter de remplir le disque. */
    private function purgeSnapshots($_dir) {
        $keep = (int) config::byKey('snapshot_keep', __CLASS__, 50);
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
                $preset = isset($_options['message']) && $_options['message'] !== ''
                        ? (int) $_options['message']
                        : (int) $this->getConfiguration('preset', 1);
                return $eqLogic->ptzGotoPreset($preset);

            case 'reconnect':
                return dahua::sendToDaemon(array(
                    'cmd'    => 'reconnect',
                    'nvr_id' => (int) $eqLogic->getId(),
                ));
        }
        return true;
    }
}
