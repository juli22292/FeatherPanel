# Updates dieses Forks

Das Admin-Dashboard fragt stabile GitHub-Releases von `juli22292/FeatherPanel` ab. Entwürfe, Vorabversionen und Tags außerhalb der Formate `vMAJOR.MINOR.PATCH` und `vMAJOR.MINOR.PATCH.BUILD` werden ignoriert. Die installierte Version steht in `APP_VERSION`. Ein Klick auf „Nach Updates suchen“ aktualisiert die Abfrage nach frühestens einer Minute; sonst werden Ergebnisse 15 Minuten zwischengespeichert.

## Ein bestehendes Panel aktualisieren

Ein bereits installiertes Panel behält seine Datenbank, Konfiguration und Docker-Volumes, wenn es mit demselben Compose-Projekt und denselben Volumes aktualisiert wird. Vor einer Umstellung von einem offiziellen Panel auf diesen Fork sind die verwendete Compose-Datei, Images, Umgebungsvariablen und Volumes zu prüfen und zu sichern. Der Fork-Updater übernimmt diese Umstellung nicht automatisch. Der normale Installer ist für eine Neuinstallation gedacht.

Für die Update-Schaltfläche ab Version 1.4.3 wird einmalig ein Host-Dienst benötigt. Er läuft außerhalb der Panel-Container und hat Zugriff auf Docker, damit das Backend keinen Docker-Socket erhält. Auf einem Linux-Host mit systemd und einem bereits laufenden **Fork-Compose-Projekt `featherpanel`**:

```bash
sudo bash deploy/fork-updater/install.sh /absoluter/pfad/docker-compose.yml /absoluter/pfad/panel.env
```

Der Installer prüft die vier Fork-Images, richtet einen geheimen Token ein und startet `featherpanel-fork-updater.service` an der Docker-Host-Gateway-Adresse auf Port 9418. Danach muss der Backend-Container mit derselben Compose-Datei neu erstellt werden, damit er `DOCKER_UPDATER_TOKEN` erhält. Prüfe anschließend `systemctl status featherpanel-fork-updater.service` und die Update-Seite im Adminbereich.

**Version 1.4.2 kann sich nicht selbst über die Update-Schaltfläche auf 1.4.3 bringen.** Ihr Backend blockiert Updates aus GitHub-Releases. Für diesen ersten Schritt ist eine einmalige manuelle Aktualisierung des bestehenden Compose-Projekts auf die 1.4.3-Images nötig; erst dann kann die Schaltfläche für spätere Releases genutzt werden. Nur das Veröffentlichen von 1.4.3 auf GitHub ändert eine laufende Installation nicht.

Der Dienst nimmt keine frei wählbaren Images, Tags oder Befehle vom Panel an. Er liest das höchste stabile Release aus diesem Repository, prüft die aktuell laufenden Fork-Images, lädt die vier neuen Images, sichert Datenbank und persistente Volumes unter `fork-update-backups/` neben `panel.env`, setzt `FEATHERPANEL_IMAGE_TAG` und erstellt nur die vier Anwendungscontainer neu. Datenbank und Volumes bleiben bestehen. Der Dienst meldet Fehler und Sicherungspfad mit `systemctl status` beziehungsweise `journalctl -u featherpanel-fork-updater.service`. Ein fehlgeschlagenes Update erfordert Prüfung und gegebenenfalls Wiederherstellung aus der Sicherung; es erfolgt kein automatischer Rollback.

Ab 1.4.3.1 unterstützen Dashboard und Host-Updater auch vierteilige Versionsnummern. Ein unverändertes 1.4.3 ignoriert solche Releases noch; für den ersten Klick auf 1.4.3.1 müssen dessen GitHub-Prüfung und Host-Updater einmalig angepasst werden. Die lokale Testinstallation erhält diese Kompatibilitätsanpassung vor dem Test.
