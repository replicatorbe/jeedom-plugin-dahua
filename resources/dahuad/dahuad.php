#!/usr/bin/env php
<?php
/* Démon Dahua pour Jeedom.
 *
 * Processus autonome : il ne charge PAS le coeur de Jeedom. Toute sa configuration
 * est récupérée au démarrage sur le callback HTTP du plugin, et les événements y sont
 * repoussés de la même façon. Une mise à jour de Jeedom ne peut donc ni le casser,
 * ni nécessiter son redémarrage.
 *
 * This file is part of Jeedom. Licensed under GNU GPL v3 or later.
 */

declare(ticks = 1);

/* ---------------------------------------------------------------- garde CLI ---
 * Sur une installation où Apache est en AllowOverride None, les .htaccess sont
 * ignorés et ce fichier est accessible en HTTP : cette garde est la seule
 * protection réelle contre un déclenchement depuis l'extérieur.
 */
if (php_sapi_name() != 'cli' || isset($_SERVER['REQUEST_METHOD']) || !isset($_SERVER['argc'])) {
    header('HTTP/1.0 404 Not Found');
    echo '<h1>404 Not Found</h1>';
    exit(1);
}

/* ------------------------------------------------------------------- options */
$opt = getopt('', array('callback:', 'apikey:', 'pid:', 'socketport:', 'loglevel:'));
foreach (array('callback', 'apikey', 'pid', 'socketport') as $required) {
    if (!isset($opt[$required])) {
        fwrite(STDERR, "Argument manquant : --$required\n");
        exit(1);
    }
}

/* ------------------------------------------------------------------- logging */
class DahuaLog {
    const LEVELS = array('debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3);
    private static $min = 3;

    public static function setLevel($_level) {
        self::$min = isset(self::LEVELS[$_level]) ? self::LEVELS[$_level] : 3;
    }
    public static function write($_level, $_msg) {
        if ((isset(self::LEVELS[$_level]) ? self::LEVELS[$_level] : 3) < self::$min) {
            return;
        }
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '][' . strtoupper($_level) . '] ' . $_msg . PHP_EOL);
    }
    public static function debug($m)   { self::write('debug', $m); }
    public static function info($m)    { self::write('info', $m); }
    public static function warning($m) { self::write('warning', $m); }
    public static function error($m)   { self::write('error', $m); }
}
DahuaLog::setLevel(isset($opt['loglevel']) ? $opt['loglevel'] : 'error');

/* =============================================================================
 * Client DHIP : une instance par NVR.
 * ========================================================================== */
class DahuaDhipClient {

    const MAGIC = "\x20\x00\x00\x00DHIP";

    /*
     * Événements émis à la cadence vidéo ou purement internes au NVR.
     * Sans ce filtre, IntelliFrame et VideoMotionInfo saturent l'API Jeedom
     * à plusieurs messages par seconde et par canal.
     */
    const BLACKLIST = array(
        'IntelliFrame', 'VideoMotionInfo', 'MDResult', 'SystemState',
        'LeFunctionStatusSync', 'InterVideoAccess', 'NewFile', 'UpdateFile',
        'RtspSessionDisconnect', 'CallSnap', 'AccessSnap', 'TimeChange',
        'NTPAdjustTime', 'DGSErrorReport',
    );

    public $config;                 // bloc de configuration du NVR
    public $socket = null;
    public $state = 'disconnected'; // disconnected | connected

    private $buffer = '';
    private $requestId = 0;
    private $sessionId = 0;
    private $keepAliveInterval = 60;
    private $lastKeepAliveSent = 0;
    private $awaitingKeepAlive = false;
    private $nextRetry = 0;

    public function __construct($_config) {
        $this->config = $_config;
    }

    public function name() {
        return $this->config['name'] . ' (' . $this->config['ip'] . ')';
    }

    public function id() {
        return (int) $this->config['eqLogic_id'];
    }

    /* Une reconnexion n'est retentée qu'après expiration du délai de backoff. */
    public function readyToRetry() {
        return time() >= $this->nextRetry;
    }

    public function scheduleRetry($_delay) {
        $this->nextRetry = time() + $_delay;
    }

    /* ----------------------------------------------------------- bas niveau */

    private function buildHeader($_length) {
        return self::MAGIC
             . pack('V', $this->sessionId)
             . pack('V', $this->requestId)
             . pack('V', $_length)
             . pack('V', 0)
             . pack('V', $_length)
             . pack('V', 0);
    }

    private function send($_payload) {
        if (!is_resource($this->socket)) {
            return false;
        }
        $body = json_encode($_payload);
        $this->requestId++;
        $written = @fwrite($this->socket, $this->buildHeader(strlen($body)) . $body);
        return $written !== false;
    }

    /*
     * Extrait du buffer tous les paquets DHIP complets.
     * Indispensable : le NVR fragmente ses réponses, et un paquet peut arriver
     * en plusieurs lectures ou plusieurs paquets dans une seule lecture.
     */
    private function drainPackets() {
        $packets = array();
        while (strlen($this->buffer) >= 32) {
            if (substr($this->buffer, 0, 8) !== self::MAGIC) {
                // Flux désynchronisé : on cherche le prochain en-tête valide.
                $next = strpos($this->buffer, self::MAGIC, 1);
                if ($next === false) {
                    $this->buffer = '';
                    DahuaLog::warning($this->name() . ' flux désynchronisé, buffer vidé');
                    break;
                }
                DahuaLog::debug($this->name() . ' resynchronisation du flux (' . $next . ' octets ignorés)');
                $this->buffer = substr($this->buffer, $next);
                continue;
            }
            $length = unpack('V', substr($this->buffer, 16, 4))[1];
            if (strlen($this->buffer) < 32 + $length) {
                break;                                  // paquet incomplet, on attend la suite
            }
            $packets[] = substr($this->buffer, 32, $length);
            $this->buffer = substr($this->buffer, 32 + $length);
        }
        return $packets;
    }

    /* Lecture bloquante courte, utilisée uniquement pendant la phase de connexion. */
    private function readPackets($_timeout = 6) {
        $read = array($this->socket); $write = null; $except = null;
        if (@stream_select($read, $write, $except, $_timeout) < 1) {
            return array();
        }
        $data = @fread($this->socket, 65535);
        if ($data === '' || $data === false) {
            return false;                               // connexion fermée
        }
        $this->buffer .= $data;
        return $this->drainPackets();
    }

    /* ------------------------------------------------------------ connexion */

    public function connect() {
        $this->disconnect();
        $this->buffer = '';
        $this->requestId = 0;
        $this->sessionId = 0;

        $errno = 0; $errstr = '';
        $this->socket = @stream_socket_client(
            'tcp://' . $this->config['ip'] . ':' . $this->config['port'],
            $errno, $errstr, 8
        );
        if ($this->socket === false) {
            $this->socket = null;
            return array(false, 'connexion TCP impossible : ' . $errstr);
        }
        stream_set_timeout($this->socket, 10);

        list($ok, $error) = $this->login();
        if (!$ok) {
            $this->disconnect();
            return array(false, $error);
        }
        if (!$this->attachEvents()) {
            $this->disconnect();
            return array(false, 'souscription aux événements refusée');
        }

        stream_set_blocking($this->socket, false);
        $this->state = 'connected';
        $this->lastKeepAliveSent = time();
        $this->awaitingKeepAlive = false;
        return array(true, '');
    }

    /*
     * Authentification en deux temps : la première requête est volontairement
     * rejetée par le NVR, qui renvoie le sel (random) et le realm du challenge.
     */
    private function login() {
        $this->send(array(
            'id'      => 10000,
            'magic'   => '0x1234',
            'method'  => 'global.login',
            'session' => 0,
            'params'  => array(
                'clientType' => '',
                'ipAddr'     => '(null)',
                'loginType'  => 'Direct',
                'password'   => '',
                'userName'   => $this->config['username'],
            ),
        ));
        $packets = $this->readPackets();
        if ($packets === false || empty($packets)) {
            return array(false, 'aucune réponse au challenge de connexion');
        }
        $challenge = json_decode($packets[0], true);
        if (!isset($challenge['params']['random'], $challenge['params']['realm'])) {
            return array(false, 'challenge de connexion illisible');
        }

        $this->sessionId = isset($challenge['session']) ? $challenge['session'] : 0;
        $hash = strtoupper(md5(
            $this->config['username'] . ':' . $challenge['params']['random'] . ':' .
            strtoupper(md5($this->config['username'] . ':' . $challenge['params']['realm'] . ':' . $this->config['password']))
        ));

        $this->send(array(
            'id'      => 10000,
            'magic'   => '0x1234',
            'method'  => 'global.login',
            'session' => $this->sessionId,
            'params'  => array(
                'userName'      => $this->config['username'],
                'password'      => $hash,
                'clientType'    => '',
                'ipAddr'        => '(null)',
                'loginType'     => 'Direct',
                'authorityType' => 'Default',
            ),
        ));
        $packets = $this->readPackets();
        if ($packets === false || empty($packets)) {
            return array(false, 'aucune réponse à l\'authentification');
        }
        $result = json_decode($packets[0], true);

        if (empty($result['result'])) {
            return array(false, self::describeError($result));
        }
        if (isset($result['params']['keepAliveInterval'])) {
            $this->keepAliveInterval = max(10, (int) $result['params']['keepAliveInterval']);
        }
        return array(true, '');
    }

    /* Traduit les codes d'erreur Dahua en message exploitable. */
    private static function describeError($_result) {
        $code = isset($_result['error']['code']) ? $_result['error']['code'] : 0;
        // 268632079 est le challenge d'authentification : il n'apparaît jamais ici.
        $known = array(
            268632080 => 'utilisateur inconnu ou mot de passe incorrect',
            268632081 => 'compte verrouillé après trop de tentatives',
            268632082 => 'compte bloqué ou désactivé',
            268632083 => 'compte déjà connecté depuis un autre poste',
            268632086 => 'équipement non initialisé',
            268894210 => 'permissions insuffisantes pour cet utilisateur',
            287637505 => 'session invalide ou expirée',
        );
        if (isset($known[$code])) {
            return $known[$code] . ' (code ' . $code . ')';
        }
        return isset($_result['error']['message'])
            ? $_result['error']['message'] . ' (code ' . $code . ')'
            : 'authentification refusée';
    }

    private function attachEvents() {
        $this->send(array(
            'id'      => $this->requestId,
            'magic'   => '0x1234',
            'method'  => 'eventManager.attach',
            'session' => $this->sessionId,
            'params'  => array('codes' => array('All')),
        ));
        $packets = $this->readPackets();
        if ($packets === false || empty($packets)) {
            return false;
        }
        $result = json_decode($packets[0], true);
        return !empty($result['result']);
    }

    public function disconnect() {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->state = 'disconnected';
    }

    /* -------------------------------------------------------- fonctionnement */

    /*
     * Lit ce qui est disponible sur le socket et retourne la liste des événements.
     * Retourne false si la connexion est perdue.
     */
    public function readEvents() {
        $data = @fread($this->socket, 65535);
        if ($data === '' || $data === false) {
            return false;
        }
        $this->buffer .= $data;

        $events = array();
        foreach ($this->drainPackets() as $packet) {
            $message = json_decode($packet, true);
            if (!is_array($message)) {
                continue;
            }
            if (isset($message['result'])) {
                $this->awaitingKeepAlive = false;       // réponse au keepAlive
                continue;
            }
            if (!isset($message['method']) || $message['method'] != 'client.notifyEventStream') {
                continue;
            }
            foreach ($message['params']['eventList'] as $raw) {
                if (in_array(isset($raw['Code']) ? $raw['Code'] : '', self::BLACKLIST, true)) {
                    continue;
                }
                $events[] = $this->normalizeEvent($raw);
            }
        }
        return $events;
    }

    /* Met l'événement brut du NVR au format attendu par Jeedom. */
    private function normalizeEvent($_raw) {
        $code = isset($_raw['Code']) ? $_raw['Code'] : '';
        // Le NVR indexe ses canaux à partir de 0, son interface les nomme D1..Dn.
        // Index vaut -1 sur les événements d'appareil (VTO), et pour AlarmLocal
        // il désigne une entrée d'alarme physique, pas un canal vidéo.
        $channel = isset($_raw['Index']) ? ((int) $_raw['Index']) + 1 : 0;
        if ($code == 'AlarmLocal' || $channel < 1) {
            $channel = 0;
        }
        return array(
            'nvr_id'  => $this->id(),
            'channel' => $channel,
            'code'    => $code,
            'action'  => isset($_raw['Action']) ? $_raw['Action'] : 'Pulse',
            'data'    => isset($_raw['Data']) ? $_raw['Data'] : array(),
            'time'    => isset($_raw['Data']['LocaleTime']) ? $_raw['Data']['LocaleTime'] : date('Y-m-d H:i:s'),
        );
    }

    /* Le NVR ferme la session si aucun keepAlive n'est reçu dans l'intervalle annoncé. */
    public function keepAliveTick() {
        if (time() - $this->lastKeepAliveSent < $this->keepAliveInterval - 5) {
            return true;
        }
        if ($this->awaitingKeepAlive) {
            DahuaLog::warning($this->name() . ' keepAlive sans réponse, reconnexion');
            return false;
        }
        $this->send(array(
            'id'      => $this->requestId,
            'magic'   => '0x1234',
            'method'  => 'global.keepAlive',
            'session' => $this->sessionId,
            'params'  => array('timeout' => $this->keepAliveInterval, 'active' => true),
        ));
        $this->lastKeepAliveSent = time();
        $this->awaitingKeepAlive = true;
        return true;
    }
}

/* =============================================================================
 * Démon.
 * ========================================================================== */
class DahuaDaemon {

    private $opt;
    private $config = array();
    private $clients = array();          // eqLogic_id du NVR => DahuaDhipClient
    private $listener = null;
    private $pulseResets = array();      // clé => timestamp de remise à zéro
    private $lastSnapshot = array();     // eqLogic_id caméra => timestamp
    private $running = true;

    public function __construct($_opt) {
        $this->opt = $_opt;
    }

    public function stop() {
        $this->running = false;
    }

    /* ------------------------------------------------------------- callback */

    private function callback($_query = '', $_body = null) {
        $url = $this->opt['callback'] . '?apikey=' . urlencode($this->opt['apikey']) . $_query;
        $attempts = $_body === null ? 1 : 3;

        for ($i = 0; $i < $attempts; $i++) {
            $ch = curl_init($url);
            $options = array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            );
            if ($_body !== null) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($_body);
                $options[CURLOPT_HTTPHEADER] = array('Content-Type: application/json');
            }
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($code == 200) {
                return $response;
            }
            DahuaLog::error('callback en échec (HTTP ' . $code . ($error != '' ? ' / ' . $error : '')
                          . ') tentative ' . ($i + 1) . '/' . $attempts);
            usleep(500000);
        }
        return false;
    }

    private function loadConfig() {
        $raw = $this->callback('&action=config');
        if ($raw === false) {
            DahuaLog::error('configuration illisible depuis Jeedom');
            return false;
        }
        $config = json_decode($raw, true);
        if (!is_array($config) || !isset($config['nvrs'])) {
            DahuaLog::error('configuration invalide : ' . substr((string) $raw, 0, 200));
            return false;
        }
        $this->config = $config;

        // Ferme les connexions des NVR supprimés ou désactivés.
        $keep = array();
        foreach ($config['nvrs'] as $nvr) {
            $keep[(int) $nvr['eqLogic_id']] = $nvr;
        }
        foreach ($this->clients as $id => $client) {
            if (!isset($keep[$id])) {
                DahuaLog::info($client->name() . ' retiré de la configuration');
                $client->disconnect();
                unset($this->clients[$id]);
            }
        }
        foreach ($keep as $id => $nvrConfig) {
            if (isset($this->clients[$id])) {
                $previous = $this->clients[$id]->config;
                $this->clients[$id]->config = $nvrConfig;
                // Une modification de connexion impose de rouvrir le socket.
                if ($previous['ip'] != $nvrConfig['ip'] || $previous['port'] != $nvrConfig['port']
                 || $previous['username'] != $nvrConfig['username'] || $previous['password'] != $nvrConfig['password']) {
                    DahuaLog::info($this->clients[$id]->name() . ' paramètres modifiés, reconnexion');
                    $this->clients[$id]->disconnect();
                }
            } else {
                $this->clients[$id] = new DahuaDhipClient($nvrConfig);
            }
        }
        DahuaLog::info(count($this->clients) . ' NVR configuré(s)');
        return true;
    }

    /* --------------------------------------------------------------- démarrage */

    public function run() {
        if (trim((string) $this->callback('&test=1')) !== 'OK') {
            DahuaLog::error('callback injoignable : ' . $this->opt['callback']);
            return 1;
        }
        if (!$this->loadConfig()) {
            return 1;
        }

        $port = (int) $this->opt['socketport'];
        $errno = 0; $errstr = '';
        $this->listener = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
        if ($this->listener === false) {
            DahuaLog::error('écoute impossible sur le port ' . $port . ' : ' . $errstr);
            return 1;
        }
        stream_set_blocking($this->listener, false);

        file_put_contents($this->opt['pid'], getmypid() . "\n");
        DahuaLog::info('démon démarré (pid ' . getmypid() . ', port ' . $port . ')');

        $this->loop();

        DahuaLog::info('arrêt du démon');
        foreach ($this->clients as $client) {
            $client->disconnect();
        }
        if (is_resource($this->listener)) {
            fclose($this->listener);
        }
        @unlink($this->opt['pid']);
        return 0;
    }

    private function loop() {
        while ($this->running) {
            pcntl_signal_dispatch();
            $this->reapChildren();
            $this->connectPending();

            // Sockets à surveiller : le serveur d'ordres + chaque NVR connecté.
            $read = array($this->listener);
            foreach ($this->clients as $client) {
                if ($client->state == 'connected' && is_resource($client->socket)) {
                    $read[] = $client->socket;
                }
            }
            $write = null; $except = null;
            $ready = @stream_select($read, $write, $except, 1);

            if ($ready > 0) {
                foreach ($read as $stream) {
                    if ($stream === $this->listener) {
                        $this->handleOrder();
                    } else {
                        $this->handleNvrStream($stream);
                    }
                }
            }

            $this->flushPulseResets();
            $this->keepAlives();
        }
    }

    private function connectPending() {
        foreach ($this->clients as $client) {
            if ($client->state == 'connected' || !$client->readyToRetry()) {
                continue;
            }
            DahuaLog::info($client->name() . ' connexion…');
            list($ok, $error) = $client->connect();
            if ($ok) {
                DahuaLog::info($client->name() . ' connecté');
                $this->push(array(array(
                    'nvr_id' => $client->id(),
                    'type'   => 'status',
                    'status' => 'connected',
                    'time'   => date('Y-m-d H:i:s'),
                )));
            } else {
                $delay = max(5, (int) $this->config['reconnect_delay']);
                DahuaLog::error($client->name() . ' ' . $error . ' — nouvel essai dans ' . $delay . 's');
                $client->scheduleRetry($delay);
                $this->push(array(array(
                    'nvr_id' => $client->id(),
                    'type'   => 'status',
                    'status' => 'disconnected',
                    'error'  => $error,
                    'time'   => date('Y-m-d H:i:s'),
                )));
            }
        }
    }

    private function handleNvrStream($_stream) {
        foreach ($this->clients as $client) {
            if ($client->socket !== $_stream) {
                continue;
            }
            $events = $client->readEvents();
            if ($events === false) {
                DahuaLog::warning($client->name() . ' connexion perdue');
                $client->disconnect();
                $client->scheduleRetry(max(5, (int) $this->config['reconnect_delay']));
                $this->push(array(array(
                    'nvr_id' => $client->id(),
                    'type'   => 'status',
                    'status' => 'disconnected',
                    'error'  => 'socket fermé par le NVR',
                    'time'   => date('Y-m-d H:i:s'),
                )));
                return;
            }
            if (!empty($events)) {
                $this->dispatchEvents($client, $events);
            }
            return;
        }
    }

    private function dispatchEvents($_client, $_events) {
        $batch = array();
        foreach ($_events as $event) {
            DahuaLog::debug($_client->name() . ' ' . $event['code'] . ' ' . $event['action']
                          . ' canal ' . $event['channel']);
            $batch[] = $event;

            // Les événements sans fin explicite sont remis à zéro par le démon.
            if ($event['action'] == 'Pulse') {
                $key = $event['nvr_id'] . '|' . $event['channel'] . '|' . $event['code'];
                $this->pulseResets[$key] = time() + max(1, (int) $this->config['pulse_duration']);
            }

            if ($event['action'] != 'Stop') {
                $this->maybeSnapshot($_client, $event);
            }
        }
        if (!empty($batch)) {
            $this->push($batch);
        }
    }

    /* Envoie un lot d'événements à Jeedom. */
    private function push($_events) {
        $this->callback('', array('events' => $_events));
    }

    private function flushPulseResets() {
        if (empty($this->pulseResets)) {
            return;
        }
        $now = time();
        $batch = array();
        foreach ($this->pulseResets as $key => $deadline) {
            if ($deadline > $now) {
                continue;
            }
            list($nvrId, $channel, $code) = explode('|', $key);
            $batch[] = array(
                'nvr_id'  => (int) $nvrId,
                'channel' => (int) $channel,
                'code'    => $code,
                'action'  => 'Stop',
                'data'    => array(),
                'time'    => date('Y-m-d H:i:s'),
            );
            unset($this->pulseResets[$key]);
        }
        if (!empty($batch)) {
            $this->push($batch);
        }
    }

    private function keepAlives() {
        foreach ($this->clients as $client) {
            if ($client->state != 'connected') {
                continue;
            }
            if (!$client->keepAliveTick()) {
                $client->disconnect();
                $client->scheduleRetry(2);
            }
        }
    }

    /* ----------------------------------------------------------- snapshots */

    /*
     * La capture est confiée à un processus fils : une requête HTTP vers le NVR
     * prend plusieurs centaines de millisecondes et gèlerait la boucle principale.
     */
    private function maybeSnapshot($_client, $_event) {
        if (empty($this->config['snapshot_on_event']) || $_event['channel'] < 1) {
            return;
        }
        if (!isset($_client->config['channels'][$_event['channel']])) {
            return;
        }
        $camera = $_client->config['channels'][$_event['channel']];
        $cameraId = (int) $camera['eqLogic_id'];

        // Une capture au plus toutes les 10 s par caméra, sinon une détection
        // continue saturerait le NVR de requêtes.
        if (isset($this->lastSnapshot[$cameraId]) && time() - $this->lastSnapshot[$cameraId] < 10) {
            return;
        }
        $this->lastSnapshot[$cameraId] = time();

        $pid = pcntl_fork();
        if ($pid == -1) {
            DahuaLog::error('fork impossible pour la capture');
            return;
        }
        if ($pid > 0) {
            return;                                     // parent : on continue la boucle
        }

        // --- processus fils ---
        $url = $this->fetchSnapshot($_client->config, $_event['channel'], $cameraId);
        if ($url !== false) {
            $this->callback('', array('events' => array(array(
                'nvr_id'  => $_client->id(),
                'type'    => 'snapshot',
                'channel' => $_event['channel'],
                'url'     => $url,
                'time'    => date('Y-m-d H:i:s'),
            ))));
        }
        exit(0);
    }

    private function fetchSnapshot($_nvrConfig, $_channel, $_cameraId) {
        $url = 'http://' . $_nvrConfig['ip'] . ':' . $_nvrConfig['port']
             . '/cgi-bin/snapshot.cgi?channel=' . $_channel;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST,
            CURLOPT_USERPWD        => $_nvrConfig['username'] . ':' . $_nvrConfig['password'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
        ));
        $image = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($image === false || $code != 200 || strlen($image) < 1024) {
            DahuaLog::warning('capture refusée par le NVR (canal ' . $_channel . ', HTTP ' . $code . ')');
            return false;
        }

        $dir = realpath(__DIR__ . '/../..') . '/data/snapshots';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            DahuaLog::error('création de ' . $dir . ' impossible');
            return false;
        }
        // Le jeton aléatoire empêche de deviner l'URL d'une capture.
        $file = 'cam' . $_cameraId . '_' . date('Ymd-His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (@file_put_contents($dir . '/' . $file, $image) === false) {
            DahuaLog::error('écriture de la capture impossible dans ' . $dir);
            return false;
        }
        $this->purgeSnapshots($dir, $_cameraId);
        return 'plugins/dahua/data/snapshots/' . $file;
    }

    private function purgeSnapshots($_dir, $_cameraId) {
        $keep = 50;
        $files = glob($_dir . '/cam' . $_cameraId . '_*.jpg');
        if ($files === false || count($files) <= $keep) {
            return;
        }
        sort($files);
        foreach (array_slice($files, 0, count($files) - $keep) as $old) {
            @unlink($old);
        }
    }

    /* Récupère les fils terminés pour ne pas laisser de zombies. */
    private function reapChildren() {
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            // rien à faire, on vide simplement la table des processus
        }
    }

    /* ------------------------------------------------------ ordres Jeedom */

    private function handleOrder() {
        $conn = @stream_socket_accept($this->listener, 0);
        if ($conn === false) {
            return;
        }
        stream_set_timeout($conn, 5);
        $line = fgets($conn, 65535);
        $order = json_decode(trim((string) $line), true);

        if (!is_array($order) || !isset($order['apikey']) || $order['apikey'] !== $this->opt['apikey']) {
            DahuaLog::warning('ordre local rejeté : clé API invalide');
            fwrite($conn, json_encode(array('state' => 'error', 'result' => 'invalid apikey')) . "\n");
            fclose($conn);
            return;
        }

        $result = array('state' => 'ok');
        switch (isset($order['cmd']) ? $order['cmd'] : '') {
            case 'reload':
                DahuaLog::info('rechargement de la configuration');
                $result['state'] = $this->loadConfig() ? 'ok' : 'error';
                break;

            case 'reconnect':
                $id = isset($order['nvr_id']) ? (int) $order['nvr_id'] : 0;
                if (isset($this->clients[$id])) {
                    DahuaLog::info($this->clients[$id]->name() . ' reconnexion demandée');
                    $this->clients[$id]->disconnect();
                    $this->clients[$id]->scheduleRetry(0);
                } else {
                    $result['state'] = 'error';
                    $result['result'] = 'NVR inconnu';
                }
                break;

            case 'status':
                $status = array();
                foreach ($this->clients as $id => $client) {
                    $status[$id] = $client->state;
                }
                $result['result'] = $status;
                break;

            default:
                $result['state'] = 'error';
                $result['result'] = 'commande inconnue';
        }

        fwrite($conn, json_encode($result) . "\n");
        fclose($conn);
    }
}

/* ------------------------------------------------------------------ signaux */
$daemon = new DahuaDaemon($opt);
$shutdown = function ($signo) use ($daemon) {
    DahuaLog::info('signal ' . $signo . ' reçu, arrêt en cours');
    $daemon->stop();
};
pcntl_signal(SIGTERM, $shutdown);
pcntl_signal(SIGINT,  $shutdown);
pcntl_signal(SIGHUP,  $shutdown);

exit($daemon->run());
