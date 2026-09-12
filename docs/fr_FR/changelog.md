# Changelog

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
