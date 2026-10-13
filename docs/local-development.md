# Lokale Entwicklung

Diese Einrichtung ist für diesen Windows-PC gedacht. Debian 13 läuft unter WSL2. Docker Engine und Compose laufen direkt in Debian. Das Repository liegt unter `/home/admin/projects/FeatherPanel`; `origin` ist `juli22292/FeatherPanel`, `upstream` ist `MythicalLTD/FeatherPanel`.

Die Datei `/home/admin/.config/featherpanel/local.env` enthält zufällig erzeugte Datenbank- und Redis-Passwörter. Sie liegt außerhalb von Git und ist nur für den Linux-Benutzer lesbar. Nicht in GitHub hochladen.

Seit dem lokalen Update auf 1.4.3 läuft die Testinstallation mit den veröffentlichten Fork-Images aus `docker-compose.yml`. Der systemd-Dienst `featherpanel-fork-updater.service` ist eingerichtet und für künftige Updates über das Panel aktiviert. Das Image-Tag und der geheime Updater-Token stehen in `local.env`.

Für Wartung durch Codex oder eine technisch versierte Person:

```bash
cd /home/admin/projects/FeatherPanel
docker compose --env-file /home/admin/.config/featherpanel/local.env -f docker-compose.yml ps
systemctl status featherpanel-fork-updater.service
```

`docker-compose.v2.git.yml` baut lokale Entwicklungs-Images. Diese Datei nicht für die laufende Testinstallation starten: Sie würde die veröffentlichten Panel-Images ersetzen. Sicherungen des lokalen Panels liegen unter `/home/admin/.config/featherpanel/fork-update-backups/` und sind nur für Root lesbar.

Die Browseradresse unter Windows ist `http://localhost:4831`. Docker veröffentlicht intern nur `127.0.0.1:4832` in WSL. Der aktivierte Dienst `featherpanel-local-proxy.service` leitet Windows-`localhost:4831` zu diesem Docker-Port weiter. Beide lauschen nur lokal; es gibt keine Routerfreigabe. `0.0.0.0` ist eine interne Bindeadresse für Dienste innerhalb von Docker und keine Browseradresse.

Die Datenbank, Redis-Daten, Plugins, Konfiguration und Uploads liegen in benannten Docker-Volumes. `docker compose down` stoppt Container, behält diese Daten aber. **`down -v` löscht Volumes und darf hier nicht ohne Backup verwendet werden.**

Der erste Administrator wurde eingerichtet. Seine Zugangsdaten liegen nur in `/home/admin/.config/featherpanel/admin-login.txt` und in der lokalen Datenbank, nicht im Git-Repository. Die allgemeine Registrierung wurde danach deaktiviert.

Bei einem Fehler helfen `docker compose ... ps` und `docker compose ... logs --tail=100`. Codex kann diese Prüfungen selbst durchführen.
