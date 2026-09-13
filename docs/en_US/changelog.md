# Changelog

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
