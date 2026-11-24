# Demo script — typical network problems on the topology graph (stand "office-demo", scenario D1)

For the person who shows it. Seven situations, one step each, on the real office stand (12 switches, 2 gateways). Every step is one command; the stand then stays up and
Zabbix keeps polling it, so the page can be opened and clicked as in a real network. Screenshots of every step are in the handoff folder (`104-d1-step0.png` …
`104-d1-step6.png`, plus `104-d1-step3-before.png` and `104-d1-step6-before.png`) as a fallback if the live demo fails.

## Before the demo

```
cd database/topology/lab
export ZBX_TOKEN=...                       # the API token of the test Zabbix
labsim --lab stands/office-demo/lab-office-demo.yaml status        # which stand is up, if any
labsim down                                # if another stand is up (the office stand or a test): the two office stands share addresses and host names
```

Page: `http://localhost/zabbix/ui/zabbix.php?action=topology.view`. The commands below are one `labsim show` each; `--reset` clears the topology data first, so every step starts clean.
A step takes a few minutes (the switches are polled one after another). Steps 1 and 5 take longer, because they wait for an alarm and for old data.
Steps 0 to 4 keep the default freshness threshold (7 days): the lines do not fade during a demo, whenever you look. Step 5 sets it to 3 minutes (that is its point: the switch that stopped
answering shows "Old data" while the others are polled again); from step 5 on, the lines fade 3 minutes after the show command. `labsim down` puts the threshold back.

To go back to the plain office stand (no demo rows): `labsim down`, then `labsim show R1 --lab lab-office.yaml --reset`. To come back: `labsim down`, `labsim show D1 --step <n> --reset`.

## Steps

### 0 — The office as it is
- Command: `labsim show D1 --step 0 --reset`
- Look: nothing selected in the filter, or *Focus host* `SW12-10-CORE-6000-48G`, depth 2. Click the big switch SW12 (the core).
- Say: this is the office network as the switches themselves describe it: switches, the cables between them, the computers and phones on them. Nothing was drawn by hand.

### 1 — A link goes down
- Command: `labsim show D1 --step 1 --reset` (waits a few minutes for the alarm)
- Look: *Focus host* `SW12-10-CORE-6000-48G`, depth 1. Click the line between SW12 and `SW13-10-ACC-6000-48G` (port 1/1/42 – 1/1/48).
- Say: one cable between two switches has failed. The line turns the colour of the alarm, and the panel shows the problem. The line is still there, because the switches have not forgotten it yet.

### 2 — An unmanaged switch under a desk
- Command: `labsim show D1 --step 2 --reset`
- Look: *Focus host* `SW17-09-ACC-2530-48G`, depth 1. Click the small circle named `16` under SW17.
- Say: three devices (two computers and a printer) are seen on one port of SW17. Somebody has put a small switch under the desk. The graph shows them hanging on one point, not as three
  separate cables that do not exist. (The MikroTik on SW18 port 6 is a second such point, until step 3.)

### 3 — One device looks like three
- Before: `labsim show D1 --step 2 --reset`, *Focus host* `SW18-09-ACC-2530-48G`, depth 1: three devices called `MikroTik` on one port. Tick *Possible duplicates only* to see why they look alike.
- Command: `labsim show D1 --step 3 --reset`
- Look: the same view: one `MikroTik`.
- Say: one router was seen under three different identities (a name, an address, a hardware address). A person marked them as the same device once, and now the graph has one device.

### 4 — A device was replaced
- Command: `labsim show D1 --step 4 --reset`
- Look: *Focus host* `SW15-10-POE-6000-24G`, depth 1. Click the new line on port 1/1/1 (the device is named NA12501003431, as before; its new hardware address is 3a:5c:91:e2:07:4b).
- Say: a device on the same port came back with a new hardware address (it was replaced or restarted). The graph shows the new one with a new line, and the old line stays faded (grey), so the history is not lost.

### 5 — A switch stops answering
- Command: `labsim show D1 --step 5 --reset` (waits about three minutes for the data to become old)
- Look: *Focus host* `SW21-07-ACC-2530-48G`, depth 1. Click `SW21-07-ACC-2530-48G`.
- Say: the switch does not answer any more. Nothing is deleted and nothing is moved: its lines stay, and the panel says its data is old. The other switches are fine.

### 6 — A router that does not announce itself
- Before: `labsim show D1 --step 5 --reset` (or any earlier step), *Focus host* `SW22-07-ACC-2530-48G`, depth 1, open *No neighbor* in the port list: port 41 is up and nothing is known about what is behind it.
- Command: `labsim show D1 --step 6 --reset`
- Look: the same view: a new device `ISP-Router` on port 41; click it.
- Say: some devices say nothing about themselves. A person can add such a device by hand and connect it to the port; it is shown like the others, with a dashed line to mark that a person drew it.
  Adding a device by hand is planned for the next release; in this demo it is done by the script, through the same code as the page will use.

### 7 — A trunk runs at the wrong speed (task 119)
- Command: `labsim show D1 --step 7 --reset`
- Look: *Focus host* `SW15-10-POE-6000-24G`, depth 1: the trunk to SW12 (SW12 1/1/41 - SW15 1/1/21). A small warning sign (task 120) is in the middle of the line, with no hover. Hover it, or click it: the mark "speed mismatch: 1 Gbit/s / 100 Mbit/s" is in the tooltip
  and the link panel; the Links row of SW15 has the short mark "speed mismatch" (both speeds in its hint box). The line itself does not change (colour, dash, width, opacity).
- Say: both ends of the cable are up, but one of them agreed on 100 Mbit/s. Nothing is down, so no alarm fires; this is what a bad cable or a failed autonegotiation looks like.
  (The office stand R1 has no such link; the deviation `speed-mismatch-sw15-21` of this stand sets `ifHighSpeed` of SW15 port 21 to 100. The page check of this step is `ui_check_040/d1_mark_check.py`, run with the stand up at step 7.)

## After the demo

```
labsim down
```
`down` stops the simulated switches and puts back the setting that makes the data old after 3 minutes. The hosts stay in Zabbix. To bring the plain office stand back: `labsim show R1 --lab lab-office.yaml --reset`.
