# Eigene Releases

Versionsnummern folgen `MAJOR.MINOR.PATCH` oder `MAJOR.MINOR.PATCH.BUILD`. Git-Tags beginnen mit `v`, zum Beispiel `v1.5.6.7`. Die aktuelle Fork-Version im Quellcode ist `1.5.6.7`.

Vor einem Release müssen `backend/boot/constants.php`, `backend/cli`, `backend/storage/cron/runner.php`, `backend/scripts/export-openapi.php` und `app` dieselbe Version enthalten. Für `v1.5.6` steht in `frontendv2/package.json` die Version `1.5.6`. Bei vierteiligen Versionsnummern wird der letzte Punkt für npm durch einen Bindestrich ersetzt. Ein Release-Tag wird erst nach erfolgreichem Build und Tests erstellt. Die GitHub Action `Fork Release` verweigert ein Tag, dessen Version nicht zum Backend und Frontend passt.

Pull Requests führen mit `Fork CI` Backend-Tests, Frontend-Build, Installer- und Updater-Prüfung sowie Docker-Builds aus. Ein `vX.Y.Z`- oder `vX.Y.Z.N`-Tag führt diese Prüfung erneut aus, baut vier eigene GHCR-Images und legt ein GitHub Release mit Quellcode-Archiv an. Es wird kein Server automatisch aktualisiert.

Wenn GHCR für ein Release ausfällt, kann ein Release mit Docker-Archiven veröffentlicht werden. Exportiere für jede unterstützte Architektur und jeden Dienst (`backend`, `frontendv2`, `mcp`, `async-runner`) das einzelne Image `ghcr.io/juli22292/featherpanel-<dienst>:<tag>` als gzip-komprimiertes Docker-Archiv. Benenne die Datei `featherpanel-<dienst>-linux-<architektur>-<tag>.tar.gz`, wobei die Architektur `amd64` oder `arm64` ist. Hänge alle vier Archive an das Release und prüfe Downloads und Prüfsummen, bevor du es stabil veröffentlichst. Auf bestehenden Installationen wird dazu der aktualisierte [Host-Dienst](updates.md) benötigt.

Version 1.4.2 ist das erste eigene Test-Update. Es korrigiert den Seitenleisten-Button und aktiviert die selbst gehosteten Anpassungen des Forks. Diese Funktionen sind von Mythic-Premium-Abonnements getrennt; Cloud-Vorteile und Support sind nicht enthalten.

Version 1.4.3 zeigt beim Dateiupload den tatsächlichen Dateinamen an. Sie enthält den Fork-Updater für spätere Updates über die Adminseite. Der erste Wechsel von 1.4.2 zu 1.4.3 erfordert die in [Updates](updates.md) beschriebene einmalige Einrichtung.

Version 1.4.3.1 ergänzt vierteilige Release-Versionen in der GitHub-Erkennung, im Host-Updater und in der Release-Pipeline. Für ein bereits laufendes 1.4.3 sind zur Anzeige dieses ersten vierteiligen Updates einmalig kompatible Prüfkomponenten erforderlich.

Version 1.4.3.2 übernimmt die Änderungen von Upstream v1.5.0 und behält die eigene Premium-Anpassung und den GitHub-Updatepfad bei.

Version 1.5.6 übernimmt die stabilen Upstream-Releases bis v1.5.5. Die eigene Premium-Anpassung und der GitHub-Updatepfad bleiben erhalten. Der aktualisierte Host-Dienst unterstützt sowohl alte als auch neue Backend-Images.
