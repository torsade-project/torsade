<!-- torsade/documentation/administration/installation.md -->

# Installation

Torsade s'installe sur une machine Debian 13 qui lui est dédiée, par un script livré dans son archive. Le serveur n'a besoin ni de Composer ni de Node : l'archive contient les dépendances PHP et l'interface déjà compilée.

Pour un premier essai dans une machine virtuelle, suivez plutôt [l'essai dans une machine virtuelle](essai-machine-virtuelle.md), qui reprend ces étapes pas à pas.

## Machine

- **Debian 13**, installation minimale, à jour, avec un accès administrateur par `sudo`. Le script refuse toute autre distribution ou version.
- **Dédiée à Torsade** : le script installe et configure Nginx, MariaDB et PHP-FPM pour Torsade seul. Il retire le site par défaut de Nginx et le pool par défaut de PHP-FPM.
- **Ressources** : 2 Go de mémoire, 2 processeurs et 20 Go de disque suffisent pour une PME. Torsade sert quelques personnes à la fois : PHP-FPM démarre au plus 10 processus, de 256 Mo au plus chacun, et MariaDB garde ses réglages de Debian. Le script exige 2 Gio libres sur `/` et sur `/var` avant de commencer ; chaque mise à jour garde trois versions de l'application (une quarantaine de Mo chacune) et chaque sauvegarde quotidienne est conservée 14 jours.
- **Dépôts Debian** joignables pendant l'installation et les mises à jour : le script n'installe que des paquets des dépôts configurés sur la machine (`/etc/apt/sources.list.d/`), jamais d'une autre source. Un miroir local de Debian convient.

## Nom de l'application

L'application est servie en HTTPS sous un seul nom :

- un **nom d'hôte** de votre réseau, par exemple `torsade.example.test`, que votre DNS fait pointer vers la machine : c'est le cas normal ;
- ou, pour un essai, l'**adresse IPv4** de la machine, par exemple `192.0.2.10`.

Remplacez ces exemples par vos propres valeurs. Le nom choisi est aussi celui que doit couvrir le certificat.

## Certificat

- **Certificat fourni** (recommandé) : un certificat pour ce nom, signé par une autorité que les postes de votre organisation connaissent (autorité interne ou publique), au format PEM, suivi de sa chaîne, et sa clé privée sans phrase de passe. Le script vérifie que la clé correspond au certificat, que la chaîne est complète jusqu'à une autorité connue du système ou à celle que vous donnez par `--authority`, que le nom est couvert et que les dates sont valides.
- **Certificat autosigné**, pour un essai : le script le crée pour le nom de l'application. Le navigateur affiche un avertissement ; le script donne l'empreinte SHA-256 du certificat, à comparer avec celle que montre le navigateur avant de l'accepter.

HSTS (le navigateur n'accepte plus que HTTPS pour ce nom, pendant un an) ne s'active qu'avec un certificat fourni, sur demande explicite : l'option `--hsts`, ou la réponse à la question du script, suivie d'une confirmation. Lisez d'abord [la sécurité](securite.md).

## Déroulé

Téléchargez l'archive `torsade-X.Y.Z.tar.gz` de la version voulue et son fichier `SHA256SUMS` depuis la page des versions du dépôt, en HTTPS : <https://github.com/torsade-project/torsade/releases>. L'empreinte de `SHA256SUMS` prouve que l'archive est intacte, pas son origine. Pour vérifier aussi sa provenance, facultativement, depuis un poste qui a l'outil `gh` de GitHub et un accès à GitHub (voir [l'intégrité de l'archive](securite.md#intégrité-de-larchive)) :

```bash
gh attestation verify torsade-X.Y.Z.tar.gz --repo torsade-project/torsade
```

Copiez l'archive et son fichier `SHA256SUMS` sur la machine, dans un même dossier, puis vérifiez l'empreinte et décompressez :

```bash
sha256sum --check SHA256SUMS
tar -xzf torsade-0.16.0.tar.gz
cd torsade-0.16.0
```

Lancez le script en root. Sans option, il pose ses questions : nom de l'application, certificat, HSTS, mises à jour de sécurité automatiques, puis nom et adresse du premier administrateur.

```bash
sudo bash deploy/install.sh
```

Toutes les réponses peuvent être données en options, par exemple pour un essai avec un certificat autosigné :

```bash
sudo bash deploy/install.sh --non-interactive --host 192.0.2.10 --self-signed --admin-name "Camille Martin" --admin-email camille.martin@example.test
```

ou avec un certificat fourni par une autorité interne, HSTS et les mises à jour de sécurité automatiques :

```bash
sudo bash deploy/install.sh --non-interactive --host torsade.example.test --certificate /root/server.crt --key /root/server.key --authority /root/authority.crt --hsts --security-updates --admin-name "Camille Martin" --admin-email camille.martin@example.test
```

`sudo bash deploy/install.sh --help` liste toutes les options. Les mises à jour de sécurité automatiques de Debian (`--security-updates`) sont désactivées par défaut.

Le script, dans l'ordre :

1. contrôle la machine (Debian 13, root, espace disque, dépôts) avant toute modification ;
2. installe les paquets : Nginx, MariaDB, PHP 8.4 (FPM, ligne de commande, OPcache et les extensions dont Torsade a besoin), curl et OpenSSL ;
3. crée le compte système `torsade`, sans shell : PHP-FPM et toutes les commandes de l'application tournent sous ce compte ;
4. crée les emplacements : code dans `/opt/torsade/releases/X.Y.Z` (lien `/opt/torsade/current`), configuration dans `/etc/torsade/`, données dans `/var/lib/torsade/`, journaux dans `/var/log/torsade/`, sauvegardes dans `/var/backups/torsade/` ;
5. installe le certificat ;
6. écrit la configuration `/etc/torsade/torsade.env`, avec une clé d'application et des mots de passe tirés au hasard ;
7. crée la base `torsade` et ses deux comptes : l'un pour l'application, qui lit et écrit les données, l'autre pour les migrations, réservé à l'installation, aux mises à jour et à la restauration ;
8. lance les migrations, puis met la configuration, les routes et les vues en cache ;
9. configure PHP-FPM et Nginx, puis les minuteries de la sauvegarde et de la purge du cache du point de contrôle ;
10. crée le premier administrateur ;
11. vérifie la configuration (`torsade:check`) et le point de contrôle `/up` en HTTPS, puis affiche un résumé.

Le script note ce qu'il fait dans `/var/log/torsade-install.log`, lisible par root seulement. Aucun mot de passe n'y figure.

## Interruption et reprise

Si une étape échoue, le script la nomme, montre les dernières lignes de son journal et s'arrête. Corrigez la cause, puis relancez **la même commande** : le script reprend, réutilise la clé de l'application et les mots de passe de la base déjà créés, et ne crée jamais un second administrateur.

Une fois l'installation terminée, le script refuse de tourner à nouveau : les versions suivantes s'installent par `torsade-update` (voir [la mise à jour](mise-a-jour.md)).

## Premier accès

1. Ouvrez `https://` suivi du nom de l'application dans un navigateur. Avec un certificat autosigné, comparez l'empreinte affichée par le navigateur avec celle du résumé avant d'accepter l'avertissement.
2. Connectez-vous avec l'adresse du premier administrateur et son mot de passe temporaire :
    - en mode interactif, le script l'a affiché une seule fois à l'écran ;
    - en mode non interactif, il est dans `/root/torsade-premier-administrateur.txt`, lisible par root seulement : supprimez ce fichier après la première connexion.
3. Torsade vous demande aussitôt de choisir votre propre mot de passe.
4. Activez la double authentification depuis vos paramètres, puis créez les comptes des autres utilisateurs depuis l'administration. Le [guide des utilisateurs](../guide/premiers-pas.md) décrit ces étapes, et [comptes et journal](../guide/comptes-et-journal.md) la gestion des comptes.

Pour la suite, voir [l'exploitation](exploitation.md), [la sauvegarde et la restauration](sauvegarde-restauration.md) et [la sécurité](securite.md).
