# Serverinfo für Linux-LXC-Container

ServerPanel zeigt **Configuration → Serverinfo** bei QEMU/KVM und LXC. Inhaber
können die lokalen Linux-Konten auslesen, zusätzliche Konten anlegen und
Passwörter setzen. Die Seite zeigt konfigurierte OpenSSH-Ports an, sofern
OpenSSH im Gast vorhanden ist. Die LXC-Unterstützung braucht einen einmaligen
SSH-Anschluss pro Proxmox-Host. FeatherTail und die Konfiguration für QEMU
werden dafür nicht benötigt.

## Proxmox-Host vorbereiten

1. Erzeuge einen dedizierten Ed25519-Schlüssel. Zum Beispiel mit
   `ssh-keygen -t ed25519 -N '' -f serverpanel-lxc.key`. Lege den privaten
   Schlüssel ausschließlich in der geschützten Panel-Konfiguration ab.
2. Kopiere nur die Datei `serverpanel-lxc.key.pub` auf den Proxmox-Host. Lege
   dort eine Datei `vmids.txt` an. Gib pro Zeile eine erlaubte VMID oder einen
   Bereich an, zum Beispiel `5000-5999` für die LXC-Container dieses Panels.
3. Führe auf dem Proxmox-Host als root aus diesem Release-Verzeichnis
   `bash install.sh vmids.txt serverpanel-lxc.key.pub` aus. Der öffentliche
   Schlüssel erzwingt den Connector als einzigen Befehl. Der Connector bietet
   nur Kontoabfrage, Anlegen und Passwortwechsel in erlaubten, lokal laufenden
   Containern. SSH-Dienst und Host-Firewall bleiben der vorhandene Zugangspfad.
4. Ermittle den Fingerabdruck des Hostschlüssels direkt am Proxmox-Host mit
   `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`. Kopiere den Wert, der
   mit `SHA256:` beginnt. Der SSH-Server muss den Ed25519-Hostschlüssel anbieten
   und den zuvor installierten Schlüssel für den eingeschränkten Root-Zugang
   akzeptieren. Verwende nach Möglichkeit die Management-Adresse.

## Panel verbinden

Lege `nodes.json` und den privaten Schlüssel im Verzeichnis
`storage/config/lxc-guest-access` des Backends ab. Bei Docker liegt dieses
Verzeichnis unter `/var/www/html/storage/config/lxc-guest-access` im bereits
persistenten Konfigurationsvolume. Verzeichnis und Dateien sollen nur für den
Backend-Benutzer und berechtigte Administratoren lesbar sein. Bei der
Standardinstallation ist der Backend-Benutzer `www-data` (UID/GID 33).

Die erste Ebene in `nodes.json` ist die ID des VDS-Nodes im Panel. Innerhalb
davon steht der aktuelle Hostname aus der Proxmox-API. Beispiel für Panel-Node 1
und Proxmox-Host `pve01`:

```json
{
  "1": {
    "pve01": {
      "host": "10.0.0.12",
      "port": 22,
      "identity": "serverpanel-lxc.key",
      "host_fingerprint": "SHA256:HIER_DEN_FINGERABDRUCK_EINTRAGEN"
    }
  }
}
```

Passe pro Proxmox-Host dessen Eintrag an. Für eine Migration zwischen Hosts
braucht es pro Zielhost einen passenden Eintrag und erlaubte VMIDs. Ein Profil
gilt für alle erlaubten LXC-Container dieses Hosts. An bestehenden Containern
ist keine Installation nötig. Öffne als Besitzer **Configuration → Serverinfo**
bei einem laufenden Container, um seine vorhandenen Linux-Konten zu lesen.

In neuen LXC-Containern kann die Ersteinrichtung bei der VM-Erstellung
ausgewählt werden. Das Panel plant sie nur ein, wenn für den ausgewählten
Proxmox-Host ein Profil vorhanden ist. Das verwendete Linux-Image muss
`/bin/sh`, `chpasswd -e` und entweder `useradd` oder BusyBox-`adduser`
enthalten. SSH-Passwortanmeldung und SFTP-Zugriff folgen der Konfiguration des
Gastbetriebssystems.
