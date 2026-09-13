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

/* Affiche le bloc de configuration correspondant au type d'équipement.
   Le type inconnu retombe sur NVR : c'est ce que pose preSave() par défaut. */
function dahuaToggleType() {
  var select = document.getElementById('sel_dahuaType')
  if (select === null) {
    return
  }
  var blocks = {
    camera: '.dahuaCameraBlock',
    rule: '.dahuaRuleBlock',
    nvr: '.dahuaNvrBlock'
  }
  var current = isset(blocks[select.value]) ? select.value : 'nvr'
  for (var type in blocks) {
    document.querySelectorAll(blocks[type]).forEach(function (el) {
      el.style.display = (type === current) ? '' : 'none'
    })
  }
}

/* Le nombre de conditions à satisfaire n'a de sens qu'en mode « au moins N ». */
function dahuaToggleThreshold() {
  var select = document.getElementById('sel_dahuaRuleMode')
  if (select === null) {
    return
  }
  document.querySelectorAll('.dahuaRuleThreshold').forEach(function (el) {
    el.style.display = (select.value === 'count') ? '' : 'none'
  })
}

/* Ajoute une option à un <select> sans passer par innerHTML : les noms de caméra
   viennent du NVR, donc d'une source externe. */
function dahuaOption(_select, _value, _label, _selected) {
  var option = document.createElement('option')
  option.value = _value
  option.textContent = _label
  if (_selected) {
    option.selected = true
  }
  _select.appendChild(option)
}

/* Construit un <select> de condition, en conservant une valeur enregistrée qui
   ne figure plus dans la liste : sans cela le champ retomberait silencieusement
   sur « à choisir » et, à l'enregistrement suivant, la règle changerait de sens. */
function dahuaConditionSelect(_key, _placeholder, _anyLabel, _items, _value, _missingLabel) {
  var select = document.createElement('select')
  select.className = 'ruleAttr form-control input-sm'
  select.setAttribute('data-l1key', _key)
  dahuaOption(select, '', _placeholder)
  dahuaOption(select, 'any', _anyLabel)
  var wanted = String(init(_value, ''))
  var known = (wanted === '' || wanted === 'any')
  for (var i = 0; i < _items.length; i++) {
    dahuaOption(select, String(_items[i].id), _items[i].name)
    if (String(_items[i].id) === wanted) {
      known = true
    }
  }
  if (!known) {
    dahuaOption(select, wanted, _missingLabel + ' (' + wanted + ')')
  }
  return select
}

/* Une ligne de condition. Construite avec createElement puis appendChild sur le
   tbody : insertAdjacentHTML sur une table crée un tbody par insertion. */
function dahuaAddCondition(_condition) {
  var table = document.getElementById('table_dahuaConditions')
  if (table === null) {
    return
  }
  var condition = _condition || {}
  var row = document.createElement('tr')
  row.classList.add('dahuaRuleCondition')

  var cell = document.createElement('td')
  cell.appendChild(dahuaConditionSelect('source',
    '{{— à choisir —}}', '{{N\'importe quelle caméra}}',
    isset(window.dahuaCameras) ? window.dahuaCameras : [],
    condition.source, '{{Caméra supprimée}}'))
  row.appendChild(cell)

  cell = document.createElement('td')
  cell.appendChild(dahuaConditionSelect('event',
    '{{— à choisir —}}', '{{N\'importe quelle détection}}',
    isset(window.dahuaEvents) ? window.dahuaEvents : [],
    condition.event, '{{Détection inconnue}}'))
  row.appendChild(cell)

  cell = document.createElement('td')
  var min = document.createElement('input')
  min.type = 'number'
  min.min = '1'
  min.className = 'ruleAttr form-control input-sm'
  min.setAttribute('data-l1key', 'min')
  min.placeholder = '1'
  cell.appendChild(min)
  row.appendChild(cell)

  cell = document.createElement('td')
  cell.style.textAlign = 'center'
  var remove = document.createElement('a')
  remove.className = 'btn btn-danger btn-xs bt_dahuaRemoveCondition'
  remove.title = '{{Supprimer cette condition}}'
  remove.innerHTML = '<i class="fas fa-minus-circle"></i>'
  cell.appendChild(remove)
  row.appendChild(cell)

  table.querySelector('tbody').appendChild(row)
  row.setJeeValues(condition, '.ruleAttr')
}

/* Une ligne d'action, calquée sur le sélecteur d'action des scénarios
   (desktop/modal/cmd.configure.php). L'ordre html() → setJeeValues →
   appendChild → replaceWith est celui du coeur : le HTML des options contient
   des <script> que seul Element.prototype.html() exécute. */
function dahuaAddAction(_containerId, _action) {
  var container = document.getElementById(_containerId)
  if (container === null) {
    return
  }
  var action = _action || {}
  if (!isset(action.options)) {
    action.options = {}
  }

  var div = '<div class="dahuaRuleAction expression">'
  div += '<input class="expressionAttr" data-l1key="type" style="display:none;" value="action">'
  div += '<div class="form-group">'
  div += '<div class="col-sm-1">'
  div += '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="enable" checked title="{{Décocher pour désactiver cette action}}">'
  div += '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="background" title="{{Exécuter en parallèle des autres actions}}">'
  div += '</div>'
  div += '<div class="col-sm-5">'
  div += '<div class="input-group">'
  div += '<span class="input-group-btn">'
  div += '<a class="btn btn-default btn-sm bt_dahuaRemoveAction roundedLeft"><i class="fas fa-minus-circle"></i></a>'
  div += '</span>'
  div += '<input class="expressionAttr form-control input-sm cmdAction" data-l1key="cmd">'
  div += '<span class="input-group-btn">'
  div += '<a class="btn btn-default btn-sm dahuaListAction" title="{{Choisir un bloc (scénario, variable, message...)}}"><i class="fas fa-tasks"></i></a>'
  div += '<a class="btn btn-default btn-sm dahuaListCmd roundedRight" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>'
  div += '</span>'
  div += '</div>'
  div += '</div>'
  div += '<div class="col-sm-6 actionOptions"></div>'
  div += '</div>'
  div += '</div>'

  var wrapper = document.createElement('div')
  wrapper.html(div)
  wrapper.setJeeValues(action, '.expressionAttr')
  container.appendChild(wrapper)
  var nodes = Array.prototype.slice.call(wrapper.childNodes)
  wrapper.replaceWith(...nodes)

  /* Les options sont rendues en asynchrone. La variante synchrone de
     displayActionOption fige l'onglet le temps d'un aller-retour PAR action :
     une règle à six actions bloquerait l'affichage six fois. */
  if (nodes.length > 0) {
    dahuaRefreshActionOptions(nodes[0], init(action.cmd, ''), action.options)
  }
}

/* Réaffiche les options d'une action après un changement de commande. */
function dahuaRefreshActionOptions(_line, _expression, _options) {
  jeedom.cmd.displayActionOption(_expression, _options, function (html) {
    var target = _line.querySelector('.actionOptions')
    if (target !== null) {
      target.html(html)
      jeedomUtils.taAutosize()
    }
  })
}

/* Récapitule la règle en une phrase. C'est le seul endroit où l'utilisateur peut
   vérifier d'un coup d'oeil qu'il a bien décrit ce qu'il voulait — une règle
   incohérente ne produit aucune erreur, elle ne se déclenche simplement jamais,
   ou bien tout le temps. */
function dahuaUpdateSummary() {
  var target = document.getElementById('span_dahuaRuleSummary')
  if (target === null) {
    return
  }
  var parts = []
  document.querySelectorAll('#table_dahuaConditions tbody tr.dahuaRuleCondition').forEach(function (row) {
    var source = row.querySelector('.ruleAttr[data-l1key="source"]')
    var event = row.querySelector('.ruleAttr[data-l1key="event"]')
    var min = row.querySelector('.ruleAttr[data-l1key="min"]')
    if (source === null || event === null || source.value === '' || event.value === '') {
      return
    }
    var label = event.options[event.selectedIndex].text + ' / ' + source.options[source.selectedIndex].text
    var times = parseInt(min.value, 10)
    if (times > 1) {
      label += ' × ' + times
    }
    parts.push(label)
  })

  if (parts.length === 0) {
    target.className = 'help-block text-danger'
    target.textContent = '{{Aucune condition complète : cette règle ne se déclenchera jamais.}}'
    return
  }

  var value = function (key, fallback) {
    var el = document.querySelector('.dahuaRuleBlock .eqLogicAttr[data-l2key="' + key + '"]')
    return (el === null || el.value === '') ? fallback : el.value
  }
  var mode = document.getElementById('sel_dahuaRuleMode')
  var text = '{{Déclenchement quand}} '
  if (mode !== null && mode.value === 'count') {
    text += '{{au moins}} ' + value('threshold', '2') + ' {{de ces conditions sont réunies}} : ' + parts.join(' | ')
  } else {
    text += parts.join(' {{ET}} ')
  }
  var scope = document.querySelector('.dahuaRuleBlock .eqLogicAttr[data-l2key="camera_scope"]')
  if (scope !== null && scope.value === 'same') {
    text += ', {{sur une seule et même caméra}}'
  } else if (scope !== null && scope.value === 'distinct') {
    text += ', {{réparties sur au moins deux caméras}}'
  }
  text += ', {{en moins de}} ' + value('window', '15') + ' {{secondes}}.'

  /* Une seule condition à une occurrence n'est pas une détection croisée : le
     dire, plutôt que de laisser croire à une protection qui n'existe pas. */
  if (parts.length === 1 && parts[0].indexOf('×') === -1) {
    target.className = 'help-block text-warning'
    text += ' {{Attention : une seule détection suffit, ce n\'est pas une double détection.}}'
  } else {
    target.className = 'help-block text-info'
  }
  target.textContent = text
}

/* Modèles de règles : de quoi ne pas partir d'une page blanche. */
function dahuaApplyTemplate(_name) {
  var models = {
    double: {
      conditions: [{ source: 'any', event: 'crossline', min: 1 }, { source: 'any', event: 'motion', min: 1 }],
      mode: 'all', scope: 'same', window: 15, cooldown: 30, hold: 10, threshold: 2
    },
    human: {
      conditions: [{ source: 'any', event: 'human', min: 1 }, { source: 'any', event: 'crossline', min: 1 }],
      mode: 'all', scope: 'same', window: 20, cooldown: 60, hold: 15, threshold: 2
    },
    corroborate: {
      conditions: [{ source: 'any', event: 'any', min: 2 }],
      mode: 'all', scope: 'distinct', window: 30, cooldown: 60, hold: 15, threshold: 2
    },
    prowler: {
      conditions: [{ source: 'any', event: 'any', min: 3 }],
      mode: 'all', scope: 'same', window: 60, cooldown: 120, hold: 30, threshold: 2
    }
  }
  var model = models[_name]
  if (!isset(model)) {
    return
  }
  var tbody = document.getElementById('table_dahuaConditions').querySelector('tbody')
  /* Appliquer un modèle remplace tout : demander avant de jeter des conditions
     déjà saisies, il n'y a pas d'annulation. */
  var filled = 0
  tbody.querySelectorAll('tr.dahuaRuleCondition').forEach(function (row) {
    var source = row.querySelector('.ruleAttr[data-l1key="source"]')
    if (source !== null && source.value !== '') { filled++ }
  })
  if (filled > 0 && !window.confirm('{{Le modèle va remplacer les conditions déjà saisies. Continuer ?}}')) {
    document.getElementById('sel_dahuaRuleTemplate').value = ''
    return
  }
  tbody.innerHTML = ''
  for (var i = 0; i < model.conditions.length; i++) {
    dahuaAddCondition(model.conditions[i])
  }
  var set = function (selector, value) {
    var el = document.querySelector(selector)
    if (el !== null) { el.value = value }
  }
  set('#sel_dahuaRuleMode', model.mode)
  set('.dahuaRuleBlock .eqLogicAttr[data-l2key="camera_scope"]', model.scope)
  set('.dahuaRuleBlock .eqLogicAttr[data-l2key="window"]', model.window)
  set('.dahuaRuleBlock .eqLogicAttr[data-l2key="cooldown"]', model.cooldown)
  set('.dahuaRuleBlock .eqLogicAttr[data-l2key="hold"]', model.hold)
  set('.dahuaRuleBlock .eqLogicAttr[data-l2key="threshold"]', model.threshold)
  dahuaToggleThreshold()
  dahuaUpdateSummary()
  dahuaMarkModified()
}

/* Le coeur teste DEUX drapeaux avant d'avertir qu'on quitte une page modifiée :
   n'en poser qu'un laisse passer la perte de données une fois sur deux. */
function dahuaMarkModified(_modified) {
  var value = (_modified === false) ? false : true
  jeeFrontEnd.modifyWithoutSave = value
  window.modifyWithoutSave = value
}

/* Création d'une règle. Le bouton « Ajouter » générique du coeur n'envoie que le
   nom, et preSave() en ferait un NVR : le type est donc posé dès la création. */
function dahuaAddRule() {
  jeeDialog.prompt('{{Nom de la règle ?}}', function (name) {
    if (name === null || name === '') {
      return
    }
    jeedom.eqLogic.save({
      type: 'dahua',
      eqLogics: [{ name: name, configuration: { type: 'rule' } }],
      error: function (error) {
        jeedomUtils.showAlert({ message: error.message, level: 'danger' })
      },
      success: function (data) {
        dahuaMarkModified(false)
        jeedomUtils.loadPage('index.php?v=d&m=dahua&p=dahua&id=' + data.id + '&saveSuccessFull=1')
      }
    })
  })
}

/* Appelée par plugin.template.js juste avant l'enregistrement. Les conditions et
   les actions sont des listes : elles ne tiennent pas dans data-lXkey, qui ne
   descend qu'à trois niveaux. On les collecte donc à la main.
   Elles ne sont écrites que pour une règle, sinon une caméra se retrouverait
   avec une configuration de règle vide. */
function saveEqLogic(_eqLogic) {
  var select = document.getElementById('sel_dahuaType')
  if (select === null || select.value !== 'rule') {
    return _eqLogic
  }
  if (!isset(_eqLogic.configuration)) {
    _eqLogic.configuration = {}
  }
  _eqLogic.configuration.conditions =
    document.querySelectorAll('#table_dahuaConditions tbody tr.dahuaRuleCondition').getJeeValues('.ruleAttr')
  _eqLogic.configuration.actions =
    document.querySelectorAll('#div_dahuaRuleActions .dahuaRuleAction').getJeeValues('.expressionAttr')
  _eqLogic.configuration.actions_end =
    document.querySelectorAll('#div_dahuaRuleActionsEnd .dahuaRuleAction').getJeeValues('.expressionAttr')
  return _eqLogic
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

  /* Le coeur ne réinitialise que les .eqLogicAttr : les conditions et les
     actions de la règle précédente resteraient affichées, et seraient
     enregistrées sur celle-ci. On les reconstruit intégralement. */
  var configuration = (isset(_eqLogic) && isset(_eqLogic.configuration)) ? _eqLogic.configuration : {}
  var i

  var tbody = document.querySelector('#table_dahuaConditions tbody')
  if (tbody !== null) {
    tbody.innerHTML = ''
    var conditions = isset(configuration.conditions) ? configuration.conditions : []
    for (i = 0; i < conditions.length; i++) {
      dahuaAddCondition(conditions[i])
    }
    /* Une règle neuve part avec une ligne : un tableau vide n'invite à rien. */
    if (conditions.length === 0) {
      dahuaAddCondition({})
    }
  }

  var containers = { div_dahuaRuleActions: 'actions', div_dahuaRuleActionsEnd: 'actions_end' }
  for (var id in containers) {
    var container = document.getElementById(id)
    if (container === null) {
      continue
    }
    container.innerHTML = ''
    var actions = isset(configuration[containers[id]]) ? configuration[containers[id]] : []
    for (i = 0; i < actions.length; i++) {
      dahuaAddAction(id, actions[i])
    }
  }

  /* Les valeurs par défaut sont appliquées par preSave() côté serveur, mais un
     champ vide ne dit pas laquelle : on les affiche. */
  var defaults = { window: '15', cooldown: '30', hold: '10', threshold: '2' }
  for (var key in defaults) {
    var field = document.querySelector('.dahuaRuleBlock .eqLogicAttr[data-l2key="' + key + '"]')
    if (field !== null && field.value === '') {
      field.value = defaults[key]
    }
  }
  var scope = document.querySelector('.dahuaRuleBlock .eqLogicAttr[data-l2key="camera_scope"]')
  if (scope !== null && scope.value === '') {
    scope.value = 'any'
  }

  dahuaToggleThreshold()
  dahuaUpdateSummary()
  dahuaShowRuleState(_eqLogic)
  var template = document.getElementById('sel_dahuaRuleTemplate')
  if (template !== null) {
    template.value = ''
  }

  /* État du démon : uniquement pertinent pour un NVR enregistré. Une règle n'a
     rien à voir avec le démon, inutile de l'interroger en ouvrant la page. */
  var badge = document.getElementById('span_dahuaDaemonStatus')
  if (badge !== null) {
    badge.style.display = 'none'
    badge.innerHTML = ''
    if (isSaved && typeSelect !== null && typeSelect.value === 'nvr') {
      dahuaRefreshDaemonStatus(_eqLogic.id)
    }
  }
}

/* État courant de la règle, lu dans les commandes déjà chargées par le coeur. */
function dahuaShowRuleState(_eqLogic) {
  var badge = document.getElementById('span_dahuaRuleState')
  var detail = document.getElementById('span_dahuaRuleDetail')
  if (badge === null || detail === null) {
    return
  }
  badge.style.display = 'none'
  detail.textContent = ''
  if (!isset(_eqLogic) || !isset(_eqLogic.cmd)) {
    return
  }
  for (var i in _eqLogic.cmd) {
    var cmd = _eqLogic.cmd[i]
    if (cmd.logicalId === 'triggered') {
      var on = (parseInt(cmd.state, 10) === 1)
      badge.className = on ? 'label label-danger' : 'label label-success'
      badge.textContent = on ? '{{Déclenchée}}' : '{{Au repos}}'
      badge.style.display = ''
    }
    if (cmd.logicalId === 'detail' && isset(cmd.state) && cmd.state !== '') {
      detail.textContent = '{{Dernier déclenchement}} : ' + cmd.state
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
    /* La réponse est enveloppée : { daemon: 'ok'|'nok', nvrs: { <id>: {...} } }. */
    var nvrs = (isset(result) && is_object(result) && isset(result.nvrs)) ? result.nvrs : null
    var status = (nvrs !== null) ? nvrs[_id] : null
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
    return
  }
  if (event.target.closest('#sel_dahuaRuleMode')) {
    dahuaToggleThreshold()
    dahuaUpdateSummary()
    return
  }
  if (event.target.closest('#sel_dahuaRuleTemplate')) {
    dahuaApplyTemplate(event.target.value)
  }
})

/* Le coeur n'arme modifyWithoutSave que sur ses propres champs : sans cela, on
   quitte la page en perdant ses conditions sans le moindre avertissement. */
dahuaContainer.addEventListener('change', function (event) {
  if (event.target.closest('.ruleAttr') || event.target.closest('.expressionAttr')) {
    dahuaMarkModified()
  }
  if (event.target.closest('.dahuaRuleBlock')) {
    dahuaUpdateSummary()
  }
})

/* Le résumé doit suivre la frappe, pas seulement la perte de focus. */
dahuaContainer.addEventListener('input', function (event) {
  if (event.target.closest('.dahuaRuleBlock')) {
    dahuaUpdateSummary()
  }
})

/* Réaffiche les options quand la commande d'une action est saisie à la main. */
dahuaContainer.addEventListener('focusout', function (event) {
  var input = event.target.closest('.dahuaRuleAction .cmdAction')
  if (input === null) {
    return
  }
  var line = input.closest('.dahuaRuleAction')
  var current = line.getJeeValues('.expressionAttr')[0]
  dahuaRefreshActionOptions(line, input.jeeValue(), init(current.options))
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

  /* --- Création d'une règle --- */
  if (event.target.closest('#bt_dahuaAddRule')) {
    dahuaAddRule()
    return
  }

  /* --- Conditions --- */
  if (event.target.closest('#bt_dahuaAddCondition')) {
    dahuaAddCondition({})
    dahuaUpdateSummary()
    dahuaMarkModified()
    return
  }
  if (target = event.target.closest('.bt_dahuaRemoveCondition')) {
    target.closest('tr.dahuaRuleCondition').remove()
    dahuaUpdateSummary()
    dahuaMarkModified()
    return
  }

  /* --- Actions --- */
  if (target = event.target.closest('.bt_dahuaAddAction')) {
    dahuaAddAction(target.getAttribute('data-container'), {})
    dahuaMarkModified()
    return
  }
  if (target = event.target.closest('.bt_dahuaRemoveAction')) {
    target.closest('.dahuaRuleAction').remove()
    dahuaMarkModified()
    return
  }
  if (target = event.target.closest('.dahuaListCmd')) {
    var cmdLine = target.closest('.dahuaRuleAction')
    jeedom.cmd.getSelectModal({ cmd: { type: 'action' } }, function (result) {
      cmdLine.querySelector('.expressionAttr[data-l1key="cmd"]').jeeValue(result.human)
      dahuaRefreshActionOptions(cmdLine, result.human, '')
      dahuaMarkModified()
    })
    return
  }
  if (target = event.target.closest('.dahuaListAction')) {
    var blockLine = target.closest('.dahuaRuleAction')
    jeedom.getSelectActionModal({}, function (result) {
      /* Certains blocs n'ont de sens que dans un scénario, et « wait » / « sleep »
         bloqueraient la réception des événements du NVR pendant toute leur durée,
         puisque les actions d'une règle s'exécutent dans le callback du démon. */
      var refuses = ['wait', 'sleep', 'stop', 'log', 'scenario_return', 'icon', 'tag']
      if (refuses.indexOf(result.human) !== -1) {
        jeedomUtils.showAlert({
          message: '{{Ce bloc n\'est pas utilisable dans une règle : il bloquerait la réception des événements ou n\'a de sens que dans un scénario. Passez par un scénario.}}',
          level: 'warning',
          timeOut: 10000
        })
        return
      }
      blockLine.querySelector('.expressionAttr[data-l1key="cmd"]').jeeValue(result.human)
      dahuaRefreshActionOptions(blockLine, result.human, '')
      dahuaMarkModified()
    })
    return
  }

  /* --- Condition d'armement : insertion d'une commande info --- */
  if (event.target.closest('#bt_dahuaArmCondition')) {
    var field = document.getElementById('in_dahuaArmCondition')
    jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (result) {
      if (typeof field.insertAtCursor === 'function') {
        field.insertAtCursor(result.human)
      } else {
        field.value += result.human
      }
      dahuaMarkModified()
    })
    return
  }

  /* --- Test d'une règle --- */
  if (target = event.target.closest('#bt_dahuaTestRule')) {
    if (target.classList.contains('disabled')) { return }
    if (!dahuaCheckSaved()) { return }
    var ruleId = dahuaCurrentId()
    if (ruleId === null) { return }
    dahuaAjax('testRule', { id: ruleId }, function (result) {
      jeedomUtils.showAlert({
        message: '{{Règle déclenchée, les actions ont été jouées}} : ' + init(result.detail, ''),
        level: 'success',
        timeOut: 8000
      })
      dahuaRefreshRuleState(ruleId)
    }, { button: target })
    return
  }

  /* --- Réinitialisation d'une règle --- */
  if (target = event.target.closest('#bt_dahuaResetRule')) {
    if (target.classList.contains('disabled')) { return }
    if (!dahuaCheckSaved()) { return }
    var resetId = dahuaCurrentId()
    if (resetId === null) { return }
    dahuaAjax('resetRule', { id: resetId }, function () {
      jeedomUtils.showAlert({ message: '{{Règle remise au repos.}}', level: 'success' })
      dahuaRefreshRuleState(resetId)
    }, { button: target })
    return
  }
})

/* Relit l'état de la règle après un test ou une réinitialisation, sans recharger
   toute la page. */
function dahuaRefreshRuleState(_id) {
  jeedom.eqLogic.print({
    type: 'dahua',
    id: _id,
    getCmdState: 1,
    error: function () {},
    success: function (data) { dahuaShowRuleState(data) }
  })
}

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
