# Changelog

## 0.7 — 23/09/2026

**Joindre l'image d'une alerte à une notification**

- Nouvelle commande **Fichier de l'image** sur chaque règle : le chemin sur le
  disque de la meilleure image de la dernière alerte. C'est elle qu'on joint à
  une notification — l'adresse de *Image du déclenchement* exige une session
  Jeedom, que Telegram, Pushover ou un serveur de mail n'ont pas. Jusqu'ici il
  fallait reconstituer ce chemin à la main dans le scénario.
- Elle pointe la pleine résolution, ou sa vignette si la purge l'a retirée, et
  elle est vidée quand l'alerte n'a aucune image, pour ne jamais joindre celle
  du déclenchement précédent. Les règles existantes la reçoivent à la mise à
  jour, déjà remplie.

**Voir si une règle peut se déclencher**

- Chaque condition d'une règle affiche désormais la **dernière fois** qu'une
  détection reçue l'aurait remplie. « Jamais vu » signale une condition que la
  caméra ne peut pas remplir — typiquement un franchissement de ligne demandé à
  une caméra sans règle IVS dans le NVR. Une telle règle ne se déclenche jamais
  et ne produit aucune erreur : on croyait que le plugin ratait des détections.
- L'indicateur suit la saisie, avant même l'enregistrement. Il dit si une
  condition peut être remplie, pas si les conditions se sont produites ensemble
  dans la fenêtre de la règle.

## 0.6.1 — 23/09/2026

**Agrandissement des images d'alerte**

- Une vignette pouvait s'afficher sur la tuile alors que le clic répondait
  « Image indisponible ». La purge retirait les images pleine résolution au-delà
  du trentième rang, toutes règles confondues, sans en prévenir la tuile. Une
  règle bavarde repoussait ainsi en quelques heures la dernière alerte des
  autres au-delà de ce rang.
- La dernière alerte de chaque règle, celle que montre sa tuile, garde
  désormais toujours sa pleine résolution.
- Nouveau réglage **Pleine résolution garantie pendant (jours)**, 3 par défaut :
  toute alerte plus récente garde son image en grand, quel que soit son rang.
  Le nombre total d'alertes conservées borne toujours la place occupée.
- Si la pleine résolution manque malgré tout, la fenêtre agrandit la vignette
  en le signalant, au lieu de ne rien montrer.
- La mise à jour corrige les tuiles déjà concernées. Les images en grand déjà
  effacées ne reviennent pas : ces tuiles n'affichent plus que les vignettes,
  jusqu'au prochain déclenchement de leur règle.

## 0.6 — 16/09/2026

**Dossiers d'alerte — le levé de doute**

- Chaque déclenchement d'une règle crée désormais un dossier daté qui lui
  appartient, avec ses propres images et sa propre description. Les trois
  défauts de l'« image du déclenchement » tombent d'un coup : elle montrait la
  capture *précédente*, elle n'en montrait qu'*une*, et le fichier qu'elle
  désignait disparaissait avec la rotation des captures courantes — une
  trentaine de minutes sur une caméra active, donc plus rien au matin pour une
  alerte de la nuit.
- Chaque caméra concernée apporte jusqu'à deux vues : celle du **moment de la
  détection**, reprise des captures que le démon prend déjà, et une **capture
  fraîche** demandée au déclenchement. La première montre l'arrivée, la seconde
  montre où la personne est allée. C'est la paire qui fait le levé de doute.
- L'image de la caméra qui complète la corrélation n'existe pas encore quand le
  dossier s'ouvre : le démon capture une à deux secondes après l'événement. Elle
  est donc rattrapée à son arrivée, et seulement si elle colle mieux à la
  détection que celle déjà en place.
- Une caméra qui n'a rien pu fournir reste affichée, avec la raison. Savoir
  qu'une caméra n'a pas répondu vaut au moins autant qu'une image : c'est
  peut-être celle qu'on a coupée.

**Affichage**

- Une tuile de dashboard par règle montre la dernière alerte : les vignettes
  côte à côte, légendées, cliquables en plein écran.
- Une page **Historique des alertes** liste tout ce qui est conservé, de la plus
  récente à la plus ancienne, filtrable par règle et par jour. Elle s'ouvre
  depuis la page du plugin ou depuis la tuile. La tuile ne montre que la
  dernière alerte de chaque règle ; s'il y en a eu cinq dans la nuit, c'est ici
  qu'on retrouve les quatre premières.
- L'historique se contente d'une session Jeedom, sans exiger le profil
  administrateur : un levé de doute n'est pas une tâche de configuration. Chaque
  alerte y est filtrée sur les droits de sa règle.

**Réglages**

- *Capturer une image fraîche à chaque alerte*, à décocher si le NVR est fragile
  ou la liaison lente.
- *Alertes conservées* (300 par défaut) et *Alertes conservées en pleine
  résolution* (30). Au-delà du second rang, seules les vignettes et la
  description sont gardées : l'alerte reste consultable, elle perd
  l'agrandissement. Une vignette pèse environ 25 Ko contre 600 Ko à 1 Mo pour
  l'image entière.

**Correctifs**

- Les libellés des tuiles du plugin sont désormais traduits en anglais ; ils ne
  l'avaient jamais été.

## 0.5 — 15/09/2026

**Supervision des caméras**

- Une caméra qui décroche est maintenant signalée. Jusqu'ici elle se taisait,
  simplement : un NVR ne produit aucun événement quand il perd une caméra — ni
  perte vidéo, ni coupure de liaison. Le démon interroge donc leur état à
  intervalle régulier, réglable dans la configuration du plugin (60 secondes par
  défaut, 0 pour désactiver).
- La tuile du NVR affiche l'état de toutes ses caméras d'un seul coup d'oeil,
  avec la santé du NVR au-dessus. Une caméra perdue depuis longtemps se
  distingue d'un incident du jour, et quand le NVR lui-même est injoignable la
  grille est estompée : son état n'est alors plus vérifiable.
- Des actions peuvent être jouées quand une caméra est perdue, et quand elle
  revient. Elles se configurent sur le NVR et ne sont jouées qu'une fois par
  perte, pas à chaque vérification. Une déconnexion du NVR ne les déclenche pas :
  elle n'est pas la perte de toutes les caméras.
- Chaque caméra porte une commande « Connectée », historisée, utilisable dans
  vos scénarios.

**Correctifs**

- L'objet parent d'un NVR se transmet désormais à ses caméras à chaque
  enregistrement, et non plus seulement à leur création. Un NVR rattaché à un
  objet après coup laissait toutes ses caméras invisibles sur le dashboard.

## 0.4.1 — 13/09/2026

**Correctifs**

- Le repli de DHIP vers CGI se déclenchait dès la première connexion ratée, et
  non après deux comme annoncé : une simple coupure du NVR suffisait à priver
  l'installation des événements que seul DHIP remonte, et plus rien ne pouvait
  y ramener. La bascule joue désormais dans les deux sens, et « Reconnecter »
  repart du transport préféré.
- Les événements pouvaient être jetés en silence : le callback répondait
  « autorisation refusée » avec un code de succès, et le démon les comptait
  livrés. Une clé API régénérée pendant que le démon tourne figeait alors toutes
  les caméras, sans le moindre message d'un côté ni de l'autre.
- Un démon qui refuse de démarrer le signale maintenant dans le centre de
  messages, au lieu de n'apparaître que dans l'onglet Santé.
- « Tester la règle » demande confirmation : le test joue réellement les
  actions, éclairage et sirène compris.

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
- Une phrase sous le tableau récapitule en clair ce que la règle fera, signale
  les combinaisons qui ne pourraient jamais se déclencher, et prévient quand une
  seule détection suffirait.
- Une règle enregistrée sans condition exploitable est signalée par un message,
  et « Tester » la refuse plutôt que d'annoncer un déclenchement trompeur.
- Une ligne de condition dont la caméra ou la détection n'a pas été choisie est
  ignorée : une règle laissée vide ne se déclenche pas au premier mouvement venu.

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
