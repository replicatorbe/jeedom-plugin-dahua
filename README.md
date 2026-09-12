# Plugin Jeedom — Dahua NVR

Remonte en temps réel les événements d'un NVR ou d'une caméra Dahua dans Jeedom.

Ce plugin succède au script [phpDahuaToJeedomBridge](https://github.com/replicatorbe/phpDahuaToJeedomBridge),
dont il reprend la mécanique de protocole en la réécrivant pour PHP 8 et en
l'intégrant proprement à Jeedom : plus de virtuel à câbler à la main, une
commande par type de détection et par caméra.

## Ce qu'il apporte

- **Un équipement par caméra**, créé automatiquement avec le nom défini dans le NVR.
- **Des commandes typées** plutôt qu'une chaîne de caractères à découper dans les
  scénarios : `Mouvement`, `Humain détecté`, `Véhicule détecté`, `Ligne franchie`...
- **Deux transports au choix**, réglables par NVR :
  - **DHIP**, le protocole natif Dahua, le seul à remonter les événements des
    interphones VTO/VTH ;
  - **CGI**, du long-polling HTTP, plus tolérant avec les firmwares récents et
    qui détecte une coupure en une quinzaine de secondes au lieu d'une minute.

  En mode automatique, le démon tente DHIP puis bascule sur CGI après deux échecs.
- **Un démon robuste** : reconnexion automatique, keepAlive, réassemblage des
  trames TCP fragmentées, filtrage des événements émis à la cadence vidéo.
- **Capture d'images** sur détection ou à la demande, **contrôle PTZ** par preset.
- **Supervision** du NVR : connexion, stockage, échecs d'authentification.

## Ce qu'il ne peut pas faire

Le plugin expose des commandes pour les sorties d'alarme du NVR et pour
l'éclairage ou la sirène des caméras, mais elles ne fonctionnent que si le
matériel les possède réellement. Beaucoup de NVR, dont toute la série
NVR41xx-xP, n'ont aucune entrée/sortie d'alarme. Et les caméras raccordées au
switch PoE interne d'un NVR sont sur un réseau privé non routé (`10.1.1.x`) :
Jeedom ne peut pas les joindre directement. Le bouton « Tester la connexion »
indique les capacités réellement détectées.

Les captures sont servies par un passe-plat PHP authentifié. Elles s'affichent
dans Jeedom, mais leur URL ne peut pas être chargée par un service externe
(Telegram, mail...) : dans ce cas, joignez le fichier depuis
`plugins/dahua/data/snapshots`.

## Prérequis

- Jeedom 4.4 ou supérieur
- PHP 8 avec les extensions `curl`, `sockets`, `pcntl`, `posix`
- Un NVR ou une caméra Dahua joignable sur le réseau local

Aucune dépendance à installer : le démon est écrit en PHP, celui de votre
Jeedom, et n'a besoin de rien d'autre.

## Installation

Copiez le dossier dans `plugins/dahua` de votre Jeedom, puis activez le plugin.

## Architecture

```
Jeedom  ──lance──►  dahuad.php  ──DHIP ou CGI──►  NVR Dahua
  ▲                     │
  └──HTTP POST JSON─────┘   plugins/dahua/core/php/jeeDahua.php
```

Le démon ne charge pas le cœur de Jeedom : il récupère sa configuration et pousse
ses événements par HTTP, authentifié par la clé API du plugin. Une mise à jour de
Jeedom ne peut donc ni l'interrompre ni le casser.

## Documentation

- [Documentation française](docs/fr_FR/index.md)
- [English documentation](docs/en_US/index.md)

## Licence

AGPL v3
