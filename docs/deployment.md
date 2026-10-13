# Deployment

Aktuell ist nur ein lokaler Test auf diesem PC vorgesehen. Es gibt keine öffentliche Domain, Routerfreigabe oder produktive Installation. Der Compose-Port ist standardmäßig auf `127.0.0.1` beschränkt.

`docker-compose.v2.git.yml` baut Backend, Frontend, MCP und Async Runner aus diesem Checkout. `docker-compose.yml` ist für spätere veröffentlichte Images unter `ghcr.io/juli22292/featherpanel-{backend,frontendv2,mcp,async-runner}` vorbereitet. MariaDB und Redis laufen als interne Dienste; sie veröffentlichen keine Host-Ports.

Für einen späteren Internetbetrieb sind zusätzlich ein geprüftes Release, HTTPS, Backup und Wiederherstellung, Zugriffsschutz, sichere Registrierung, Monitoring und eine separate Sicherheitsprüfung nötig. Das ist derzeit nicht eingerichtet. Der alte Upstream-Installer unter `installer/` ist nicht der lokale Installationsweg dieses Forks; für zukünftige Releases ist `install.sh` vorgesehen.

Persistente Daten befinden sich in den in Compose definierten Volumes. Vor einem Update müssen mindestens die Datenbank und die Konfigurations-, Plugin- und Upload-Volumes gesichert werden. Ein neuer Container ersetzt diese Daten nicht.
