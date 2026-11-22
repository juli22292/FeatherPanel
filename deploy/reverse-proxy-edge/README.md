# Öffentlich verwaltete Reverse Proxys

ServerPanel kann eigene HTTP-/HTTPS-Domains am öffentlichen NGINX anlegen. Der
Connector gehört auf den Host, an dem die öffentlichen TCP-Ports 80 und 443
ankommen. In der Installation mit dem öffentlichen NGINX in CT 2001 und Wings in
CT 2002 gehört er in CT 2001. Der NGINX leitet HTTP an die dem Server zugewiesene
Allocation weiter und verwaltet HTTPS über Certbot.

## Einmalige Einrichtung

1. Prüfe auf dem öffentlichen NGINX-Host, ob Port 80 und 443 aus dem Internet
   erreichbar sind und ob seine NGINX-HTTP-Konfiguration
   `/etc/nginx/conf.d/*.conf` einbindet. NGINX, Certbot, Python 3, `dig` und
   die systemd-Einheit `certbot.timer` müssen vorhanden sein. Auf Debian
   liefern beispielsweise die Pakete `nginx certbot python3 dnsutils` diese
   Programme.
2. Kopiere `policy.example.json` nach `policy.json`. Trage nur die Netzbereiche
   und Ports der erreichbaren Server-Allocations ein. Schütze eigene
   Panel-, Node- und andere reservierte DNS-Zonen über `reserved_names`. Der
   Beispielwert `carrot.gay` schützt auch seine Unterdomains. Der Socket ist
   ausschließlich für die konfigurierte Gruppe zugänglich, normalerweise
   `www-data` mit GID 33 beim Docker-Backend.
3. Führe auf diesem Host aus dem Verzeichnis `deploy/reverse-proxy-edge`
   `bash install.sh policy.json` als root aus. Der Dienst ist ausschließlich
   über seinen Unix-Socket erreichbar. Der Dokumentenstamm für HTTP-01 liegt
   unter `/var/lib/serverpanel-edge/challenges`.
4. Ergänze in der vorhandenen Compose-Konfiguration unter dem Dienst
   `backend` diesen Mount zusätzlich zu den bestehenden Volumes und erstelle
   ausschließlich diesen Dienst neu.

   ```yaml
   volumes:
     - /run/serverpanel-edge:/run/serverpanel-edge:ro
   ```

   Verwende den bestehenden Compose-Projektnamen und seine bestehende
   Umgebungsdatei. Prüfe bei einem Backend mit angepasster GID die
   Gruppenberechtigung des Sockets.
5. Öffne **Admin → System → Reverse Proxy**. Im Abschnitt für den öffentlichen
   HTTPS-Proxy muss der Connector als erreichbar erscheinen. Gib die
   öffentliche IPv4 oder IPv6 am Eingang und eine E-Mail für die Zertifikate
   an und aktiviere die Funktion. Erlaube bei Bedarf zusätzlich
   benutzerdefinierte Proxys in **Admin → Settings → Servers**. Bei einer
   abweichenden öffentlichen IP in einer Spell-Regel korrigiere die Zuordnung
   oder nutze einen passenden Einstiegspunkt.

## Nutzung

Unter **Create Proxy** eine Domain und eine Allocation auswählen, dann die
DNS-Prüfung starten. Die Oberfläche zeigt die A-/AAAA-Einträge und den TXT-Eintrag
für die Domainkontrolle. Die DNS-Adressen müssen auf den konfigurierten
öffentlichen Einstieg zeigen. Eine direkte DNS-Auflösung ist erforderlich.
Nach der Überprüfung wird der Auftrag in die Warteschlange eingereiht. Der
öffentliche NGINX beantwortet HTTP-01 für diese Domain und aktiviert HTTPS nach
der Ausstellung des Zertifikats. Der TXT-Eintrag bleibt für Wiederholungen
verfügbar. Der Status erscheint in der Proxy-Liste.

Certbot erneuert ausgestellte Zertifikate regelmäßig über seinen Timer. Der
Deploy-Hook prüft NGINX und lädt ihn nach einer erfolgreichen Erneuerung neu.
Über die Proxy-Liste lässt sich die Domain samt Zertifikat entfernen. Eine
referenzierte Allocation ist bis dahin in der Datenbank gegen Löschen und
Neuzuordnung gesperrt.

Sichere die Panel-Datenbank sowie die Connector-Dateien unter
`/var/lib/serverpanel-edge`, die erzeugten NGINX-Dateien und die
Certbot-Daten. Erstellungsfehler und Details zur Zertifikatsanforderung
stehen im Journal von `serverpanel-edge.service`.
