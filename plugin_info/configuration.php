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

	<fieldset>
		<legend><i class="fas fa-bell"></i> {{Dossiers d'alerte}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Capturer une image fraîche à chaque alerte}}</label>
			<div class="col-md-2">
				<input type="checkbox" class="configKey" data-l1key="alert_shot">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{En plus de l'image du moment de la détection, demander au NVR une capture des caméras concernées à l'instant du déclenchement. Décocher si le NVR est fragile ou la liaison lente.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Alertes conservées}}</label>
			<div class="col-md-2">
				<input type="number" min="1" max="5000" step="1" class="configKey form-control" data-l1key="alert_keep" placeholder="300">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Nombre total de dossiers d'alerte gardés, toutes règles confondues : une règle bavarde évince donc l'historique des autres. De 1 à 5000, 300 par défaut ; une valeur vide ou hors bornes revient au défaut. Compter environ 50 Mo par caméra concernée pour 300 alertes.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Alertes conservées en pleine résolution}}</label>
			<div class="col-md-2">
				<input type="number" min="0" max="5000" step="1" class="configKey form-control" data-l1key="alert_keep_full" placeholder="30">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Au-delà de ce rang, seules les vignettes et la description sont gardées : l'alerte reste consultable mais perd l'agrandissement. Une image pleine résolution pèse de 600 Ko à 1 Mo, et une alerte en porte jusqu'à deux par caméra. 0 pour ne garder que des vignettes ; la valeur est ramenée à celle du réglage précédent si elle la dépasse.}}</span>
			</div>
		</div>
	</fieldset>
</form>
