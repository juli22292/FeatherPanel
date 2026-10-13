# Eigene Releases

Versionsnummern folgen `MAJOR.MINOR.PATCH`, Git-Tags beginnen mit `v`, zum Beispiel `v1.4.2`. Die aktuelle Fork-Version im Quellcode ist `1.4.2`.

Vor einem Release müssen `backend/public/index.php`, `backend/cli`, `backend/storage/cron/runner.php`, `backend/scripts/export-openapi.php` und `frontendv2/package.json` dieselbe Version enthalten. Ein Release-Tag wird erst nach erfolgreichem Build und Tests erstellt. Die GitHub Action `Fork Release` verweigert ein Tag, dessen Version nicht zum Backend und Frontend passt.

Pull Requests führen mit `Fork CI` Backend-Tests, Frontend-Build, Installer-Syntaxprüfung und Docker-Builds aus. Ein `vX.Y.Z`-Tag führt diese Prüfung erneut aus, baut vier eigene GHCR-Images und legt ein GitHub Release mit Quellcode-Archiv an. Es wird kein Server automatisch aktualisiert.

Version 1.4.2 ist das erste eigene Test-Update. Es korrigiert den Seitenleisten-Button und aktiviert die selbst gehosteten Anpassungen des Forks. Diese Funktionen sind von Mythic-Premium-Abonnements getrennt; Cloud-Vorteile und Support sind nicht enthalten.
