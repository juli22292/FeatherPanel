# Update-Anzeige

Der Backend-Dienst verwendet `UPDATE_REPOSITORY=juli22292/FeatherPanel`. Im Admin-Dashboard werden die GitHub-Releases dieses Repositorys abgefragt und 15 Minuten zwischengespeichert. Drafts, Pre-Releases und Tags ohne dreiteilige Versionsnummer werden ignoriert. Es zählt die höchste stabile Versionsnummer, nicht die Reihenfolge der GitHub-Liste.

Die installierte Version kommt aus `APP_VERSION`. Die Anzeige zeigt aktuelle und neueste Version, Release Notes, Veröffentlichungsdatum und einen Link zum GitHub Release. Der Dashboard-Endpunkt erfordert die vorhandene Berechtigung `ADMIN_DASHBOARD_VIEW`; die Plugin-Schnittstellen bleiben unverändert.

Für diesen Fork gibt es bewusst keinen automatischen Panel-Update-Button. Der Backend-Endpunkt lehnt automatische Updates bei gesetztem `UPDATE_REPOSITORY` ebenfalls ab. So kann ein alter Updater keine offiziellen Images oder ungesicherte Datenänderung starten. Ein künftiges Update muss nach Backup und Prüfung der Release Notes manuell erfolgen.
