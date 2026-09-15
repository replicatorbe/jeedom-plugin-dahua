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

require_once __DIR__ . '/../../../core/php/core.inc.php';

function dahua_install() {
    dahua_prepareData();
    /*
     * Le démon appelle toujours le callback depuis 127.0.0.1 : restreindre l'API
     * du plugin à la boucle locale ne gêne rien et évite qu'elle réponde au LAN.
     */
    config::save('api::dahua::mode', 'localhost', 'core');
}

function dahua_update() {
    dahua_prepareData();
    dahua_migrateCommands();
    dahua_migrateCameraOnline();
}

function dahua_remove() {
    try {
        dahua::deamon_stop();
    } catch (Throwable $e) {
        // le plugin peut être désactivé alors que la classe n'est plus chargeable
    }
}

/* Le dossier des captures doit exister et rester interdit d'accès direct :
 * les images sont servies par core/php/snapshot.php après contrôle de session. */
function dahua_prepareData() {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir . '/snapshots')) {
        @mkdir($dir . '/snapshots', 0775, true);
    }
    @file_put_contents($dir . '/.htaccess', "Order allow,deny\nDeny from all\n");
}

/*
 * Aligne les équipements déjà créés sur les réglages d'affichage actuels :
 * sans cela, une caméra créée avant la mise à jour continue d'afficher quinze
 * croix rouges au repos.
 */
function dahua_migrateCommands() {
    if (config::byKey('migration::widgets', 'dahua', 0) == 1) {
        return;
    }
    foreach (eqLogic::byType('dahua') as $eqLogic) {
        if ($eqLogic->getConfiguration('type') != dahua::TYPE_CAMERA) {
            continue;
        }
        foreach (dahua::$_channelEvents as $def) {
            $cmd = $eqLogic->getCmd('info', $def['logicalId']);
            if (!is_object($cmd)) {
                continue;
            }
            $cmd->setIsVisible($def['visible']);
            $cmd->setIsHistorized($def['historized']);
            if ($def['logicalId'] != 'videoloss') {
                $cmd->setDisplay('invertBinary', 1);
            }
            if (isset($def['generic'])) {
                $cmd->setGeneric_type($def['generic']);
            }
            try {
                $cmd->save();
            } catch (Throwable $e) {
                log::add('dahua', 'error', 'migration ' . $def['logicalId'] . ' : ' . $e->getMessage());
            }
        }
    }
    config::save('migration::widgets', 1, 'dahua');
}

/*
 * Crée la commande de joignabilité sur les caméras déjà en place et la tuile de
 * synthèse sur les NVR existants.
 *
 * Une clé de migration NEUVE est indispensable : « migration::widgets » vaut
 * déjà 1 sur toute installation mise à jour une fois, et cette fonction-là sort
 * aussitôt. Réutiliser sa clé ne créerait donc rien, en silence.
 *
 * postSave() fait tout le travail — création des commandes manquantes, puis
 * nettoyage de celles de l'autre type. On ne duplique pas cette logique ici.
 */
function dahua_migrateCameraOnline() {
    if (config::byKey('migration::camera_online', 'dahua', 0) == 1) {
        return;
    }
    foreach (eqLogic::byType('dahua') as $eqLogic) {
        $type = $eqLogic->getConfiguration('type');
        if ($type != dahua::TYPE_CAMERA && $type != dahua::TYPE_NVR) {
            continue;
        }
        try {
            $eqLogic->postSave();
        } catch (Throwable $e) {
            log::add('dahua', 'error', 'migration ' . $eqLogic->getName() . ' : ' . $e->getMessage());
        }
    }
    config::save('migration::camera_online', 1, 'dahua');
}
