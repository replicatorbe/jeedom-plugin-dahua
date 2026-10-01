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
 * Dossier d'alerte : ce qu'on regarde pour lever un doute.
 *
 * Un déclenchement de règle crée ici un dossier autonome, qui porte ses propres
 * images et sa propre description. Autonome est le mot important : jusqu'ici
 * l'« image du déclenchement » n'était qu'une URL posée dans une commande, donc
 * écrasée au déclenchement suivant, et qui pointait un fichier détruit par la
 * rotation des captures courantes — mesuré sur une caméra active, celle-ci ne
 * conserve que trente minutes. Une alerte de la nuit n'avait plus d'image au
 * matin.
 *
 * Chaque caméra ayant participé au déclenchement apporte jusqu'à deux images :
 *
 *   det  — l'image du moment de la détection. Elle n'est pas prise ici : le
 *          démon capture déjà à chaque événement, on retrouve le fichier dont
 *          l'horodatage colle à la détection et on le COPIE dans le dossier.
 *          Coût nul pour le NVR, et c'est la seule image réellement
 *          contemporaine de l'événement.
 *   live — une capture fraîche, demandée au démon au moment du déclenchement.
 *          Elle montre où la personne est allée depuis. Elle arrive de façon
 *          asynchrone, après coup : le dossier est donc valide et affichable
 *          avant qu'elle n'existe.
 *
 * Chaque image est doublée d'une vignette de THUMB_WIDTH pixels. Ce n'est pas
 * du confort : les captures pèsent de 600 Ko à 1 Mo, leur vignette 25 Ko. Une
 * tuile de dashboard qui afficherait quatre images pleine résolution
 * téléchargerait plusieurs méga-octets à chaque rendu.
 */
class dahuaAlert {

    /* Les deux natures d'image d'un dossier. Toute valeur hors de cette liste
     * est refusée : le nom de fichier qu'elle compose est servi au navigateur. */
    const KIND_DET  = 'det';
    const KIND_LIVE = 'live';

    const THUMB_WIDTH   = 320;
    const THUMB_QUALITY = 80;

    /*
     * Écart maximal toléré entre une détection et la capture qu'on lui associe
     * À L'OUVERTURE du dossier, où l'on CHOISIT la meilleure parmi celles déjà
     * présentes sur le disque. Le démon capture une à deux secondes après
     * l'événement, mais son verrou anti-rafale de dix secondes par caméra fait
     * qu'une détection peut n'avoir aucune capture à elle : on prend alors la
     * plus proche. Au-delà d'une minute et demie l'image ne montre plus la même
     * scène, mieux vaut pas d'image du tout qu'une image qui raconte autre
     * chose.
     */
    const MATCH_WINDOW = 90;

    /*
     * Le rattrapage, lui, est beaucoup plus strict — et il doit l'être.
     *
     * À l'ouverture on choisit ; au rattrapage on ACCEPTE une capture qui vient
     * d'arriver, sans rien à quoi la comparer. Avec la tolérance de l'ouverture,
     * une alerte restée sans image se verrait attribuer, une minute plus tard,
     * la capture d'un mouvement sans aucun rapport — une voiture qui passe — et
     * l'inscrirait comme « image du moment de la détection ». Elle serait même
     * verrouillée contre toute correction ultérieure, son écart étant désormais
     * renseigné.
     *
     * Quinze secondes couvrent le cas réel : le fils du démon écrit une à deux
     * secondes après l'événement, et le verrou anti-rafale de dix secondes par
     * caméra peut décaler la capture d'autant.
     */
    const CATCHUP_WINDOW = 15;

    /*
     * Au-delà, une capture fraîche demandée mais jamais revenue cesse d'être
     * « en attente » : le démon abandonne au bout de dix secondes et poste son
     * échec, donc passé ce délai c'est l'ordre lui-même qui s'est perdu. Une
     * tuile qui annonce une capture en route pour l'éternité ment aussi sûrement
     * qu'une image absente.
     */
    const SHOT_EXPIRY = 60;

    const DEFAULT_KEEP      = 300;
    const DEFAULT_KEEP_FULL = 30;

    /*
     * Âge en deçà duquel une alerte garde sa pleine résolution, quel que soit
     * son rang. Le rang seul ne dit rien du temps : à trente alertes par jour,
     * les trente dernières couvrent une demi-journée, et l'alerte de ce matin
     * n'était déjà plus agrandissable le soir. Trois jours couvrent un
     * week-end d'absence, le cas où l'on revient sur des alertes qu'on n'a pas
     * vues passer.
     */
    const DEFAULT_KEEP_FULL_DAYS = 3;

    /* Plafond de saisie. Au-delà, ce n'est plus une rétention, c'est une fuite. */
    const MAX_KEEP = 5000;

    /*
     * Âge maximal, en jours, de tout ce que le plugin garde sur le disque —
     * dossiers d'alerte et captures courantes. Les quotas en nombre ne disent
     * rien du temps : sur une installation calme, trois cents alertes couvrent
     * des mois, et une caméra muette garde ses cinquante captures pour
     * toujours. Sept jours couvrent une semaine d'absence. 0 désactive.
     */
    const DEFAULT_MAX_AGE_DAYS = 7;

    /*
     * Nombre de caméras qu'un dossier d'alerte peut porter.
     *
     * Quatre, et la même valeur que MAX_ALERT_SHOTS côté démon — un écart entre
     * les deux laisserait des caméras inscrites dans la description mais jamais
     * capturées, donc éternellement « en attente » dans la tuile. Quatre parce
     * qu'au-delà ce n'est plus un levé de doute, et surtout parce que le serveur
     * HTTP embarqué du NVR est fragile : le démon s'impose déjà un seul NVR
     * sondé par tour de boucle pour cette raison précise.
     */
    const MAX_CAMERAS = 4;

    /* Repère du dernier dossier ouvert, pour que le rattrapage puisse renoncer
     * sans toucher au disque. Voir catchUpDetection(). */
    const CACHE_LAST = 'dahua::alert::last';

    /* ============================================================== NOMMAGE */

    /*
     * L'identifiant commence par la date en UTC : le tri lexicographique des
     * dossiers est donc chronologique, ce dont vivent la purge et la page
     * d'historique. Le jeton aléatoire empêche de deviner l'URL d'une image, et
     * règle du même coup la collision de deux règles déclenchées dans la même
     * seconde.
     */
    public static function newId($_ruleId) {
        return gmdate('Ymd-His') . '_r' . (int) $_ruleId . '_' . bin2hex(random_bytes(4));
    }

    /*
     * Un identifiant et un nom de fichier venus de l'extérieur composent un
     * chemin sur disque, et ce chemin est servi au navigateur. Ils sont donc
     * validés par liste blanche, jamais assainis : aucune barre oblique, aucun
     * point, rien qui puisse sortir du dossier.
     *
     * Le modificateur D n'est pas décoratif : sans lui, « $ » accepte un saut de
     * ligne final, et « …_a1b2c3d4\n » passerait la validation. Inexploitable
     * ici puisque le chemin n'existerait pas, mais une liste blanche qui laisse
     * passer ce qu'elle n'a pas prévu n'est plus une liste blanche.
     *
     * L'expression de l'identifiant est dupliquée dans resources/dahuad/dahuad.php
     * (case « alertshot ») : le démon ne charge pas le coeur de Jeedom et ne peut
     * donc pas appeler cette méthode. Toute modification ici doit y être reportée.
     */
    public static function isValidId($_id) {
        return is_string($_id) && preg_match('/^\d{8}-\d{6}_r\d+_[0-9a-f]{8}$/D', $_id) === 1;
    }

    public static function isValidFile($_file) {
        return is_string($_file) && preg_match('/^cam\d+_(det|live)(_t)?\.jpg$/D', $_file) === 1;
    }

    public static function fileName($_cameraId, $_kind, $_thumb = false) {
        if ($_kind !== self::KIND_DET && $_kind !== self::KIND_LIVE) {
            return false;
        }
        return 'cam' . (int) $_cameraId . '_' . $_kind . ($_thumb ? '_t' : '') . '.jpg';
    }

    /* ============================================================= DOSSIERS */

    /* Racine des dossiers d'alerte. Voisine de data/snapshots, donc couverte par
     * le même data/.htaccess : rien n'y est lisible sans passer par le
     * passe-plat authentifié. */
    public static function baseDir() {
        $base = realpath(__DIR__ . '/../..');
        if ($base === false) {
            log::add('dahua', 'error', __('Racine du plugin introuvable', __FILE__));
            return false;
        }
        $dir = $base . '/data/alerts';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            log::add('dahua', 'error', __('Création du dossier des alertes impossible :', __FILE__) . ' ' . $dir);
            return false;
        }
        return $dir;
    }

    /* Chemin d'un dossier d'alerte existant, ou false. Ne crée rien : un
     * identifiant inconnu doit se lire comme une alerte purgée, pas comme une
     * invitation à fabriquer un dossier vide. */
    public static function path($_id) {
        if (!self::isValidId($_id)) {
            return false;
        }
        $base = self::baseDir();
        if ($base === false) {
            return false;
        }
        $dir = $base . '/' . $_id;
        return is_dir($dir) ? $dir : false;
    }

    /* ================================================================ META */

    public static function readMeta($_id) {
        $dir = self::path($_id);
        if ($dir === false) {
            return null;
        }
        $raw = @file_get_contents($dir . '/meta.json');
        if ($raw === false || $raw === '') {
            return null;
        }
        $meta = json_decode($raw, true);
        return is_array($meta) ? $meta : null;
    }

    /*
     * Écriture atomique de la description.
     *
     * Fichier temporaire puis rename(), et jamais une écriture en place. Une
     * troncature suivie d'une écriture laisse, entre les deux, un meta.json VIDE
     * sur le disque : un lecteur qui passe là lit une alerte sans règle et sans
     * caméra, et un processus qui meurt là — délai d'exécution atteint, worker
     * recyclé, Apache redémarré pendant une rafale — la laisse vide pour
     * toujours. Elle serait alors définitivement muette : plus de règle à
     * republier, plus d'images déclarées, et rien nulle part pour le dire.
     * rename() est atomique sur un même système de fichiers : un lecteur voit
     * l'ancienne version ou la nouvelle, jamais un entre-deux.
     *
     * JSON_INVALID_UTF8_SUBSTITUTE n'est pas décoratif : depuis que les causes
     * d'échec de capture sont persistées, un message de curl venu du réseau peut
     * contenir un octet non-UTF-8, et json_encode retournerait false — donc un
     * fichier vide, par le chemin le plus discret qui soit.
     */
    private static function writeMeta($_dir, $_meta) {
        $json = json_encode($_meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                  | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            log::add('dahua', 'error', __('Description d\'alerte non encodable :', __FILE__)
                   . ' ' . json_last_error_msg());
            return false;
        }
        $tmp = $_dir . '/meta.json.tmp';
        if (@file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
            log::add('dahua', 'error', __('Écriture de la description d\'alerte impossible :', __FILE__) . ' ' . $_dir);
            return false;
        }
        if (!@rename($tmp, $_dir . '/meta.json')) {
            @unlink($tmp);
            log::add('dahua', 'error', __('Remplacement de la description d\'alerte impossible :', __FILE__) . ' ' . $_dir);
            return false;
        }
        return true;
    }

    /*
     * Lecture-modification-écriture sous verrou exclusif, la publication
     * comprise.
     *
     * Indispensable et non théorique : les captures fraîches sont postées par un
     * processus fils PAR CAMÉRA, donc par des requêtes HTTP concurrentes.
     *
     * Le verrou porte sur un fichier dédié, jamais sur meta.json lui-même. C'est
     * obligatoire dès lors qu'on écrit par rename() : le verrou suit l'inode,
     * or rename() en substitue un autre. Un second processus qui aurait ouvert
     * l'ancien inode attendrait alors un verrou sur un fichier déjà remplacé,
     * l'obtiendrait, et travaillerait sur une version morte.
     *
     * $_after est joué AVANT de rendre le verrou, et c'est tout l'intérêt : sans
     * cela, deux fils qui relisent puis publient chacun de leur côté peuvent
     * écrire la commande dans le désordre, et la tuile resterait figée sur la
     * version la moins complète — deux images sur quatre, définitivement.
     */
    private static function updateMeta($_id, $_mutator, $_after = null) {
        $dir = self::path($_id);
        if ($dir === false) {
            return null;
        }
        /*
         * Une description absente n'est pas une description vide : on refuse
         * d'en fabriquer une. Sans ce garde-fou, un dossier dont meta.json a été
         * effacé — purge interrompue, effacement manuel — s'en verrait écrire un
         * réduit aux seuls champs modifiés ici, sans date. Il remonterait alors
         * en tête de l'historique et ferait renoncer le rattrapage dès le
         * premier élément, définitivement et sans un mot.
         */
        if (self::readMeta($_id) === null) {
            return null;
        }
        $lock = @fopen($dir . '/.lock', 'c');
        if ($lock === false) {
            log::add('dahua', 'error', __('Verrou d\'alerte inaccessible :', __FILE__) . ' ' . $dir);
            return null;
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            log::add('dahua', 'error', __('Verrouillage de l\'alerte impossible :', __FILE__) . ' ' . $dir);
            return null;
        }

        $meta = self::readMeta($_id);
        if (!is_array($meta)) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return null;
        }
        $meta = call_user_func($_mutator, $meta);

        $written = null;
        if (is_array($meta) && self::writeMeta($dir, $meta)) {
            $written = $meta;
            if ($_after !== null) {
                call_user_func($_after, $meta);
            }
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        return $written;
    }

    /*
     * Republie l'alerte telle qu'elle vient d'être écrite, sans la relire : la
     * valeur publiée est exactement celle qui est sur le disque, et l'ordre des
     * publications suit celui des écritures puisque le verrou est encore tenu.
     */
    private static function publisher($_id) {
        return function ($_meta) use ($_id) {
            if (!isset($_meta['rule_id'])) {
                return;
            }
            $rule = self::ruleOf($_meta);
            if (is_object($rule)) {
                dahuaAlert::publish($rule, $_id, $_meta);
            }
        };
    }

    /*
     * La règle d'une alerte, contrôlée.
     *
     * byId() ne garantit rien : un identifiant réattribué à un autre équipement
     * — la règle a été supprimée, un NVR a pris son numéro — ferait écrire des
     * commandes d'alerte sur un étranger, sans effet et sans trace. Le reste du
     * plugin applique ce même contrôle.
     */
    public static function ruleOf($_meta) {
        if (!isset($_meta['rule_id'])) {
            return null;
        }
        $rule = dahua::byId((int) $_meta['rule_id']);
        if (!is_object($rule) || $rule->getEqType_name() != 'dahua'
         || $rule->getConfiguration('type') != dahua::TYPE_RULE) {
            return null;
        }
        return $rule;
    }

    /* ============================================================ OUVERTURE */

    /*
     * Crée le dossier d'une alerte et y range tout de suite les images de
     * détection. Retourne l'identifiant, ou '' si rien n'a pu être écrit — un
     * échec ici ne doit jamais empêcher la règle de se déclencher, c'est une
     * alarme.
     *
     * $_group est la liste des détections qui ont validé la corrélation, telle
     * que la tient dahuaRule : [{m, ch, cam, e, t}, …]. Elle peut être vide
     * (bouton « Tester »), auquel cas $_cameras fournit les caméras de repli.
     */
    public static function open($_rule, $_group, $_detail, $_cameras = array()) {
        $base = self::baseDir();
        if ($base === false) {
            return '';
        }

        $cameras = self::camerasOfGroup($_group);
        if (empty($cameras)) {
            foreach ($_cameras as $camera) {
                if (!is_object($camera)) {
                    continue;
                }
                $cameras[(int) $camera->getId()] = array(
                    'id'      => (int) $camera->getId(),
                    'channel' => (int) $camera->getConfiguration('channel'),
                    'event'   => '',
                    'at'      => time(),
                    // Aucune détection réelle à dater : c'est la capture la plus
                    // récente de la caméra qui sera retenue, si elle est assez
                    // fraîche. C'est exactement ce qu'on attend d'un test.
                    'times'   => array(time()),
                );
            }
        }
        if (empty($cameras)) {
            /*
             * Cas réel et pas théorique : « Tester » sur une règle dont toutes
             * les conditions sont en « n'importe quelle caméra ». L'IHM annonce
             * « déclenchée », et sans cette ligne rien nulle part n'expliquerait
             * l'absence de dossier.
             */
            log::add('dahua', 'info', $_rule->getHumanName() . ' '
                   . __('déclenchée sans caméra identifiable : aucun dossier d\'alerte n\'a été créé.', __FILE__));
            return '';
        }
        if (count($cameras) > self::MAX_CAMERAS) {
            log::add('dahua', 'warning', $_rule->getHumanName() . ' '
                   . __('concerne', __FILE__) . ' ' . count($cameras) . ' '
                   . __('caméras : seules les', __FILE__) . ' ' . self::MAX_CAMERAS
                   . ' ' . __('premières sont retenues dans le dossier d\'alerte.', __FILE__));
            $cameras = array_slice($cameras, 0, self::MAX_CAMERAS, true);
        }

        $id  = self::newId($_rule->getId());
        $dir = $base . '/' . $id;
        if (!@mkdir($dir, 0775, true)) {
            log::add('dahua', 'error', __('Création du dossier d\'alerte impossible :', __FILE__) . ' ' . $dir);
            return '';
        }

        $meta = array(
            'id'       => $id,
            'rule_id'  => (int) $_rule->getId(),
            'rule'     => $_rule->getName(),
            'object'   => is_object($_rule->getObject()) ? $_rule->getObject()->getName() : '',
            'time'     => time(),
            'detail'   => (string) $_detail,
            'full'     => 1,
            'cameras'  => array(),
        );

        foreach ($cameras as $entry) {
            $camera = dahua::byId($entry['id']);
            $line = array(
                'id'      => $entry['id'],
                'name'    => is_object($camera) ? $camera->getName() : (__('canal', __FILE__) . ' ' . $entry['channel']),
                'channel' => $entry['channel'],
                'event'   => $entry['event'],
                'at'      => $entry['at'],
                /* Toutes les dates auxquelles cette caméra a détecté quelque
                 * chose, et pas seulement la première : l'appariement d'une
                 * capture se mesure contre l'ensemble, à l'ouverture comme au
                 * rattrapage. Comparer deux écarts calculés sur des références
                 * différentes ferait remplacer une bonne image par une moins
                 * bonne. */
                'times'   => $entry['times'],
            );
            $attached = self::attachDetection($dir, $entry['id'], $entry['times'], $line['name']);
            if ($attached !== null) {
                $line['det']       = $attached['file'];
                $line['det_delta'] = $attached['delta'];
            }
            $meta['cameras'][] = $line;
        }

        /* Pas de verrou ici, et ce n'est pas un oubli : le dossier vient d'être
         * créé, son nom porte quatre octets aléatoires, et l'ordre de capture
         * n'est envoyé qu'ensuite. Personne d'autre ne peut connaître ce chemin.
         * L'écriture atomique, elle, reste de mise : un lecteur ne doit jamais
         * pouvoir tomber sur un fichier à moitié écrit. */
        if (!self::writeMeta($dir, $meta)) {
            /* Le dossier existe déjà et contient des images que plus rien ne
             * réclamerait : la purge ne le verrait qu'au bout de plusieurs
             * centaines d'alertes, c'est-à-dire jamais sur une installation
             * calme. On le retire tout de suite. */
            self::removeDir($dir);
            return '';
        }

        /* Repère lu par le rattrapage pour renoncer sans toucher au disque. */
        cache::set(self::CACHE_LAST, time(), 0);
        return $id;
    }

    /*
     * Les caméras d'un groupe de détections, dans leur ordre d'apparition, avec
     * toutes les dates auxquelles chacune a détecté quelque chose.
     *
     * L'identité est l'id de l'équipement et non le canal, pour la même raison
     * que dans le moteur de corrélation : sur deux NVR, le canal 1 existe deux
     * fois. Une détection sans équipement connu est ignorée — on ne saurait ni
     * la nommer, ni retrouver ses captures.
     */
    private static function camerasOfGroup($_group) {
        $cameras = array();
        if (!is_array($_group)) {
            return $cameras;
        }
        foreach ($_group as $hit) {
            $id = isset($hit['cam']) ? (int) $hit['cam'] : 0;
            if ($id <= 0) {
                continue;
            }
            $time = isset($hit['t']) ? (int) $hit['t'] : time();
            if (!isset($cameras[$id])) {
                $cameras[$id] = array(
                    'id'      => $id,
                    'channel' => isset($hit['ch']) ? (int) $hit['ch'] : 0,
                    'event'   => isset($hit['e']) ? (string) $hit['e'] : '',
                    'at'      => $time,
                    'times'   => array(),
                );
            }
            if (!in_array($time, $cameras[$id]['times'], true)) {
                $cameras[$id]['times'][] = $time;
            }
        }
        return $cameras;
    }

    /*
     * Retrouve, parmi les captures courantes de la caméra, celle qui colle le
     * mieux à l'une de ses détections, et la copie dans le dossier d'alerte.
     *
     * Copie et non lien : le fichier d'origine sera détruit par la rotation des
     * cinquante captures, et c'est précisément ce à quoi le dossier d'alerte
     * doit survivre.
     */
    private static function attachDetection($_dir, $_cameraId, $_times, $_name) {
        $source = dahua::snapshotDir();
        if ($source === false || empty($_times)) {
            return null;
        }
        $files = glob($source . '/cam' . (int) $_cameraId . '_*.jpg');
        if ($files === false || empty($files)) {
            return null;
        }

        $best = self::closestSnapshot($files, $_times);
        if ($best === null) {
            return null;
        }
        if ($best['delta'] > self::MATCH_WINDOW) {
            /*
             * Sans cette trace, une horloge de NVR décalée prive TOUTES les
             * alertes de leur image de détection sans le moindre symptôme : le
             * coeur tolère jusqu'à cinq minutes d'écart sur la date d'un
             * événement, la fenêtre d'appariement en tolère une et demie. Le
             * diagnostic est alors impossible sans lire ce fichier.
             */
            log::add('dahua', 'debug', __('Aucune capture assez proche pour', __FILE__) . ' ' . $_name
                   . ' : ' . __('la plus voisine est à', __FILE__) . ' ' . $best['delta'] . ' '
                   . __('secondes de la détection (limite', __FILE__) . ' ' . self::MATCH_WINDOW . ').');
            return null;
        }

        $name = self::fileName($_cameraId, self::KIND_DET);
        if (!@copy($best['file'], $_dir . '/' . $name)) {
            log::add('dahua', 'warning', __('Copie de la capture de détection impossible :', __FILE__) . ' ' . $best['file']);
            return null;
        }
        self::makeThumb($_dir . '/' . $name, $_dir . '/' . self::fileName($_cameraId, self::KIND_DET, true));
        return array('file' => $name, 'delta' => $best['delta']);
    }

    /* La capture la plus proche de l'une quelconque des dates fournies. Mesurer
     * contre l'ensemble, et jamais contre la seule première : une caméra qui a
     * détecté deux fois dans la fenêtre de corrélation a deux dates également
     * légitimes. */
    private static function closestSnapshot($_files, $_times) {
        $best = null;
        foreach ($_files as $file) {
            $stamp = self::stampOf(basename($file));
            if ($stamp === null) {
                continue;
            }
            foreach ($_times as $time) {
                $delta = abs($stamp - (int) $time);
                if ($best === null || $delta < $best['delta']) {
                    $best = array('file' => $file, 'delta' => $delta);
                }
            }
        }
        return $best;
    }

    /*
     * Date d'une capture, lue dans son nom. Les deux producteurs (Jeedom et le
     * démon) écrivent gmdate : l'interpréter en UTC est donc obligatoire, sinon
     * l'écart avec la détection vaudrait deux heures en été et aucune image ne
     * serait jamais retenue.
     */
    private static function stampOf($_file) {
        if (!preg_match('/^cam\d+_(\d{8}-\d{6})_[0-9a-f]{8}\.jpg$/D', $_file, $matches)) {
            return null;
        }
        $date = DateTime::createFromFormat('Ymd-His', $matches[1], new DateTimeZone('UTC'));
        return ($date === false) ? null : $date->getTimestamp();
    }

    /* ============================================================ RATTRAPAGE */

    /*
     * Rattrape l'image de détection qui n'existait pas encore à l'ouverture.
     *
     * C'est la pièce sans laquelle tout le reste tient du faux-semblant. La
     * chronologie est implacable :
     *
     *   t+0,0 s  le NVR émet la détection ;
     *   t+0,1 s  le démon forke pour capturer, et pousse l'événement ;
     *   t+0,2 s  Jeedom corrèle et déclenche la règle — open() cherche alors
     *            une capture qui n'est pas encore écrite ;
     *   t+2,0 s  le fils du démon écrit enfin l'image et la signale ici.
     *
     * Les caméras qui ont détecté plus tôt ont bien leur image, elle est sur le
     * disque depuis longtemps. Mais celle qui COMPLÈTE la corrélation, celle qui
     * vient de voir passer quelqu'un, n'en a aucune. D'où ce rattrapage : à
     * chaque capture annoncée par le démon, on regarde si une alerte toute
     * fraîche attend mieux pour cette caméra. « Mieux » est mesuré, pas supposé.
     */
    public static function catchUpDetection($_cameraId, $_url) {
        /*
         * Sortie immédiate dans l'écrasante majorité des cas, et sans toucher au
         * disque. Cette méthode est appelée à CHAQUE capture, donc à chaque
         * détection de chaque caméra, toute la journée — et 99 % du temps il
         * n'existe aucune alerte récente. Sans ce repère, chaque capture
         * paierait un scandir et jusqu'à dix lectures de description, dans la
         * requête même que le démon abandonne au bout de quatre secondes.
         */
        $last = (int) cache::byKey(self::CACHE_LAST)->getValue(0);
        if ($last <= 0 || (time() - $last) > self::CATCHUP_WINDOW) {
            return false;
        }
        if (!preg_match('/file=(cam\d+_\d{8}-\d{6}_[0-9a-f]{8}\.jpg)/', (string) $_url, $matches)) {
            return false;
        }
        $file  = $matches[1];
        $stamp = self::stampOf($file);
        $source = dahua::snapshotDir();
        if ($stamp === null || $source === false || !is_file($source . '/' . $file)) {
            return false;
        }

        $cameraId = (int) $_cameraId;
        $now      = time();

        /*
         * Les alertes de la fenêtre, de la PLUS ANCIENNE à la plus récente.
         *
         * L'ordre compte, et il compte beaucoup : chaque amélioration republie
         * la commande de sa règle. Parcourues du plus récent au plus ancien,
         * deux alertes d'une même règle laisseraient la tuile sur la plus
         * vieille des deux — c'est-à-dire exactement le défaut que ce dossier
         * corrige, remis en place par la porte de service.
         */
        $window = array();
        foreach (self::recent(10) as $meta) {
            if (!isset($meta['time']) || ($now - (int) $meta['time']) > self::CATCHUP_WINDOW) {
                break;
            }
            $window[] = $meta;
        }
        $window = array_reverse($window);

        $improved = false;
        foreach ($window as $meta) {
            foreach (isset($meta['cameras']) && is_array($meta['cameras']) ? $meta['cameras'] : array() as $camera) {
                if ((int) $camera['id'] !== $cameraId) {
                    continue;
                }
                $times = (isset($camera['times']) && is_array($camera['times']) && !empty($camera['times']))
                       ? $camera['times'] : array((int) $camera['at']);
                $delta = null;
                foreach ($times as $time) {
                    $candidate = abs($stamp - (int) $time);
                    if ($delta === null || $candidate < $delta) {
                        $delta = $candidate;
                    }
                }
                if ($delta === null || $delta > self::CATCHUP_WINDOW) {
                    continue;
                }
                // Déjà servie par une capture plus proche : on ne touche à rien.
                if (isset($camera['det_delta']) && (int) $camera['det_delta'] <= $delta) {
                    continue;
                }
                if (self::replaceDetection($meta['id'], $cameraId, $source . '/' . $file, $delta)) {
                    $improved = true;
                }
            }
        }
        return $improved;
    }

    /* Pose la nouvelle image de détection, la déclare et republie, le tout sous
     * le même verrou que les comptes rendus de capture : sans quoi un rattrapage
     * et une capture fraîche arrivant ensemble publieraient chacun leur vision
     * partielle, dans un ordre quelconque. */
    private static function replaceDetection($_id, $_cameraId, $_source, $_delta) {
        $dir = self::path($_id);
        if ($dir === false) {
            return false;
        }
        $name = self::fileName($_cameraId, self::KIND_DET);
        if (!@copy($_source, $dir . '/' . $name)) {
            log::add('dahua', 'warning', __('Rattrapage de la capture de détection impossible :', __FILE__) . ' ' . $_source);
            return false;
        }
        self::makeThumb($dir . '/' . $name, $dir . '/' . self::fileName($_cameraId, self::KIND_DET, true));

        $cameraId = (int) $_cameraId;
        $delta    = (int) $_delta;
        $written = self::updateMeta($_id, function ($_meta) use ($cameraId, $name, $delta) {
            foreach (isset($_meta['cameras']) && is_array($_meta['cameras']) ? $_meta['cameras'] : array() as $index => $camera) {
                if ((int) $camera['id'] === $cameraId) {
                    $_meta['cameras'][$index]['det']       = $name;
                    $_meta['cameras'][$index]['det_delta'] = $delta;
                }
            }
            return $_meta;
        }, self::publisher($_id));
        return ($written !== null);
    }

    /* ============================================================= VIGNETTE */

    /*
     * Une capture pèse de 600 Ko à 1 Mo, sa vignette 25 Ko. Sans elle, une
     * grille de quatre images sur le dashboard coûterait plusieurs méga-octets
     * par rendu.
     *
     * L'échec n'est pas fatal : on se rabat alors sur l'image pleine résolution
     * à l'affichage. Mieux vaut un dashboard lourd qu'un dashboard vide — et la
     * purge en tient compte, elle ne retire jamais une image pleine résolution
     * dont la vignette manque.
     */
    public static function makeThumb($_source, $_target) {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagescale')) {
            return false;
        }
        $raw = @file_get_contents($_source);
        if ($raw === false) {
            return false;
        }
        try {
            $image = @imagecreatefromstring($raw);
            if ($image === false) {
                return false;
            }
            $thumb = @imagescale($image, self::THUMB_WIDTH);
            imagedestroy($image);
            if ($thumb === false) {
                return false;
            }
            $ok = @imagejpeg($thumb, $_target, self::THUMB_QUALITY);
            imagedestroy($thumb);
            return $ok;
        } catch (Throwable $e) {
            log::add('dahua', 'warning', __('Vignette impossible :', __FILE__) . ' ' . $e->getMessage());
            return false;
        }
    }

    /* ====================================================== CAPTURE FRAÎCHE */

    /*
     * Demande au démon une capture fraîche des caméras de l'alerte.
     *
     * C'est LE point sensible de toute cette mécanique. Cet appel part depuis
     * fire(), donc depuis la requête HTTP que le démon est en train d'attendre,
     * et le démon abandonne au bout de quatre secondes en rejouant puis en
     * jetant le lot d'événements. Une capture coûte jusqu'à vingt secondes par
     * caméra : la prendre ici gèlerait la remontée des événements et ferait
     * perdre des détections.
     *
     * D'où ce détour : on n'envoie qu'un ordre, sans attendre la réponse
     * (connexion, écriture, fermeture — de l'ordre de la milliseconde), et c'est
     * le démon qui forke et capture de son côté. Il sait déjà le faire, il le
     * fait à chaque événement.
     *
     * Les caméras pour lesquelles un ordre est réellement parti sont inscrites
     * dans la description. Sans cette marque, la tuile ne pourrait pas
     * distinguer « capture en route » de « aucune capture demandée » — et
     * annoncerait une attente éternelle dès que le réglage est décoché ou que le
     * démon est arrêté.
     */
    public static function requestLiveShots($_id) {
        if ((int) config::byKey('alert_shot', 'dahua', 1) != 1) {
            return false;
        }
        $meta = self::readMeta($_id);
        if ($meta === null || empty($meta['cameras'])) {
            return false;
        }

        $byNvr = array();
        foreach ($meta['cameras'] as $entry) {
            $camera = dahua::byId((int) $entry['id']);
            if (!is_object($camera)) {
                continue;
            }
            $nvrId   = (int) $camera->getConfiguration('nvr_id');
            $channel = (int) $camera->getConfiguration('channel');
            if ($nvrId <= 0 || $channel <= 0) {
                continue;
            }
            $byNvr[$nvrId][] = array('id' => (int) $entry['id'], 'channel' => $channel);
        }
        if (empty($byNvr)) {
            return false;
        }

        /* Un ordre par NVR : rien n'interdit à une règle de corréler deux
         * caméras rattachées à deux enregistreurs différents. */
        $requested = array();
        foreach ($byNvr as $nvrId => $cameras) {
            $sent = dahua::sendToDaemon(array(
                'cmd'     => 'alertshot',
                'alert'   => $_id,
                'nvr_id'  => $nvrId,
                'cameras' => $cameras,
            ), false, 2);
            if ($sent === false) {
                /* Démon arrêté ou socket muet : ces caméras n'auront jamais de
                 * capture fraîche, et il vaut mieux le dire que laisser la tuile
                 * l'attendre. */
                log::add('dahua', 'debug', __('Capture d\'alerte non demandée, démon injoignable :', __FILE__)
                       . ' ' . $_id);
                continue;
            }
            foreach ($cameras as $camera) {
                $requested[] = (int) $camera['id'];
            }
        }
        if (empty($requested)) {
            return false;
        }

        self::updateMeta($_id, function ($_meta) use ($requested) {
            foreach (isset($_meta['cameras']) && is_array($_meta['cameras']) ? $_meta['cameras'] : array() as $index => $camera) {
                if (in_array((int) $camera['id'], $requested, true)) {
                    $_meta['cameras'][$index]['live_requested'] = 1;
                }
            }
            return $_meta;
        }, self::publisher($_id));
        return true;
    }

    /*
     * Enregistre le résultat d'une capture fraîche, postée par le démon après
     * coup. Le fichier, lui, a déjà été écrit par le fils du démon : ici on ne
     * fait que l'inscrire dans la description, et republier sous le verrou.
     */
    public static function noteCapture($_id, $_cameraId, $_ok, $_error = '') {
        $cameraId = (int) $_cameraId;
        $dir = self::path($_id);
        if ($dir === false) {
            // Alerte purgée entre la demande et la réponse : le fichier que le
            // démon vient d'écrire n'a plus de dossier, il n'y a rien à dire.
            return null;
        }
        $name = self::fileName($cameraId, self::KIND_LIVE);
        $exists = $_ok && is_file($dir . '/' . $name);
        /* La cause vient du réseau et finit dans un fichier puis dans une tuile :
         * on la borne. Un message à rallonge ne renseigne personne et gonfle une
         * valeur de commande relue à chaque rafraîchissement. */
        $_error = substr(trim((string) $_error), 0, 200);

        return self::updateMeta($_id, function ($_meta) use ($cameraId, $exists, $_error, $name) {
            if (!isset($_meta['cameras']) || !is_array($_meta['cameras'])) {
                return $_meta;
            }
            foreach ($_meta['cameras'] as $index => $camera) {
                if ((int) $camera['id'] !== $cameraId) {
                    continue;
                }
                if ($exists) {
                    $_meta['cameras'][$index]['live'] = $name;
                    unset($_meta['cameras'][$index]['live_error']);
                } else {
                    $_meta['cameras'][$index]['live_error'] = ($_error != '')
                        ? (string) $_error
                        : __('capture indisponible', __FILE__);
                }
            }
            return $_meta;
        }, self::publisher($_id));
    }

    /* ==================================================== ACTIONS DIFFÉRÉES */

    /*
     * Une règle peut retenir ses actions « au déclenchement » jusqu'à l'arrivée
     * de la capture fraîche (option « Attendre la capture fraîche avant
     * d'agir »). Le cas qui l'a imposée : une notification qui joint « Fichier
     * de l'image » partait AVANT toute image — la capture de détection était
     * trop ancienne ou absente (bouton « Tester »), et la capture fraîche
     * arrivait une seconde trop tard.
     *
     * L'attente est inscrite ICI, dans la description de l'alerte, et non dans
     * le cache ou dans une variable : c'est le seul état qui soit à la fois
     *   - sur le disque, donc survivant à un redémarrage de Jeedom ou d'Apache ;
     *   - protégé par le verrou de l'alerte, celui-là même sous lequel
     *     noteCapture() inscrit la capture ET republie les commandes d'image.
     * Ce second point fait tout : prendre le jeton sous ce verrou garantit que
     * « Fichier de l'image » est déjà à jour quand les actions partent, et
     * qu'un seul des candidats (l'événement de capture, le processus de
     * secours, le cron) peut le prendre. Exactement une exécution.
     *
     * Le même mécanisme porte le délai de confirmation (option
     * « confirm_delay ») : les actions sont retenues N secondes, puis la
     * condition d'armement est réévaluée avant de les jouer. Deux champs de
     * plus suffisent — « confirm », l'heure avant laquelle personne ne prend
     * le jeton sauf retour au repos forcé, et « live », qui dit si la capture
     * fraîche est attendue elle aussi. Les deux attentes se superposent :
     * l'échéance est la plus lointaine des deux.
     *
     * Forme dans meta.json :
     *   actions: {state: pending|done, since, deadline, rule_id,
     *             confirm, live, fired, fired_before, done_at, reason}
     * since, deadline et confirm sont des horodatages à la microseconde : le
     * délai réel est journalisé, et « 1,0 s » ou « 10,0 s » ne se distinguent
     * pas à la seconde près d'un arrondi malheureux. fired et fired_before
     * servent à rendre la temporisation si la confirmation échoue.
     *
     * Une alerte dont la confirmation a échoué garde son dossier — c'est de
     * l'historique — et porte « cancelled », la raison de l'annulation.
     */
    const ACTIONS_PENDING = 'pending';
    const ACTIONS_DONE    = 'done';

    /*
     * Où en sont les captures fraîches de l'alerte :
     *   'live'     — au moins une capture fraîche est inscrite. C'est le
     *                signal attendu : publish() préfère toute capture fraîche à
     *                toute image de détection, la meilleure image publiée est
     *                donc déjà une photo de l'instant de l'alerte. Attendre les
     *                autres caméras retarderait l'alarme du temps de la plus
     *                lente — jusqu'à dix secondes pour une caméra tombée, qui
     *                ne répondra jamais — pour une notification qui ne joint de
     *                toute façon qu'une image ;
     *   'returned' — toutes les captures demandées sont revenues, toutes en
     *                échec : plus rien à attendre, autant partir tout de suite ;
     *   'none'     — aucune capture n'a été demandée (réglage décoché, démon
     *                injoignable) : il n'y a jamais rien eu à attendre ;
     *   ''         — des captures sont encore en route.
     */
    public static function liveReadiness($_meta) {
        $requested = 0;
        $returned  = 0;
        foreach (isset($_meta['cameras']) && is_array($_meta['cameras']) ? $_meta['cameras'] : array() as $camera) {
            if (isset($camera[self::KIND_LIVE]) && $camera[self::KIND_LIVE] != '') {
                return 'live';
            }
            if (empty($camera['live_requested'])) {
                continue;
            }
            $requested++;
            if (isset($camera['live_error'])) {
                $returned++;
            }
        }
        if ($requested == 0) {
            return 'none';
        }
        return ($returned >= $requested) ? 'returned' : '';
    }

    /*
     * Inscrit l'attente des actions de la règle, sous le verrou de l'alerte.
     *
     * Retourne 'pending' si l'attente est posée. Sinon, la raison de ne pas
     * attendre : l'état de liveReadiness() quand la capture est déjà là (elle
     * peut arriver entre l'ordre de capture et cet appel : c'est ici, sous le
     * verrou, que la course est tranchée), ou '' si la description est
     * illisible. Dans tous ces cas l'appelant joue les actions tout de suite :
     * une attente qu'on ne sait pas inscrire serait une attente que rien ne
     * viendrait jamais lever.
     */
    public static function deferActions($_id, $_ruleId, $_since, $_deadline, $_extra = array()) {
        $outcome = '';
        /* Un délai de confirmation s'inscrit toujours : que la photo soit déjà
         * là ne dispense pas de réévaluer la condition d'armement. */
        $confirm = isset($_extra['confirm']) ? (float) $_extra['confirm'] : 0.0;
        self::updateMeta($_id, function ($_meta) use (&$outcome, $_ruleId, $_since, $_deadline, $_extra, $confirm) {
            $ready = self::liveReadiness($_meta);
            if ($ready !== '' && $confirm <= 0) {
                $outcome = $ready;
                return null;                      // rien à écrire
            }
            $_meta['actions'] = array_merge(array(
                'state'    => self::ACTIONS_PENDING,
                'rule_id'  => (int) $_ruleId,
                'since'    => (float) $_since,
                'deadline' => (float) $_deadline,
            ), array_intersect_key($_extra, array_flip(array('confirm', 'live', 'fired', 'fired_before'))));
            $outcome = self::ACTIONS_PENDING;
            return $_meta;
        });
        /* updateMeta() rend null quand l'écriture a échoué : l'attente n'est
         * alors pas inscrite, et la déclarer posée la rendrait éternelle. */
        if ($outcome === self::ACTIONS_PENDING) {
            $meta = self::readMeta($_id);
            if (!isset($meta['actions']['state']) || $meta['actions']['state'] !== self::ACTIONS_PENDING) {
                return '';
            }
        }
        return $outcome;
    }

    /*
     * L'attente en cours d'une alerte, ou null s'il n'y en a pas (ou plus).
     * Lecture sans verrou, réservée à la surveillance : la décision, elle, se
     * prend toujours dans claimActions(), sous le verrou.
     */
    public static function pendingActions($_id) {
        $meta = self::readMeta($_id);
        if (!isset($meta['actions']['state']) || $meta['actions']['state'] !== self::ACTIONS_PENDING) {
            return null;
        }
        return array(
            'rule_id'  => isset($meta['actions']['rule_id']) ? (int) $meta['actions']['rule_id'] : 0,
            'since'    => isset($meta['actions']['since']) ? (float) $meta['actions']['since'] : 0.0,
            'deadline' => isset($meta['actions']['deadline']) ? (float) $meta['actions']['deadline'] : 0.0,
            'confirm'  => self::confirmOf($meta['actions']),
            'live'     => self::waitsLive($meta['actions']),
            'ready'    => self::liveReadiness($meta),
        );
    }

    /* Heure avant laquelle le jeton n'est pas donné (délai de confirmation),
     * 0 s'il n'y en a pas. */
    private static function confirmOf($_actions) {
        return isset($_actions['confirm']) ? (float) $_actions['confirm'] : 0.0;
    }

    /* L'attente porte-t-elle aussi sur la capture fraîche ? Une attente
     * inscrite avant le délai de confirmation n'a pas ce champ : elle
     * n'attendait que la photo. */
    private static function waitsLive($_actions) {
        return !isset($_actions['live']) || (int) $_actions['live'] == 1;
    }

    /*
     * Prend le jeton d'exécution des actions en attente. Un seul appelant
     * l'obtient, quel que soit le nombre de candidats simultanés : tout se
     * passe sous le verrou exclusif de l'alerte, et le jeton pris est écrit
     * avant que le verrou ne soit rendu.
     *
     * Sans $_force, le jeton n'est donné qu'une fois le délai de confirmation
     * écoulé, et seulement si la capture est là (ou n'est pas attendue) ou si
     * l'échéance est passée ; avec $_force (réinitialisation, désactivation
     * de la règle, rattrapage), il est donné dans tous les cas.
     *
     * Retourne null, ou {rule_id, since, deadline, confirm, fired,
     * fired_before, reason} avec pour raison 'live' / 'returned' / 'none' (la
     * capture est là, ou plus rien à attendre), 'confirmed' (délai de
     * confirmation écoulé, pas de capture attendue), 'timeout' (échéance
     * atteinte sans capture) ou 'forced'.
     */
    public static function claimActions($_id, $_force, $_now) {
        $claim = null;
        $written = self::updateMeta($_id, function ($_meta) use (&$claim, $_force, $_now) {
            if (!isset($_meta['actions']['state']) || $_meta['actions']['state'] !== self::ACTIONS_PENDING) {
                return null;                      // déjà joué, ou jamais différé
            }
            $deadline = isset($_meta['actions']['deadline']) ? (float) $_meta['actions']['deadline'] : 0.0;
            $confirm  = self::confirmOf($_meta['actions']);
            $ready = self::waitsLive($_meta['actions']) ? self::liveReadiness($_meta) : 'confirmed';
            if ($_now < $confirm) {
                /* Délai de confirmation en cours : seul un retour au repos
                 * forcé passe, et il ne confirme rien. */
                if (!$_force) {
                    return null;
                }
                $reason = 'forced';
            } elseif ($ready !== '') {
                $reason = $ready;
            } elseif ($_now >= $deadline) {
                $reason = 'timeout';
            } elseif ($_force) {
                $reason = 'forced';
            } else {
                return null;                      // ni capture, ni échéance
            }
            $_meta['actions']['state']   = self::ACTIONS_DONE;
            $_meta['actions']['done_at'] = (float) $_now;
            $_meta['actions']['reason']  = $reason;
            $claim = array(
                'rule_id'  => isset($_meta['actions']['rule_id']) ? (int) $_meta['actions']['rule_id']
                                                                   : (int) $_meta['rule_id'],
                'since'    => isset($_meta['actions']['since']) ? (float) $_meta['actions']['since'] : (float) $_now,
                'deadline' => $deadline,
                'confirm'  => $confirm,
                'fired'    => isset($_meta['actions']['fired']) ? (int) $_meta['actions']['fired'] : 0,
                'fired_before' => isset($_meta['actions']['fired_before']) ? (int) $_meta['actions']['fired_before'] : 0,
                'reason'   => $reason,
            );
            return $_meta;
        });
        /*
         * Jeton pris en mémoire mais pas écrit : on ne joue rien. Jouer ici
         * laisserait l'attente « pending » sur le disque, et le prochain
         * candidat jouerait les actions une seconde fois. Le rattrapage réessaiera.
         */
        if ($claim !== null && $written === null) {
            log::add('dahua', 'error', __('Actions différées non jouées : description d\'alerte non enregistrée', __FILE__)
                   . ' ' . $_id);
            return null;
        }
        return $claim;
    }

    /*
     * Les alertes récentes dont les actions attendent encore, pour le
     * rattrapage du cron. Seuls les dossiers postérieurs à $_since sont lus :
     * leur date est dans leur nom, inutile d'ouvrir les centaines d'autres.
     * Rend {identifiant => échéance}.
     */
    public static function pendingSince($_since) {
        $base = self::baseDir();
        if ($base === false) {
            return array();
        }
        $pending = array();
        foreach (self::listDirs($base) as $id) {
            if (self::timeOf($id) < (int) $_since) {
                continue;
            }
            $info = self::pendingActions($id);
            if ($info !== null) {
                $pending[$id] = $info['deadline'];
            }
        }
        ksort($pending);                          // la plus ancienne d'abord
        return $pending;
    }

    /*
     * Note l'annulation d'une alerte dont la confirmation a échoué. Le dossier
     * reste : l'historique doit montrer que les caméras ont vu quelqu'un, et
     * pourquoi personne n'a été prévenu. $_republish ne republie la tuile que
     * si cette alerte est toujours la dernière de la règle — une alerte plus
     * récente ne doit pas être recouverte par celle-ci.
     */
    public static function markCancelled($_id, $_reason, $_republish) {
        return self::updateMeta($_id, function ($_meta) use ($_reason) {
            $_meta['cancelled'] = (string) $_reason;
            return $_meta;
        }, $_republish ? self::publisher($_id) : null) !== null;
    }

    /*
     * Trace de l'analyse par le classifieur de vision dans le meta.json de
     * l'alerte. Permet un audit après coup — quelle catégorie, quelle
     * confiance, combien de temps — et une consultation depuis l'historique.
     * Rien de la décision prise par la règle n'y est écrit : seul le brut.
     */
    public static function setAi($_id, $_result) {
        if (!is_array($_result)) {
            return false;
        }
        $trace = array(
            'ok'          => !empty($_result['ok']),
            'categorie'   => isset($_result['categorie']) ? (string) $_result['categorie'] : '',
            'confiance'   => isset($_result['confiance']) ? (int) $_result['confiance'] : 0,
            'description' => isset($_result['description']) ? (string) $_result['description'] : '',
            'indices'     => isset($_result['indices']) && is_array($_result['indices']) ? array_values($_result['indices']) : array(),
            'erreur'      => isset($_result['erreur']) ? (string) $_result['erreur'] : '',
            'modele'      => isset($_result['modele']) ? (string) $_result['modele'] : '',
            'duree_ms'    => isset($_result['duree_ms']) ? (int) $_result['duree_ms'] : 0,
            'at'          => time(),
        );
        return self::updateMeta($_id, function ($_meta) use ($trace) {
            $_meta['ai'] = $trace;
            return $_meta;
        }) !== null;
    }

    /* =============================================================== WIDGET */

    /*
     * La valeur de la commande « Images de l'alerte ». Un seul objet porte tout :
     * une commande par image demanderait autant de rafraîchissements, qui
     * pourraient se croiser et montrer une grille mêlant deux alertes.
     *
     * Groupé PAR CAMÉRA, et non en une liste d'images à plat. Une liste plate
     * perd la seule chose qu'on veut absolument dire : une caméra qui n'a pas
     * répondu disparaîtrait de la tuile, purement et simplement. Or « SUD n'a
     * pas répondu » est une information de levé de doute au moins aussi
     * précieuse qu'une image — c'est peut-être justement la caméra qu'on a
     * coupée.
     *
     * Les clés sont courtes parce que cette valeur traverse addslashes() côté
     * coeur avant d'atterrir dans le gabarit, et qu'elle est relue par un
     * JSON.parse dans le navigateur :
     *   a  identifiant de l'alerte    t   date du déclenchement (epoch)
     *   d  libellé du déclenchement   p   nombre de captures encore attendues
     *   hd images pleine résolution encore disponibles
     *   c  caméras : n nom, i images {k nature, f fichier},
     *      e cause d'échec, w capture demandée et pas encore revenue
     *
     * La tuile déduit l'expiration d'une attente de « t » : passé une minute,
     * « w » ne veut plus dire « en route » mais « jamais revenue ».
     */
    public static function widgetValue($_meta) {
        if (!is_array($_meta)) {
            return '';
        }
        $cameras = array();
        $pending = 0;
        foreach (isset($_meta['cameras']) && is_array($_meta['cameras']) ? $_meta['cameras'] : array() as $camera) {
            $entry = array(
                'n' => isset($camera['name']) ? (string) $camera['name'] : '',
                'i' => array(),
            );
            foreach (array(self::KIND_DET, self::KIND_LIVE) as $kind) {
                if (isset($camera[$kind]) && $camera[$kind] != '') {
                    $entry['i'][] = array('k' => $kind, 'f' => (string) $camera[$kind]);
                }
            }
            if (isset($camera['live_error'])) {
                $entry['e'] = (string) $camera['live_error'];
            } elseif (!isset($camera[self::KIND_LIVE]) && !empty($camera['live_requested'])) {
                /*
                 * « En attente » n'est affirmé que si un ordre est réellement
                 * parti. Le déduire d'une simple absence ferait annoncer une
                 * capture en route alors qu'aucune n'a été demandée — réglage
                 * décoché, démon arrêté — et la tuile l'attendrait pour
                 * toujours.
                 */
                $entry['w'] = 1;
                $pending++;
            }
            $cameras[] = $entry;
        }
        return json_encode(array(
            'a'  => isset($_meta['id']) ? $_meta['id'] : '',
            't'  => isset($_meta['time']) ? (int) $_meta['time'] : time(),
            /* Une alerte annulée le dit dans son libellé, le seul texte que la
             * tuile affiche : des images sans « annulée » passeraient pour une
             * intrusion dont personne n'a été averti. */
            'd'  => (isset($_meta['detail']) ? (string) $_meta['detail'] : '')
                  . (!empty($_meta['cancelled']) ? ' — ' . __('annulée :', __FILE__) . ' ' . $_meta['cancelled'] : ''),
            /* « p » n\'est lu par aucune interface : la tuile préfère le « w »
             * de chaque caméra, pour pouvoir dire LAQUELLE manque plutôt que
             * combien. Il est conservé parce qu\'il est le seul endroit où le
             * total est exact, et qu\'une notification ou un scénario peut s\'en
             * servir sans avoir à parcourir les caméras. */
            'p'  => $pending,
            // Nom distinct du « f » de chaque image, qui est un nom de fichier :
            // deux sens pour une même clé sont une erreur qui attend son heure.
            'hd' => isset($_meta['full']) ? (int) $_meta['full'] : 1,
            'c'  => $cameras,
        ), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    /*
     * Pousse l'état courant d'une alerte vers les commandes de sa règle.
     *
     * « image » (au singulier) est conservée et continue de porter une URL
     * unique : des scénarios existants s'en servent pour joindre une image à une
     * notification, et la casser serait une régression silencieuse. On lui donne
     * la meilleure image disponible — une capture fraîche si l'une est arrivée,
     * à défaut une image de détection.
     */
    public static function publish($_rule, $_id, $_meta = null) {
        $meta = ($_meta !== null) ? $_meta : self::readMeta($_id);
        if (!is_array($meta)) {
            return false;
        }
        if (!is_object($_rule->getCmd('info', 'images'))) {
            /*
             * checkAndUpdateCmd() sur une commande absente retourne false sans
             * rien journaliser. Sans cette ligne, une règle créée avant cette
             * version écrirait ses dossiers sur le disque et n'en montrerait
             * jamais rien, sans que personne puisse comprendre pourquoi.
             */
            log::add('dahua', 'warning', $_rule->getHumanName() . ' '
                   . __('n\'a pas la commande « Images de l\'alerte » : enregistrez la règle pour la créer.', __FILE__));
        }
        $_rule->checkAndUpdateCmd('images', self::widgetValue($meta));

        /*
         * Deux passes, et pas une seule : chercher « live puis det » caméra par
         * caméra retiendrait l'image de détection de la première caméra alors
         * qu'une capture fraîche existe sur la seconde. Ce n'est pas ce que
         * promet le commentaire ci-dessus.
         */
        $best = '';
        $bestFile = '';
        foreach (array(self::KIND_LIVE, self::KIND_DET) as $kind) {
            if ($best != '') {
                break;
            }
            foreach (isset($meta['cameras']) && is_array($meta['cameras']) ? $meta['cameras'] : array() as $camera) {
                if (isset($camera[$kind]) && $camera[$kind] != '') {
                    $best = self::url($_id, $camera[$kind]);
                    $bestFile = self::filePath($_id, $camera[$kind]);
                    break;
                }
            }
        }
        /*
         * Écrite même vide, et c'est délibéré. La laisser en place quand la
         * nouvelle alerte n'a aucune image ferait joindre à une notification
         * l'image du déclenchement PRÉCÉDENT — le défaut d'origine, en plus
         * sournois puisqu'il ne surviendrait plus qu'une fois sur dix.
         */
        $_rule->checkAndUpdateCmd('image', $best);
        /* Même règle : écrite même vide, pour ne jamais joindre à une
         * notification le fichier du déclenchement précédent. */
        $_rule->checkAndUpdateCmd('image_file', $bestFile);
        /* Même image en adresse complète, lisible avec la clé d'images. Bâtie
         * sur le fichier retenu ci-dessus — pleine résolution ou vignette —
         * pour ne jamais désigner une image purgée. */
        $_rule->checkAndUpdateCmd('image_url', ($bestFile != '') ? self::localUrl($_id, basename($bestFile)) : '');
        return true;
    }

    /*
     * Chemin sur le disque d'une image d'alerte, pour la joindre à une
     * notification. La pleine résolution si elle est encore là, sa vignette
     * sinon : une republication tardive — la mise à jour du plugin, la purge
     * qui prévient la tuile — ne doit pas donner un chemin vers un fichier
     * effacé. '' si ni l'une ni l'autre n'existe.
     */
    public static function filePath($_id, $_file) {
        $dir = self::path($_id);
        if ($dir === false || !self::isValidFile($_file)) {
            return '';
        }
        if (is_file($dir . '/' . $_file)) {
            return $dir . '/' . $_file;
        }
        $thumb = substr($_file, 0, -4) . '_t.jpg';
        return is_file($dir . '/' . $thumb) ? $dir . '/' . $thumb : '';
    }

    /* URL servie par le passe-plat authentifié. Le dossier data/ est interdit
     * par Apache, et doit le rester : ce sont les images d'une caméra de
     * surveillance. */
    public static function url($_id, $_file) {
        return 'plugins/dahua/core/php/snapshot.php?alert=' . rawurlencode($_id)
             . '&file=' . rawurlencode($_file);
    }

    /* Adresse complète sur le réseau local, pour un client sans session qui
     * présente la clé d'images. L'accès interne configuré dans Jeedom, à défaut
     * la boucle locale : le client visé tourne sur Jeedom lui-même. */
    public static function localUrl($_id, $_file) {
        $base = rtrim((string) network::getNetworkAccess('internal'), '/');
        if ($base == '') {
            $base = 'http://127.0.0.1';
        }
        return $base . '/' . self::url($_id, $_file);
    }

    /* ============================================================== LECTURE */

    /*
     * Les alertes les plus récentes, description lue. Le tri vient du nom du
     * dossier, qui commence par la date en UTC : aucun appel à filemtime, donc
     * aucune surprise si un déploiement a touché les fichiers.
     */
    public static function recent($_limit = 100, $_ruleId = 0) {
        $base = self::baseDir();
        if ($base === false) {
            return array();
        }
        $dirs = self::listDirs($base);
        if (empty($dirs)) {
            return array();
        }
        rsort($dirs);

        $limit  = max(1, (int) $_limit);
        $ruleId = (int) $_ruleId;
        $alerts = array();
        foreach ($dirs as $id) {
            if ($ruleId > 0 && strpos($id, '_r' . $ruleId . '_') === false) {
                continue;
            }
            $meta = self::readMeta($id);
            if ($meta === null) {
                continue;
            }
            if ($ruleId > 0 && (int) (isset($meta['rule_id']) ? $meta['rule_id'] : 0) !== $ruleId) {
                continue;
            }
            $alerts[] = $meta;
            if (count($alerts) >= $limit) {
                break;
            }
        }
        return $alerts;
    }

    /* Les seuls noms retenus sont ceux que cette classe sait avoir écrits : un
     * dossier déposé à la main ne sera ni listé, ni purgé. */
    private static function listDirs($_base) {
        $entries = @scandir($_base);
        if ($entries === false) {
            return array();
        }
        $dirs = array();
        foreach ($entries as $entry) {
            if (self::isValidId($entry) && is_dir($_base . '/' . $entry)) {
                $dirs[] = $entry;
            }
        }
        return $dirs;
    }

    /* ================================================================ PURGE */

    /*
     * Rétention à deux étages, et c'est ce qui rend la chose tenable : les
     * vignettes coûtent 25 Ko, les images pleine résolution de 600 Ko à 1 Mo.
     * Garder trois cents alertes en vignettes tient dans quelques dizaines de
     * méga-octets ; garder les trente dernières en pleine résolution en coûte
     * une centaine. Tout garder en pleine résolution demanderait des gigaoctets.
     *
     * Le budget est GLOBAL, toutes règles confondues : dix règles se partagent
     * les trois cents dossiers, elles ne les multiplient pas.
     *
     * Appelée par le cron minute, et non à l'écriture. La purge des captures
     * courantes, elle, ne se déclenche qu'à l'écriture, si bien qu'une caméra
     * devenue muette y laisse ses fichiers indéfiniment. On ne refait pas cette
     * erreur ici.
     *
     * L'âge maximal (max_age_days) est appliqué dans la même boucle : la date
     * se lit dans le nom du dossier, le coût est nul, et il n'y a aucune
     * raison de laisser une alerte périmée survivre une heure de plus.
     */
    public static function purge() {
        $base = self::baseDir();
        if ($base === false) {
            return;
        }
        /*
         * Zéro et les saisies illisibles retombent sur la valeur par défaut, et
         * surtout pas sur le minimum. Dans ce même formulaire, l'intervalle de
         * surveillance des caméras documente « 0 désactive » : un utilisateur
         * qui écrit 0 dans « Alertes conservées » attend « sans limite », pas
         * « n'en garder qu'une ». Ramener à 1 détruirait tout son historique à
         * la minute suivante, sans confirmation et sans trace.
         *
         * Zéro reste en revanche légitime pour la pleine résolution : « ne garder
         * que des vignettes » est un réglage sensé sur une installation à
         * l'étroit, et le seul moyen de diviser la place occupée par vingt-cinq.
         */
        $keep     = self::setting('alert_keep', self::DEFAULT_KEEP, 1);
        $keepFull = min($keep, self::setting('alert_keep_full', self::DEFAULT_KEEP_FULL, 0));
        /* Zéro désactive la garantie par âge : seul le rang compte alors. */
        $fullDays = self::setting('alert_keep_full_days', self::DEFAULT_KEEP_FULL_DAYS, 0);
        $fullSince = time() - $fullDays * 86400;
        $oldest    = self::oldestKept();

        $dirs = self::listDirs($base);
        if (empty($dirs)) {
            return;
        }
        rsort($dirs);                                 // la plus récente d'abord

        /*
         * La dernière alerte de chaque règle est celle que montre sa tuile, et
         * elle échappe aux deux quotas.
         *
         * Le rang est global, or les règles ne déclenchent pas au même rythme :
         * une règle bavarde — NORD, trente alertes par jour — poussait en
         * quelques heures la dernière alerte d'une règle rare au-delà du
         * trentième rang. Ses pleines résolutions étaient effacées alors que la
         * tuile, jamais republiée, annonçait toujours l'agrandissement : la
         * vignette s'affichait, le clic répondait « Image indisponible ». Une
         * fois sur deux selon l'heure, ce qui le rendait difficile à attribuer.
         *
         * Le surcoût est borné : une alerte par règle. Seul « 0 » pour la pleine
         * résolution s'applique aussi à elle, puisque c'est un choix explicite
         * de ne garder que des vignettes ; la tuile est alors republiée, pour
         * qu'elle cesse de proposer un plein écran qui ne donnerait rien.
         *
         * La garantie par âge s'ajoute au rang, elle ne le remplace pas : une
         * alerte garde sa pleine résolution si elle est parmi les plus
         * récentes en nombre OU en temps. Le disque reste borné par le nombre
         * total de dossiers, que l'âge ne protège pas. Et « 0 » pour la pleine
         * résolution l'emporte ici aussi, pour la même raison.
         */
        $shown = array();                             // règle => dernière alerte déjà vue
        $rank  = 0;
        foreach ($dirs as $id) {
            $rank++;
            $ruleId = self::ruleIdOf($id);
            $latest = !isset($shown[$ruleId]) && self::ruleExists($ruleId);
            $shown[$ruleId] = true;                   // un seul appel par règle
            if (($rank > $keep || self::timeOf($id) < $oldest) && !$latest) {
                self::removeDir($base . '/' . $id);
                continue;
            }
            if ($keepFull > 0 && ($rank <= $keepFull || $latest || self::timeOf($id) >= $fullSince)) {
                continue;
            }
            self::stripFullImages($base . '/' . $id, $id, $latest);
        }
    }

    /*
     * Date en deçà de laquelle rien n'est gardé, selon l'âge maximal réglé ; 0
     * quand il est désactivé. Même règle que les autres réglages : une saisie
     * hors bornes revient au défaut, et seul un 0 explicite désactive.
     */
    public static function oldestKept() {
        /* Ici 0 désactive : une saisie illisible, que (int) ramènerait à 0,
         * doit retomber sur le défaut et non couper la purge sans le dire. */
        $raw = trim((string) config::byKey('max_age_days', 'dahua', self::DEFAULT_MAX_AGE_DAYS));
        $days = ctype_digit($raw) ? self::setting('max_age_days', self::DEFAULT_MAX_AGE_DAYS, 0)
                                  : self::DEFAULT_MAX_AGE_DAYS;
        return ($days > 0) ? time() - $days * 86400 : 0;
    }

    /*
     * Purge par âge des captures courantes (data/snapshots).
     *
     * Le nombre y est déjà borné à l'écriture (snapshot_keep par caméra), mais
     * seulement à l'écriture : une caméra muette, ou supprimée, y laisse ses
     * fichiers indéfiniment. Appelée par le cron horaire — une capture de plus
     * ou de moins pendant une heure ne change rien, et le dossier compte
     * plusieurs centaines de fichiers.
     *
     * Échappent à l'âge, pour chaque caméra existante :
     *   - sa capture la plus récente, celle que montre « Dernière image » : une
     *     caméra calme depuis une semaine n'a pas à perdre sa vignette ;
     *   - le fichier que désignent « Fichier de l'image » et « Dernière image »,
     *     s'ils diffèrent : un scénario qui le joint à une notification ne doit
     *     pas tomber sur un fichier effacé.
     * Ceux d'une caméra supprimée n'ont plus de commande à servir et suivent
     * la règle commune.
     *
     * La date se lit dans le nom (cam<id>_<Ymd-His en UTC>_<jeton>.jpg), comme
     * pour les alertes : un déploiement ou une copie qui toucherait les dates
     * des fichiers ne doit rien changer. Un nom qui n'a pas cette forme n'a pas
     * été écrit par le plugin, et n'est pas touché.
     */
    public static function purgeSnapshots() {
        $oldest = self::oldestKept();
        if ($oldest <= 0) {
            return 0;
        }
        $dir = dahua::snapshotDir();
        if ($dir === false) {
            return 0;
        }
        $files = glob($dir . '/cam*_*.jpg');
        if (empty($files)) {
            return 0;
        }
        /* Tri par nom : par caméra, puis par date au sein d'une caméra. */
        sort($files);

        $kept = array();
        $cameras = array();
        foreach (dahua::byTypeAndSearchConfiguration('dahua', array('type' => dahua::TYPE_CAMERA)) as $camera) {
            $cameras[(int) $camera->getId()] = true;
            foreach (array('snapshot_file', 'snapshot') as $logicalId) {
                $cmd = $camera->getCmd('info', $logicalId);
                $value = is_object($cmd) ? (string) $cmd->execCmd() : '';
                /* « Dernière image » est une adresse (…snapshot.php?file=…),
                 * « Fichier de l'image » un chemin : on n'en garde que le nom. */
                if (preg_match('/[?&]file=([^&]+)/', $value, $match) === 1) {
                    $value = rawurldecode($match[1]);
                }
                if ($value != '') {
                    $kept[basename($value)] = true;
                }
            }
        }
        $newest = array();
        foreach ($files as $file) {
            if (preg_match('/^cam(\d+)_/', basename($file), $match) === 1 && isset($cameras[(int) $match[1]])) {
                $newest[(int) $match[1]] = basename($file);   // tri croissant : la dernière gagne
            }
        }
        foreach ($newest as $name) {
            $kept[$name] = true;
        }

        $removed = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (isset($kept[$name])
             || preg_match('/^cam\d+_(\d{8}-\d{6})_[0-9a-f]{8}\.jpg$/D', $name, $match) !== 1) {
                continue;
            }
            $date = DateTime::createFromFormat('!Ymd-His', $match[1], new DateTimeZone('UTC'));
            if ($date === false || $date->getTimestamp() >= $oldest) {
                continue;
            }
            if (@unlink($file)) {
                $removed++;
            }
        }
        if ($removed > 0) {
            log::add('dahua', 'debug', $removed . ' ' . __('captures de plus de', __FILE__) . ' '
                   . round((time() - $oldest) / 86400) . ' ' . __('jours supprimées', __FILE__));
        }
        return $removed;
    }

    /* La règle d'un dossier se lit dans son nom (…_r<id>_…), sans ouvrir la
     * description : la purge tourne chaque minute sur des centaines de
     * dossiers. */
    private static function ruleIdOf($_id) {
        return preg_match('/_r(\d+)_/', $_id, $match) === 1 ? (int) $match[1] : 0;
    }

    /* La date de l'alerte, lue elle aussi dans son nom : il commence par la
     * date du déclenchement en UTC (voir newId()). */
    private static function timeOf($_id) {
        $date = DateTime::createFromFormat('!Ymd-His', substr($_id, 0, 15), new DateTimeZone('UTC'));
        return ($date === false) ? 0 : $date->getTimestamp();
    }

    /* Une règle supprimée n'a plus de tuile à servir : sa dernière alerte
     * rentre dans le rang commun, sans quoi elle ne serait jamais purgée. */
    private static function ruleExists($_ruleId) {
        return $_ruleId > 0 && is_object(self::ruleOf(array('rule_id' => $_ruleId)));
    }

    /*
     * Un réglage hors bornes retombe sur son défaut, jamais sur le minimum.
     * (int) '' vaut 0, (int) 'abc' vaut 0, et (int) '  ' vaut 0 sans que
     * config::byKey ne voie une chaîne vide : trois façons de détruire un
     * historique si l'on se contentait d'un max().
     */
    private static function setting($_key, $_default, $_min) {
        $value = (int) config::byKey($_key, 'dahua', $_default);
        if ($value < $_min || $value > self::MAX_KEEP) {
            return $_default;
        }
        return $value;
    }

    /*
     * Ne retire que les images pleine résolution, en laissant les vignettes et
     * la description : l'alerte reste consultable et datée, elle perd seulement
     * le plein écran. Le drapeau « full » le dit à l'interface, qui cesse alors
     * de proposer un agrandissement qui ne donnerait rien.
     */
    private static function stripFullImages($_dir, $_id, $_shown = false) {
        $files = glob($_dir . '/cam*_*.jpg');
        if ($files === false) {
            return;
        }
        $removed = false;
        foreach ($files as $file) {
            $name = basename($file);
            // Les vignettes portent le suffixe _t : ce sont elles qu'on garde.
            if (substr($name, -6) === '_t.jpg') {
                continue;
            }
            /*
             * Pas de vignette pour cette image — GD absent, image illisible :
             * la supprimer laisserait la description désigner un fichier qui
             * n'existe plus nulle part, et la tuile afficherait un cadre vide.
             * Mieux vaut garder une image lourde que n'en avoir aucune.
             */
            $thumb = substr($name, 0, -4) . '_t.jpg';
            if (!is_file($_dir . '/' . $thumb)) {
                continue;
            }
            if (@unlink($file)) {
                $removed = true;
            }
        }
        if ($removed) {
            /* Le drapeau n'a d'effet sur la tuile que s'il lui parvient : la
             * description seule ne suffit pas, la tuile lit la commande. */
            self::updateMeta($_id, function ($_meta) {
                $_meta['full'] = 0;
                return $_meta;
            }, $_shown ? self::publisher($_id) : null);
        }
    }

    /*
     * Suppression bornée au contenu que cette classe produit. Pas de parcours
     * récursif : un dossier d'alerte n'a pas de sous-dossier, et un effacement
     * récursif lancé sur un chemin mal formé est le genre d'erreur qu'on ne
     * commet qu'une fois.
     */
    private static function removeDir($_dir) {
        $files = glob($_dir . '/cam*.jpg');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        /* Les images d'abord, la description ensuite, le verrou en dernier : si
         * la suppression est interrompue en cours de route, ce qui subsiste est
         * un dossier sans images mais encore descriptible, que la purge suivante
         * reprendra proprement. L'ordre inverse laisserait des images qu'aucune
         * description ne réclame plus. */
        foreach (array('meta.json', 'meta.json.tmp', '.lock') as $name) {
            @unlink($_dir . '/' . $name);
        }
        if (!@rmdir($_dir)) {
            log::add('dahua', 'debug', __('Dossier d\'alerte non supprimé :', __FILE__) . ' ' . $_dir);
        }
    }
}
