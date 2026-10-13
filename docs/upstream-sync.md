# Änderungen des Originalprojekts übernehmen

`origin` zeigt auf `juli22292/FeatherPanel`; `upstream` auf `MythicalLTD/FeatherPanel`. Der Original-Entwicklungszweig heißt `develop`. Der eigene Stand soll über einen separaten Update-Zweig und Pull Request geprüft werden.

Für Codex oder eine technisch versierte Person: zuerst `git fetch upstream`, dann einen neuen Zweig vom eigenen Hauptzweig anlegen und `upstream/develop` dort zusammenführen. Konflikte werden einzeln geprüft; insbesondere Updatequelle, eigene Images, lokale Portbindung, Installer und Lizenzen dürfen nicht unbemerkt überschrieben werden. Danach Build und Tests ausführen und einen Pull Request zum eigenen Hauptzweig öffnen.

Die Übernahme ändert keine laufenden Container. Ein Release und ein späteres Update erfolgen getrennt. Datenbank und Volumes vor einem laufenden Update sichern.
