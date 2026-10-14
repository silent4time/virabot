# Eylan v1.8.12

## Important
- `inboundid` on each bot panel must be exactly: openvpn | l2tp | cisco | wireguard | multi
- Creating users named L2tp/Openvpn inside Eylan web UI does NOT control bot protocol flags
- Use the **API base URL** for panel URL in the bot

## Apply
```bash
cp Eylan.php apply_eylan_panels.php /home/silentsell/
cd /home/silentsell
php apply_eylan_panels.php
php -l panels.php && php -l Eylan.php
```

## Verify inboundid in DB
```sql
SELECT name_panel, type, inboundid, url_panel FROM marzban_panel WHERE type='eylan';
```
