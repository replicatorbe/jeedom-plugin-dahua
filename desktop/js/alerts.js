/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Page d'historique des alertes : filtrage et plein écran, rien d'autre.
 *
 * Tout le contenu est rendu par desktop/php/alerts.php. Ce script ne construit
 * aucune entrée et n'appelle aucune porte AJAX : celle du plugin exige
 * isConnect('admin') alors que la page est ouverte aux profils restreints. Si
 * ce fichier ne s'exécutait pas, la page resterait entièrement lisible — elle
 * perdrait seulement ses deux listes déroulantes et l'agrandissement.
 */

/* Masque les entrées qui ne répondent pas aux deux filtres, et tient le
   compteur à jour. Le filtrage porte sur des entrées DÉJÀ rendues : aucune
   requête n'est émise, et les vignettes déjà téléchargées le restent. */
function dahuaAlertsFilter() {
  var ruleSelect = document.getElementById('sel_dahuaAlertRule')
  var daySelect = document.getElementById('sel_dahuaAlertDay')
  var wantedRule = (ruleSelect === null) ? '' : ruleSelect.value
  var wantedDay = (daySelect === null) ? '' : daySelect.value
  var shown = 0

  document.querySelectorAll('#div_dahuaAlertList .dahuaAlertEntry').forEach(function (entry) {
    var keep = (wantedRule === '' || entry.getAttribute('data-rule') === wantedRule) &&
      (wantedDay === '' || entry.getAttribute('data-day') === wantedDay)
    entry.style.display = keep ? '' : 'none'
    if (keep) {
      shown++
    }
  })

  var counter = document.getElementById('span_dahuaAlertShown')
  if (counter !== null) {
    /* textContent et jamais innerHTML : le nombre est calculé ici, mais la
       règle vaut pour tout ce que ce script écrit, et une exception est une
       habitude qui se prend. */
    counter.textContent = String(shown)
  }

  /* Un filtre qui ne rend rien doit le dire. Une liste vide sans un mot se lit
     comme une page cassée, et on recharge au lieu de relâcher le filtre. */
  var empty = document.getElementById('div_dahuaAlertNoMatch')
  if (empty !== null) {
    empty.style.display = (shown === 0) ? '' : 'none'
  }
}

/* Ouvre une vignette en pleine résolution.
   jeeDialog.modal() est la seule modale garantie par ce coeur : fslightbox
   n'est pas chargé et bootbox dépend d'un réglage jQuery. Les options ne sont
   prises en compte qu'au premier appel, la modale mémorisant ensuite son
   propre objet _jeeDialog — les rappeler à chaque ouverture est donc sans
   effet, et sans danger. */
function dahuaAlertOpenShot(_shot) {
  var url = _shot.getAttribute('data-full')
  if (url === null || url === '') {
    return
  }
  var dialog = document.getElementById('md_dahuaAlertFull')
  if (dialog === null) {
    return
  }
  var title = document.getElementById('span_dahuaAlertFullTitle')
  if (title !== null) {
    title.textContent = String(init(_shot.getAttribute('data-title'), ''))
  }
  var image = document.getElementById('img_dahuaAlertFull')
  if (image !== null) {
    image.setAttribute('src', url)
  }
  /*
   * La purge tourne au cron minute : une page laissée ouverte dix minutes peut
   * montrer des vignettes dont le dossier n'existe plus. Sans ce contrôle, la
   * modale s'ouvrait sur une image brisée, avec le bon titre et aucune
   * explication.
   */
  var note = document.getElementById('span_dahuaAlertFullNote')
  if (note !== null) { note.textContent = '' }
  if (image !== null) {
    image.onerror = function () {
      image.removeAttribute('src')
      if (note !== null) { note.textContent = '{{Cette image n\'est plus conservée.}}' }
    }
  }

  jeeDialog.modal(dialog, { width: '90vw', height: 'auto', top: '5vh' })
  dialog._jeeDialog.show()
  dialog.focus()
}

/* Ferme la modale sans la détruire : hide() se contente de la masquer, là où
   close() la retire du DOM et rendrait toute ouverture suivante impossible. */
function dahuaAlertCloseShot() {
  var dialog = document.getElementById('md_dahuaAlertFull')
  if (dialog === null || !isset(dialog._jeeDialog)) {
    return
  }
  dialog._jeeDialog.hide()
  /* La source est vidée à la fermeture : une capture pleine résolution pèse
     jusqu'à un méga-octet, et la garder en mémoire n'a aucun intérêt une fois
     la modale masquée. */
  var image = document.getElementById('img_dahuaAlertFull')
  if (image !== null) {
    image.removeAttribute('src')
  }
}

/*
 * Les écouteurs sont attachés à la racine du script, et surtout pas dans un
 * DOMContentLoaded : les pages du coeur sont chargées en AJAX, l'événement a
 * déjà eu lieu quand ce fichier s'exécute et le corps ne serait jamais joué.
 */
var dahuaAlertRuleSelect = document.getElementById('sel_dahuaAlertRule')
if (dahuaAlertRuleSelect !== null) {
  dahuaAlertRuleSelect.addEventListener('change', dahuaAlertsFilter)
}

var dahuaAlertDaySelect = document.getElementById('sel_dahuaAlertDay')
if (dahuaAlertDaySelect !== null) {
  dahuaAlertDaySelect.addEventListener('change', dahuaAlertsFilter)
}

/* Un seul écouteur délégué sur la liste, et non un par vignette : cent alertes
   font jusqu'à huit cents images. La cible est reconnue à son attribut
   data-full, que le serveur ne pose que si le fichier pleine résolution existe
   encore — les alertes dont la purge n'a gardé que les vignettes ne sont donc
   pas cliquables, et ne promettent rien qu'elles ne puissent tenir. */
var dahuaAlertList = document.getElementById('div_dahuaAlertList')
if (dahuaAlertList !== null) {
  /* Une vignette dont le dossier a été purgé depuis le rendu de la page
     deviendrait une icône d'image brisée, en silence. On la retire et on le
     dit. L'écouteur est posé en phase de CAPTURE : « error » ne remonte pas,
     une délégation classique ne le verrait jamais. */
  dahuaAlertList.addEventListener('error', function (event) {
    var broken = event.target
    if (broken === null || broken.tagName !== 'IMG') { return }
    var shot = broken.closest('.dahuaAlertShot')
    if (shot === null) { return }
    shot.removeAttribute('data-full')
    broken.remove()
    var gone = document.createElement('div')
    gone.className = 'dahuaAlertNote'
    gone.textContent = '{{Image purgée}}'
    shot.insertBefore(gone, shot.firstChild)
  }, true)

  dahuaAlertList.addEventListener('click', function (event) {
    var shot = event.target.closest('.dahuaAlertShot[data-full]')
    if (shot !== null) {
      dahuaAlertOpenShot(shot)
    }
  })
}

/*
 * Deux façons de refermer, et aucune sur document : le coeur ne nettoie les
 * écouteurs que sous #div_pageContainer, si bien qu'un écouteur posé sur
 * document survivrait à chaque visite de la page et s'y accumulerait.
 */
var dahuaAlertCloseButton = document.getElementById('bt_dahuaAlertFullClose')
if (dahuaAlertCloseButton !== null) {
  dahuaAlertCloseButton.addEventListener('click', dahuaAlertCloseShot)
}

var dahuaAlertFullImage = document.getElementById('img_dahuaAlertFull')
if (dahuaAlertFullImage !== null) {
  dahuaAlertFullImage.addEventListener('click', dahuaAlertCloseShot)
}

/* Échap ferme aussi, et l'écouteur est posé SUR la modale, pas sur document :
   le coeur ne nettoie les écouteurs que sous #div_pageContainer, un écouteur
   plus haut s'accumulerait à chaque visite de la page. C'est le focus() de
   l'ouverture qui rend la touche opérante. */
var dahuaAlertFullDialog = document.getElementById('md_dahuaAlertFull')
if (dahuaAlertFullDialog !== null) {
  dahuaAlertFullDialog.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { dahuaAlertCloseShot() }
  })
}
