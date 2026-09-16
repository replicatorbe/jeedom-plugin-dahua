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
    dahua_migrateRuleImages();
}

function dahua_remove() {
    try {
        dahua::deamon_stop();
    } catch (Throwable $e) {
        // le plugin peut être désactivé alors que la classe n'est plus chargeable
    }
}

/* Les images du plugin doivent exister quelque part et rester interdites d'accès
 * direct : captures courantes dans data/snapshots, dossiers d'alerte dans
 * data/alerts. Les unes comme les autres ne sont servies que par
 * core/php/snapshot.php, après contrôle de session — le .htaccess posé à la
 * racine de data/ couvre donc les deux. */
function dahua_prepareData() {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir . '/snapshots')) {
        @mkdir($dir . '/snapshots', 0775, true);
    }
    if (!is_dir($dir . '/alerts')) {
        @mkdir($dir . '/alerts', 0775, true);
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
            /*
             * addCmdIfMissing() ne touche jamais une commande déjà là — c'est ce
             * qui protège la personnalisation de l'utilisateur. Conséquence : sur
             * une installation existante, « Connecté » reste visible ET le widget
             * affiche la même information dans son bandeau. On masque donc ici,
             * une seule fois, ce que la création pose désormais masqué.
             */
            if ($type == dahua::TYPE_NVR) {
                $cmd = $eqLogic->getCmd('info', 'online');
                if (is_object($cmd) && $cmd->getIsVisible() == 1) {
                    $cmd->setIsVisible(0);
                    $cmd->save();
                }
            }
        } catch (Throwable $e) {
            log::add('dahua', 'error', 'migration ' . $eqLogic->getName() . ' : ' . $e->getMessage());
        }
    }
    config::save('migration::camera_online', 1, 'dahua');
}

/*
 * Donne aux règles déjà créées la commande qui porte les images de leur alerte.
 *
 * Sans cette passe, rien ne paraîtrait cassé et tout le serait : postSave() ne
 * s'exécute qu'à l'enregistrement d'un équipement, et checkAndUpdateCmd() sur
 * une commande absente retourne false SANS RIEN JOURNALISER. Une règle créée
 * avant cette version continuerait donc de se déclencher, d'écrire son dossier
 * d'alerte sur le disque, et n'en montrerait jamais rien — la panne muette dont
 * ce plugin a déjà fait deux fois les frais.
 */
function dahua_migrateRuleImages() {
    if (config::byKey('migration::rule_images', 'dahua', 0) == 1) {
        return;
    }
    $complete = true;
    foreach (eqLogic::byType('dahua') as $eqLogic) {
        if ($eqLogic->getConfiguration('type') != dahua::TYPE_RULE) {
            continue;
        }
        if (is_object($eqLogic->getCmd('info', 'images'))) {
            continue;
        }
        try {
            /* postSave() plutôt que save() : la commande manquante est créée,
             * sans repasser par la normalisation du formulaire ni rejouer le
             * relâchement d'une règle en cours de déclenchement. */
            $eqLogic->postSave();
        } catch (Throwable $e) {
            $complete = false;
            log::add('dahua', 'error', 'migration ' . $eqLogic->getName() . ' : ' . $e->getMessage());
        }
    }
    /*
     * Le drapeau n'est posé que si TOUTES les règles sont passées. Le poser
     * quand même ferait qu'une règle en échec — collision de nom, équipement en
     * défaut — ne serait jamais retentée, et n'aurait donc jamais sa commande :
     * elle écrirait ses dossiers d'alerte sans jamais rien afficher. La
     * migration est sans effet sur une règle déjà traitée, la rejouer ne coûte
     * rien.
     */
    if ($complete) {
        config::save('migration::rule_images', 1, 'dahua');
    }
}
