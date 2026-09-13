# Changelog

## 0.4 — 13/09/2026

**Règles de détection croisée**

- Nouveau type d'équipement : une règle rapproche plusieurs détections survenues
  dans une même fenêtre de temps et ne déclenche que si elles arrivent ensemble.
  Un mouvement seul est souvent un faux positif, un mouvement accompagné d'une
  ligne franchie ne l'est presque jamais.
- Conditions libres : n'importe quelle caméra ou une caméra précise, n'importe
  quelle détection ou une détection précise, avec un nombre d'occurrences exigé.
- Trois portées de caméras : peu importe lesquelles, toutes sur la même caméra
  (double détection locale), ou sur au moins deux caméras différentes
  (corroboration).
- Deux modes : toutes les conditions, ou au moins N d'entre elles.
- Fenêtre de corrélation, temporisation anti-rebond et durée de maintien
  réglables séparément.
- Condition d'armement facultative, sous la forme d'une expression Jeedom.
- Actions Jeedom déclenchées nativement, avec le sélecteur d'action des
  scénarios : commandes de tout plugin, scénarios, variables, messages. Un
  second bloc d'actions est joué au retour au repos.
- Commandes créées : Déclenchée (info binaire historisée, type générique
  `ALARM_STATE`), Détail du déclenchement, Image du déclenchement, Tester et
  Réinitialiser.
- Quatre modèles préremplis : double détection, confirmation humaine, intrusion
  corroborée, rôdeur.
- La corrélation se fonde sur les dates d'arrivée des détections et non sur
  l'état des commandes : elle reste juste même quand une impulsion est déjà
  retombée, quand la fin d'une détection s'est perdue, ou quand le démon envoie
  d'un coup un lot d'événements accumulés.
- L'onglet Santé signale les règles sans condition et celles dont une condition
  désigne une caméra supprimée.
- Une même détection ne peut satisfaire qu'une seule condition : deux conditions
  qui se recouvrent ne peuvent pas être validées par un unique événement.
- Une condition d'armement invalide n'arme pas la règle, et le signale par un
  message ; elle ne peut plus passer pour vraie en silence.
- Une règle déclenchée retombe proprement quand on la désactive, et un état de
  corrélation perdu (cache vidé, sauvegarde restaurée) ne la laisse plus bloquée.
- Une action qui viserait une commande de la règle elle-même est ignorée plutôt
  que de la relancer en boucle.
- Une horloge de NVR en avance ne peut plus figer la fenêtre de corrélation.

**Correctif**

- Le badge d'état du démon sur la page d'un NVR affichait toujours « aucune
  information » : la réponse du contrôleur n'était pas lue au bon endroit.

## 0.3 — 12/09/2026

Première version publiée.

**Connexion**

- Démon PHP autonome, sans dépendance à installer, qui garde une connexion
  permanente vers un ou plusieurs NVR Dahua.
- Deux transports par NVR : DHIP (protocole natif, seul à remonter les
  événements des interphones VTO/VTH) et CGI (long-polling HTTP, qui détecte une
  coupure en une quinzaine de secondes). En mode automatique, le démon essaie
  DHIP puis bascule sur CGI après deux échecs.
- Port HTTP configurable séparément du port DHIP, pour les captures et le PTZ.
- Reconnexion automatique, keepalive, réassemblage des trames fragmentées et
  filtrage des événements émis à la cadence vidéo.

**Équipements et commandes**

- Auto-découverte des caméras à partir des noms de canaux du NVR.
- Une commande binaire par type de détection : mouvement, humain, véhicule,
  franchissement de ligne et de zone, perte vidéo, masquage, visage,
  stationnement, objet abandonné, objet retiré, rôdeur, anomalie sonore,
  variation sonore, incendie.
- Dernier événement et date du dernier événement, sur la caméra et sur le NVR.
- Supervision du NVR : état de connexion, stockage absent, défaut de stockage,
  espace disque faible, échec de connexion, alarme entrée locale, changement
  réseau.
- Commandes d'action « Sortie alarme ON/OFF » sur le NVR et « Lumière blanche »
  et « Sirène » sur les caméras, masquées par défaut et fonctionnelles seulement
  si le matériel les expose réellement.
- Les détections les moins courantes sont créées mais masquées, pour garder un
  widget lisible.

**Images et PTZ**

- Capture d'image sur détection ou à la demande, limitée à une toutes les
  10 secondes par caméra, avec purge automatique des plus anciennes.
- Les captures sont servies par un passe-plat PHP authentifié : elles
  s'affichent dans Jeedom, mais leur adresse n'est pas lisible par un service
  externe.
- Contrôle PTZ par rappel de preset, avec un preset par défaut par caméra.

**Interface**

- Bouton « Tester la connexion » : modèle, firmware, nombre de canaux et
  capacités détectées.
- Bouton « Capturer une image maintenant », avec aperçu immédiat.
- Intégration à l'onglet Santé de Jeedom : état du démon et de chaque NVR.
