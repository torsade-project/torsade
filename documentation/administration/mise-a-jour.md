<!-- torsade/documentation/administration/mise-a-jour.md -->

# Mise à jour

Une nouvelle version de Torsade s'installe par la commande `torsade-update`, que l'installation a placée dans `/usr/local/sbin`, avec l'archive de cette version.

## Lancer une mise à jour

Téléchargez l'archive `torsade-X.Y.Z.tar.gz` de la nouvelle version et son fichier `SHA256SUMS`, copiez-les sur la machine dans un même dossier, puis :

```bash
sudo torsade-update torsade-0.16.1.tar.gz
```

La commande :

1. vérifie l'empreinte de l'archive dans le fichier `SHA256SUMS` qui est à côté d'elle (ou celui que donne `--checksums`) et refuse une archive dont l'empreinte ne correspond pas ; sans ce fichier, elle refuse aussi, sauf avec l'option `--without-checksum`, déconseillée ;
2. refuse une version égale ou plus ancienne que la version installée ;
3. passe la main au script de mise à jour de la nouvelle version, qui :
    - met l'application en maintenance, puis lance une sauvegarde et attend qu'elle réussisse : rien n'est écrit entre la sauvegarde et la mise à jour ;
    - installe la nouvelle version dans `/opt/torsade/releases/X.Y.Z`, la relie à la configuration et aux données, lance ses migrations et met ses caches à jour ;
    - en fait la version en service d'un coup, en basculant le lien `/opt/torsade/current` ;
    - réécrit les fichiers que gère Torsade (commandes de `/usr/local/sbin`, site Nginx, pool PHP-FPM, unités systemd), jamais ceux de l'administrateur : `/etc/torsade/torsade.env`, `/etc/torsade/nginx-local.conf`, `/etc/torsade/backup.conf` ;
    - recharge PHP-FPM et Nginx, sort de la maintenance, vérifie `/up` et `torsade:check` ;
    - garde les trois dernières versions dans `/opt/torsade/releases` et efface les plus anciennes.

Elle note ce qu'elle fait dans `/var/log/torsade-update.log`, lisible par root seulement, et donne à la fin le dossier de la sauvegarde faite avant la mise à jour.

L'empreinte prouve que l'archive est intacte, pas qu'elle vient bien de Torsade : téléchargez toujours l'archive et `SHA256SUMS` depuis la page des versions du dépôt, en HTTPS : <https://github.com/torsade-project/torsade/releases>.

Pour vérifier aussi qu'elle vient bien de Torsade, vérifiez son attestation de provenance avant de la copier sur la machine. Cette vérification est facultative ; elle se fait depuis un poste qui a l'outil `gh` de GitHub, connecté à un compte GitHub (voir [l'intégrité de l'archive](securite.md#intégrité-de-larchive)) :

```bash
gh attestation verify torsade-X.Y.Z.tar.gz --repo torsade-project/torsade
```

## En cas d'échec

La base n'est jamais remise en arrière automatiquement : la commande dit ce qu'elle a laissé et quelle commande remet l'application en état.

- **Avant les migrations** (archive illisible, sauvegarde impossible, espace disque) : la nouvelle version est retirée et la version en place est de nouveau servie. Rien n'est à faire, sinon corriger la cause.
- **Pendant ou après les migrations, avant la bascule** : l'application reste en maintenance, car la base a pu être modifiée en partie. Pour revenir à la version en place avec les données d'avant la mise à jour, lancez la commande que donne le message :

    ```bash
    sudo torsade-restore /var/backups/torsade/20261017T023000Z
    ```

- **Après la bascule**, si `/up` ou `torsade:check` échoue : l'application reste en maintenance. Pour revenir à la version précédente, encore présente dans `/opt/torsade/releases`, avec les données d'avant la mise à jour, lancez la commande que donne le message :

    ```bash
    sudo torsade-restore --release 0.16.0 /var/backups/torsade/20261017T023000Z
    ```

Tant que cette restauration n'a pas eu lieu, la base peut être migrée en partie : `torsade-backup` et `torsade-update` refusent de tourner, et le disent. Ces commandes demandent une confirmation ; voir [la sauvegarde et la restauration](sauvegarde-restauration.md). Le journal `/var/log/torsade-update.log` donne la cause de l'échec.
