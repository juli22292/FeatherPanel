# Eigene Releases

Versionsnummern folgen `MAJOR.MINOR.PATCH`, Git-Tags beginnen mit `v`, zum Beispiel `v1.4.2`. Die aktuelle Ausgangsversion im Quellcode ist `1.4.1`.

Vor einem Release müssen `backend/public/index.php`, `backend/cli`, `backend/storage/cron/runner.php`, `backend/scripts/export-openapi.php` und `frontendv2/package.json` dieselbe Version enthalten. Ein Release-Tag wird erst nach erfolgreichem Build und Tests erstellt. Die GitHub Action `Fork Release` verweigert ein Tag, dessen Version nicht zum Backend und Frontend passt.

Pull Requests führen mit `Fork CI` Backend-Tests, Frontend-Build, Installer-Syntaxprüfung und Docker-Builds aus. Ein `vX.Y.Z`-Tag führt diese Prüfung erneut aus, baut vier eigene GHCR-Images und legt ein GitHub Release mit Quellcode-Archiv an. Es wird kein Server automatisch aktualisiert.

Das Repository hat derzeit noch **kein eigenes GitHub Release**. Bis das erste Release erstellt und die Images veröffentlicht sind, wird lokal aus dem Quellcode gebaut. `install.sh` meldet ausdrücklich, wenn noch kein stabiles Release verfügbar ist.
