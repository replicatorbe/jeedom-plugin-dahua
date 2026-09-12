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

/* Affiche le bloc de configuration correspondant au type d'équipement. */
function dahuaToggleType() {
  var select = document.getElementById('sel_dahuaType')
  if (select === null) {
    return
  }
  var isNvr = (select.value !== 'camera')
  document.querySelectorAll('.dahuaNvrBlock').forEach(function (el) {
    el.style.display = isNvr ? '' : 'none'
  })
  document.querySelectorAll('.dahuaCameraBlock').forEach(function (el) {
    el.style.display = isNvr ? 'none' : ''
  })
}

/* Appelée par plugin.template.js après le chargement d'un équipement. */
function printEqLogic(_eqLogic) {
  dahuaToggleType()

  /* Le type reste modifiable : changer NVR <-> caméra recalcule le logicalId et
     supprime les commandes de l'ancien type. On avertit simplement l'utilisateur. */
  var isSaved = (isset(_eqLogic) && isset(_eqLogic.id) && _eqLogic.id != '')
  var typeSelect = document.getElementById('sel_dahuaType')
  var frozen = document.getElementById('span_dahuaTypeFrozen')
  if (frozen !== null) {
    frozen.style.display = isSaved ? '' : 'none'
  }

  var img = document.getElementById('img_dahuaSnapshot')
  if (img !== null) {
    img.style.display = 'none'
    img.src = ''
  }

  /* État du démon : uniquement pertinent pour un NVR enregistré. */
  var badge = document.getElementById('span_dahuaDaemonStatus')
  if (badge !== null) {
    badge.style.display = 'none'
    badge.innerHTML = ''
    if (isSaved && typeSelect !== null && typeSelect.value !== 'camera') {
      dahuaRefreshDaemonStatus(_eqLogic.id)
    }
  }
}

/* Requête AJAX vers le contrôleur du plugin.
   _options : { button: <élément à désactiver pendant l'appel>, silent: true pour ne rien afficher } */
function dahuaAjax(_action, _data, _success, _options) {
  var options = _options || {}
  var button = isset(options.button) ? options.button : null
  var released = false
  var release = function () {
    if (button === null || released) {
      return
    }
    released = true
    button.removeAttribute('disabled')
    button.classList.remove('disabled')
  }
  if (button !== null) {
    button.setAttribute('disabled', 'disabled')
    button.classList.add('disabled')
    /* Filet de sécurité : jamais de bouton bloqué si la réponse n'arrive pas. */
    setTimeout(release, 60000)
  }

  var payload = Object.assign({ action: _action }, _data || {})
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/dahua/core/ajax/dahua.ajax.php',
    data: payload,
    dataType: 'json',
    noDisplayError: true,
    error: function (request, status, error) {
      release()
      if (options.silent === true) {
        return
      }
      domUtils.handleAjaxError(request, status, error)
    },
    success: function (data) {
      release()
      if (data.state != 'ok') {
        if (options.silent !== true) {
          jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        }
        return
      }
      _success(data.result)
    }
  })
}

/* Identifiant de l'équipement actuellement ouvert, ou null s'il n'est pas encore enregistré. */
function dahuaCurrentId() {
  var input = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  if (input === null || input.value === '') {
    jeedomUtils.showAlert({
      message: '{{Enregistrez d\'abord l\'équipement.}}',
      level: 'warning'
    })
    return null
  }
  return input.value
}

/* Les actions serveur travaillent sur les valeurs en base : refuse de partir
   si l'écran contient des modifications non enregistrées. */
function dahuaCheckSaved() {
  var modified = (typeof jeeFrontEnd !== 'undefined' && jeeFrontEnd.modifyWithoutSave === true)
    || window.modifyWithoutSave === true
  if (modified) {
    jeedomUtils.showAlert({
      message: '{{Enregistrez vos modifications avant de continuer}}',
      level: 'warning'
    })
    return false
  }
  return true
}

/* Badge d'état de la connexion du NVR telle que vue par le démon. */
function dahuaRefreshDaemonStatus(_id) {
  if (document.getElementById('span_dahuaDaemonStatus') === null) {
    return
  }
  dahuaAjax('daemonStatus', {}, function (result) {
    var badge = document.getElementById('span_dahuaDaemonStatus')
    if (badge === null) {
      return
    }
    var status = (isset(result) && is_object(result)) ? result[_id] : null
    if (!isset(status)) {
      badge.className = 'label label-danger'
      badge.innerHTML = '<i class="fas fa-plug"></i> {{Démon : aucune information}}'
      badge.style.display = ''
      return
    }
    var connected = (status.state == 'connected')
    badge.className = connected ? 'label label-success' : 'label label-danger'
    badge.innerHTML = connected ? '<i class="fas fa-plug"></i> {{Connecté}}' : '<i class="fas fa-plug"></i> {{Déconnecté}}'
    if (isset(status.transport) && status.transport != '') {
      badge.innerHTML += ' (' + status.transport + ')'
    }
    badge.style.display = ''
  }, { silent: true })
}

/* Les écouteurs sont posés à la racine du script : les pages sont chargées en
   AJAX par jeedomUtils.loadPage, l'évènement DOMContentLoaded a déjà eu lieu.
   La garde évite qu'une absence du conteneur ne casse tout le fichier. */
var dahuaContainer = document.getElementById('div_pageContainer') || document.body

dahuaContainer.addEventListener('change', function (event) {
  if (event.target.closest('#sel_dahuaType')) {
    dahuaToggleType()
  }
})

dahuaContainer.addEventListener('click', function (event) {
  var target = null

  /* --- Test de connexion au NVR --- */
  if (target = event.target.closest('#bt_dahuaTestConnection')) {
    if (target.classList.contains('disabled')) { return }
    if (!dahuaCheckSaved()) { return }
    var id = dahuaCurrentId()
    if (id === null) { return }
    jeedomUtils.showAlert({ message: '{{Test en cours...}}', level: 'info', timeOut: 2000 })
    dahuaAjax('testConnection', { id: id }, function (result) {
      var message = '{{Connexion réussie}} : ' + result.type + ' — ' + result.version
                  + ' (' + result.channels + ' {{canaux}})'
      /* Beaucoup de NVR n'ont ni sortie d'alarme ni relais de caméra. Le dire ici
         évite de chercher pourquoi les commandes correspondantes échouent. */
      var capacites = []
      if (result.alarmOut) { capacites.push('{{sortie d\'alarme}}') }
      if (result.coaxial)  { capacites.push('{{éclairage / sirène}}') }
      message += capacites.length
        ? '<br>{{Sorties détectées}} : ' + capacites.join(', ')
        : '<br>{{Aucune sortie d\'alarme ni relais détecté sur ce matériel.}}'
      jeedomUtils.showAlert({ message: message, level: 'success', timeOut: 12000 })
      dahuaRefreshDaemonStatus(id)
    }, { button: target })
    return
  }

  /* --- Découverte des caméras --- */
  if (target = event.target.closest('#bt_dahuaDiscover')) {
    if (target.classList.contains('disabled')) { return }
    if (!dahuaCheckSaved()) { return }
    var id = dahuaCurrentId()
    if (id === null) { return }
    dahuaAjax('discover', { id: id }, function (result) {
      if (result.created === 0) {
        jeedomUtils.showAlert({
          message: '{{Aucune nouvelle caméra : toutes sont déjà créées.}}',
          level: 'warning'
        })
        return
      }
      jeedomUtils.showAlert({
        message: result.created + ' {{caméra(s) créée(s). Rechargement...}}',
        level: 'success'
      })
      setTimeout(function () {
        jeedomUtils.loadPage('index.php?v=d&m=dahua&p=dahua&id=' + id)
      }, 1500)
    }, { button: target })
    return
  }

  /* --- Capture immédiate --- */
  if (target = event.target.closest('#bt_dahuaSnapshot')) {
    if (target.classList.contains('disabled')) { return }
    if (!dahuaCheckSaved()) { return }
    var id = dahuaCurrentId()
    if (id === null) { return }
    jeedomUtils.showAlert({ message: '{{Capture en cours...}}', level: 'info', timeOut: 2000 })
    dahuaAjax('snapshot', { id: id }, function (result) {
      var img = document.getElementById('img_dahuaSnapshot')
      if (img === null) { return }
      img.src = result.url + (result.url.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now()
      img.style.display = ''
    }, { button: target })
    return
  }
})

/* Ligne du tableau des commandes. */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '<a class="btn btn-danger btn-xs cmdAction pull-right" data-action="remove"><i class="fas fa-minus-circle"></i></a>'
  tr += '</td>'

  /* Une ligne créée en DOM : insertAdjacentHTML sur la table génère un <tbody>
     par insertion et toutes les commandes se retrouveraient dans la même ligne. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}
