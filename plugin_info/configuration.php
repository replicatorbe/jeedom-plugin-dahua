<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-plug"></i> {{Démon}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Port d'écoute local}}</label>
			<div class="col-md-2">
				<input class="configKey form-control" data-l1key="socketport" placeholder="55060">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Port sur 127.0.0.1 par lequel Jeedom transmet ses ordres au démon. À changer uniquement en cas de conflit.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Délai de reconnexion (s)}}</label>
			<div class="col-md-2">
				<input class="configKey form-control" data-l1key="reconnect_delay" placeholder="15">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Attente avant de retenter une connexion perdue vers un NVR.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-bolt"></i> {{Événements}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Durée des événements instantanés (s)}}</label>
			<div class="col-md-2">
				<input class="configKey form-control" data-l1key="pulse_duration" placeholder="5">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Certains événements n'ont pas de fin annoncée par le NVR. La commande retombe à 0 après ce délai.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-video"></i> {{Surveillance des caméras}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Intervalle de vérification (s)}}</label>
			<div class="col-md-2">
				<input class="configKey form-control" data-l1key="camera_check_interval" placeholder="60">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Une caméra qui décroche ne prévient pas : le NVR n'émet aucun événement. Le démon interroge donc son état à intervalle régulier. 0 désactive la surveillance ; minimum 15 secondes.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-camera"></i> {{Captures d'images}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Capturer à chaque détection}}</label>
			<div class="col-md-2">
				<input type="checkbox" class="configKey" data-l1key="snapshot_on_event">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Une capture au maximum toutes les 10 secondes par caméra. L'image est exposée par la commande « Dernière image ».}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Captures conservées par caméra}}</label>
			<div class="col-md-2">
				<input class="configKey form-control" data-l1key="snapshot_keep" placeholder="50">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Les plus anciennes sont supprimées automatiquement.}}</span>
			</div>
		</div>
	</fieldset>
</form>
