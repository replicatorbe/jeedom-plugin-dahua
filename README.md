# Plugin Jeedom — Dahua NVR

Remonte en temps réel les événements d'un NVR ou d'une caméra Dahua dans Jeedom,
via le protocole natif **DHIP**.

Ce plugin succède au script [phpDahuaToJeedomBridge](https://github.com/replicatorbe/phpDahuaToJeedomBridge),
dont il reprend la mécanique de protocole en la réécrivant pour PHP 8 et en
l'intégrant proprement à Jeedom : plus de virtuel à câbler à la main, une
commande par type de détection et par caméra.

## Ce qu'il apporte

- **Un équipement par caméra**, créé automatiquement avec le nom défini dans le NVR.
- **Des commandes typées** plutôt qu'une chaîne de caractères à découper dans les
  scénarios : `Mouvement`, `Humain détecté`, `Véhicule détecté`, `Ligne franchie`...
- **Un démon robuste** : reconnexion automatique, keepAlive, réassemblage des
  trames TCP fragmentées, filtrage des événements émis à la cadence vidéo.
- **Capture d'images** sur détection ou à la demande, **contrôle PTZ** par preset.
- **Supervision** du NVR : connexion, stockage, échecs d'authentification.

## Prérequis

- Jeedom 4.4 ou supérieur
- PHP 8 avec les extensions `curl`, `sockets`, `pcntl`, `posix`
- Un NVR ou une caméra Dahua joignable sur le réseau local

Aucune dépendance à installer : le démon est écrit en PHP.

## Installation

Copiez le dossier dans `plugins/dahua` de votre Jeedom, puis activez le plugin.

## Architecture

```
Jeedom  ──lance──►  dahuad.php  ──DHIP/TCP──►  NVR Dahua
  ▲                     │
  └──HTTP POST JSON─────┘   plugins/dahua/core/php/jeeDahua.php
```

Le démon ne charge pas le cœur de Jeedom : il récupère sa configuration et pousse
ses événements par HTTP, authentifié par la clé API du plugin. Une mise à jour de
Jeedom ne peut donc ni l'interrompre ni le casser.

## Licence

AGPL v3
