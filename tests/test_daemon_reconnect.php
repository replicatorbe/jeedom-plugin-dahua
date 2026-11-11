<?php
/* Jeu d'essai hors ligne de la reconnexion du démon à un NVR : niveau de
 * journal des essais ratés, et bascule de transport DHIP <-> CGI.
 *
 *   php tests/test_daemon_reconnect.php
 *
 * Aucune dépendance : ni Jeedom, ni NVR. Le « NVR » est 127.0.0.1, et chaque
 * situation se fabrique avec de vraies sockets locales :
 *   - port fermé           : refus immédiat, comme un hôte qui redémarre ;
 *   - port ouvert muet     : la poignée de main TCP aboutit (file d'attente du
 *                            noyau), rien d'autre — assez pour la sonde ;
 *   - port qui raccroche   : un fils accepte et ferme aussitôt, comme un NVR
 *                            dont le DHIP ne répond pas au challenge.
 *
 * dahuad.php est un script autonome (garde CLI, lecture de la clé, boucle
 * principale) : on n'en charge que les classes, extraites du texte. Les
 * méthodes privées y deviennent protégées pour qu'une sous-classe d'essai
 * puisse remplacer l'envoi vers Jeedom et appeler connectPending(). */

date_default_timezone_set('Europe/Brussels');

/* ============================================================== DOUBLURES */

class DahuaLog {
    public static $lines = array();
    public static function debug($m)   { }
    public static function info($m)    { }
    public static function warning($m) { self::$lines[] = array('warning', $m); }
    public static function error($m)   { self::$lines[] = array('error', $m); }
    public static function count($_level, $_needle = '') {
        $n = 0;
        foreach (self::$lines as $line) {
            if ($line[0] == $_level && ($_needle === '' || strpos($line[1], $_needle) !== false)) {
                $n++;
            }
        }
        return $n;
    }
}

/* ============================================================== CHARGEMENT */

$source = file_get_contents(__DIR__ . '/../resources/dahuad/dahuad.php');
$start  = strpos($source, 'abstract class DahuaTransport');
$end    = strpos($source, '/* ------------------------------------------------------------------ signaux */');
if ($start === false || $end === false) {
    fwrite(STDERR, "Classes du démon introuvables dans dahuad.php\n");
    exit(1);
}
eval(str_replace('private ', 'protected ', substr($source, $start, $end - $start)));

class EssaiDaemon extends DahuaDaemon {
    public $pushed = array();
    public function __construct() {
        parent::__construct(array());
        $this->config = array('reconnect_delay' => 30);
    }
    protected function push($_events) {
        $this->pushed[] = $_events;
    }
    public function setClient($_client) {
        $this->clients = array(1 => $_client);
    }
    public function client() {
        return $this->clients[1];
    }
    /* Un essai, sans attendre le backoff. */
    public function attempt() {
        Closure::bind(function () { $this->nextRetry = 0; }, $this->clients[1], 'DahuaTransport')();
        $this->connectPending();
    }
    public function drop($_reason) {
        $this->dropClient($this->clients[1], $_reason);
    }
}

/* Recule le début de la panne, au lieu d'attendre trois minutes. */
function vieillir($_client, $_seconds) {
    Closure::bind(function () use ($_seconds) { $this->outageSince -= $_seconds; },
                  $_client, 'DahuaTransport')();
}

/* Un port libre, aussitôt refermé : s'y connecter est refusé. */
function portFerme() {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($server, false);
    fclose($server);
    return (int) substr($name, strrpos($name, ':') + 1);
}

function portOuvert(&$_server) {
    $_server = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($_server, false);
    return (int) substr($name, strrpos($name, ':') + 1);
}

function nvr($_dhipPort, $_httpPort) {
    return new DahuaDhipTransport(array(
        'eqLogic_id' => 1, 'name' => 'NVR essai', 'ip' => '127.0.0.1',
        'port' => $_dhipPort, 'http_port' => $_httpPort,
        'username' => 'admin', 'password' => 'x', 'transport' => 'auto',
    ));
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

echo "\n== Hôte injoignable : pas de bascule de transport ==\n";
DahuaLog::$lines = array();
$daemon = new EssaiDaemon();
$daemon->setClient(nvr(portFerme(), portFerme()));
for ($i = 0; $i < 4; $i++) {
    $daemon->attempt();
}
verifie('toujours en DHIP après 4 refus', $daemon->client()->label(), 'DHIP');
verifie('aucune ligne de bascule', DahuaLog::count('warning', 'bascule'), 0);
verifie('une ligne par essai, pas trois', count(DahuaLog::$lines), 4);
verifie('un seul signalement de déconnexion à Jeedom', count($daemon->pushed), 1);

echo "\n== Niveau de journal : une seule erreur par panne, et si elle dure ==\n";
verifie('panne de moins de 3 min : aucune erreur', DahuaLog::count('error'), 0);
verifie('panne de moins de 3 min : des avertissements', DahuaLog::count('warning'), 4);
vieillir($daemon->client(), 200);
$daemon->attempt();
verifie('au-delà de 3 min : une erreur', DahuaLog::count('error'), 1);
verifie('l\'erreur dit depuis quand', DahuaLog::count('error', 'injoignable depuis 3 min'), 1);
$daemon->attempt();
$daemon->attempt();
verifie('les essais suivants : avertissements', DahuaLog::count('error'), 1);
$daemon->client()->scheduleRetry(0, true);           // reconnecté
DahuaLog::$lines = array();
$daemon->attempt();
verifie('après reconnexion, nouvelle panne : avertissement', DahuaLog::count('warning'), 1);
verifie('après reconnexion, nouvelle panne : pas d\'erreur', DahuaLog::count('error'), 0);

echo "\n== Redémarrage du NVR : déconnexion puis refus ==\n";
DahuaLog::$lines = array();
$daemon = new EssaiDaemon();
$daemon->setClient(nvr(portFerme(), portFerme()));
$daemon->drop('connexion fermée par le NVR');
vieillir($daemon->client(), 15);
$daemon->attempt();                                  // « Connection refused » à +15 s
verifie('refus 15 s après la déconnexion : pas d\'erreur', DahuaLog::count('error'), 0);
verifie('refus 15 s après la déconnexion : avertissement',
        DahuaLog::count('warning', 'Connection refused'), 1);
vieillir($daemon->client(), 170);
$daemon->attempt();
verifie('toujours absent 3 min après la déconnexion : erreur', DahuaLog::count('error'), 1);

echo "\n== Port DHIP fermé, HTTP ouvert : bascule sur CGI ==\n";
DahuaLog::$lines = array();
$http = null;
$daemon = new EssaiDaemon();
$daemon->setClient(nvr(portFerme(), portOuvert($http)));
$daemon->attempt();
verifie('premier refus : pas encore de bascule', $daemon->client()->label(), 'DHIP');
$daemon->attempt();
verifie('second refus : bascule sur CGI', $daemon->client()->label(), 'CGI');
verifie('la bascule est journalisée', DahuaLog::count('warning', 'bascule sur le transport CGI'), 1);
fclose($http);

echo "\n== DHIP qui raccroche au challenge : bascule sur CGI ==\n";
DahuaLog::$lines = array();
$dhip = null;
$dhipPort = portOuvert($dhip);
$pid = pcntl_fork();
if ($pid == 0) {
    while ($conn = @stream_socket_accept($dhip, 30)) {
        fclose($conn);
    }
    exit(0);
}
$daemon = new EssaiDaemon();
$daemon->setClient(nvr($dhipPort, portFerme()));
$daemon->attempt();
vieillir($daemon->client(), 60);
$daemon->attempt();
verifie('échec de protocole : bascule sur CGI', $daemon->client()->label(), 'CGI');
list($since, $reported) = $daemon->client()->outageState();
verifie('la panne continue après la bascule', time() - $since >= 60, true);
posix_kill($pid, SIGTERM);
pcntl_waitpid($pid, $status);
fclose($dhip);

echo "\n";
printf("Bilan : %d ok, %d en échec.\n", $ok, $ko);
exit($ko > 0 ? 1 : 0);
