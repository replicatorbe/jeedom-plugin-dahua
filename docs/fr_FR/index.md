# Plugin Dahua NVR

Reçoit en temps réel les événements d'un NVR ou d'une caméra Dahua et les expose
sous forme de commandes Jeedom : détection de mouvement, détection intelligente
(humain, véhicule), franchissement de ligne, perte vidéo, capture d'images et
contrôle PTZ.

La liaison utilise le protocole **DHIP**, le canal natif des équipements Dahua.
Un démon maintient une connexion permanente vers le NVR : les événements
arrivent en quelques millisecondes, sans interrogation périodique.

## Installation

1. Installez le plugin, puis activez-le.
2. Ouvrez la configuration du plugin et laissez les valeurs par défaut si vous
   n'avez pas de conflit de port.
3. Créez un équipement de type **NVR / Enregistreur**.

## Configurer le NVR

| Champ | Valeur |
|---|---|
| Adresse IP | l'adresse de votre NVR sur le réseau local |
| Port | `80` dans la quasi-totalité des cas |
| Utilisateur | un compte du NVR, `admin` par défaut |
| Mot de passe | le mot de passe de ce compte |

Enregistrez, puis utilisez **Tester la connexion** : le plugin affiche le modèle,
la version de firmware et le nombre de canaux. Si ce test échoue, rien d'autre ne
fonctionnera — corrigez-le avant de continuer.

> Créez de préférence un compte dédié sur le NVR plutôt que d'utiliser `admin`.
> Le mot de passe est stocké dans la base Jeedom sans chiffrement, comme pour
> tous les plugins qui pilotent un équipement réseau.

## Créer les caméras

Le bouton **Découvrir les caméras** interroge le NVR et crée un équipement par
canal, avec le nom déjà configuré dans le NVR. Chaque caméra reçoit ses
commandes automatiquement.

Vous pouvez aussi créer une caméra à la main : choisissez le type
**Caméra (canal)**, sélectionnez le NVR et saisissez le numéro de canal tel que
le NVR l'affiche (`D1` → 1, `D2` → 2...).

## Commandes disponibles

### Sur chaque caméra

| Commande | Type | Description |
|---|---|---|
| Mouvement | binaire | détection de mouvement classique |
| Humain détecté | binaire | détection intelligente SMD |
| Véhicule détecté | binaire | détection intelligente SMD |
| Ligne franchie | binaire | règle IVS de franchissement de ligne |
| Zone franchie | binaire | règle IVS d'intrusion de zone |
| Perte vidéo | binaire | le canal ne reçoit plus de flux |
| Caméra masquée | binaire | objectif obstrué |
| Visage détecté | binaire | détection de visage |
| Dernier événement | texte | code et action du dernier événement reçu |
| Dernière image | texte | chemin de la dernière capture |
| Capturer une image | action | déclenche une capture immédiate |
| Aller au preset | action | rappelle un preset PTZ |

Les commandes binaires passent à `1` au début de l'événement et retombent à `0`
à sa fin. Les événements que le NVR n'annonce pas comme terminés retombent
automatiquement après le délai réglé dans la configuration du plugin.

### Sur le NVR

`Connecté`, `Dernier événement`, `Défaut de stockage`, `Espace disque faible`,
`Échec de connexion`, `Alarme entrée locale`, et une action `Reconnecter`.

## Utilisation dans un scénario

Déclenchement sur la détection d'un humain :

```
Déclencheur : #[Extérieur][NORD][Humain détecté]# == 1
```

Envoyer la dernière image dans une notification :

```
#[Extérieur][NORD][Dernière image]#
```

## Captures d'images

Activez **Capturer à chaque détection** pour qu'une image soit prise à chaque
début d'événement. Le rythme est limité à une capture toutes les 10 secondes par
caméra afin de ne pas saturer le NVR. Les images sont stockées dans
`plugins/dahua/data/snapshots` et les plus anciennes sont supprimées
automatiquement.

## PTZ

La commande **Aller au preset** rappelle un preset enregistré dans le NVR. Sans
valeur, elle utilise le preset par défaut défini sur l'équipement. Avec une
valeur (via un scénario), elle rappelle le preset correspondant.

## En cas de problème

Le plugin écrit dans deux journaux :

- **dahua** — le traitement des événements côté Jeedom ;
- **dahuad** — la connexion au NVR et le flux brut.

Passez le niveau de log en *Debug* pour voir chaque événement reçu.

| Symptôme | Cause probable |
|---|---|
| Démon non démarrable | aucun NVR configuré, ou équipement désactivé |
| `utilisateur inconnu ou mot de passe incorrect` | identifiants erronés |
| `compte verrouillé après trop de tentatives` | le NVR a bloqué le compte, attendez ou débloquez-le depuis son interface |
| `compte déjà connecté depuis un autre poste` | la limite de sessions du NVR est atteinte |
| Aucun événement | la détection n'est pas activée sur le canal dans le NVR |
| Humain/Véhicule toujours à 0 | la détection intelligente (SMD) n'est pas activée sur ce canal |

Le NVR limite le nombre de connexions simultanées (10 par défaut). Si vous avez
plusieurs clients connectés, libérez-en avant de lancer le démon.
