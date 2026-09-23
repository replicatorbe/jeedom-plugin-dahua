# Changelog

## 0.6.1 — 2026-09-23

**Enlarging alert images**

- A thumbnail could show on the tile while clicking it answered "Image
  unavailable". The purge removed full resolution images beyond the thirtieth
  rank, all rules taken together, without telling the tile. A chatty rule could
  thus push the latest alert of the others beyond that rank within hours.
- The latest alert of each rule, the one its tile shows, now always keeps its
  full resolution.
- New setting **Full resolution guaranteed for (days)**, 3 by default: any more
  recent alert keeps its enlargement, whatever its rank. The total number of
  alerts kept still bounds the space used.
- If the full resolution is missing anyway, the window enlarges the thumbnail
  and says so, instead of showing nothing.
- The update fixes the tiles already affected. Full resolution images already
  deleted do not come back: those tiles only show thumbnails until their rule
  triggers again.

## 0.6 — 2026-09-16

**Alert folders — the visual verification**

- Every trigger of a rule now creates a dated folder of its own, with its own
  images and its own description. The three flaws of the "Trigger image"
  command fall at once: it showed the *previous* snapshot, it showed only
  *one*, and the file it pointed at disappeared with the snapshot rotation —
  about thirty minutes on a busy camera, so nothing left in the morning for a
  night-time alert.
- Every camera involved brings up to two views: the one from the **moment of
  the detection**, taken from the snapshots the daemon already makes, and a
  **fresh snapshot** asked for at trigger time. The first shows the arrival,
  the second shows where the person went. It is the pair that makes the visual
  verification.
- The image of the camera that completes the correlation does not exist yet
  when the folder opens: the daemon captures one to two seconds after the
  event. It is therefore back-filled when it arrives, and only if it fits the
  detection better than the one already in place.
- A camera that could provide nothing stays on display, with the reason.
  Knowing that a camera did not answer is worth at least as much as an image:
  it may well be the one that was cut.

**Display**

- One dashboard tile per rule shows the last alert: the thumbnails side by
  side, captioned, clickable full screen.
- An **Alert history** page lists everything that is kept, from the most recent
  to the oldest, filterable by rule and by day. It opens from the plugin page
  or from the tile. The tile only shows the last alert of each rule; if there
  were five during the night, this is where the first four are found.
- The history makes do with a Jeedom session, without requiring the
  administrator profile: a visual verification is not a configuration task.
  Every alert there is filtered on the rights of its rule.

**Settings**

- *Take a fresh snapshot on every alert*, to be unchecked if the NVR is fragile
  or the link slow.
- *Alerts kept* (300 by default) and *Alerts kept at full resolution* (30).
  Beyond that second rank, only the thumbnails and the description are kept:
  the alert can still be read, it loses the enlargement. A thumbnail weighs
  about 25 KB against 600 KB to 1 MB for the whole image.

**Fixes**

- The labels of the plugin tiles are now translated into English; they never
  had been.

## 0.5 — 2026-09-15

**Camera monitoring**

- A camera that drops off is now reported. Until now it simply went quiet: an
  NVR emits no event when it loses a camera — neither video loss nor link
  failure. The daemon therefore polls their state at a regular interval,
  configurable in the plugin settings (60 seconds by default, 0 to disable).
- The NVR tile shows the state of every camera at a glance, with the NVR's own
  health above it. A camera lost long ago is told apart from a fresh incident,
  and when the NVR itself is unreachable the grid is dimmed: camera state can no
  longer be verified.
- Actions can be run when a camera is lost, and when it comes back. They are
  configured on the NVR and run once per loss, not on every check. An NVR
  disconnection does not trigger them: it is not the loss of every camera.
- Each camera carries a "Connected" command, historised, usable in your
  scenarios.

**Fixes**

- A NVR's parent object is now passed on to its cameras on every save, not only
  when they are created. Attaching a NVR to an object after the fact used to
  leave all its cameras invisible on the dashboard.

## 0.4.1 — 2026-09-13

**Fixes**

- The fallback from DHIP to CGI fired on the very first failed connection
  instead of after two as announced: a brief NVR outage was enough to lose the
  events only DHIP reports, with no way back. The switch now works both ways,
  and "Reconnect" starts again from the preferred transport.
- Events could be dropped silently: the callback answered "not authorised" with
  a success code, and the daemon counted them as delivered. An API key
  regenerated while the daemon was running then froze every camera, without a
  single message on either side.
- A daemon that refuses to start now says so in the message centre, instead of
  showing up only in the Health tab.
- "Test the rule" asks for confirmation: the test really plays the actions,
  lighting and siren included.

## 0.4 — 2026-09-13

**Cross-detection rules**

- New equipment type: a rule correlates several detections that happened within
  the same time window and only triggers when they arrive together. Motion alone
  is often a false positive, motion together with a crossed line almost never is.
- Free-form conditions: any camera or a specific one, any detection or a specific
  one, with a required number of occurrences.
- Three camera scopes: any of them, all on the same camera (local double
  detection), or on at least two different cameras (corroboration).
- Two modes: all the conditions, or at least N of them.
- Correlation window, anti-bounce cooldown and hold time, all set separately.
- Optional arming condition, written as a Jeedom expression.
- Jeedom actions run natively, using the scenario action picker: commands from
  any plugin, scenarios, variables, messages. A second block of actions is played
  on release.
- Commands created: Triggered (historized binary info, generic type
  `ALARM_STATE`), Trigger detail, Trigger image, Test and Reset.
- Four ready-made templates: double detection, human confirmation, corroborated
  intrusion, loiterer.
- Correlation is based on the arrival dates of the detections rather than on the
  state of the commands: it stays correct even when a pulse has already fallen
  back, when the end of a detection was lost, or when the daemon sends a batch of
  accumulated events at once.
- The Health tab reports rules with no condition and rules whose condition points
  at a deleted camera.
- A single detection can satisfy only one condition: two overlapping conditions
  can no longer both be credited by one event.
- An invalid arming condition does not arm the rule and raises a message; it can
  no longer pass for true silently.
- A triggered rule falls back cleanly when disabled, and a lost correlation state
  (cleared cache, restored backup) no longer leaves it stuck.
- An action targeting a command of the rule itself is ignored rather than looping.
- An NVR clock running ahead can no longer freeze the correlation window.
- A sentence below the table summarises in plain words what the rule will do,
  flags combinations that could never trigger, and warns when a single detection
  would be enough.
- A rule saved without a usable condition raises a message, and "Test" refuses it
  rather than announcing a misleading trigger.
- A condition row whose camera or detection was not picked is ignored: a rule
  left empty does not fire on the first motion that comes along.

**Fix**

- The daemon status badge on an NVR page always showed "no information": the
  controller answer was not read at the right place.

## 0.3 — 2026-09-12

First published release.

**Connection**

- Standalone PHP daemon, with no dependency to install, keeping a permanent
  connection to one or more Dahua NVRs.
- Two transports per NVR: DHIP (the native protocol, the only one reporting
  VTO/VTH intercom events) and CGI (HTTP long-polling, which detects an outage
  within about fifteen seconds). In automatic mode the daemon tries DHIP, then
  falls back to CGI after two failures.
- HTTP port configurable separately from the DHIP port, for snapshots and PTZ.
- Automatic reconnection, keepalive, reassembly of fragmented frames and
  filtering of events emitted at video frame rate.

**Devices and commands**

- Camera auto-discovery from the channel names of the NVR.
- One binary command per detection type: motion, human, vehicle, line crossing,
  region crossing, video loss, tampering, face, parking, object left behind,
  object taken away, loitering, audio anomaly, audio mutation, fire.
- Last event and last event date, on both the camera and the NVR.
- NVR supervision: connection state, storage missing, storage failure, low disk
  space, login failure, local alarm input, network change.
- "Alarm output ON/OFF" action commands on the NVR, "White light" and "Siren" on
  the cameras, hidden by default and only working if the hardware actually
  exposes them.
- The less common detections are created but hidden, to keep the widget
  readable.

**Images and PTZ**

- Snapshot on detection or on demand, capped at one every 10 seconds per camera,
  with automatic purge of the oldest ones.
- Snapshots are served through an authenticated PHP proxy: they display inside
  Jeedom, but their address cannot be read by an external service.
- PTZ control by preset recall, with a default preset per camera.

**Interface**

- "Test connection" button: model, firmware, channel count and detected
  capabilities.
- "Take a snapshot now" button, with an immediate preview.
- Integration with the Jeedom Health page: daemon state and state of each NVR.
