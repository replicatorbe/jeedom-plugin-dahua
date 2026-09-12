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
  var img = document.getElementById('img_dahuaSnapshot')
  if (img !== null) {
    img.style.display = 'none'
    img.src = ''
  }
}

/* Requête AJAX vers le contrôleur du plugin. */
function dahuaAjax(_action, _data, _success) {
  var payload = Object.assign({ action: _action }, _data || {})
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/dahua/core/ajax/dahua.ajax.php',
    data: payload,
    dataType: 'json',
    global: false,
    error: function (request, status, error) {
      jeedomUtils.handleAjaxError(request, status, error)
    },
    success: function (data) {
      if (data.state != 'ok') {
        jeedomUtils.showAlert({ message: data.result, level: 'danger' })
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

document.addEventListener('DOMContentLoaded', function () {
  var container = document.getElementById('div_pageContainer')
  if (container === null) {
    return
  }

  container.addEventListener('change', function (event) {
    if (event.target.closest('#sel_dahuaType')) {
      dahuaToggleType()
    }
  })

  container.addEventListener('click', function (event) {
    var target = null

    /* --- Test de connexion au NVR --- */
    if (target = event.target.closest('#bt_dahuaTestConnection')) {
      var id = dahuaCurrentId()
      if (id === null) { return }
      jeedomUtils.showAlert({ message: '{{Test en cours...}}', level: 'info', timeOut: 2000 })
      dahuaAjax('testConnection', { id: id }, function (result) {
        jeedomUtils.showAlert({
          message: '{{Connexion réussie}} : ' + result.type + ' — ' + result.version
            + ' (' + result.channels + ' {{canaux}})',
          level: 'success',
          timeOut: 8000
        })
      })
      return
    }

    /* --- Découverte des caméras --- */
    if (target = event.target.closest('#bt_dahuaDiscover')) {
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
        setTimeout(function () { window.location.reload() }, 1500)
      })
      return
    }

    /* --- Capture immédiate --- */
    if (target = event.target.closest('#bt_dahuaSnapshot')) {
      var id = dahuaCurrentId()
      if (id === null) { return }
      jeedomUtils.showAlert({ message: '{{Capture en cours...}}', level: 'info', timeOut: 2000 })
      dahuaAjax('snapshot', { id: id }, function (result) {
        var img = document.getElementById('img_dahuaSnapshot')
        img.src = result.url + '?t=' + Date.now()
        img.style.display = ''
      })
      return
    }
  })
})

/* Ligne du tableau des commandes. */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">'
  tr += '<td>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="logicalId" style="margin-top:3px;" placeholder="{{Identifiant interne}}" readonly>'
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
  tr += '</tr>'

  document.getElementById('table_cmd').insertAdjacentHTML('beforeend', tr)
  var lastRow = document.querySelector('#table_cmd tbody tr:last-child')
  jeedom.cmd.changeType(lastRow, init(_cmd.subType))
  lastRow.setJeeValues(_cmd, '.cmdAttr')
}
