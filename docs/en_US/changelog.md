# Changelog

## 1.0.0

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
