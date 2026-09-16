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
 * Moteur de corrélation des détections.
 *
 * Une règle rapproche plusieurs détections survenues dans une même fenêtre de
 * temps et déclenche alors des actions Jeedom. Le but est d'écarter les faux
 * positifs : une ombre fait bouger des pixels, elle ne franchit pas une ligne.
 *
 * Le moteur raisonne exclusivement sur la DATE D'ARRIVÉE des événements, jamais
 * sur l'état des commandes binaires. C'est la seule approche solide :
 *   - une impulsion retombe à 0 au bout de pulse_duration secondes, donc une
 *     fenêtre plus longue ne serait jamais couverte ;
 *   - un « Start » dont le « Stop » s'est perdu laisse la commande à 1
 *     indéfiniment, et toute détection ultérieure paraîtrait corrélée ;
 *   - le coeur n'appelle pas scenario::check() quand une commande reçoit deux
 *     fois la même valeur : la seconde détection passerait inaperçue.
 *
 * L'état vit dans le cache de Jeedom : chaque appel du callback est un process
 * PHP neuf, une variable statique ne survivrait pas d'un événement au suivant.
 */
class dahuaRule {

    const CACHE_PREFIX = 'dahua::rule::';

    /* Toutes les conditions, ou seulement N d'entre elles. */
    const MODE_ALL   = 'all';
    const MODE_COUNT = 'count';

    /* « N'importe quelle caméra » / « n'importe quelle détection ». */
    const ANY = 'any';

    /*
     * Portée des caméras dans une corrélation :
     *   ANY      — peu importe d'où viennent les détections ;
     *   SAME     — toutes sur un même canal (double détection locale) ;
     *   DISTINCT — réparties sur au moins deux canaux (corroboration).
     */
    const SCOPE_ANY      = 'any';
    const SCOPE_SAME     = 'same';
    const SCOPE_DISTINCT = 'distinct';

    const DEFAULT_WINDOW   = 15;
    const DEFAULT_COOLDOWN = 30;
    const DEFAULT_HOLD     = 10;
    const DEFAULT_THRESHOLD = 2;

    /*
     * Bornes de sécurité. Une fenêtre démesurée rapprocherait des détections
     * sans rapport ; une liste de détections non bornée grossirait sans fin sur
     * une caméra bavarde.
     */
    const MAX_WINDOW = 3600;
    const MAX_HITS   = 60;

    /* Une seule lecture des règles et de leur état par requête HTTP. */
    private static $_rules  = null;
    private static $_states = array();

    /* Cause du dernier refus de déclenchement, pour que « Tester » soit explicite. */
    private static $_refusal = '';

    /* ================================================================ RÈGLES */

    /* Les règles désactivées sont exclues : la case « Activer » du coeur est
     * l'interrupteur d'armement, utilisable depuis un scénario. */
    public static function all() {
        if (self::$_rules === null) {
            self::$_rules = dahua::byTypeAndSearchConfiguration('dahua',
                array('type' => dahua::TYPE_RULE), true);
        }
        return self::$_rules;
    }

    /*
     * Une ligne dont la caméra ou la détection n'a pas été choisie est écartée.
     * C'est volontairement strict : traiter un champ vide comme « n'importe
     * lequel » ferait qu'une règle enregistrée sans rien remplir se déclencherait
     * sur la première détection venue, exactement le faux positif qu'elle est
     * censée écarter.
     */
    public static function conditions($_rule) {
        $conditions = $_rule->getConfiguration('conditions');
        if (!is_array($conditions)) {
            return array();
        }
        $clean = array();
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $source = isset($condition['source']) ? trim((string) $condition['source']) : '';
            $event  = isset($condition['event'])  ? trim((string) $condition['event'])  : '';
            if ($source == '' || $event == '') {
                continue;
            }
            $clean[] = array(
                'source' => $source,
                'event'  => $event,
                // Borné par MAX_HITS : au-delà, la condition serait inatteignable
                // puisque la liste des détections retenues est tronquée.
                'min'    => max(1, min(self::MAX_HITS, (int) $condition['min'])),
            );
        }
        return $clean;
    }

    /* Une condition qui nomme sa caméra et sa détection l'emporte sur une
     * condition générique quand les deux acceptent la même détection. */
    private static function specificity($_condition) {
        return (($_condition['source'] != self::ANY) ? 2 : 0)
             + (($_condition['event']  != self::ANY) ? 1 : 0);
    }

    /* Identité de la caméra dans les regroupements. L'id de l'équipement, et non
     * le numéro de canal : sur deux NVR, le canal 1 existe deux fois. */
    private static function hitCamera($_hit) {
        return ($_hit['cam'] > 0) ? 'eq' . $_hit['cam'] : 'ch' . $_hit['ch'];
    }

    /* Les index de conditions satisfaites par une détection, avec repli sur
     * l'ancien format d'état pour ne pas planter sur une entrée de cache. */
    private static function hitMatches($_hit) {
        if (isset($_hit['m']) && is_array($_hit['m'])) {
            return $_hit['m'];
        }
        return isset($_hit['c']) ? array((int) $_hit['c']) : array();
    }

    public static function window($_rule) {
        $window = (int) $_rule->getConfiguration('window', self::DEFAULT_WINDOW);
        return max(1, min(self::MAX_WINDOW, $window));
    }

    /* ============================================================ ÉVÉNEMENTS */

    /*
     * Appelé par jeeDahua.php pour chaque détection reçue d'une caméra.
     * $_timestamp est la date de l'ÉVÉNEMENT, pas celle du traitement : si
     * Jeedom a été lent, un seul POST peut apporter une minute d'événements d'un
     * coup, et les compter comme simultanés fabriquerait des faux positifs.
     */
    public static function onEvent($_camera, $_channel, $_code, $_action, $_timestamp) {
        /*
         * Un « Stop » n'est pas une détection, c'est sa fin — réelle, ou
         * fabriquée par le démon quand une impulsion expire. Les deux sont
         * indiscernables ici, et aucun des deux n'est un déclencheur.
         */
        if ($_action == 'Stop' || !isset(dahua::$_channelEvents[$_code])) {
            return;
        }
        $rules = self::all();
        if (empty($rules)) {
            return;
        }

        $eventId = dahua::$_channelEvents[$_code]['logicalId'];
        $camId   = is_object($_camera) ? (int) $_camera->getId() : 0;

        /*
         * Une horloge de NVR en avance est acceptée jusqu'à 5 minutes par
         * jeeDahua.php. Sans ce plafond, une détection datée dans le futur
         * deviendrait la référence de la fenêtre et écarterait toutes les
         * suivantes, gelant la règle jusqu'à ce qu'on la rattrape.
         */
        $timestamp = min((int) $_timestamp, time());

        foreach ($rules as $rule) {
            $conditions = self::conditions($rule);
            if (empty($conditions)) {
                continue;
            }
            /*
             * Une détection est enregistrée UNE fois, avec la liste des conditions
             * qu'elle peut satisfaire. C'est l'évaluation qui l'attribuera à l'une
             * d'elles : la créditer à toutes ferait qu'un seul mouvement validerait
             * à lui seul une règle « mouvement ET ligne franchie » dès que les deux
             * conditions se recouvrent.
             */
            $matches = array();
            foreach ($conditions as $index => $condition) {
                if (self::conditionMatches($condition, $camId, $eventId)) {
                    $matches[] = $index;
                }
            }
            if (empty($matches)) {
                continue;
            }
            $state = self::state($rule->getId());
            $state['hits'][] = array(
                'm'   => $matches,
                'ch'  => (int) $_channel,
                'cam' => $camId,
                'e'   => $eventId,
                't'   => $timestamp,
            );
            self::evaluate($rule, $state, $timestamp, $conditions);
            self::saveState($rule->getId(), $state);
        }
    }

    private static function conditionMatches($_condition, $_camId, $_eventId) {
        if ($_condition['source'] != self::ANY && (int) $_condition['source'] != $_camId) {
            return false;
        }
        if ($_condition['event'] != self::ANY && $_condition['event'] != $_eventId) {
            return false;
        }
        return true;
    }

    /* ============================================================ ÉVALUATION */

    private static function evaluate($_rule, &$_state, $_timestamp, $_conditions) {
        $window = self::window($_rule);

        /*
         * La fenêtre est ancrée sur la détection la plus récente connue, et non
         * sur l'heure courante : un lot arrivé en retard reste corrélé sur ses
         * dates réelles. L'écart est pris en valeur absolue, le NVR et le démon
         * n'ayant pas forcément la même horloge à la seconde près.
         */
        $reference = (int) $_timestamp;
        foreach ($_state['hits'] as $hit) {
            if ($hit['t'] > $reference) {
                $reference = $hit['t'];
            }
        }
        $hits = array();
        foreach ($_state['hits'] as $hit) {
            if (($reference - $hit['t']) <= $window) {
                $hits[] = $hit;
            }
        }
        if (count($hits) > self::MAX_HITS) {
            $hits = array_slice($hits, -self::MAX_HITS);
        }
        $_state['hits'] = $hits;

        /*
         * En portée « même caméra », chaque caméra est évaluée séparément : sinon
         * un mouvement au nord et une ligne franchie au sud passeraient pour une
         * double détection locale.
         */
        $groups = array();
        if ($_rule->getConfiguration('camera_scope', self::SCOPE_ANY) == self::SCOPE_SAME) {
            foreach ($hits as $index => $hit) {
                $groups[self::hitCamera($hit)][] = $index;
            }
        } else {
            $groups[] = array_keys($hits);
        }

        foreach ($groups as $indexes) {
            $group = array();
            foreach ($indexes as $index) {
                $group[] = $hits[$index];
            }
            if (!self::satisfied($_rule, $group, $_conditions)) {
                continue;
            }
            if (self::fire($_rule, $_state, $group)) {
                /*
                 * Seules les détections consommées sont oubliées : en portée
                 * « même caméra », celles des autres caméras continuent de
                 * s'accumuler pour leur propre compte.
                 */
                foreach ($indexes as $index) {
                    unset($hits[$index]);
                }
                $_state['hits'] = array_values($hits);
                self::saveState($_rule->getId(), $_state);
            }
            return;
        }
    }

    private static function satisfied($_rule, $_group, $_conditions) {
        /*
         * Corroboration : la même caméra qui se répète n'apporte aucune preuve
         * supplémentaire, on exige deux canaux distincts.
         */
        if ($_rule->getConfiguration('camera_scope', self::SCOPE_ANY) == self::SCOPE_DISTINCT) {
            $cameras = array();
            foreach ($_group as $hit) {
                $cameras[self::hitCamera($hit)] = true;
            }
            if (count($cameras) < 2) {
                return false;
            }
        }

        /*
         * Chaque détection ne sert qu'UNE condition. Elle est attribuée à la plus
         * spécifique de celles qu'elle satisfait et qui attend encore des
         * occurrences. Sans cette règle, deux conditions qui se recouvrent
         * (« n'importe quelle détection » et « Mouvement sur NORD ») seraient
         * toutes deux créditées par un seul mouvement, et la double détection se
         * déclencherait sur une détection unique.
         */
        $counts = array_fill(0, count($_conditions), 0);
        foreach ($_group as $hit) {
            $chosen = null;
            $best   = -1;
            foreach (self::hitMatches($hit) as $index) {
                if (!isset($_conditions[$index])) {
                    continue;
                }
                $score = (($counts[$index] < $_conditions[$index]['min']) ? 10 : 0)
                       + self::specificity($_conditions[$index]);
                if ($score > $best) {
                    $best   = $score;
                    $chosen = $index;
                }
            }
            if ($chosen !== null) {
                $counts[$chosen]++;
            }
        }

        $satisfied = 0;
        foreach ($_conditions as $index => $condition) {
            if ($counts[$index] >= $condition['min']) {
                $satisfied++;
            }
        }
        $needed = count($_conditions);
        if ($_rule->getConfiguration('mode') == self::MODE_COUNT) {
            $threshold = (int) $_rule->getConfiguration('threshold', self::DEFAULT_THRESHOLD);
            $needed = min($needed, max(1, $threshold));
        }
        return $satisfied >= $needed;
    }

    /* ========================================================= DÉCLENCHEMENT */

    private static function fire($_rule, &$_state, $_group, $_detail = null) {
        $now = time();
        self::$_refusal = '';

        $cooldown = max(0, (int) $_rule->getConfiguration('cooldown', self::DEFAULT_COOLDOWN));
        if ($_state['fired'] > 0 && ($now - $_state['fired']) < $cooldown) {
            self::$_refusal = __('temporisation en cours, encore', __FILE__) . ' '
                            . ($cooldown - ($now - $_state['fired'])) . ' ' . __('secondes', __FILE__);
            log::add('dahua', 'debug', $_rule->getHumanName() . ' — ' . self::$_refusal);
            return false;
        }
        if (!self::armCondition($_rule)) {
            self::$_refusal = __('la condition d\'armement n\'est pas remplie', __FILE__);
            log::add('dahua', 'debug', $_rule->getHumanName() . ' — ' . self::$_refusal);
            return false;
        }

        $_state['fired'] = $now;
        $_state['until'] = $now + max(1, (int) $_rule->getConfiguration('hold', self::DEFAULT_HOLD));

        /*
         * L'état est persisté AVANT les effets de bord, pour deux raisons : une
         * action qui viserait la règle elle-même retrouverait sinon une
         * temporisation à zéro et bouclerait sans fin ; et si une action échouait
         * durement, « until » serait perdu et la commande resterait à 1 pour
         * toujours — le pire mode de panne pour une alarme.
         */
        self::saveState($_rule->getId(), $_state);

        $detail = ($_detail !== null) ? $_detail : self::describe($_group);
        log::add('dahua', 'info', $_rule->getHumanName() . ' ' . __('déclenchée :', __FILE__) . ' ' . $detail);

        $_rule->checkAndUpdateCmd('detail', $detail);

        /*
         * Le dossier d'alerte est ouvert AVANT « triggered », et ce n'est pas un
         * détail d'ordonnancement : un scénario réveillé par le passage à 1 lit
         * aussitôt « image » pour la joindre à sa notification. Ouvrir le
         * dossier après lui ferait envoyer l'image du déclenchement précédent —
         * exactement le défaut que tout ceci corrige.
         *
         * Rien ici ne doit pouvoir empêcher le déclenchement : c'est une alarme.
         * Un disque plein ou un dossier illisible se journalise et la règle
         * continue son travail.
         */
        try {
            $alertId = dahuaAlert::open($_rule, $_group, $detail, self::namedCameras($_rule));
            if ($alertId !== '') {
                dahuaAlert::publish($_rule, $alertId);
                dahuaAlert::requestLiveShots($alertId);
            }
        } catch (Throwable $e) {
            log::add('dahua', 'error', $_rule->getHumanName() . ' '
                   . __('dossier d\'alerte non créé :', __FILE__) . ' ' . $e->getMessage());
        }

        $_rule->checkAndUpdateCmd('triggered', 1);

        self::runActions($_rule, 'actions');
        return true;
    }

    /*
     * Condition d'armement facultative, par exemple #[Maison][Présence][Etat]# == 0.
     */
    private static function armCondition($_rule) {
        $expression = trim((string) $_rule->getConfiguration('arm_condition'));
        if ($expression == '') {
            return true;
        }
        $messageKey = 'ruleCondition' . $_rule->getId();
        try {
            /*
             * Même chaîne de substitution que le coeur pour les conditions de
             * scénario, et dans cet ordre :
             *   humanReadableToCmd — #[Objet][Éq][Cmd]# devient #123#. Indispensable :
             *       cmdToValue ne résout QUE la forme numérique, alors que le
             *       sélecteur de commandes insère la forme humaine ;
             *   setTags            — #date#, #time#, variable(), tag() ;
             *   cmdToValue(quote)  — la valeur, entre guillemets si c'est du texte,
             *       sans quoi toute commande info de type chaîne casse l'expression.
             */
            $resolved = cmd::humanReadableToCmd($expression);
            $scenario = null;
            $resolved = scenarioExpression::setTags($resolved, $scenario, true);
            $resolved = cmd::cmdToValue($resolved, true);
            $result   = evaluate($resolved);

            /*
             * evaluate() ne lève jamais : quand elle ne sait pas interpréter, elle
             * rend son entrée telle quelle. C'est le seul moyen de détecter
             * l'échec — et sans ce contrôle une expression cassée passerait pour
             * vraie, puisqu'une chaîne non vide vaut vrai.
             */
            if (is_string($result) && $result === $resolved) {
                throw new Exception(__('expression non interprétable :', __FILE__) . ' ' . $resolved);
            }
            message::removeAll('dahua', $messageKey);
            return is_bool($result)
                 ? $result
                 : ($result !== null && $result !== '' && $result != 0);
        } catch (Throwable $e) {
            /*
             * Dans le doute on n'arme pas — mais on le dit. Ce plugin a déjà connu
             * deux pannes parfaitement muettes ; une règle qui ne se déclenche
             * plus doit laisser une trace ET un message.
             *
             * Le message est posé explicitement : log::add ne l'ajoute au centre
             * de messages que si le réglage global « addMessageForErrorLog » est
             * actif, ce qui n'est pas le cas par défaut.
             */
            $text = $_rule->getHumanName() . ' '
                  . __('condition d\'armement invalide, la règle ne se déclenchera pas :', __FILE__)
                  . ' ' . $e->getMessage();
            log::add('dahua', 'error', $text);
            message::add('dahua', $text, '', $messageKey);
            return false;
        }
    }

    private static function describe($_group) {
        $parts = array();
        foreach ($_group as $hit) {
            $camera = ($hit['cam'] > 0) ? dahua::byId($hit['cam']) : null;
            $name   = is_object($camera) ? $camera->getName() : (__('canal', __FILE__) . ' ' . $hit['ch']);
            // Une même détection répétée n'apparaît qu'une fois, à sa date la
            // plus récente : le libellé reste lisible dans une notification.
            $parts[$name . '|' . $hit['e']] = $name . ' ' . self::eventName($hit['e'])
                                            . ' ' . date('H:i:s', $hit['t']);
        }
        return implode(' + ', array_values($parts));
    }

    private static function eventName($_logicalId) {
        foreach (dahua::$_channelEvents as $definition) {
            if ($definition['logicalId'] == $_logicalId) {
                return __($definition['name'], __FILE__);
            }
        }
        return $_logicalId;
    }

    /*
     * Les caméras explicitement nommées par les conditions de la règle.
     *
     * Sert uniquement de repli quand aucune détection réelle n'a validé le
     * déclenchement, c'est-à-dire pour le bouton « Tester » : celui-ci
     * court-circuite l'évaluation et appelle fire() avec un groupe vide, si bien
     * qu'un test ne produirait aucune image et ne prouverait rien de la chaîne
     * qu'il est censé vérifier.
     *
     * Les conditions en « n'importe quelle caméra » sont ignorées : elles ne
     * désignent personne, et les traiter comme « toutes » ferait capturer huit
     * canaux à chaque test.
     */
    private static function namedCameras($_rule) {
        $cameras = array();
        foreach (self::conditions($_rule) as $condition) {
            if ($condition['source'] == self::ANY) {
                continue;
            }
            $id = (int) $condition['source'];
            if ($id <= 0 || isset($cameras[$id])) {
                continue;
            }
            $camera = dahua::byId($id);
            if (is_object($camera) && $camera->getConfiguration('type') == dahua::TYPE_CAMERA) {
                $cameras[$id] = $camera;
            }
        }
        return $cameras;
    }

    /* =============================================================== ACTIONS */

    /*
     * scenarioExpression::createAndExec est le primitif du coeur pour les
     * actions configurables : il accepte aussi bien une commande qu'un scénario
     * ou une variable, et honore les options « désactivée » et « en tâche de
     * fond » posées par le sélecteur.
     */
    /*
     * Le dispatcher vit désormais dans la classe principale, la supervision des
     * caméras s'en servant aussi. La garde anti-boucle reste active ici : une
     * règle dont « Tester » serait posé comme sa propre action boucherait
     * indéfiniment.
     */
    public static function runActions($_rule, $_key) {
        dahua::runActions($_rule, $_key, array(), true);
    }

    /* ================================================================ RETOUR */

    /*
     * Remet à 0 les règles dont la durée de maintien est écoulée. Appelée après
     * chaque lot d'événements et par le cron du plugin : sans cette seconde
     * passe, une règle déclenchée en fin de soirée resterait allumée jusqu'à la
     * détection suivante.
     */
    public static function checkHold() {
        foreach (self::all() as $rule) {
            $triggered = $rule->getCmd('info', 'triggered');
            if (!is_object($triggered) || $triggered->execCmd() != 1) {
                continue;
            }
            $state = self::state($rule->getId());
            /*
             * « until » vide alors que la règle est déclenchée : l'état a été
             * perdu (cache vidé, sauvegarde restaurée, exception en plein
             * déclenchement). On fait retomber la règle plutôt que de laisser la
             * commande à 1 indéfiniment — c'est le rattrapage qui manquait, et
             * c'est le mode de panne le plus dangereux pour une alarme.
             */
            if (!empty($state['until']) && time() < $state['until']) {
                continue;
            }
            $state['until'] = 0;
            self::saveState($rule->getId(), $state);
            self::release($rule);
        }
    }

    public static function release($_rule) {
        $triggered = $_rule->getCmd('info', 'triggered');
        if (!is_object($triggered) || $triggered->execCmd() != 1) {
            return;
        }
        $_rule->checkAndUpdateCmd('triggered', 0);
        self::runActions($_rule, 'actions_end');
    }

    /* Remise à zéro complète, y compris les détections en attente. */
    public static function reset($_rule) {
        /* Symétrie avec le retour au repos normal : si la règle était
         * déclenchée, les actions de fin doivent être jouées, sinon une sirène
         * allumée le resterait. */
        self::release($_rule);
        self::saveState($_rule->getId(), array('hits' => array(), 'fired' => 0, 'until' => 0));
        $_rule->checkAndUpdateCmd('triggered', 0);
        $_rule->checkAndUpdateCmd('detail', __('Réinitialisée', __FILE__));
        return true;
    }

    /*
     * Simulation : joue le déclenchement comme s'il venait du NVR, temporisation
     * et condition d'armement comprises — un test qui contournerait ces deux
     * réglages ne prouverait rien.
     */
    public static function test($_rule) {
        if ($_rule->getIsEnable() != 1) {
            throw new Exception(__('Cette règle est désactivée : réactivez-la pour la tester.', __FILE__));
        }
        /*
         * Le test court-circuite l'évaluation des conditions — c'est son rôle,
         * il sert à vérifier les actions. Mais répondre « déclenchée » sur une
         * règle qui ne pourra jamais l'être d'elle-même serait mensonger.
         */
        if (count(self::conditions($_rule)) == 0) {
            throw new Exception(__('Cette règle n\'a aucune condition complète : elle ne se déclencherait jamais d\'elle-même. Choisissez une caméra et une détection sur chaque ligne avant de tester.', __FILE__));
        }
        $state = self::state($_rule->getId());
        // Les détections déjà accumulées sont préservées : tester une règle ne
        // doit pas effacer une corrélation réelle en cours.
        $pending = $state['hits'];
        if (!self::fire($_rule, $state, array(), __('Test manuel', __FILE__))) {
            throw new Exception(__('Déclenchement refusé :', __FILE__) . ' ' . self::$_refusal . '.');
        }
        $state['hits'] = $pending;
        self::saveState($_rule->getId(), $state);
        return true;
    }

    /* ================================================================= ÉTAT */

    private static function state($_id) {
        $_id = (int) $_id;
        if (!isset(self::$_states[$_id])) {
            $raw   = cache::byKey(self::CACHE_PREFIX . $_id)->getValue('');
            $state = ($raw != '') ? json_decode($raw, true) : null;
            if (!is_array($state)) {
                $state = array();
            }
            self::$_states[$_id] = array(
                'hits'  => (isset($state['hits']) && is_array($state['hits'])) ? $state['hits'] : array(),
                'fired' => isset($state['fired']) ? (int) $state['fired'] : 0,
                'until' => isset($state['until']) ? (int) $state['until'] : 0,
            );
        }
        return self::$_states[$_id];
    }

    private static function saveState($_id, $_state) {
        $_id = (int) $_id;
        self::$_states[$_id] = $_state;
        // Durée de vie infinie : la temporisation et la durée de maintien
        // doivent survivre à une fenêtre de corrélation expirée.
        cache::set(self::CACHE_PREFIX . $_id, json_encode($_state), 0);
    }

    public static function forget($_id) {
        unset(self::$_states[(int) $_id]);
        cache::delete(self::CACHE_PREFIX . (int) $_id);
    }
}
