# Eigene Releases

Versionsnummern folgen `MAJOR.MINOR.PATCH` oder `MAJOR.MINOR.PATCH.BUILD`; Git-Tags beginnen mit `v`, zum Beispiel `v1.4.3.1`. Die aktuelle Fork-Version im Quellcode ist `1.4.3.1`.

Vor einem Release müssen `backend/public/index.php`, `backend/cli`, `backend/storage/cron/runner.php` und `backend/scripts/export-openapi.php` dieselbe Version enthalten. Da npm keine vierteiligen Versionsnummern akzeptiert, trägt `frontendv2/package.json` für `v1.4.3.1` die interne SemVer-Version `1.4.3-1`. Ein Release-Tag wird erst nach erfolgreichem Build und Tests erstellt. Die GitHub Action `Fork Release` verweigert ein Tag, dessen Version nicht zum Backend und Frontend passt.

Pull Requests führen mit `Fork CI` Backend-Tests, Frontend-Build, Installer- und Updater-Prüfung sowie Docker-Builds aus. Ein `vX.Y.Z`- oder `vX.Y.Z.N`-Tag führt diese Prüfung erneut aus, baut vier eigene GHCR-Images und legt ein GitHub Release mit Quellcode-Archiv an. Es wird kein Server automatisch aktualisiert.

Version 1.4.2 ist das erste eigene Test-Update. Es korrigiert den Seitenleisten-Button und aktiviert die selbst gehosteten Anpassungen des Forks. Diese Funktionen sind von Mythic-Premium-Abonnements getrennt; Cloud-Vorteile und Support sind nicht enthalten.

Version 1.4.3 zeigt beim Dateiupload den tatsächlichen Dateinamen an. Sie enthält den Fork-Updater für spätere Updates über die Adminseite. Der erste Wechsel von 1.4.2 zu 1.4.3 erfordert die in [Updates](updates.md) beschriebene einmalige Einrichtung.

Version 1.4.3.1 ergänzt vierteilige Release-Versionen in der GitHub-Erkennung, im Host-Updater und in der Release-Pipeline. Für ein bereits laufendes 1.4.3 sind zur Anzeige dieses ersten vierteiligen Updates einmalig kompatible Prüfkomponenten erforderlich.
