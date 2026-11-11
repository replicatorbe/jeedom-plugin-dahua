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
 * Confirmation d'une perte de caméra par sondes successives.
 *
 * Le démon sonde périodiquement l'état des canaux (RemoteDevice, toutes les
 * camera_check_interval secondes). Une seule sonde négative suffisait à déclarer
 * la caméra perdue ; or certaines caméras (SUDPTZ, canal 8) décrochent quelques
 * secondes plusieurs fois par jour : une sonde tombe sur ce trou, la suivante
 * les retrouve, et chaque fois une notification « hors ligne » partait pour une
 * minute d'absence.
 *
 * La perte n'est donc déclarée qu'à la CONFIRM-ième sonde négative consécutive.
 * Le retour reste immédiat : une sonde positive suffit, et remet le compte à
 * zéro. Le prix est un délai de détection allongé d'une période de sonde.
 *
 * Le callback est un processus neuf à chaque lot : le compte vit dans le cache,
 * comme l'état d'alerte des caméras (dahua::camState).
 */
class dahuaProbe {

    const CACHE   = 'dahua::probemiss::';
    const CONFIRM = 2;

    /*
     * Enregistre une sonde d'une caméra jusque-là en ligne, et dit si la perte
     * est confirmée. Une sonde positive efface le compte et rend false.
     */
    public static function lossConfirmed($_camId, $_up) {
        $key = self::CACHE . (int) $_camId;
        $misses = (int) cache::byKey($key)->getValue(0);
        if ($_up) {
            if ($misses > 0) {
                cache::delete($key);
            }
            return false;
        }
        $misses++;
        if ($misses >= self::CONFIRM) {
            cache::delete($key);
            return true;
        }
        cache::set($key, $misses, 0);
        return false;
    }
}
