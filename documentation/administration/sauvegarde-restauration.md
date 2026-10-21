<!-- torsade/documentation/administration/sauvegarde-restauration.md -->

# Sauvegarde et restauration

## Ce que contient une sauvegarde

`torsade-backup` écrit chaque sauvegarde dans un dossier de `/var/backups/torsade/` nommé d'après sa date et son heure en temps universel, par exemple `/var/backups/torsade/20261017T023000Z/`, lisible par root seulement :

| Fichier             | Contenu                                                                                                                                                                                        |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `database.sql.gz`   | export cohérent de la base `torsade`, pris en une transaction pendant que l'application tourne                                                                                                 |
| `backup.info`       | version de Torsade, nom de la machine, date                                                                                                                                                    |
| `configuration.env` | réglages conservés : `APP_KEY`, `APP_NAME`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_DISPLAY_TIMEZONE`, `SESSION_LIFETIME`, `LOG_LEVEL`, `LOG_DAILY_DAYS`, `TRUSTED_HOSTS`, `TRUSTED_PROXIES` |
| `SHA256SUMS`        | empreintes des trois fichiers, vérifiées avant toute restauration                                                                                                                              |

`APP_KEY`, la clé de l'application, en fait partie : sans elle, les secrets de double authentification enregistrés dans la base sont illisibles. Une sauvegarde ne contient ni les mots de passe de la base ni la clé privée du certificat, propres à la machine.

## Sauvegarde quotidienne

Une minuterie systemd lance `torsade-backup` chaque nuit vers 2 h 30, et au démarrage suivant si la machine était éteinte à cette heure :

```bash
systemctl list-timers 'torsade-*'
```

Les sauvegardes sont conservées 14 jours. Pour changer cette durée, modifiez `KEEP_DAYS` dans `/etc/torsade/backup.conf`, que les mises à jour ne réécrivent jamais.

Les réussites et les échecs vont dans le journal du système :

```bash
sudo journalctl -t torsade-backup
```

Pour une sauvegarde immédiate, par exemple avant une intervention :

```bash
sudo torsade-backup
```

La commande affiche le dossier de la sauvegarde. Une mise à jour lance elle-même une sauvegarde, une fois l'application en maintenance.

## Copie hors de la machine

Une sauvegarde qui reste sur la machine ne protège pas de sa perte : panne de disque, machine détruite ou chiffrée par un rançongiciel. Copiez régulièrement les sauvegardes ailleurs, par exemple sur un serveur de sauvegarde ou un support conservé hors du local du serveur. Torsade laisse ce choix à l'administrateur : l'outil dépend de votre organisation.

Une sauvegarde contient le plan de votre réseau et la clé de l'application : sa copie doit être aussi protégée que la machine elle-même, chiffrée si elle quitte vos locaux.

Pour regrouper une sauvegarde dans un seul fichier de votre dossier personnel, lisible par vous seul, avant de la copier :

```bash
sudo tar -C /var/backups/torsade -cf ~/torsade-sauvegarde.tar 20261017T023000Z
sudo chown "$USER": ~/torsade-sauvegarde.tar
chmod 600 ~/torsade-sauvegarde.tar
```

puis copiez `torsade-sauvegarde.tar` avec l'outil de votre choix, par exemple `scp`, et supprimez-le de la machine.

## Restauration sur la même machine

Pour revenir à l'état d'une sauvegarde :

```bash
sudo torsade-restore /var/backups/torsade/20261017T023000Z
```

La commande vérifie les empreintes de la sauvegarde, rappelle ce qu'elle va faire et demande de taper `restaurer` pour confirmer ; `--yes` confirme d'avance. Puis elle met l'application en maintenance, remplace la base par celle de la sauvegarde, lance les migrations, reconstruit les caches, recharge PHP-FPM et sort de la maintenance. Tout ce qui a été saisi depuis la sauvegarde est perdu.

Une sauvegarde d'une version plus récente que la version installée est refusée : installez d'abord cette version. Une sauvegarde d'une version plus ancienne est suivie des migrations de la version installée.

Après une mise à jour qui a échoué une fois la nouvelle version en service, `--release` revient d'abord à la version précédente, encore présente dans `/opt/torsade/releases` (voir [la mise à jour](mise-a-jour.md)).

La commande note ce qu'elle fait dans `/var/log/torsade-restore.log` et dans le journal du système (`sudo journalctl -t torsade-restore`).

## Reconstruction sur une machine neuve

1. Installez Torsade sur la nouvelle machine, dans la même version que la sauvegarde ou une plus récente (voir [l'installation](installation.md)).
2. Copiez-y le fichier de la sauvegarde et décompressez-le dans `/var/backups/torsade` :

    ```bash
    sudo tar -C /var/backups/torsade -xf torsade-sauvegarde.tar
    ```

3. Restaurez la base **et la configuration conservée** :

    ```bash
    sudo torsade-restore --configuration /var/backups/torsade/20261017T023000Z
    ```

`--configuration` reprend les réglages de la sauvegarde, dont `APP_KEY`, et garde ceux de la nouvelle machine : mots de passe de la base, adresse de l'application (`APP_URL`), HSTS et certificat. Les comptes, leurs mots de passe et leur double authentification sont ceux de la sauvegarde ; le premier administrateur créé par l'installation de la nouvelle machine disparaît avec sa base.
