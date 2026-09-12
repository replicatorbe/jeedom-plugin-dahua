# Dahua NVR plugin

Receives events from a Dahua NVR or camera in real time and exposes them as
Jeedom commands: motion detection, smart detection (human, vehicle), line
crossing, video loss, snapshots and PTZ control.

A daemon keeps a permanent connection to the NVR, so events arrive within a few
hundred milliseconds, with no polling. The daemon is written in PHP and installs
no dependency.

## Installation

1. Install the plugin, then enable it.
2. Open the plugin configuration and keep the default values unless you have a
   port conflict.
3. Create a device of type **NVR / Recorder**.

## Configuring the NVR

| Field | Value |
|---|---|
| IP address | the address of your NVR on the local network |
| Port | the DHIP protocol port, `80` in most cases |
| HTTP port (CGI) | the web interface port, `80` by default |
| Transport | `Automatic (DHIP then CGI)`, unless you have a specific reason (see below) |
| Username | an NVR account, `admin` by default |
| Password | the password of that account |

Save, then use **Test connection**: the plugin reports the model, the firmware
version and the number of channels. It also asks the NVR about its alarm outputs
and its coaxial control (lighting, siren). If this test fails, nothing else will
work — fix it before going any further.

> Prefer a dedicated account on the NVR over `admin`. The password is stored in
> the Jeedom database unencrypted, as it is for every plugin that drives a
> network device.

### Port and HTTP port

Two ports are requested because they are not always the same:

- **Port** is used for the event connection (DHIP).
- **HTTP port (CGI)** is used for snapshots, PTZ commands and the CGI transport,
  which all go through the web API of the NVR.

On a factory NVR both are `80` and there is nothing to change. If you moved the
DHIP service to `5000` while keeping the web interface on `80`, enter `5000` as
the port and `80` as the HTTP port.

## Choosing the transport

Each NVR has a **Transport** setting with three values.

| Value | Behaviour |
|---|---|
| Automatic (DHIP then CGI) | tries DHIP, switches to CGI after two failed connections |
| DHIP only | uses DHIP only, never switches |
| CGI only | uses HTTP long-polling only |

**DHIP** is the native protocol of Dahua devices. It is the richer one: it is
the only transport that reports door station events from VTO/VTH intercoms
(call, door open). In exchange, it takes about a minute to notice a network
outage, the time its keepalive needs to expire.

**CGI** is HTTP long-polling. The NVR sends a heartbeat every five seconds, so
an outage is seen within about fifteen seconds. Some recent firmwares reject
DHIP authentication while accepting CGI perfectly — hence the automatic
fallback.

In practice, leave it on **Automatic**. Force `CGI only` if your NVR does not
accept DHIP and you want to avoid the two pointless attempts at every startup.
Force `DHIP only` if you have an intercom and do not want to lose its events
should a fallback happen.

The transport actually in use is written to the `dahuad` log on every
connection.

## Creating the cameras

The **Discover cameras** button queries the NVR and creates one device per
channel, using the name already configured in the NVR. Each camera gets its
commands automatically. Save the NVR before running discovery.

You can also create a camera by hand: pick the type **Camera (channel)**,
select the NVR and enter the channel number as the NVR displays it (`D1` → 1,
`D2` → 2, and so on).

## Available commands

### On each camera

| Command | Type | Description |
|---|---|---|
| Motion | binary | classic motion detection |
| Human detected | binary | SMD smart detection |
| Vehicle detected | binary | SMD smart detection |
| Line crossed | binary | IVS line crossing rule |
| Region crossed | binary | IVS intrusion rule |
| Video loss | binary | the channel no longer receives a stream |
| Camera tampering | binary | the lens is obstructed |
| Face detected | binary | face detection |
| Parking detection | binary | vehicle parked in a forbidden area |
| Object left behind | binary | object left in the monitored area |
| Object taken away | binary | object removed from the monitored area |
| Loitering | binary | prolonged presence in the area |
| Audio anomaly | binary | unusual sound picked up by the microphone |
| Audio mutation | binary | abrupt change in sound level |
| Fire warning | binary | flame or smoke detection |
| Last event | string | code and action of the last event received |
| Last event date | string | timestamp of that last event |
| Last snapshot | string | address of the latest snapshot |
| Take a snapshot | action | triggers an immediate capture |
| Go to preset | action | recalls a PTZ preset |

Binary commands go to `1` when the event starts and back to `0` when it ends.
Events whose end the NVR never announces fall back automatically after the delay
set in the plugin configuration.

All these commands are created, but they will only report something if the
matching detection is enabled on the channel, inside the NVR. A camera without a
microphone will never report an audio anomaly, for instance.

To avoid fifteen rows per camera, only the most common detections are visible to
start with: motion, human, vehicle and video loss. The others exist and work,
they are simply hidden. Tick "Display" in the **Commands** tab of the device to
show one.

### On the NVR

| Command | Type | Description |
|---|---|---|
| Connected | binary | the daemon has an established connection to this NVR |
| Storage missing | binary | no disk detected |
| Storage failure | binary | disk error |
| Low disk space | binary | the disk is nearly full |
| Login failure | binary | an authentication attempt was rejected by the NVR |
| Local alarm input | binary | physical alarm input of the NVR |
| Network change | binary | the network configuration changed |
| Last event | string | code and action of the last global event |
| Last event date | string | timestamp of that last event |
| Reconnect | action | forces the daemon to reopen the connection |
| Alarm output ON / OFF | action | toggles the first alarm output of the NVR |

## Relays, siren and alarm outputs

The plugin exposes action commands for the alarm outputs of the NVR
(`Alarm output ON` / `Alarm output OFF`) and for the lighting or siren of the
cameras (`White light ON` / `OFF`, `Siren ON` / `OFF`). They are hidden by
default: show them from the **Commands** tab if your hardware supports them.

**They only work if the hardware actually provides them.** This is the most
common disappointment:

- Many NVRs have no physical alarm input or output at all. The whole NVR41xx-xP
  series is an example. The command exists in Jeedom, but the NVR answers with
  an error.
- Cameras connected to the internal PoE switch of the NVR sit on a private
  network (typically `10.1.1.x`) that is not routed. Jeedom cannot reach them
  directly: only the events relayed by the NVR come through, and their lighting
  or siren cannot be driven.
- A camera plugged into your own local network, with its own IP address, and
  whose model has a floodlight or a siren, will work normally.

To find out where you stand, the simplest check is to show the command from the
**Commands** tab and run it once. If the hardware does not support it, Jeedom
shows an explicit error such as "This hardware has no alarm output" instead of
failing silently. The **Test connection** button also probes the NVR for these
capabilities.

## Snapshots

Enable **Capture on every detection** in the plugin configuration to take an
image at the start of every event. The rate is capped at one capture every
10 seconds per camera so the NVR is not flooded. Images are stored in
`plugins/dahua/data/snapshots` and the oldest ones are deleted automatically,
according to the number set in the configuration.

The **Take a snapshot now** button, in the camera tab, triggers a capture and
displays the result straight away. It is the quickest way to check that the HTTP
port and the credentials are right.

### What "Last snapshot" contains

The command returns an address such as:

```
plugins/dahua/core/php/snapshot.php?file=cam12_20250912-204501_a1b2c3d4.jpg
```

This is not a public file: it is a PHP proxy that checks the request comes from
an authenticated Jeedom session before serving the image. The snapshot folder
itself is not directly accessible.

What this implies:

- **Inside Jeedom** (camera widget, dashboard tile, mobile interface) the image
  displays normally: the browser is already authenticated.
- **Outside Jeedom**, it does not. A Telegram notification, an email or any
  other external service given that address will not be able to load the image:
  it has no Jeedom session and will get an access error.

To send an image in a notification, attach the **file** rather than the address.
Its path on disk is `plugins/dahua/data/snapshots/<file name>` under your Jeedom
root, and notification plugins that accept a file attachment (Telegram, Mail and
others) can use it.

## PTZ

The **Go to preset** command recalls a preset stored in the NVR. With no value,
it uses the default preset defined on the camera device. With a value passed
from a scenario, it recalls that preset.

This command goes through the HTTP port. It only applies to motorised cameras,
and the preset must exist in the NVR.

## Plugin configuration

| Setting | Purpose |
|---|---|
| Local listening port | port on `127.0.0.1` through which Jeedom sends its orders to the daemon (55060 by default). Change it only if another service already uses that port. |
| Reconnection delay | wait before retrying a lost connection to an NVR. |
| Instant event duration | delay after which a binary command falls back to 0 when the NVR does not announce the end of the event. |
| Capture on every detection | takes an image at the start of every event. |
| Snapshots kept per camera | beyond that number, the oldest ones are deleted. |

The local listening port is never exposed to the outside: the daemon only
listens on the loopback interface, and every order is signed with the plugin API
key.

## Health tab

The Jeedom **Health** page (Analysis → Health, Dahua NVR section) shows at a
glance:

- whether the daemon is running or stopped;
- for each NVR, whether it is connected, along with its address.

It is the first place to look when nothing is coming through any more.

## Use in a scenario

Trigger on a human detection:

```
Trigger: #[Outside][NORTH][Human detected]# == 1
```

Recall preset 3, then take a snapshot:

```
Action: #[Outside][NORTH][Go to preset]# with message 3
Action: #[Outside][NORTH][Take a snapshot]#
```

## Troubleshooting

The plugin writes to two logs:

- **dahua** — event processing on the Jeedom side;
- **dahuad** — the connection to the NVR and the raw stream.

Set the log level to *Debug* to see every event received.

| Symptom | Likely cause |
|---|---|
| Daemon not launchable | no NVR configured, or the device is disabled |
| `unknown user or wrong password` | wrong credentials |
| `account locked after too many attempts` | the NVR locked the account, wait or unlock it from its own interface |
| `account already logged in from another host` | the NVR session limit is reached |
| Constant fallback to CGI | the firmware rejects DHIP; force `CGI only` to save time at startup |
| No event at all | detection is not enabled on the channel inside the NVR |
| Human/Vehicle always 0 | smart detection (SMD) is not enabled on that channel |
| Snapshots fail while events work | the HTTP port is wrong, or the account is not allowed to capture |
| Image missing in an external notification | expected: the address requires a Jeedom session, attach the file instead |
| Alarm output does nothing | the hardware has no such output, or the camera sits behind the PoE switch of the NVR |

The NVR limits the number of simultaneous connections (10 by default). If you
have several clients connected, free one before starting the daemon.
