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
				<span class="help-block" style="margin:0;">{{Les plus anciennes sont supprimées automatiquement. Voir aussi la durée de conservation plus bas.}}</span>
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
				<span class="help-block" style="margin:0;">{{Nombre total de dossiers d'alerte gardés, toutes règles confondues : une règle bavarde évince donc l'historique des autres, sauf la dernière alerte de chacune, toujours gardée. De 1 à 5000, 300 par défaut ; une valeur vide ou hors bornes revient au défaut. Compter environ 50 Mo par caméra concernée pour 300 alertes.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Alertes conservées en pleine résolution}}</label>
			<div class="col-md-2">
				<input type="number" min="0" max="5000" step="1" class="configKey form-control" data-l1key="alert_keep_full" placeholder="30">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Au-delà de ce rang, seules les vignettes et la description sont gardées : l'alerte reste consultable mais perd l'agrandissement. Une image pleine résolution pèse de 600 Ko à 1 Mo, et une alerte en porte jusqu'à deux par caméra. La dernière alerte de chaque règle, celle que montre sa tuile, garde toujours sa pleine résolution. 0 pour ne garder que des vignettes, y compris pour celle-là ; la valeur est ramenée à celle du réglage précédent si elle la dépasse.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Pleine résolution garantie pendant (jours)}}</label>
			<div class="col-md-2">
				<input type="number" min="0" max="5000" step="1" class="configKey form-control" data-l1key="alert_keep_full_days" placeholder="3">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Toute alerte plus récente que ce nombre de jours garde sa pleine résolution, quel que soit son rang : une règle bavarde ne peut plus priver d'agrandissement les alertes de la veille. 0 désactive cette garantie, seul le rang compte alors. 3 par défaut. Sans effet si le réglage précédent vaut 0. Le nombre total d'alertes conservées borne toujours la place occupée.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Clé d'accès aux images}}</label>
			<div class="col-md-4">
				<input class="form-control" readonly value="<?php echo htmlspecialchars(dahua::imageKey(), ENT_QUOTES); ?>">
			</div>
			<div class="col-md-4">
				<span class="help-block" style="margin:0;">{{Mot de passe en authentification HTTP Basic (utilisateur libre) pour lire la commande « Adresse de l'image (accès par clé) » sans session Jeedom, par exemple depuis le widget caméra de JeedomConnect. Ne donne accès qu'aux images.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-brain"></i> {{Confirmation IA (optionnelle)}}</legend>
		<div class="form-group">
			<div class="col-md-offset-1 col-md-10">
				<span class="help-block">{{Pour les règles où elle est cochée, chaque alerte envoie la capture fraîche au service ci-dessous, qui dit si la scène montre un humain, un véhicule, un animal, un insecte devant l'objectif, de la végétation ou rien. Les règles n'arment que si la catégorie fait partie de celles qu'elles acceptent. Utile pour les caméras sans détection humaine ni véhicule native, où un papillon de nuit volant devant l'IR satisfait motion + franchissement de ligne. Les images quittent votre réseau : avec OpenAI elles ne servent pas à entraîner les modèles mais sont conservées jusqu'à trente jours ; un modèle local compatible (Ollama) les garde chez vous. En cas de service injoignable ou de pas de réponse, l'alerte joue normalement (fail-open) : jamais d'alarme muette.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Clé API}}</label>
			<div class="col-md-4">
				<input type="password" class="configKey form-control" data-l1key="ai_apikey" autocomplete="new-password" placeholder="sk-…" />
			</div>
			<div class="col-md-4">
				<span class="help-block" style="margin:0;">{{Clé secrète du service. Vide désactive la confirmation IA sur toutes les règles, qu'elles l'aient cochée ou non.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Adresse du service}}</label>
			<div class="col-md-4">
				<input class="configKey form-control" data-l1key="ai_base_url" placeholder="https://api.openai.com/v1" />
			</div>
			<div class="col-md-4">
				<span class="help-block" style="margin:0;">{{Toute API compatible OpenAI convient, chemin de version compris : https://api.openai.com/v1, ou http://192.168.1.20:11434/v1 pour un Ollama local.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Modèle}}</label>
			<div class="col-md-4">
				<input class="configKey form-control" data-l1key="ai_model" placeholder="gpt-5.4-nano" />
			</div>
			<div class="col-md-4">
				<span class="help-block" style="margin:0;">{{Il doit savoir lire des images. Le petit modèle d'OpenAI répond en deux secondes environ, pour une fraction de centime par alerte.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Qualité d'image envoyée}}</label>
			<div class="col-md-4">
				<select class="configKey form-control" data-l1key="ai_detail">
					<option value="high">{{Haute (recommandé)}}</option>
					<option value="auto">{{Automatique}}</option>
					<option value="low">{{Basse (économique)}}</option>
				</select>
			</div>
			<div class="col-md-4">
				<span class="help-block" style="margin:0;">{{En basse qualité, l'image est réduite à 512 pixels et une silhouette au loin devient invisible. Haute recommandée pour les caméras extérieures.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Délai maximal (s)}}</label>
			<div class="col-md-2">
				<input class="configKey form-control" data-l1key="ai_timeout" type="number" min="5" max="60" placeholder="12" />
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{De 5 à 60 secondes, 12 par défaut. Passé ce délai sans réponse, la règle joue sans analyse. Doit rester inférieur au « Délai de confirmation » des règles qui utilisent l'IA.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Contexte des lieux (facultatif)}}</label>
			<div class="col-md-7">
				<textarea class="configKey form-control" data-l1key="ai_context" rows="2" maxlength="500" placeholder="{{Un chat du quartier passe souvent devant la caméra NORD. Les voisins garent leur camionnette en face.}}"></textarea>
			</div>
			<div class="col-md-1">
				<span class="help-block" style="margin:0;">{{Indication passée au modèle, 500 caractères maximum. Ni consigne, ni alibi.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-broom"></i> {{Conservation}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Durée de conservation maximale (jours)}}</label>
			<div class="col-md-2">
				<input type="number" min="0" max="5000" step="1" class="configKey form-control" data-l1key="max_age_days" placeholder="7">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Captures et dossiers d'alerte plus anciens sont supprimés, quels que soient les nombres réglés plus haut. Restent toujours la dernière capture de chaque caméra, celle que désigne sa commande « Fichier de l'image », et la dernière alerte de chaque règle, celle que montre sa tuile. Alertes vérifiées chaque minute, captures chaque heure. 0 désactive ; 7 par défaut ; une valeur vide ou hors bornes revient au défaut.}}</span>
			</div>
		</div>
	</fieldset>
</form>
