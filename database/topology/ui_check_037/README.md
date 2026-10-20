# ui_check_037 — node styles, label and legend (model §12.2), office stand

Screenshots of the office stand (task 034; `lab/stands/office`, R1 run from `--reset`, scope: host SW12, 3 hops) before and after task 037 are not in git: they show real host names and MACs of the office
network (the same reason as for `stands/office/data/`). They are in the handoff folder: `037-office-before.png`, `037-office-after.png`, `037-office-after-legend-collapsed.png`.

Made with headless Chrome, without a login: `render_view.py` builds a page that runs the real view code (`before` = git HEAD of the view, `after` = the working tree) against the JSON that
`dump_ui.php` reads from the database (the same functions as the endpoint `topology.devices.get`). `fetch` is stubbed, so ports, problems and the ingest status are empty.

```
php dump_ui.php 11921 3 > $OUT/shots/dump.json              # hostid of SW12, hops
python3 render_view.py before $OUT/shots/dump.json          # $OUT/shots/harness-before.html
python3 render_view.py after  $OUT/shots/dump.json [1]      # 1: legend collapsed
google-chrome --headless=new --no-sandbox --window-size=1300,820 --virtual-time-budget=15000 --screenshot=out.png file://$OUT/shots/harness-after.html
```
