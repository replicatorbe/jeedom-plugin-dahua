<?php
/* Jeu d'essai hors ligne des actions différées d'une règle (option « Attendre
 * la capture fraîche avant d'agir »).
 *
 *   php tests/test_deferred_actions.php
 *
 * Aucune dépendance : ni Jeedom, ni base, ni démon, ni NVR. Le coeur est
 * remplacé par des doublures minimales, et l'horloge du moteur par une horloge
 * virtuelle — « capture arrivée à 1 s, actions à 1 s » se vérifie alors à la
 * microseconde, sans attendre et sans dépendre de la charge de la machine.
 *
 * Les classes du plugin sont COPIÉES dans un dossier temporaire avant d'être
 * chargées : dahuaAlert range ses dossiers d'alerte à côté de son propre
 * fichier (data/alerts), et un essai ne doit rien écrire dans le dépôt. */

date_default_timezone_set('Europe/Brussels');

/* ============================================================== DOUBLURES */

function __($_text, $_file = '') {
    return $_text;
}

class log {
    public static $lines = array();
    public static function add($_plugin, $_level, $_message) {
        self::$lines[] = $_level . ' ' . $_message;
    }
    public static function has($_needle) {
        foreach (self::$lines as $line) {
            if (strpos($line, $_needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

class message {
    public static $added = array();
    public static function add($_plugin, $_message, $_action = '', $_key = '') {
        self::$added[] = $_message;
    }
    public static function removeAll($_plugin = '', $_key = '') {
    }
}

class cacheEntry {
    private $_value;
    public function __construct($_value) {
        $this->_value = $_value;
    }
    public function getValue($_default = '') {
        return ($this->_value === null) ? $_default : $this->_value;
    }
}

class cache {
    public static $data = array();
    public static function byKey($_key) {
        return new cacheEntry(isset(self::$data[$_key]) ? self::$data[$_key] : null);
    }
    public static function set($_key, $_value, $_lifetime = 0) {
        self::$data[$_key] = $_value;
    }
    public static function delete($_key) {
        unset(self::$data[$_key]);
    }
}

class config {
    public static $values = array('alert_shot' => 1);
    public static function byKey($_key, $_plugin = 'core', $_default = '') {
        return array_key_exists($_key, self::$values) ? self::$values[$_key] : $_default;
    }
}

/* Le processus de secours n'est pas lancé : on note seulement qu'il l'aurait
 * été, et l'essai appelle dahuaRule::watch() lui-même, à l'heure voulue. */
class system {
    public static $calls = array();
    public static function php($_arguments, $_sudo = false) {
        self::$calls[] = $_arguments;
        return '';
    }
}

class network {
    public static function getNetworkAccess($_mode = 'auto') {
        return 'http://jeedom.local';
    }
}

class FakeCmd {
    private $_eq;
    private $_logicalId;
    public function __construct($_eq, $_logicalId) {
        $this->_eq = $_eq;
        $this->_logicalId = $_logicalId;
    }
    public function execCmd() {
        return $this->_eq->value($this->_logicalId);
    }
}

class FakeEq {
    public $id;
    public $name;
    public $config;
    public $values = array();
    public $enabled = 1;
    public function __construct($_id, $_name, $_config) {
        $this->id = $_id;
        $this->name = $_name;
        $this->config = $_config;
    }
    public function getId() { return $this->id; }
    public function getName() { return $this->name; }
    public function getHumanName() { return '[Test][' . $this->name . ']'; }
    public function getObject() { return null; }
    public function getIsEnable() { return $this->enabled; }
    public function getEqType_name() { return 'dahua'; }
    public function getConfiguration($_key = '', $_default = '') {
        return array_key_exists($_key, $this->config) ? $this->config[$_key] : $_default;
    }
    public function getCmd($_type = null, $_logicalId = null) {
        return new FakeCmd($this, $_logicalId);
    }
    public function checkAndUpdateCmd($_logicalId, $_value, $_date = null) {
        $this->values[$_logicalId] = $_value;
        return true;
    }
    public function value($_logicalId) {
        return isset($this->values[$_logicalId]) ? $this->values[$_logicalId] : '';
    }
}

class dahua {
    const TYPE_NVR    = 'nvr';
    const TYPE_CAMERA = 'camera';
    const TYPE_RULE   = 'rule';
    public static $_channelEvents = array();
    public static $equipments = array();
    /* Chaque action jouée : quelle liste, à quelle heure virtuelle, et ce que
     * « Fichier de l'image » valait à cet instant — c'est ce que la
     * notification aurait joint. */
    public static $played = array();
    public static $snapshotDir = '';

    public static function byId($_id) {
        return isset(self::$equipments[(int) $_id]) ? self::$equipments[(int) $_id] : null;
    }
    public static function byTypeAndSearchConfiguration($_type, $_search, $_onlyEnable = false) {
        $found = array();
        foreach (self::$equipments as $eq) {
            if ($eq->getConfiguration('type') == $_search['type'] && (!$_onlyEnable || $eq->getIsEnable())) {
                $found[] = $eq;
            }
        }
        return $found;
    }
    public static function runActions($_eqLogic, $_key, $_tags = array(), $_guardSelf = true) {
        self::$played[] = array(
            'rule' => $_eqLogic->getId(),
            'key'  => $_key,
            'at'   => dahuaRule::now(),
            'file' => $_eqLogic->value('image_file'),
        );
    }
    public static function snapshotDir() {
        return self::$snapshotDir;
    }
    public static function sendToDaemon($_payload, $_waitAnswer = true, $_timeout = 5) {
        return true;
    }
}

/* ============================================================== CHARGEMENT */

$root = sys_get_temp_dir() . '/dahua-essai-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($root . '/core/class', 0775, true);
mkdir($root . '/data/snapshots', 0775, true);
foreach (array('dahuaAlert', 'dahuaRule') as $class) {
    copy(__DIR__ . '/../core/class/' . $class . '.class.php', $root . '/core/class/' . $class . '.class.php');
    require_once $root . '/core/class/' . $class . '.class.php';
}
dahua::$snapshotDir = $root . '/data/snapshots';
/* Le script du processus de secours doit exister : le moteur refuse de lancer
 * un chemin introuvable, et le dit. */
mkdir($root . '/core/php', 0775, true);
copy(__DIR__ . '/../core/php/jeeDahuaWait.php', $root . '/core/php/jeeDahuaWait.php');

function cleanup($_dir) {
    if (!is_dir($_dir)) {
        return;
    }
    foreach (scandir($_dir) as $entry) {
        if ($entry == '.' || $entry == '..') {
            continue;
        }
        $path = $_dir . '/' . $entry;
        is_dir($path) && !is_link($path) ? cleanup($path) : unlink($path);
    }
    rmdir($_dir);
}
register_shutdown_function('cleanup', $root);

/* ================================================================= OUTILS */

$ok = 0;
$ko = 0;
function verifie($_titre, $_obtenu, $_attendu) {
    global $ok, $ko;
    if ($_obtenu == $_attendu) {
        $ok++;
        printf("  %-62s ok\n", $_titre);
        return;
    }
    $ko++;
    printf("  %-62s ÉCHEC : obtenu %s, attendu %s\n", $_titre,
           var_export($_obtenu, true), var_export($_attendu, true));
}

/* L'horloge virtuelle du moteur, et un sommeil qui la fait avancer. Un rappel
 * facultatif permet de faire « arriver » une capture pendant le sommeil. */
$now = 0.0;
$onSleep = null;
dahuaRule::$_clock = function () use (&$now) {
    return $now;
};
dahuaRule::$_sleeper = function ($_seconds) use (&$now, &$onSleep) {
    $now += $_seconds;
    if ($onSleep !== null) {
        call_user_func($onSleep, $now);
    }
};

$camera = new FakeEq(10, 'NORD', array('type' => 'camera', 'nvr_id' => 1, 'channel' => 2));
dahua::$equipments[10] = $camera;

function rule($_id, $_config) {
    $rule = new FakeEq($_id, 'Règle ' . $_id, array_merge(array(
        'type'       => 'rule',
        'conditions' => array(array('source' => '10', 'event' => 'motion', 'min' => 1)),
        'cooldown'   => 30,
        'hold'       => 10,
    ), $_config));
    dahua::$equipments[$_id] = $rule;
    return $rule;
}

/* Le dossier de la dernière alerte d'une règle. */
function alertOf($_rule) {
    $alerts = dahuaAlert::recent(1, $_rule->getId());
    return empty($alerts) ? '' : $alerts[0]['id'];
}

/* Ce que fait le démon : écrire la capture dans le dossier, puis la poster —
 * jeeDahua.php l'inscrit (noteCapture) et lève l'attente (onLiveShot). */
function captureArrives($_alertId, $_ok = true, $_event = true) {
    if ($_ok) {
        file_put_contents(dahuaAlert::path($_alertId) . '/cam10_live.jpg', 'jpeg');
    }
    dahuaAlert::noteCapture($_alertId, 10, $_ok, $_ok ? '' : 'pas de réponse');
    if ($_event) {
        dahuaRule::onLiveShot($_alertId);
    }
}

function played($_ruleId, $_key = null) {
    $list = array();
    foreach (dahua::$played as $entry) {
        if ($entry['rule'] == $_ruleId && ($_key === null || $entry['key'] == $_key)) {
            $list[] = $entry;
        }
    }
    return $list;
}

/* Une nouvelle requête HTTP, ou un nouveau processus : le moteur relit règles
 * et états, qu'il ne garde en mémoire que le temps d'une requête. */
function clearStates() {
    foreach (array('_states' => array(), '_rules' => null) as $name => $empty) {
        $property = new ReflectionProperty('dahuaRule', $name);
        $property->setAccessible(true);
        $property->setValue(null, $empty);
    }
}

/* Durée de maintien écoulée, sans attendre : on recule « until » dans l'état. */
function holdElapsed($_rule) {
    $key = dahuaRule::CACHE_PREFIX . $_rule->getId();
    $state = json_decode(cache::$data[$key], true);
    $state['until'] = 1;
    cache::$data[$key] = json_encode($state);
    clearStates();
}

/* ================================================================= ESSAIS */

echo "\n== Capture arrivée à 1 s : actions à 1 s ==\n";
$now = 1000.0;
$rule = rule(1, array('wait_live' => 1, 'wait_live_max' => 10));
dahuaRule::test($rule);
$alert = alertOf($rule);
verifie('dossier d\'alerte ouvert', $alert != '', true);
verifie('« Déclenchée » passe à 1 immédiatement', $rule->value('triggered'), 1);
verifie('aucune action jouée au déclenchement', count(played(1)), 0);
verifie('processus de secours lancé', count(system::$calls), 1);
verifie('il reçoit l\'identifiant de l\'alerte', strpos(system::$calls[0], $alert) !== false, true);
verifie('« Fichier de l\'image » vide avant la capture', $rule->value('image_file'), '');
$now = 1001.0;
captureArrives($alert);
verifie('actions jouées une fois', count(played(1, 'actions')), 1);
verifie('à 1 s du déclenchement', played(1, 'actions')[0]['at'], 1001.0);
verifie('« Fichier de l\'image » à jour quand elles partent',
        played(1, 'actions')[0]['file'], dahuaAlert::path($alert) . '/cam10_live.jpg');
verifie('journal : capture fraîche et délai réel', log::has('actions jouées après 1,0 s : capture fraîche enregistrée'), true);
/* Le processus de secours se réveille ensuite : il ne doit rien rejouer. */
verifie('le processus de secours ne rejoue rien', dahuaRule::watch($alert), false);
/* Le démon rejoue son compte rendu (délai de quatre secondes dépassé). */
captureArrives($alert);
$now = 1100.0;
dahuaRule::checkHold();
dahuaRule::recoverDeferred();
verifie('toujours une seule exécution', count(played(1, 'actions')), 1);

echo "\n== Capture jamais arrivée : actions à l'échéance ==\n";
$now = 2000.0;
$rule = rule(2, array('wait_live' => 1, 'wait_live_max' => 10));
dahuaRule::test($rule);
$alert = alertOf($rule);
verifie('aucune action avant l\'échéance', count(played(2)), 0);
dahuaRule::watch($alert);
verifie('actions jouées une fois', count(played(2, 'actions')), 1);
verifie('exactement à l\'échéance (10 s)', played(2, 'actions')[0]['at'], 2010.0);
verifie('sans photo', played(2, 'actions')[0]['file'], '');
verifie('journal : délai maximum atteint', log::has('actions jouées après 10,0 s : délai maximum atteint sans capture fraîche'), true);
$now = 2011.0;
captureArrives($alert);
dahuaRule::checkHold();
dahuaRule::recoverDeferred();
verifie('capture tardive : rien n\'est rejoué', count(played(2, 'actions')), 1);

echo "\n== L'événement ne réveille rien : le secours voit la capture ==\n";
$now = 3000.0;
$rule = rule(3, array('wait_live' => 1, 'wait_live_max' => 10));
dahuaRule::test($rule);
$alert = alertOf($rule);
$onSleep = function ($_t) use ($alert) {
    static $done = false;
    if (!$done && $_t >= 3001.0) {
        $done = true;
        captureArrives($alert, true, false);      // inscrite, mais personne n'est prévenu
    }
};
dahuaRule::watch($alert);
$onSleep = null;
verifie('actions jouées une fois', count(played(3, 'actions')), 1);
verifie('au sondage suivant la capture (1 s)', played(3, 'actions')[0]['at'], 3001.0);
verifie('avec la photo', played(3, 'actions')[0]['file'], dahuaAlert::path($alert) . '/cam10_live.jpg');

echo "\n== Toutes les captures en échec : pas d'attente inutile ==\n";
$now = 4000.0;
$rule = rule(4, array('wait_live' => 1, 'wait_live_max' => 10));
dahuaRule::test($rule);
$alert = alertOf($rule);
$now = 4002.5;
captureArrives($alert, false);
verifie('actions jouées dès l\'échec', count(played(4, 'actions')), 1);
verifie('à 2,5 s', played(4, 'actions')[0]['at'], 4002.5);
verifie('journal : captures en échec', log::has('actions jouées après 2,5 s : captures fraîches toutes en échec'), true);

echo "\n== Anti double exécution : un seul jeton ==\n";
$now = 5000.0;
$rule = rule(5, array('wait_live' => 1, 'wait_live_max' => 10));
dahuaRule::test($rule);
$alert = alertOf($rule);
$now = 5011.0;
$first  = dahuaAlert::claimActions($alert, true, $now);
$second = dahuaAlert::claimActions($alert, true, $now);
verifie('premier candidat : jeton obtenu', is_array($first), true);
verifie('raison : échéance', $first['reason'], 'timeout');
verifie('second candidat : rien', $second, null);
verifie('l\'événement ensuite : rien', dahuaRule::onLiveShot($alert), false);
verifie('le secours ensuite : rien', dahuaRule::watch($alert), false);
dahuaRule::recoverDeferred();
verifie('le jeton pris ici n\'a rien joué d\'autre', count(played(5, 'actions')), 0);

/* Les candidats réels sont des processus distincts (requête du démon,
 * processus de secours, cron) : seul le verrou de fichier les départage. On
 * lance donc de vrais processus concurrents, pas des appels successifs. */
if (function_exists('pcntl_fork')) {
    $now = 5500.0;
    $rule = rule(25, array('wait_live' => 1, 'wait_live_max' => 10));
    dahuaRule::test($rule);
    $alert = alertOf($rule);
    $tally = $root . '/claims.txt';
    $children = array();
    for ($i = 0; $i < 8; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            if (dahuaAlert::claimActions($alert, true, 5511.0) !== null) {
                file_put_contents($tally, "1\n", FILE_APPEND | LOCK_EX);
            }
            /* Sortie brutale : le fils ne doit pas jouer le nettoyage du père. */
            posix_kill(getmypid(), SIGKILL);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }
    verifie('8 processus concurrents : un seul jeton', count(file($tally)), 1);
}

echo "\n== Option désactivée : actions immédiates ==\n";
$now = 6000.0;
$calls = count(system::$calls);
$rule = rule(6, array('wait_live' => 0));
dahuaRule::test($rule);
verifie('actions jouées au déclenchement', count(played(6, 'actions')), 1);
verifie('à l\'instant même', played(6, 'actions')[0]['at'], 6000.0);
verifie('aucun processus de secours', count(system::$calls), $calls);
verifie('aucune attente inscrite', dahuaAlert::pendingActions(alertOf($rule)), null);

echo "\n== Capture fraîche décochée dans le plugin : rien à attendre ==\n";
$now = 7000.0;
config::$values['alert_shot'] = 0;
$rule = rule(7, array('wait_live' => 1, 'wait_live_max' => 10));
dahuaRule::test($rule);
config::$values['alert_shot'] = 1;
verifie('actions jouées au déclenchement', count(played(7, 'actions')), 1);
verifie('aucun processus de secours', count(system::$calls), $calls);
verifie('journal : pas d\'attente', log::has('pas d\'attente : la capture fraîche est désactivée'), true);

echo "\n== Maintien plus court que l'attente : actions de fin après ==\n";
$now = 8000.0;
$rule = rule(8, array('wait_live' => 1, 'wait_live_max' => 10, 'hold' => 1));
dahuaRule::test($rule);
$alert = alertOf($rule);
holdElapsed($rule);
$now = 8003.0;
clearStates();
dahuaRule::checkHold();
verifie('maintien écoulé, capture attendue : toujours déclenchée', $rule->value('triggered'), 1);
verifie('aucune action de fin avant les actions', count(played(8)), 0);
$now = 8004.0;
captureArrives($alert);
$keys = array_map(function ($_e) { return $_e['key']; }, played(8));
verifie('ordre : actions puis actions de fin', $keys, array('actions', 'actions_end'));
verifie('retour au repos dès les actions parties', $rule->value('triggered'), 0);

echo "\n== Maintien court, capture jamais venue : rattrapage de checkHold ==\n";
$now = 9000.0;
$rule = rule(9, array('wait_live' => 1, 'wait_live_max' => 10, 'hold' => 1));
dahuaRule::test($rule);
holdElapsed($rule);
$now = 9011.0;                                    // échéance passée, marge non écoulée
clearStates();
dahuaRule::checkHold();
verifie('dans la marge du secours : rien ne part', count(played(9)), 0);
$now = 9013.0;                                    // secours mort avec Jeedom
clearStates();
dahuaRule::checkHold();
$keys = array_map(function ($_e) { return $_e['key']; }, played(9));
verifie('ordre : actions puis actions de fin', $keys, array('actions', 'actions_end'));

echo "\n== Réinitialisation pendant l'attente ==\n";
$now = 10000.0;
$rule = rule(11, array('wait_live' => 1, 'wait_live_max' => 10));
dahuaRule::test($rule);
$alert = alertOf($rule);
$now = 10000.5;
dahuaRule::reset($rule);
$keys = array_map(function ($_e) { return $_e['key']; }, played(11));
verifie('actions jouées avant les actions de fin', $keys, array('actions', 'actions_end'));
$now = 10001.0;
captureArrives($alert);
verifie('capture ensuite : rien n\'est rejoué', count(played(11, 'actions')), 1);

echo "\n== Redémarrage de Jeedom pendant l'attente ==\n";
$now = 20000.0;
$rule = rule(12, array('wait_live' => 1, 'wait_live_max' => 10, 'cooldown' => 0));
dahuaRule::test($rule);
$alert = alertOf($rule);
clearStates();                                    // nouveau processus, secours mort
$now = 20070.0;
dahuaRule::recoverDeferred();
verifie('le cron joue l\'attente échue', count(played(12, 'actions')), 1);
verifie('journal : rattrapage et retard', log::has('(rattrapage, avec 60 s de retard)'), true);

$now = 30000.0;
$rule = rule(13, array('wait_live' => 1, 'wait_live_max' => 10));
dahuaRule::test($rule);
$now = 30010.0 + dahuaRule::WAIT_MAX_LATE + 60;
dahuaRule::recoverDeferred();
verifie('trop tard : abandonnées, pas jouées', count(played(13, 'actions')), 0);
verifie('abandon annoncé au centre de messages', count(message::$added), 1);
dahuaRule::recoverDeferred();
verifie('et annoncé une seule fois', count(message::$added), 1);

echo "\n== Bornes du délai ==\n";
verifie('défaut', dahuaRule::waitLiveMax(rule(20, array())), 10);
verifie('vide', dahuaRule::waitLiveMax(rule(21, array('wait_live_max' => ''))), 10);
verifie('0 ramené à 1', dahuaRule::waitLiveMax(rule(22, array('wait_live_max' => 0))), 1);
verifie('120 ramené à 30', dahuaRule::waitLiveMax(rule(23, array('wait_live_max' => 120))), 30);

echo "\n== Temporisation inchangée ==\n";
$now = 40000.0;
$rule = rule(24, array('wait_live' => 1, 'wait_live_max' => 10));
dahuaRule::test($rule);
$refused = false;
try {
    dahuaRule::test($rule);
} catch (Exception $e) {
    $refused = (strpos($e->getMessage(), 'temporisation') !== false);
}
verifie('second test refusé par la temporisation', $refused, true);

echo "\n";
printf("Bilan : %d ok, %d en échec.\n", $ok, $ko);
exit($ko > 0 ? 1 : 0);
