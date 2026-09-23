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
 * Historique des alertes : ce qu'on ouvre au matin après une nuit agitée.
 *
 * La tuile du dashboard ne montre que la DERNIÈRE alerte de chaque règle. S'il
 * y en a eu cinq entre minuit et six heures, les quatre premières n'existent
 * nulle part dans l'interface, alors que leurs dossiers sont bien sur le
 * disque. Cette page les rend, de la plus récente à la plus ancienne.
 *
 * Rendu entièrement côté serveur, et ce n'est pas un choix d'esthétique : la
 * seule porte AJAX du plugin (core/ajax/dahua.ajax.php) exige
 * isConnect('admin'), or cette page doit rester ouverte à un utilisateur
 * restreint. Le JavaScript qui l'accompagne ne fait donc que filtrer des
 * entrées déjà rendues et agrandir une image : aucune donnée ne transite par
 * lui, et rien ne casse s'il ne s'exécute pas.
 *
 * Atteinte par index.php?v=d&m=dahua&p=alerts — le routeur du coeur fait
 * include_file('desktop', <p>, 'php', <m>), il n'y a aucune déclaration à
 * ajouter nulle part.
 */

/*
 * isConnect() et non isConnect('admin'), contrairement à desktop/php/dahua.php.
 *
 * Un levé de doute n'est pas une tâche d'administration : la personne qui
 * rentre à trois heures du matin doit pouvoir regarder ce qui a déclenché, sans
 * pour autant avoir le droit de reconfigurer le NVR. Le filtrage par droits est
 * fait plus bas, alerte par alerte.
 */
if (!isConnect()) {
	throw new Exception('{{401 - Accès non autorisé}}');
}

/* L'autochargeur du coeur ne résout que la classe portant le nom du plugin :
 * dahua serait trouvée seule, dahuaAlert non. dahua.class.php la tire — c'est
 * exactement la raison pour laquelle core/php/snapshot.php fait de même. */
require_once __DIR__ . '/../../core/class/dahua.class.php';

/*
 * Volumétrie. Le poids des images n'est pas le vrai sujet.
 *
 * Chaque vignette est une requête à core/php/snapshot.php, et chaque requête
 * démarre TOUT Jeedom : core.inc.php coûte une vingtaine de millisecondes,
 * auxquelles s'ajoutent le contrôle de session et celui des droits. Cent
 * alertes à quatre caméras et deux vues font huit cents images, donc huit cents
 * démarrages du coeur — une quinzaine de secondes de processeur serveur pour
 * qui fait défiler la page jusqu'en bas, et bien davantage sur une carte SD.
 * loading="lazy" borne le trafic réseau, pas ce coût-là.
 *
 * D'où trente entrées par page, et une pagination pour le reste. Avec, toujours,
 * les deux autres garde-fous : uniquement des vignettes (25 Ko contre 600 Ko à
 * 1 Mo), et loading="lazy" pour ne charger que ce qui entre dans la fenêtre.
 */
$dahuaAlertsMax    = 30;
$dahuaPage         = max(1, (int) init('page', 1));
$dahuaAlertsOffset = ($dahuaPage - 1) * $dahuaAlertsMax;

/*
 * Le balayage suit la rétention réelle, et non un nombre écrit en dur.
 *
 * Le filtrage par droits a lieu APRÈS la lecture : s'arrêter à trois cents
 * descriptions alors que l'installation en conserve mille priverait un
 * utilisateur restreint de toutes ses alertes passé ce rang, silencieusement,
 * pendant que la page lui annoncerait « mille alertes conservées ». Le coût est
 * une lecture de petit fichier JSON par dossier ; ce sont les images qui pèsent.
 */
$dahuaScanKeep = (int) config::byKey('alert_keep', 'dahua', dahuaAlert::DEFAULT_KEEP);
if ($dahuaScanKeep < 1 || $dahuaScanKeep > dahuaAlert::MAX_KEEP) {
	$dahuaScanKeep = dahuaAlert::DEFAULT_KEEP;
}
$dahuaAlertsScan = max($dahuaAlertsOffset + $dahuaAlertsMax + 1, $dahuaScanKeep);

/* Tout ce qui est affiché ici vient du NVR (noms de caméras, causes d'échec
 * remontées par curl) ou d'une saisie utilisateur (noms de règles) : rien ne
 * sort sans passer par là. */
$dahuaEsc = function ($_text) {
	return htmlspecialchars((string) $_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

/*
 * URL d'une image du dossier, vignette et pleine résolution.
 *
 * L'existence est vérifiée sur le disque et non déduite de la description :
 * deux situations parfaitement normales la prennent en défaut. La purge à deux
 * étages retire les images pleine résolution en laissant les vignettes, et
 * makeThumb() peut avoir échoué (GD absent, image illisible) en laissant au
 * contraire l'image seule. Un <img> pointant un fichier disparu afficherait un
 * cadre brisé, et un clic « plein écran » ouvrirait une modale vide.
 */
$dahuaImage = function ($_dir, $_id, $_file) {
	$out = array('thumb' => '', 'full' => '');
	if ($_dir === false || substr($_file, -4) !== '.jpg') {
		return $out;
	}
	$thumb = substr($_file, 0, -4) . '_t.jpg';
	if (is_file($_dir . '/' . $_file)) {
		$out['full'] = dahuaAlert::url($_id, $_file);
	}
	if (is_file($_dir . '/' . $thumb)) {
		$out['thumb'] = dahuaAlert::url($_id, $thumb);
	} else {
		/* Pas de vignette : on se rabat sur l'image entière, lourde mais
		 * différée par loading="lazy". Mieux vaut une page plus lente qu'une
		 * caméra qui disparaît de l'historique. */
		$out['thumb'] = $out['full'];
	}
	return $out;
};

/*
 * Les alertes retenues, avec leur règle.
 *
 * ruleOf() rend null quand la règle a été supprimée, ou quand son identifiant a
 * été réattribué à un autre équipement : le dossier est alors orphelin, plus
 * rien ne permet de dire à qui il appartient, et il n'est pas affiché. Le
 * contrôle hasRight('r') est le même que celui du passe-plat d'images : un
 * profil restreint ne doit pas voir l'historique d'un objet auquel il n'a pas
 * droit — et ne pourrait de toute façon pas en charger les vignettes.
 */
$dahuaAlerts    = array();
$dahuaRuleNames = array();
$dahuaDays      = array();

/*
 * Les règles et leurs droits sont mémorisés par identifiant, et c'est loin
 * d'être une micro-optimisation.
 *
 * ruleOf() fait un eqLogic::byId(), qui interroge la base à CHAQUE appel — il
 * n'y a aucun cache d'instances dans le coeur. hasRight() enchaîne jusqu'à
 * trois isConnect(), dont chacun refait un user::byId(). Sur trois cents
 * descriptions balayées, cela faisait plus de mille requêtes SQL par
 * chargement, pour une poignée de règles distinctes. Mesuré à 88 ms sur cette
 * machine, donc proche de la seconde sur un Jeedom en carte SD.
 */
$dahuaRuleCache  = array();
$dahuaRightCache = array();

foreach (dahuaAlert::recent($dahuaAlertsScan) as $dahuaMeta) {
	$dahuaRuleId = isset($dahuaMeta['rule_id']) ? (int) $dahuaMeta['rule_id'] : 0;
	if (!array_key_exists($dahuaRuleId, $dahuaRuleCache)) {
		$dahuaRuleCache[$dahuaRuleId]  = dahuaAlert::ruleOf($dahuaMeta);
		$dahuaRightCache[$dahuaRuleId] = is_object($dahuaRuleCache[$dahuaRuleId])
			&& $dahuaRuleCache[$dahuaRuleId]->hasRight('r');
	}
	if (!$dahuaRightCache[$dahuaRuleId]) {
		continue;
	}
	$dahuaAlerts[] = array('meta' => $dahuaMeta, 'rule' => $dahuaRuleCache[$dahuaRuleId]);
	if (count($dahuaAlerts) >= ($dahuaAlertsOffset + $dahuaAlertsMax + 1)) {
		break;
	}
}

/*
 * Pagination. Le découpage est fait APRÈS le filtrage par droits : découper
 * avant ferait des pages de tailles inégales, voire vides, selon ce à quoi
 * l'utilisateur a droit.
 *
 * Une entrée est lue au-delà de la page pour savoir s'il y a une suite, sans
 * avoir à compter l'ensemble.
 */
$dahuaHasMore = (count($dahuaAlerts) > ($dahuaAlertsOffset + $dahuaAlertsMax));
$dahuaAlerts  = array_slice($dahuaAlerts, $dahuaAlertsOffset, $dahuaAlertsMax);

/* Les deux listes de filtrage sont construites sur les alertes RÉELLEMENT
 * retenues : proposer une règle ou un jour qui ne donnerait aucun résultat est
 * une invitation à croire que le filtre est cassé. Les alertes arrivant de la
 * plus récente à la plus ancienne, l'ordre d'insertion des jours est déjà le
 * bon — aucun tri à faire. */
foreach ($dahuaAlerts as $dahuaEntry) {
	$dahuaRuleNames[(int) $dahuaEntry['rule']->getId()] = $dahuaEntry['rule']->getName();
	$dahuaTime = isset($dahuaEntry['meta']['time']) ? (int) $dahuaEntry['meta']['time'] : 0;
	$dahuaDays[date('Y-m-d', $dahuaTime)] = date('d/m/Y', $dahuaTime);
}
asort($dahuaRuleNames);

/*
 * La rétention, dite en clair. Sans elle, une alerte manquante se lit comme un
 * bug du plugin alors que c'est le réglage qui a fait son travail.
 *
 * Les bornes reprennent celles de dahuaAlert::setting(), qui est privée : une
 * saisie hors bornes y retombe sur le défaut et non sur le minimum, et annoncer
 * ici une valeur que la purge n'applique pas serait pire que de se taire.
 */
$dahuaKeep = (int) config::byKey('alert_keep', 'dahua', dahuaAlert::DEFAULT_KEEP);
if ($dahuaKeep < 1 || $dahuaKeep > dahuaAlert::MAX_KEEP) {
	$dahuaKeep = dahuaAlert::DEFAULT_KEEP;
}
$dahuaKeepFull = (int) config::byKey('alert_keep_full', 'dahua', dahuaAlert::DEFAULT_KEEP_FULL);
if ($dahuaKeepFull < 0 || $dahuaKeepFull > dahuaAlert::MAX_KEEP) {
	$dahuaKeepFull = dahuaAlert::DEFAULT_KEEP_FULL;
}
$dahuaKeepFull = min($dahuaKeep, $dahuaKeepFull);
$dahuaKeepDays = (int) config::byKey('alert_keep_full_days', 'dahua', dahuaAlert::DEFAULT_KEEP_FULL_DAYS);
if ($dahuaKeepDays < 0 || $dahuaKeepDays > dahuaAlert::MAX_KEEP) {
	$dahuaKeepDays = dahuaAlert::DEFAULT_KEEP_FULL_DAYS;
}

$dahuaNow = time();
?>

<style>
/*
 * La modale en position fixe, là où le coeur la met en position absolue.
 *
 * Aujourd'hui les deux donnent le même résultat : ce n'est pas le document qui
 * défile dans cette interface mais #div_mainContainer, si bien que le bloc
 * conteneur d'un position:absolute coïncide en permanence avec la fenêtre, et
 * que setPosition() — qui écrit « top » sans jamais tenir compte d'un
 * défilement — tombe juste.
 *
 * On ne s'appuie pas dessus pour autant. Cette page est longue par nature, et
 * la propriété tient à un choix de mise en page du coeur qui n'a rien à voir
 * avec nous : le jour où le document défilerait, une vignette cliquée en bas
 * ouvrirait la boîte très au-dessus de l'écran, sans que rien ne l'explique.
 */
#md_dahuaAlertFull {
	position: fixed;
	max-width: 96vw;
}
#md_dahuaAlertFull .jeeDialogContent img {
	max-width: 100%;
	max-height: 78vh;
	object-fit: contain;
}

/* Aucune couleur en dur : le plugin doit rester lisible sur le thème clair
   comme sur le thème sombre, qui redéfinissent ces variables. Les triplets
   (--panel-bg-color) s'emploient obligatoirement via rgba(). */
.dahuaAlertEntry {
	background-color: rgba(var(--panel-bg-color), var(--opacity));
	border-radius: var(--border-radius);
	padding: 8px 10px;
	margin: 0 0 10px 0;
}
.dahuaAlertWhen { font-weight: bold; }
.dahuaAlertObject { opacity: .75; margin-left: 5px; }
.dahuaAlertDetail { margin: 4px 0 8px 0; opacity: .85; }
.dahuaAlertCam {
	display: inline-block;
	vertical-align: top;
	margin: 0 16px 10px 0;
}
.dahuaAlertCamName { font-weight: bold; margin-bottom: 4px; }
.dahuaAlertShot {
	display: inline-block;
	vertical-align: top;
	margin: 0 8px 0 0;
	padding-left: 6px;
}
/* La distinction entre les deux natures d'image est TOUT l'intérêt du
   dispositif : la première montre l'arrivée, la seconde montre où la personne
   est allée depuis. Elle est portée deux fois, par la couleur et par le texte,
   pour ne pas reposer sur la seule perception des couleurs. */
.dahuaAlertDet  { border-left: 3px solid var(--al-info-color); }
.dahuaAlertLive { border-left: 3px solid var(--al-warning-color); }
.dahuaAlertDet .dahuaAlertShotLabel  { color: var(--al-info-color); }
.dahuaAlertLive .dahuaAlertShotLabel { color: var(--al-warning-color); }
.dahuaAlertShot img {
	display: block;
	width: 160px;
	border-radius: var(--border-radius);
}
.dahuaAlertShot[data-full] img { cursor: pointer; }
.dahuaAlertShotLabel { font-size: 11px; margin-top: 2px; }
.dahuaAlertDelta { opacity: .7; }
.dahuaAlertFail { color: var(--al-danger-color); font-size: 12px; margin-top: 3px; }
.dahuaAlertWait, .dahuaAlertMissing, .dahuaAlertNote { opacity: .7; font-size: 12px; }
#md_dahuaAlertFull img { max-width: 100%; }
</style>

<div class="row row-overflow">
	<div class="col-xs-12">
		<div class="pull-right" style="margin-top:5px;">
			<?php if (isConnect('admin')) { ?>
			<!-- La page du plugin exige isConnect('admin') : proposer le retour à
			     un utilisateur restreint l'enverrait sur un « 401 ». -->
			<a class="btn btn-sm btn-default" href="index.php?v=d&amp;m=dahua&amp;p=dahua"><i class="fas fa-arrow-circle-left"></i> {{Retour au plugin}}</a>
			<?php } ?>
		</div>
		<legend><i class="fas fa-history"></i> {{Historique des alertes}}</legend>

		<?php
		if (count($dahuaAlerts) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucune alerte à afficher.}}</b><br>';
			echo '{{Un dossier d\'alerte est créé à chaque déclenchement d\'une règle de détection croisée, avec les images des caméras concernées. Dès qu\'une règle déclenchera, l\'alerte apparaîtra ici.}}';
			echo '</div>';
		} else {
			/* Barre de filtrage. Les deux listes sont rendues par le serveur et
			 * le tri se fait ensuite en JavaScript sur des entrées déjà
			 * présentes : aucun rechargement, et surtout aucune requête — les
			 * images déjà téléchargées le restent. */
			echo '<div class="form-inline" style="margin:5px 5px 12px 5px;">';

			echo '<div class="form-group">';
			echo '<label for="sel_dahuaAlertRule">{{Règle}}</label> ';
			echo '<select id="sel_dahuaAlertRule" class="form-control input-sm">';
			echo '<option value="">{{Toutes les règles}}</option>';
			foreach ($dahuaRuleNames as $dahuaRuleId => $dahuaRuleName) {
				echo '<option value="' . (int) $dahuaRuleId . '">' . $dahuaEsc($dahuaRuleName) . '</option>';
			}
			echo '</select>';
			echo '</div>';

			echo '<div class="form-group" style="margin-left:12px;">';
			echo '<label for="sel_dahuaAlertDay">{{Jour}}</label> ';
			echo '<select id="sel_dahuaAlertDay" class="form-control input-sm">';
			echo '<option value="">{{Tous les jours}}</option>';
			foreach ($dahuaDays as $dahuaDayKey => $dahuaDayLabel) {
				echo '<option value="' . $dahuaEsc($dahuaDayKey) . '">' . $dahuaEsc($dahuaDayLabel) . '</option>';
			}
			echo '</select>';
			echo '</div>';

			echo '</div>';

			echo '<div class="help-block" style="margin:0 5px 10px 5px;">';
			echo '<b id="span_dahuaAlertShown">' . count($dahuaAlerts) . '</b> {{alerte(s) affichée(s), de la plus récente à la plus ancienne.}}';
			/* La page, et seulement si elle en cache d'autres : annoncer une
			 * troncature là où il n'y en a pas — exactement trente alertes, et
			 * pas une de plus — ferait chercher ce qui manque. */
			if ($dahuaPage > 1 || $dahuaHasMore) {
				echo ' {{Page}} ' . $dahuaPage . '.';
			}
			echo '<br>{{Rétention :}} ' . $dahuaKeep . ' {{alertes conservées, dont les}} ' . $dahuaKeepFull;
			if ($dahuaKeepFull > 0 && $dahuaKeepDays > 0) {
				echo ' {{plus récentes, et toutes celles des}} ' . $dahuaKeepDays;
				echo ' {{derniers jours, avec leurs images en pleine résolution ; au-delà il ne reste que les vignettes.}}';
			} else {
				echo ' {{plus récentes avec leurs images en pleine résolution ; au-delà il ne reste que les vignettes.}}';
			}
			echo '</div>';

			echo '<div class="alert alert-info" id="div_dahuaAlertNoMatch" style="margin:5px;display:none;">{{Aucune alerte ne correspond à ce filtre.}}</div>';

			echo '<div id="div_dahuaAlertList">';

			foreach ($dahuaAlerts as $dahuaEntry) {
				$dahuaMeta = $dahuaEntry['meta'];
				$dahuaRule = $dahuaEntry['rule'];
				$dahuaId   = isset($dahuaMeta['id']) ? (string) $dahuaMeta['id'] : '';
				$dahuaTime = isset($dahuaMeta['time']) ? (int) $dahuaMeta['time'] : 0;
				/* Le dossier est résolu une seule fois par alerte : path() fait
				 * un is_dir(), inutile de le refaire pour chaque image. */
				$dahuaDir  = ($dahuaId != '') ? dahuaAlert::path($dahuaId) : false;

				echo '<div class="dahuaAlertEntry" data-rule="' . (int) $dahuaRule->getId() . '"';
				echo ' data-day="' . $dahuaEsc(date('Y-m-d', $dahuaTime)) . '">';

				/* Date et heure dans le fuseau de l'installation : l'identifiant
				 * du dossier, lui, est en UTC (c'est ce qui rend son tri
				 * chronologique) et serait illisible ici. */
				echo '<span class="dahuaAlertWhen">' . $dahuaEsc(date('d/m/Y', $dahuaTime));
				echo ' &mdash; ' . $dahuaEsc(date('H:i:s', $dahuaTime)) . '</span>';
				echo ' <span class="label label-info">' . $dahuaEsc($dahuaRule->getName()) . '</span>';
				/* L'objet porte ses propres droits, distincts de ceux de
				 * l'équipement : un profil restreint peut avoir accès à la règle
				 * sans avoir accès à l'objet qui la contient. C'était la seule
				 * donnée de la page qui échappait au modèle de droits. */
				$dahuaObject = $dahuaRule->getObject();
				if (is_object($dahuaObject) && $dahuaObject->hasRight('r')) {
					echo '<span class="dahuaAlertObject"><i class="fas fa-folder"></i> ' . $dahuaEsc($dahuaObject->getName()) . '</span>';
				}
				/* La purge à deux étages a retiré les images pleine résolution :
				 * le dire évite de chercher pourquoi le plein écran ne s'ouvre
				 * plus sur les alertes anciennes. */
				if (isset($dahuaMeta['full']) && (int) $dahuaMeta['full'] === 0) {
					echo ' <span class="label label-default" title="{{Les images en pleine résolution de cette alerte ont été purgées.}}">{{vignettes seules}}</span>';
				}

				if (isset($dahuaMeta['detail']) && $dahuaMeta['detail'] != '') {
					echo '<div class="dahuaAlertDetail">' . $dahuaEsc($dahuaMeta['detail']) . '</div>';
				}

				$dahuaCameras = (isset($dahuaMeta['cameras']) && is_array($dahuaMeta['cameras']))
					? $dahuaMeta['cameras'] : array();
				if (count($dahuaCameras) == 0) {
					/* Cas réel : « Tester » sur une règle dont toutes les
					 * conditions sont en « n'importe quelle caméra ». Le dossier
					 * existe, daté, mais ne porte aucune image. */
					echo '<div class="dahuaAlertNote">{{Aucune caméra n\'a pu être identifiée pour cette alerte.}}</div>';
				}

				foreach ($dahuaCameras as $dahuaCamera) {
					/* Une description est un fichier sur le disque, écrit par une
					 * version antérieure ou tronqué par un incident : aucune de
					 * ses clés n'est garantie. Un accès direct lèverait un
					 * avertissement PHP 8 au beau milieu du rendu. */
					$dahuaCamName = isset($dahuaCamera['name']) ? (string) $dahuaCamera['name'] : '';

					echo '<div class="dahuaAlertCam">';
					echo '<div class="dahuaAlertCamName"><i class="fas fa-video"></i> ';
					echo ($dahuaCamName != '') ? $dahuaEsc($dahuaCamName) : '{{Caméra inconnue}}';
					echo '</div>';

					$dahuaShots = 0;
					foreach (array(
						array('kind' => dahuaAlert::KIND_DET,  'css' => 'dahuaAlertDet',  'label' => '{{À la détection}}'),
						array('kind' => dahuaAlert::KIND_LIVE, 'css' => 'dahuaAlertLive', 'label' => '{{À l\'instant de l\'alerte}}'),
					) as $dahuaKind) {
						if (!isset($dahuaCamera[$dahuaKind['kind']]) || $dahuaCamera[$dahuaKind['kind']] == '') {
							continue;
						}
						$dahuaUrls = $dahuaImage($dahuaDir, $dahuaId, (string) $dahuaCamera[$dahuaKind['kind']]);
						if ($dahuaUrls['thumb'] == '') {
							continue;
						}
						$dahuaShots++;

						$dahuaTitle = $dahuaCamName . ' - ' . date('d/m/Y H:i:s', $dahuaTime);

						echo '<figure class="dahuaAlertShot ' . $dahuaKind['css'] . '"';
						/* L'attribut n'est posé QUE si le fichier pleine
						 * résolution existe : c'est lui, et lui seul, qui rend
						 * la vignette cliquable côté JavaScript. Proposer un
						 * agrandissement qui n'ouvrirait rien serait pire que
						 * de ne rien proposer. */
						if ($dahuaUrls['full'] != '') {
							echo ' data-full="' . $dahuaEsc($dahuaUrls['full']) . '"';
							echo ' data-title="' . $dahuaEsc($dahuaTitle) . '"';
							echo ' title="{{Cliquer pour agrandir}}"';
						}
						echo '>';
						/* loading="lazy" : cent alertes font jusqu'à huit cents
						 * images, et le navigateur n'en télécharge que ce qui
						 * entre dans la fenêtre. C'est ce seul attribut qui rend
						 * la page tenable. */
						echo '<img loading="lazy" src="' . $dahuaEsc($dahuaUrls['thumb']) . '" alt="">';
						echo '<figcaption class="dahuaAlertShotLabel">' . $dahuaKind['label'];
						if ($dahuaKind['kind'] === dahuaAlert::KIND_DET && isset($dahuaCamera['det_delta'])) {
							echo ' <span class="dahuaAlertDelta" title="{{Écart entre la détection et la capture retenue}}">';
							echo '&plusmn;' . (int) $dahuaCamera['det_delta'] . ' s</span>';
						}
						echo '</figcaption>';
						echo '</figure>';
					}

					if ($dahuaShots == 0) {
						echo '<div class="dahuaAlertMissing">{{Aucune image conservée pour cette caméra.}}</div>';
					}

					/*
					 * La cause d'un échec est affichée même quand une autre
					 * image existe, et la caméra n'est JAMAIS retirée de la
					 * liste. « SUD n'a pas répondu » est une information de levé
					 * de doute au moins aussi précieuse qu'une image : c'est
					 * peut-être justement la caméra qu'on a coupée.
					 */
					if (isset($dahuaCamera['live_error']) && $dahuaCamera['live_error'] != '') {
						echo '<div class="dahuaAlertFail"><i class="fas fa-exclamation-triangle"></i> ';
						echo $dahuaEsc($dahuaCamera['live_error']) . '</div>';
					} elseif (!isset($dahuaCamera[dahuaAlert::KIND_LIVE]) && !empty($dahuaCamera['live_requested'])) {
						/* Une attente n'est annoncée que si un ordre est
						 * réellement parti, et elle expire : passé SHOT_EXPIRY,
						 * ce n'est plus « en route » mais « jamais revenue ». Sur
						 * une page d'historique c'est presque toujours le second
						 * cas, mais une alerte de la minute écoulée peut encore
						 * être en cours. */
						if (($dahuaNow - $dahuaTime) <= dahuaAlert::SHOT_EXPIRY) {
							echo '<div class="dahuaAlertWait">{{Capture en cours...}}</div>';
						} else {
							echo '<div class="dahuaAlertFail"><i class="fas fa-exclamation-triangle"></i> {{La capture demandée n\'est jamais revenue.}}</div>';
						}
					}

					echo '</div>';
				}

				echo '</div>';
			}

			echo '</div>';

			/*
			 * Navigation entre pages. De vrais liens, interceptés par le coeur
			 * et chargés en AJAX comme n'importe quel lien interne : pas de
			 * JavaScript à écrire, et l'adresse reste partageable.
			 *
			 * Les filtres, eux, ne portent que sur la page affichée. C'est
			 * assumé : les rendre globaux demanderait de tout charger, ce que
			 * cette pagination existe précisément pour éviter.
			 */
			if ($dahuaPage > 1 || $dahuaHasMore) {
				echo '<div style="margin:12px 5px;text-align:center;">';
				if ($dahuaPage > 1) {
					echo '<a class="btn btn-sm btn-default" href="index.php?v=d&amp;m=dahua&amp;p=alerts&amp;page='
					   . ($dahuaPage - 1) . '"><i class="fas fa-chevron-left"></i> {{Plus récentes}}</a> ';
				}
				if ($dahuaHasMore) {
					echo '<a class="btn btn-sm btn-default" href="index.php?v=d&amp;m=dahua&amp;p=alerts&amp;page='
					   . ($dahuaPage + 1) . '">{{Plus anciennes}} <i class="fas fa-chevron-right"></i></a>';
				}
				echo '</div>';
			}
		}
		?>
	</div>
</div>

<!--
	Plein écran. jeeDialog.modal() est la seule modale garantie par ce coeur :
	fslightbox n'est pas chargé et bootbox dépend d'un réglage jQuery.

	Le bouton de fermeture est volontairement placé DANS .jeeDialogTitle, et non
	en enfant direct de la modale : modal() n'attache son propre gestionnaire
	qu'à « :scope > .btClose », et celui-ci supprime l'élément du DOM. La modale
	ne pourrait alors être ouverte qu'une seule fois. Imbriqué ici, il reste à
	notre charge — le JavaScript se contente de masquer.
-->
<div id="md_dahuaAlertFull" class="jeeDialog jeeDialogPrompt" tabindex="-1" style="display:none;">
	<div class="jeeDialogTitle">
		<span class="title" id="span_dahuaAlertFullTitle"></span><button class="btClose" id="bt_dahuaAlertFullClose" type="button"></button>
	</div>
	<div class="jeeDialogContent" style="text-align:center;">
		<img id="img_dahuaAlertFull" alt="{{Image de l'alerte en pleine résolution}}" title="{{Cliquer pour fermer}}" style="cursor:pointer;">
		<div id="span_dahuaAlertFullNote" class="dahuaAlertNote dahuaAlertBad" style="padding:20px 0;"></div>
	</div>
</div>

<?php include_file('desktop', 'alerts', 'js', 'dahua'); ?>
