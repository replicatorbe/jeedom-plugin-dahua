# Plugin Dahua NVR

Reçoit en temps réel les événements d'un NVR ou d'une caméra Dahua et les expose
sous forme de commandes Jeedom : détection de mouvement, détection intelligente
(humain, véhicule), franchissement de ligne, perte vidéo, capture d'images et
contrôle PTZ.

Un démon maintient une connexion permanente vers le NVR : les événements
arrivent en quelques centaines de millisecondes, sans interrogation périodique.
Ce démon est écrit en PHP et n'installe aucune dépendance.

## Installation

1. Installez le plugin, puis activez-le.
2. Ouvrez la configuration du plugin et laissez les valeurs par défaut si vous
   n'avez pas de conflit de port.
3. Créez un équipement de type **NVR / Enregistreur**.

## Configurer le NVR

| Champ | Valeur |
|---|---|
| Adresse IP | l'adresse de votre NVR sur le réseau local |
| Port | le port du protocole DHIP, `80` dans la plupart des cas |
| Port HTTP (CGI) | le port de l'interface web, `80` par défaut |
| Transport | `Automatique (DHIP puis CGI)`, sauf raison particulière (voir plus bas) |
| Utilisateur | un compte du NVR, `admin` par défaut |
| Mot de passe | le mot de passe de ce compte |

Enregistrez, puis utilisez **Tester la connexion** : le plugin affiche le modèle,
la version de firmware et le nombre de canaux. Il interroge au passage le NVR sur
ses sorties d'alarme et sur le contrôle coaxial (éclairage, sirène). Si ce test
échoue, rien d'autre ne fonctionnera — corrigez-le avant de continuer.

> Créez de préférence un compte dédié sur le NVR plutôt que d'utiliser `admin`.
> Le mot de passe est stocké dans la base Jeedom sans chiffrement, comme pour
> tous les plugins qui pilotent un équipement réseau.

### Port et port HTTP

Deux ports sont demandés parce qu'ils ne sont pas toujours identiques :

- **Port** sert à la connexion événementielle (DHIP).
- **Port HTTP (CGI)** sert aux captures d'images, aux commandes PTZ et au
  transport CGI, qui passent tous par l'API web du NVR.

Sur un NVR sorti d'usine, les deux valent `80` et il n'y a rien à changer. Si
vous avez déplacé le service DHIP sur `5000` tout en gardant l'interface web sur
`80`, renseignez `5000` en port et `80` en port HTTP.

## Choix du transport

Chaque NVR a un réglage **Transport** avec trois valeurs.

| Valeur | Comportement |
|---|---|
| Automatique (DHIP puis CGI) | essaie DHIP, bascule sur CGI après deux échecs de connexion |
| DHIP uniquement | n'utilise que DHIP, ne bascule jamais |
| CGI uniquement | n'utilise que le long-polling HTTP |

**DHIP** est le protocole natif des équipements Dahua. C'est le plus riche : il
est le seul à remonter les événements des interphones VTO/VTH (appel, ouverture
de porte). En contrepartie, il détecte une coupure réseau en une minute environ,
le temps que son keepalive expire.

**CGI** est du long-polling HTTP. Le NVR envoie un battement toutes les cinq
secondes : une coupure est donc vue en une quinzaine de secondes. Certains
firmwares récents refusent l'authentification DHIP mais acceptent parfaitement
le CGI — c'est la raison d'être de la bascule automatique.

En pratique, laissez **Automatique**. Forcez `CGI uniquement` si votre NVR
n'accepte pas DHIP et que vous voulez éviter les deux tentatives inutiles à
chaque démarrage. Forcez `DHIP uniquement` si vous avez un interphone et que
vous ne voulez surtout pas perdre ses événements lors d'une bascule.

Le transport réellement utilisé est indiqué dans le log `dahuad` à chaque
connexion.

## Créer les caméras

Le bouton **Découvrir les caméras** interroge le NVR et crée un équipement par
canal, avec le nom déjà configuré dans le NVR. Chaque caméra reçoit ses
commandes automatiquement. Enregistrez le NVR avant de lancer la découverte.

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
| Stationnement | binaire | véhicule stationné dans une zone interdite |
| Objet abandonné | binaire | objet laissé dans la zone surveillée |
| Objet retiré | binaire | objet retiré de la zone surveillée |
| Rôdeur | binaire | présence prolongée dans la zone |
| Anomalie sonore | binaire | son inhabituel détecté par le micro |
| Variation sonore | binaire | changement brusque du niveau sonore |
| Détection incendie | binaire | détection de flamme ou de fumée |
| Dernier événement | texte | code et action du dernier événement reçu |
| Date du dernier événement | texte | horodatage de ce dernier événement |
| Dernière image | texte | adresse de la dernière capture |
| Capturer une image | action | déclenche une capture immédiate |
| Aller au preset | action | rappelle un preset PTZ |

Les commandes binaires passent à `1` au début de l'événement et retombent à `0`
à sa fin. Les événements que le NVR n'annonce pas comme terminés retombent
automatiquement après le délai réglé dans la configuration du plugin.

Toutes ces commandes sont créées, mais elles ne remonteront quelque chose que si
la détection correspondante est activée sur le canal, dans le NVR. Une caméra
sans micro ne remontera jamais d'anomalie sonore, par exemple.

Pour ne pas afficher quinze lignes par caméra, seules les détections les plus
courantes sont visibles au départ : mouvement, humain, véhicule et perte vidéo.
Les autres existent et fonctionnent, elles sont simplement masquées. Cochez
« Afficher » dans l'onglet **Commandes** de l'équipement pour en montrer une.

### Sur le NVR

| Commande | Type | Description |
|---|---|---|
| Connecté | binaire | le démon a une connexion établie vers ce NVR |
| Stockage absent | binaire | aucun disque détecté |
| Défaut de stockage | binaire | erreur sur le disque |
| Espace disque faible | binaire | le disque arrive à saturation |
| Échec de connexion | binaire | tentative d'authentification refusée sur le NVR |
| Alarme entrée locale | binaire | entrée d'alarme physique du NVR |
| Changement réseau | binaire | modification de la configuration réseau |
| Dernier événement | texte | code et action du dernier événement global |
| Date du dernier événement | texte | horodatage de ce dernier événement |
| Reconnecter | action | force le démon à relancer la connexion |
| Sortie alarme ON / OFF | action | bascule la première sortie d'alarme du NVR |

## Relais, sirène et sorties d'alarme

Le plugin expose des commandes d'action pour les sorties d'alarme du NVR
(`Sortie alarme ON` / `Sortie alarme OFF`) et pour l'éclairage ou la sirène des
caméras (`Lumière blanche ON` / `OFF`, `Sirène ON` / `OFF`). Elles sont masquées
par défaut : affichez-les depuis l'onglet **Commandes** si votre matériel les
gère.

**Elles ne fonctionnent que si le matériel les expose réellement.** C'est le
point qui déçoit le plus souvent :

- Beaucoup de NVR n'ont aucune entrée/sortie d'alarme physique. C'est le cas de
  toute la série NVR41xx-xP, par exemple. La commande existe dans Jeedom, mais
  le NVR répond par une erreur.
- Les caméras raccordées au switch PoE interne du NVR sont sur un réseau privé
  (typiquement `10.1.1.x`) qui n'est pas routé. Jeedom ne peut pas les joindre
  directement : seuls les événements transitant par le NVR remontent, et le
  pilotage de leur éclairage ou de leur sirène n'est pas possible.
- Une caméra branchée sur votre réseau local, avec sa propre adresse IP, et dont
  le modèle possède un projecteur ou une sirène, fonctionnera normalement.

Pour savoir où vous en êtes, le plus simple est d'afficher la commande depuis
l'onglet **Commandes** et de la tester une fois. Si le matériel ne la gère pas,
Jeedom affiche une erreur explicite du type « Ce matériel ne dispose pas de
sortie d'alarme » au lieu d'échouer en silence. Le bouton **Tester la connexion**
interroge lui aussi le NVR sur ces capacités.

## Captures d'images

Activez **Capturer à chaque détection** dans la configuration du plugin pour
qu'une image soit prise à chaque début d'événement. Le rythme est limité à une
capture toutes les 10 secondes par caméra afin de ne pas saturer le NVR. Les
images sont stockées dans `plugins/dahua/data/snapshots` et les plus anciennes
sont supprimées automatiquement, selon le nombre réglé dans la configuration.

Le bouton **Capturer une image maintenant**, dans l'onglet de la caméra,
déclenche une capture et affiche le résultat immédiatement. C'est le moyen le
plus rapide de vérifier que le port HTTP et les identifiants sont bons.

### Ce que contient « Dernière image »

La commande renvoie une adresse du type :

```
plugins/dahua/core/php/snapshot.php?file=cam12_20250912-204501_a1b2c3d4.jpg
```

Ce n'est pas un fichier public : c'est un passe-plat PHP qui vérifie que la
requête vient d'une session Jeedom authentifiée avant de servir l'image. Le
dossier des captures n'est pas accessible directement.

Conséquence à connaître :

- **Dans Jeedom** (widget de la caméra, tuile du dashboard, interface mobile),
  l'image s'affiche normalement : le navigateur est déjà authentifié.
- **Hors de Jeedom**, non. Une notification Telegram, un mail ou tout autre
  service externe à qui vous passeriez cette adresse ne pourra pas charger
  l'image : il n'a pas de session Jeedom et recevra une erreur d'accès.

Pour envoyer une image dans une notification, joignez le **fichier** plutôt que
l'adresse. Le chemin sur le disque est
`plugins/dahua/data/snapshots/<nom du fichier>` sous la racine de votre Jeedom,
et les plugins de notification qui acceptent une pièce jointe sur disque
(Telegram, Mail...) savent l'utiliser.

## PTZ

La commande **Aller au preset** rappelle un preset enregistré dans le NVR. Sans
valeur, elle utilise le preset par défaut défini sur l'équipement caméra. Avec
une valeur passée depuis un scénario, elle rappelle le preset correspondant.

Cette commande passe par le port HTTP. Elle ne concerne que les caméras
motorisées, et le preset doit exister dans le NVR.

## Configuration du plugin

| Réglage | Rôle |
|---|---|
| Port d'écoute local | port sur `127.0.0.1` par lequel Jeedom transmet ses ordres au démon (55060 par défaut). À changer seulement si un autre service occupe ce port. |
| Délai de reconnexion | attente avant de retenter une connexion perdue vers un NVR. |
| Durée des événements instantanés | délai après lequel une commande binaire retombe à 0 quand le NVR n'annonce pas la fin de l'événement. |
| Capturer à chaque détection | prend une image au début de chaque événement. |
| Captures conservées par caméra | au-delà, les plus anciennes sont supprimées. |

Le port d'écoute local n'est jamais exposé à l'extérieur : le démon n'écoute que
sur la boucle locale, et chaque ordre est signé par la clé API du plugin.

## Onglet Santé

L'onglet **Santé** de Jeedom (menu Analyse → Santé, section Dahua NVR) donne en
un coup d'œil :

- l'état du démon, démarré ou arrêté ;
- pour chaque NVR, s'il est connecté, avec son adresse.

C'est le premier endroit à regarder quand plus rien ne remonte.

## Règles de détection croisée

Une détection isolée se trompe souvent : une ombre, un insecte devant l'objectif
ou un phare qui balaie un mur suffisent à faire monter la détection de mouvement.
Deux détections différentes au même endroit, dans le même court intervalle, se
trompent beaucoup plus rarement. C'est ce que fait une **règle** : elle rapproche
plusieurs détections et ne déclenche que si elles surviennent ensemble.

Une règle est un équipement à part entière : elle apparaît sur le dashboard, son
état s'historise, elle se désactive comme n'importe quel équipement — y compris
depuis un scénario — et elle peut déclencher ses propres actions Jeedom sans
qu'aucun scénario ne soit nécessaire.

### Créer une règle

Sur la page du plugin, cliquez sur **Ajouter une règle** et donnez-lui un nom.

Le menu **Modèle** remplit le formulaire pour les cas les plus courants :

| Modèle | Ce qu'il configure |
|---|---|
| Double détection sur une caméra | Ligne franchie + Mouvement, même caméra, 15 s |
| Confirmation humaine | Humain détecté + Ligne franchie, même caméra, 20 s |
| Intrusion corroborée | 2 détections quelconques sur 2 caméras différentes, 30 s |
| Rôdeur | 3 détections sur la même caméra en 60 s |

Tout reste modifiable ensuite.

### Les conditions

Chaque ligne du tableau décrit une détection à attendre :

- **Caméra** — une caméra précise, ou *N'importe quelle caméra*.
- **Détection** — Mouvement, Ligne franchie, Humain détecté… ou *N'importe quelle
  détection*.
- **Fois** — nombre d'occurrences exigées. Laissez 1 dans le cas courant ; mettez
  3 pour « trois passages devant la même caméra ».

Une ligne dont la caméra ou la détection est restée sur « à choisir » est
**ignorée**. C'est volontaire : une règle enregistrée sans avoir été remplie ne
doit pas se déclencher à la première détection venue. Une phrase sous le tableau
récapitule en clair ce que la règle fera, et signale le cas où elle ne pourrait
jamais se déclencher.

Une même détection ne peut satisfaire qu'**une seule** condition. Si vous écrivez
« n'importe quelle détection » et « Mouvement sur NORD », il faudra bien deux
détections distinctes, pas un unique mouvement qui coche les deux cases.

**Il faut** choisit entre *toutes les conditions* et *au moins N d'entre elles*.
Le second mode sert à écrire « deux détections parmi ces quatre », sans imposer
lesquelles.

### « Les détections doivent venir… » — le champ à comprendre

C'est le réglage le moins intuitif de l'écran, et le plus important. Il ne fait
pas la même chose que la colonne « Sur quelle caméra ».

- La **colonne du tableau** dit *d'où une détection a le droit de venir*. C'est
  un filtre, appliqué ligne par ligne.
- Ce **champ-ci** dit *si les détections retenues doivent venir du même endroit*.
  Ce n'est pas un filtre : c'est une contrainte sur le **lien entre elles**.

Trois valeurs :

- *de n'importe quelles caméras* — aucune contrainte de provenance ;
- *toutes d'une seule et même caméra* — la double détection locale. « Ligne
  franchie » + « Mouvement », caméra laissée sur *n'importe laquelle* : la règle
  se déclenche dès qu'une caméra, n'importe laquelle, voit les deux. C'est ce
  qui élimine les faux positifs d'un point de vue précis ;
- *d'au moins deux caméras différentes* — la corroboration. Quelqu'un qui
  traverse le jardin est vu par deux caméras, une ombre ne l'est pas. La
  répétition sur une seule caméra n'y suffit jamais.

C'est ce champ qui fait tout le travail du modèle **Intrusion corroborée** :
cette règle n'a qu'une seule condition, « n'importe quelle détection, 2 fois ».
Prise seule, deux mouvements de la même caméra la satisferaient. Réglée sur
*d'au moins deux caméras différentes*, elle exige deux caméras distinctes.

À l'inverse, si vous nommez une caméra précise sur chaque ligne, ce champ ne
change presque rien — la provenance est déjà décidée. La page vous le dit sous
le menu déroulant.

Deux combinaisons rendent la règle **définitivement muette**, et la page les
signale en rouge avant que vous n'enregistriez : exiger *une seule et même
caméra* alors que les conditions en nomment plusieurs, ou exiger *deux caméras
différentes* alors qu'elles nomment toutes la même.

### Les délais

- **Fenêtre** — écart maximal entre la première et la dernière détection. Toute
  la chaîne, du NVR à Jeedom, travaille à la seconde : une fenêtre de 15 s vaut
  en pratique 14 à 16 s. Descendre sous 5 s est déconseillé, les moteurs de
  détection du NVR ne rendent pas leur verdict en même temps.
- **Temporisation** — délai minimal avant un nouveau déclenchement. Sans elle, un
  seul passage déclenche la règle cinq fois de suite.
- **Durée de maintien** — durée pendant laquelle la commande *Déclenchée* reste
  à 1.

### La condition d'armement

Champ facultatif. La règle ne déclenche que si l'expression y est vraie, par
exemple `#[Maison][Présence][Etat]# == 0` pour n'alerter qu'en votre absence.
C'est la même syntaxe que dans un scénario, et le bouton à droite du champ ouvre
le sélecteur de commandes.

Si l'expression est invalide, la règle **ne déclenche pas**, le journal `dahua`
l'indique en erreur et **un message apparaît dans le centre de messages** : une
alarme muette est préférable à une alarme qui part sur une expression cassée,
mais l'anomalie ne doit pas passer inaperçue. Le message disparaît de lui-même
dès que l'expression redevient exploitable.

Pour désarmer complètement une règle, décochez « Activer » en haut de sa page —
un scénario peut le faire aussi. Une règle déclenchée retombe proprement au
moment où on la désactive, actions de retour comprises.

### Les actions

Le bloc **Actions au déclenchement** utilise le même sélecteur que les scénarios :
commandes de n'importe quel plugin, scénarios, variables, messages. Les cases à
gauche de chaque ligne désactivent l'action ou l'exécutent en tâche de fond.

**Actions au retour au repos** est joué à la fin de la durée de maintien.

Ces deux blocs sont facultatifs. La commande *Déclenchée* change d'état dans tous
les cas : si vous préférez un scénario, déclenchez-le simplement dessus.

### Commandes créées

| Commande | Type | Rôle |
|---|---|---|
| Déclenchée | info / binaire | 1 pendant la durée de maintien. Historisée, type générique `ALARM_STATE`. |
| Détail du déclenchement | info / texte | « NORD Ligne franchie 12:00:03 + NORD Mouvement 12:00:05 » |
| Image du déclenchement | info / texte | Adresse de la dernière capture de la caméra qui a complété la corrélation |
| Tester | action | Joue le déclenchement pour de vrai, actions comprises. Masquée par défaut : elle exécute toutes les actions de la règle, y compris sur des équipements auxquels l'utilisateur du dashboard n'a pas forcément droit. |
| Réinitialiser | action | Remet la règle au repos, joue les actions de retour et oublie les détections en attente |

Les deux boutons **Tester la règle** et **Réinitialiser** sont également en bas de
la page de la règle, avec l'état courant et le dernier déclenchement.

Pour envoyer une photo dans une notification, utilisez *Image du déclenchement* :
l'adresse demande une session Jeedom, il faut donc joindre le fichier plutôt que
le lien si le destinataire est externe.

### Bon à savoir

La règle se fonde sur les **dates d'arrivée des détections**, pas sur l'état des
commandes de la caméra. C'est voulu : une détection instantanée retombe à 0 au
bout de quelques secondes, et une détection dont la fin s'est perdue reste à 1
indéfiniment. Une condition de scénario du type « les deux commandes sont à 1 »
échouerait dans le premier cas et déclencherait à tort dans le second.

Conséquence pratique : si Jeedom a été indisponible et que le démon envoie d'un
coup tout ce qu'il avait accumulé, les détections restent corrélées sur leurs
dates réelles. Une règle peut donc se déclencher avec du retard, mais jamais à
tort.

## Utilisation dans un scénario

Déclenchement sur la détection d'un humain :

```
Déclencheur : #[Extérieur][NORD][Humain détecté]# == 1
```

Rappeler le preset 3 puis capturer une image :

```
Action : #[Extérieur][NORD][Aller au preset]# avec le message 3
Action : #[Extérieur][NORD][Capturer une image]#
```

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
| Bascule permanente sur le CGI | le firmware refuse DHIP ; forcez `CGI uniquement` pour gagner du temps au démarrage |
| Aucun événement | la détection n'est pas activée sur le canal dans le NVR |
| Humain/Véhicule toujours à 0 | la détection intelligente (SMD) n'est pas activée sur ce canal |
| Capture impossible alors que les événements remontent | le port HTTP est faux, ou le compte n'a pas le droit de capture |
| Image absente dans une notification externe | attendu : l'adresse demande une session Jeedom, joignez le fichier |
| Sortie d'alarme sans effet | le matériel n'a pas cette sortie, ou la caméra est derrière le switch PoE du NVR |

Le NVR limite le nombre de connexions simultanées (10 par défaut). Si vous avez
plusieurs clients connectés, libérez-en avant de lancer le démon.
