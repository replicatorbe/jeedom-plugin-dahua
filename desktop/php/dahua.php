<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('dahua');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
$nvrs = dahua::byTypeAndSearchConfiguration('dahua', array('type' => dahua::TYPE_NVR));

/*
 * Les listes déroulantes des conditions sont construites en JavaScript, une
 * règle ayant un nombre de lignes variable. Elles sont donc transmises ici, et
 * restent figées jusqu'au rechargement de la page — comme la liste des NVR du
 * bloc caméra. La découverte des caméras recharge déjà la page.
 */
$dahuaCameras = array();
foreach (dahua::byTypeAndSearchConfiguration('dahua', array('type' => dahua::TYPE_CAMERA)) as $camera) {
	$dahuaCameras[] = array('id' => $camera->getId(), 'name' => $camera->getName());
}
$dahuaEvents = array();
foreach (dahua::$_channelEvents as $definition) {
	$dahuaEvents[] = array('id' => $definition['logicalId'], 'name' => __($definition['name'], __FILE__));
}
sendVarToJS('dahuaCameras', $dahuaCameras);
sendVarToJS('dahuaEvents', $dahuaEvents);
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter un NVR}}</span>
			</div>
			<div class="cursor logoPrimary" id="bt_dahuaAddRule">
				<i class="fas fa-project-diagram"></i>
				<br>
				<span>{{Ajouter une règle}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<?php
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';

		// Aide affichée tant qu'aucun équipement n'existe (installation neuve).
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun équipement pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Ajouter un NVR » et donnez-lui un nom.}}</li>';
			echo '<li>{{Renseignez son adresse IP, ses ports et ses identifiants.}}</li>';
			echo '<li>{{Enregistrez l\'équipement, puis cliquez sur « Tester la connexion ».}}</li>';
			echo '<li>{{Cliquez enfin sur « Découvrir les caméras » : un équipement est créé pour chaque canal du NVR.}}</li>';
			echo '</ol>';
			echo '</div>';
		}

		// Affichage d'une vignette d'équipement.
		$displayCard = function ($_eqLogic, $_icon) {
			$opacity = ($_eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $_eqLogic->getId() . '">';
			echo '<i class="fas ' . $_icon . '" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $_eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo ($_eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		};

		// Les NVR portent la connexion, les caméras en sont les canaux, les règles
		// corrèlent leurs détections : trois listes distinctes. Le type inconnu
		// retombe sur NVR, c'est la valeur que preSave() pose par défaut.
		$nvrEqLogics = array();
		$cameraEqLogics = array();
		$ruleEqLogics = array();
		foreach ($eqLogics as $eqLogic) {
			switch ($eqLogic->getConfiguration('type')) {
				case dahua::TYPE_CAMERA: $cameraEqLogics[] = $eqLogic; break;
				case dahua::TYPE_RULE:   $ruleEqLogics[] = $eqLogic;   break;
				default:                 $nvrEqLogics[] = $eqLogic;    break;
			}
		}

		echo '<legend><i class="fas fa-server"></i> {{NVR}}</legend>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($nvrEqLogics as $eqLogic) {
			$displayCard($eqLogic, 'fa-server');
		}
		echo '</div>';

		echo '<legend><i class="fas fa-video"></i> {{Caméras}}</legend>';
		if (count($cameraEqLogics) == 0 && count($nvrEqLogics) > 0) {
			echo '<div class="alert alert-info" style="margin:5px;">{{Aucune caméra. Ouvrez un NVR puis cliquez sur « Découvrir les caméras ».}}</div>';
		}
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($cameraEqLogics as $eqLogic) {
			$displayCard($eqLogic, 'fa-video');
		}
		echo '</div>';

		echo '<legend><i class="fas fa-project-diagram"></i> {{Règles de détection croisée}}</legend>';
		if (count($ruleEqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '{{Une règle déclenche des actions Jeedom quand plusieurs détections surviennent dans un court intervalle : un mouvement seul est souvent un faux positif, un mouvement accompagné d\'une ligne franchie ne l\'est presque jamais.}}';
			echo '</div>';
		}
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($ruleEqLogics as $eqLogic) {
			$displayCard($eqLogic, 'fa-project-diagram');
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex;">
			<span class="input-group-btn">
				<a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
				</a><a class="btn btn-sm btn-default eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span>
				</a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
				</a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
				</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Equipement}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<form class="form-horizontal">
					<fieldset>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Nom de l'équipement}}</label>
							<div class="col-sm-6">
								<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
								<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom}}">
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Objet parent}}</label>
							<div class="col-sm-6">
								<select class="eqLogicAttr form-control" data-l1key="object_id">
									<option value="">{{Aucun}}</option>
									<?php
									foreach (jeeObject::buildTree(null, false) as $object) {
										echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
									}
									?>
								</select>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Catégorie}}</label>
							<div class="col-sm-8">
								<?php
								foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
									echo '<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'] . '</label>';
								}
								?>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Activer}}</label>
							<div class="col-sm-2">
								<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Visible}}</label>
							<div class="col-sm-2">
								<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
							</div>
						</div>

						<div class="form-group">
							<label class="col-sm-3 control-label">{{Type d'équipement}}</label>
							<div class="col-sm-3">
								<select class="eqLogicAttr form-control" id="sel_dahuaType" data-l1key="configuration" data-l2key="type">
									<option value="nvr">{{NVR / Enregistreur}}</option>
									<option value="camera">{{Caméra (canal)}}</option>
									<option value="rule">{{Règle de détection croisée}}</option>
								</select>
							</div>
							<div class="col-sm-5">
								<span class="help-block" style="margin:0;">{{Le NVR porte la connexion ; chaque caméra correspond à un de ses canaux.}}</span>
								<span class="help-block" id="span_dahuaTypeFrozen" style="margin:0;display:none;"><i class="fas fa-exclamation-triangle"></i> {{Changer le type supprimera les commandes propres à l'ancien type.}}</span>
							</div>
						</div>
					</fieldset>

					<!-- ============================ NVR ============================ -->
					<fieldset class="dahuaNvrBlock">
						<legend><i class="fas fa-server"></i> {{Connexion au NVR}}</legend>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Adresse IP}}</label>
							<div class="col-sm-3">
								<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="192.168.0.176">
							</div>
							<label class="col-sm-1 control-label">{{Port}}</label>
							<div class="col-sm-2">
								<input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="port" placeholder="80" title="{{Port DHIP}}">
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Port HTTP (CGI)}}</label>
							<div class="col-sm-2">
								<input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="http_port" placeholder="80">
							</div>
							<div class="col-sm-6">
								<span class="help-block" style="margin:0;">{{Port de l'interface web du NVR, utilisé par les requêtes CGI et les captures. 80 par défaut.}}</span>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Transport}}</label>
							<div class="col-sm-3">
								<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="transport">
									<option value="auto">{{Automatique (DHIP puis CGI)}}</option>
									<option value="dhip">{{DHIP uniquement}}</option>
									<option value="cgi">{{CGI uniquement}}</option>
								</select>
							</div>
							<div class="col-sm-6">
								<span class="help-block" style="margin:0;">{{DHIP est le protocole natif Dahua (événements temps réel) ; CGI est le mode de secours en HTTP.}}</span>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Utilisateur}}</label>
							<div class="col-sm-3">
								<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="username" placeholder="admin" autocomplete="off">
							</div>
							<label class="col-sm-1 control-label">{{Mot de passe}}</label>
							<div class="col-sm-3">
								<input type="password" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="password" autocomplete="new-password">
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label"></label>
							<div class="col-sm-8">
								<a class="btn btn-info" id="bt_dahuaTestConnection"><i class="fas fa-plug"></i> {{Tester la connexion}}</a>
								<a class="btn btn-primary" id="bt_dahuaDiscover"><i class="fas fa-search"></i> {{Découvrir les caméras}}</a>
								<span class="label label-default" id="span_dahuaDaemonStatus" style="display:none;margin-left:10px;" title="{{État de la connexion vue par le démon}}"></span>
								<span class="help-block" style="margin:0;">{{La découverte crée un équipement par canal nommé sur le NVR. Enregistrez l'équipement avant de lancer la découverte.}}</span>
							</div>
						</div>
					</fieldset>

					<!-- ========================== CAMÉRA =========================== -->
					<fieldset class="dahuaCameraBlock">
						<legend><i class="fas fa-video"></i> {{Rattachement}}</legend>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{NVR}}</label>
							<div class="col-sm-4">
								<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="nvr_id">
									<option value="">{{Sélectionnez un NVR}}</option>
									<?php
									foreach ($nvrs as $nvr) {
										echo '<option value="' . $nvr->getId() . '">' . $nvr->getName() . ' (' . $nvr->getConfiguration('ip') . ')</option>';
									}
									?>
								</select>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Canal}}</label>
							<div class="col-sm-2">
								<input type="number" min="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="channel" placeholder="1">
							</div>
							<div class="col-sm-6">
								<span class="help-block" style="margin:0;">{{Numéro affiché par le NVR (D1 = 1, D2 = 2...).}}</span>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Preset PTZ par défaut}}</label>
							<div class="col-sm-2">
								<input type="number" min="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="preset" placeholder="1">
							</div>
							<div class="col-sm-6">
								<span class="help-block" style="margin:0;">{{Utilisé par la commande « Aller au preset » quand aucune valeur n'est passée.}}</span>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label"></label>
							<div class="col-sm-8">
								<a class="btn btn-info" id="bt_dahuaSnapshot"><i class="fas fa-camera"></i> {{Capturer une image maintenant}}</a>
							</div>
						</div>
						<div class="form-group">
							<div class="col-sm-offset-3 col-sm-8">
								<img id="img_dahuaSnapshot" style="max-width:100%;display:none;border-radius:4px;">
							</div>
						</div>
					</fieldset>

					<!-- =========================== RÈGLE =========================== -->
					<fieldset class="dahuaRuleBlock">
						<legend><i class="fas fa-bullseye"></i> {{Détections à rapprocher}}</legend>

						<div class="form-group">
							<label class="col-sm-3 control-label">{{Modèle}}</label>
							<div class="col-sm-4">
								<select class="form-control" id="sel_dahuaRuleTemplate">
									<option value="">{{Partir d'un modèle...}}</option>
									<option value="double">{{Double détection sur une caméra}}</option>
									<option value="human">{{Confirmation humaine}}</option>
									<option value="corroborate">{{Intrusion corroborée (2 caméras)}}</option>
									<option value="prowler">{{Rôdeur (3 détections en 1 minute)}}</option>
								</select>
							</div>
							<div class="col-sm-5">
								<span class="help-block" style="margin:0;">{{Remplit les conditions et les délais. Tout reste modifiable ensuite.}}</span>
							</div>
						</div>

						<div class="form-group">
							<div class="col-sm-offset-3 col-sm-9">
								<div class="table-responsive">
									<table id="table_dahuaConditions" class="table table-bordered table-condensed">
										<thead>
											<tr>
												<th style="width:40%;">{{Caméra}}</th>
												<th style="width:40%;">{{Détection}}</th>
												<th style="width:12%;">{{Fois}}</th>
												<th style="width:8%;"></th>
											</tr>
										</thead>
										<tbody></tbody>
									</table>
								</div>
								<a class="btn btn-default btn-sm" id="bt_dahuaAddCondition"><i class="fas fa-plus-circle"></i> {{Ajouter une condition}}</a>
								<span class="help-block" style="margin:5px 0 0 0;">{{« Fois » exige plusieurs occurrences de la même détection : trois passages devant la même caméra, par exemple.}}</span>
								<div id="span_dahuaRuleSummary" class="help-block" style="margin:8px 0 0 0;"></div>
							</div>
						</div>

						<div class="form-group">
							<label class="col-sm-3 control-label">{{Il faut}}</label>
							<div class="col-sm-4">
								<select class="eqLogicAttr form-control" id="sel_dahuaRuleMode" data-l1key="configuration" data-l2key="mode">
									<option value="all">{{Toutes les conditions ci-dessus}}</option>
									<option value="count">{{Au moins N conditions parmi elles}}</option>
								</select>
							</div>
							<div class="col-sm-4 dahuaRuleThreshold" style="display:none;">
								<div class="input-group">
									<span class="input-group-addon">{{N =}}</span>
									<input type="number" min="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="threshold" placeholder="2" title="{{Nombre de conditions à satisfaire}}">
								</div>
							</div>
						</div>

						<div class="form-group">
							<label class="col-sm-3 control-label">{{Dans une fenêtre de}}</label>
							<div class="col-sm-2">
								<input type="number" min="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="window" placeholder="15">
							</div>
							<div class="col-sm-7">
								<span class="help-block" style="margin:0;">{{Secondes. Écart maximal entre la première et la dernière détection. Toute la chaîne est à la seconde : une fenêtre de 15 s vaut en pratique 14 à 16 s.}}</span>
							</div>
						</div>

						<div class="form-group">
							<label class="col-sm-3 control-label">{{Caméras concernées}}</label>
							<div class="col-sm-4">
								<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="camera_scope">
									<option value="any">{{Peu importe lesquelles}}</option>
									<option value="same">{{Toutes sur la même caméra}}</option>
									<option value="distinct">{{Sur au moins deux caméras différentes}}</option>
								</select>
							</div>
							<div class="col-sm-5">
								<span class="help-block" style="margin:0;">{{« La même caméra » est la double détection locale ; « deux caméras différentes » est la corroboration, la répétition sur un seul canal n'y suffit pas.}}</span>
							</div>
						</div>

						<legend><i class="fas fa-stopwatch"></i> {{Délais}}</legend>

						<div class="form-group">
							<label class="col-sm-3 control-label">{{Temporisation}}</label>
							<div class="col-sm-2">
								<input type="number" min="0" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cooldown" placeholder="30">
							</div>
							<div class="col-sm-7">
								<span class="help-block" style="margin:0;">{{Secondes. Délai minimal avant un nouveau déclenchement. Sans lui, un seul passage déclenche cinq fois.}}</span>
							</div>
						</div>

						<div class="form-group">
							<label class="col-sm-3 control-label">{{Durée de maintien}}</label>
							<div class="col-sm-2">
								<input type="number" min="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="hold" placeholder="10">
							</div>
							<div class="col-sm-7">
								<span class="help-block" style="margin:0;">{{Secondes pendant lesquelles la commande « Déclenchée » reste à 1.}}</span>
							</div>
						</div>

						<legend><i class="fas fa-lock"></i> {{Armement}}</legend>

						<div class="form-group">
							<label class="col-sm-3 control-label">{{Condition d'armement}}</label>
							<div class="col-sm-6">
								<div class="input-group">
									<input type="text" class="eqLogicAttr form-control" id="in_dahuaArmCondition" data-l1key="configuration" data-l2key="arm_condition" placeholder="#[Maison][Présence][Etat]# == 0">
									<span class="input-group-btn">
										<a class="btn btn-default roundedRight" id="bt_dahuaArmCondition" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>
									</span>
								</div>
							</div>
							<div class="col-sm-3">
								<span class="help-block" style="margin:0;">{{Facultatif. La règle ne déclenche que si cette expression est vraie.}}</span>
							</div>
						</div>
						<div class="form-group">
							<div class="col-sm-offset-3 col-sm-8">
								<span class="help-block" style="margin:0;">{{Décocher « Activer » en haut de cette page désarme complètement la règle, et un scénario peut le faire aussi.}}</span>
							</div>
						</div>

						<legend><i class="fas fa-bolt"></i> {{Actions au déclenchement}}</legend>
						<div class="form-group">
							<div class="col-sm-offset-1 col-sm-10">
								<div id="div_dahuaRuleActions"></div>
								<a class="btn btn-default btn-sm bt_dahuaAddAction" data-container="div_dahuaRuleActions"><i class="fas fa-plus-circle"></i> {{Ajouter une action}}</a>
								<span class="help-block" style="margin:5px 0 0 0;">{{La commande « Déclenchée » change d'état dans tous les cas : ces actions ne sont utiles que si vous voulez éviter d'écrire un scénario.}}</span>
							</div>
						</div>

						<legend><i class="fas fa-undo"></i> {{Actions au retour au repos}}</legend>
						<div class="form-group">
							<div class="col-sm-offset-1 col-sm-10">
								<div id="div_dahuaRuleActionsEnd"></div>
								<a class="btn btn-default btn-sm bt_dahuaAddAction" data-container="div_dahuaRuleActionsEnd"><i class="fas fa-plus-circle"></i> {{Ajouter une action}}</a>
								<span class="help-block" style="margin:5px 0 0 0;">{{Jouées à la fin de la durée de maintien. Sur une installation calme, le retour au repos peut prendre jusqu'à une minute de plus : il est vérifié à chaque détection reçue, et à défaut une fois par minute.}}</span>
							</div>
						</div>

						<legend><i class="fas fa-vial"></i> {{Mise au point}}</legend>

						<div class="form-group">
							<label class="col-sm-3 control-label">{{État}}</label>
							<div class="col-sm-8">
								<span id="span_dahuaRuleState" class="label label-default" style="display:none;"></span>
								<span id="span_dahuaRuleDetail" class="help-block" style="margin:5px 0 0 0;"></span>
							</div>
						</div>
						<div class="form-group">
							<div class="col-sm-offset-3 col-sm-8">
								<a class="btn btn-info" id="bt_dahuaTestRule"><i class="fas fa-vial"></i> {{Tester la règle}}</a>
								<a class="btn btn-default" id="bt_dahuaResetRule"><i class="fas fa-undo"></i> {{Réinitialiser}}</a>
								<span class="help-block" style="margin:5px 0 0 0;">{{« Tester » joue le déclenchement pour de vrai, actions comprises, et démarre la temporisation. « Réinitialiser » remet la règle au repos, joue les actions de retour et oublie les détections en attente.}}</span>
							</div>
						</div>
					</fieldset>
				</form>
			</div>

			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<a class="btn btn-success btn-sm cmdAction pull-right" data-action="add"><i class="fas fa-plus-circle"></i> {{Commande}}</a>
				<br><br>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th>{{Nom}}</th>
								<th>{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th>{{Action}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'dahua', 'js', 'dahua'); ?>
