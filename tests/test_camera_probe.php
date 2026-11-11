<?php
/* Jeu d'essai hors ligne de la sonde de joignabilité des caméras : une caméra
 * en ligne n'est déclarée perdue qu'à la deuxième sonde négative consécutive,
 * et son retour reste immédiat.
 *
 *   php tests/test_camera_probe.php
 *
 * Aucune dépendance : ni Jeedom, ni démon. Le coeur est remplacé par des
 * doublures minimales. jeeDahua.php est un point d'entrée (inclusion du coeur,
 * contrôle de la clé, lecture du corps) : on n'en charge que les fonctions,
 * extraites du texte, et on leur passe des relevés comme le ferait le démon. */

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

class FakeCmd {
    private $eq;
    private $logicalId;
    public function __construct($_eq, $_logicalId) {
        $this->eq = $_eq;
        $this->logicalId = $_logicalId;
    }
    public function execCmd() {
        return isset($this->eq->values[$this->logicalId]) ? $this->eq->values[$this->logicalId] : '';
    }
}

class FakeEq {
    public $values = array();
    public $published = 0;
    private $id;
    private $name;
    public function __construct($_id, $_name) {
        $this->id = $_id;
        $this->name = $_name;
    }
    public function getId() { return $this->id; }
    public function getName() { return $this->name; }
    public function getHumanName() { return '[' . $this->name . ']'; }
    public function getEqType_name() { return 'dahua'; }
    public function getCmd($_type, $_logicalId) { return new FakeCmd($this, $_logicalId); }
    public function checkAndUpdateCmd($_logicalId, $_value, $_date = null) {
        $this->values[$_logicalId] = (string) $_value;
        return true;
    }
    public function publishCamStatus() { $this->published++; }
}

class dahua {
    public static $equipments = array();
    public static $played = array();
    public static $states = array();
    public static function byId($_id) {
        return isset(self::$equipments[$_id]) ? self::$equipments[$_id] : null;
    }
    public static function byLogicalId($_logicalId, $_type) {
        return isset(self::$equipments[$_logicalId]) ? self::$equipments[$_logicalId] : null;
    }
    public static function camState($_id) {
        return isset(self::$states[$_id]) ? self::$states[$_id] : array('fired' => 0);
    }
    public static function saveCamState($_id, $_state) {
        self::$states[$_id] = $_state;
    }
    public static function runActions($_eqLogic, $_key, $_tags = array(), $_guardSelf = true) {
        self::$played[] = $_key;
    }
}

/* ============================================================== CHARGEMENT */

require_once __DIR__ . '/../core/class/dahuaProbe.class.php';
$source = file_get_contents(__DIR__ . '/../core/php/jeeDahua.php');
$start  = strpos($source, 'function dahuaEventDate');
if ($start === false) {
    fwrite(STDERR, "Fonctions introuvables dans jeeDahua.php\n");
    exit(1);
}
eval(substr($source, $start));

$nvr = new FakeEq(1, 'NVR');
$cam = new FakeEq(18, 'SUDPTZ');
dahua::$equipments[1] = $nvr;
dahua::$equipments['cam::1::8'] = $cam;

function sonde($_up) {
    handleDahuaEvent(array('nvr_id' => 1, 'type' => 'camera_status', 'channels' => array(8 => $_up)));
}

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

/* ================================================================= ESSAIS */

echo "\n== Première observation ==\n";
sonde(1);
verifie('caméra vue en ligne', $cam->values['online'], '1');
verifie('aucune action', count(dahua::$played), 0);

echo "\n== Décrochage d'une seule sonde ==\n";
sonde(0);
verifie('une sonde négative : toujours en ligne', $cam->values['online'], '1');
verifie('une sonde négative : pas d\'action « perdue »', count(dahua::$played), 0);
sonde(1);
verifie('retour à la sonde suivante : en ligne', $cam->values['online'], '1');
verifie('retour : aucune action « retour »', count(dahua::$played), 0);
sonde(0);
verifie('une sonde négative isolée, après retour : en ligne', $cam->values['online'], '1');
sonde(1);

echo "\n== Vraie perte : deux sondes négatives consécutives ==\n";
sonde(0);
sonde(0);
verifie('deux sondes négatives : hors ligne', $cam->values['online'], '0');
verifie('deux sondes négatives : action « perdue »', dahua::$played, array('camera_lost_actions'));
sonde(0);
verifie('troisième sonde négative : pas de nouvelle action', count(dahua::$played), 1);

echo "\n== Retour immédiat ==\n";
sonde(1);
verifie('une sonde positive suffit', $cam->values['online'], '1');
verifie('action « retour » jouée', end(dahua::$played), 'camera_back_actions');

echo "\n== Caméra déjà à 0 (NVR reconnecté) ==\n";
$cam->values['online'] = '0';
dahua::$played = array();
sonde(0);
verifie('rien à confirmer : reste à 0', $cam->values['online'], '0');
verifie('aucune action', count(dahua::$played), 0);

echo "\n";
printf("Bilan : %d ok, %d en échec.\n", $ok, $ko);
exit($ko > 0 ? 1 : 0);
