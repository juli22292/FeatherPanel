# FeatherPanel – Fork von juli22292

Dies ist die angepasste Version von [FeatherPanel](https://github.com/MythicalLTD/FeatherPanel) für `juli22292`. Der Originalcode und die Hinweise der ursprünglichen Autoren bleiben erhalten. Die maßgebliche Lizenz ist [GNU AGPL 3.0 oder neuer](LICENSE); einzelne Dateien enthalten zusätzliche Copyright- und Lizenzhinweise.

## Lokal entwickeln

Der Quellcode liegt unter `/home/admin/projects/FeatherPanel` in Debian/WSL2. Die lokale Umgebung baut alle Panel-Dienste aus diesem Fork und öffnet nur `http://localhost:4831`. Zugangsdaten liegen außerhalb des Repositorys. Die genauen Schritte stehen in [Lokale Entwicklung](docs/local-development.md).

Die Datei `docker-compose.v2.git.yml` baut eigene lokale Images. `docker-compose.yml` verwendet veröffentlichte Images unter `ghcr.io/juli22292/`.

## Updates und Releases

Die Adminanzeige prüft stabile GitHub-Releases von `juli22292/FeatherPanel`. Ab Version 1.4.3 kann die Adminseite spätere Fork-Releases über einen separat eingerichteten Host-Updater installieren. Für den ersten Wechsel von 1.4.2 ist eine einmalige manuelle Aktualisierung nötig. [Update-System](docs/updates.md) und [Releases](docs/releases.md) beschreiben den Ablauf. Der [Installer](install.sh) installiert die neueste veröffentlichte Version; er überschreibt keine bestehende Installation.

Seit Version 1.4.2 sind die im Quellcode enthaltenen lokalen Anpassungen für Branding, KI-Anzeige und Seitenleiste im Fork verfügbar. Das ist kein Mythic-Premium-Abonnement und beinhaltet keine Cloud-Vorteile oder bevorzugten Support.

Änderungen des Originalprojekts lassen sich über das Git-Remote `upstream` übernehmen. Siehe [Upstream übernehmen](docs/upstream-sync.md). Ein öffentlicher Server ist derzeit nicht eingerichtet; siehe [Deployment](docs/deployment.md).

Die ausführliche ursprüngliche Projektbeschreibung bleibt unter [.github/README.md](.github/README.md) erhalten.
