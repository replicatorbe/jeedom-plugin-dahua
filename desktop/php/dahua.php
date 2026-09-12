<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('dahua');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
$nvrs = dahua::byTypeAndSearchConfiguration('dahua', array('type' => dahua::TYPE_NVR));
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

		// Les NVR portent la connexion, les caméras en sont les canaux : deux listes distinctes.
		$nvrEqLogics = array();
		$cameraEqLogics = array();
		foreach ($eqLogics as $eqLogic) {
			if ($eqLogic->getConfiguration('type') == dahua::TYPE_CAMERA) {
				$cameraEqLogics[] = $eqLogic;
			} else {
				$nvrEqLogics[] = $eqLogic;
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
