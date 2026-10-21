<!-- torsade/documentation/administration/essai-machine-virtuelle.md -->

# Essai dans une machine virtuelle

Ce pas à pas installe Torsade dans une machine virtuelle Debian 13, depuis un poste Windows, avec un certificat autosigné et l'adresse IP de la machine comme nom. Il convient à VMware Workstation comme à tout autre hyperviseur : il dit ce qu'il faut régler, pas où se trouve chaque réglage dans un logiciel donné.

Dans les commandes, remplacez `192.0.2.10` par l'adresse de votre machine virtuelle et `utilisateur` par le compte créé à l'installation de Debian.

## 1. Image de Debian

Téléchargez l'image d'installation officielle de Debian 13 pour votre architecture (en général `amd64`, image « netinst ») depuis le site de Debian, `debian.org`, et vérifiez son empreinte avec les fichiers `SHA256SUMS` publiés à côté d'elle.

## 2. Machine virtuelle

Créez une machine virtuelle avec :

- comme système invité, Debian en 64 bits, ou à défaut Linux en 64 bits ;
- 2 Go de mémoire, 2 processeurs, un disque de 20 Go ;
- une carte réseau reliée en pont au réseau du poste, pour que la machine ait une adresse sur votre réseau et soit joignable depuis le poste Windows ; un réseau privé entre l'hôte et la machine convient s'il donne aussi accès à Internet pour les dépôts de Debian ;
- l'image de Debian dans le lecteur de CD virtuel.

## 3. Installation de Debian

Démarrez la machine et suivez l'installateur de Debian :

- langue, pays et clavier : les vôtres ;
- nom de la machine : par exemple `torsade` ;
- **mot de passe de root : laissez-le vide**. L'installateur désactive alors le compte root et donne `sudo` au compte que vous créez ensuite ;
- compte utilisateur : votre nom et un mot de passe solide ;
- partitionnement : le mode assisté sur le disque entier, tout dans une seule partition ;
- miroir de l'archive Debian : celui de votre pays ;
- **choix des logiciels** : aucun environnement de bureau ; seulement le serveur SSH et les utilitaires usuels du système.

Redémarrez, connectez-vous dans la console de la machine et notez son adresse :

```bash
hostname -I
```

## 4. Archive de Torsade

Sur le poste Windows, téléchargez depuis la page des versions de Torsade sur GitHub (« Releases ») les deux fichiers de la version voulue : `torsade-X.Y.Z.tar.gz` et `SHA256SUMS`, dans un même dossier.

Copiez-les sur la machine virtuelle depuis PowerShell ou l'invite de commandes de Windows, qui fournissent `scp` :

```powershell
scp torsade-0.16.0.tar.gz SHA256SUMS utilisateur@192.0.2.10:
```

À la première connexion, `scp` affiche l'empreinte de la clé SSH de la machine : acceptez-la si vous venez de l'installer.

## 5. Installation de Torsade

Connectez-vous à la machine par SSH (`ssh utilisateur@192.0.2.10`) ou dans sa console, puis vérifiez l'archive et décompressez-la :

```bash
sha256sum --check SHA256SUMS
tar -xzf torsade-0.16.0.tar.gz
cd torsade-0.16.0
```

`sha256sum` doit répondre `torsade-0.16.0.tar.gz: OK`. Lancez l'installation :

```bash
sudo bash deploy/install.sh
```

Répondez aux questions :

- nom de l'application : l'adresse IP de la machine, par exemple `192.0.2.10` ;
- certificat : `non`, pour un certificat autosigné ;
- mises à jour de sécurité automatiques : comme vous voulez pour un essai ;
- nom complet et adresse du premier administrateur.

L'installation dure quelques minutes. À la fin, le script affiche le mot de passe temporaire du premier administrateur, **une seule fois** : notez-le. Il affiche aussi l'empreinte SHA-256 du certificat autosigné : gardez ce résumé à l'écran.

## 6. Premier accès

1. Sur le poste Windows, ouvrez `https://192.0.2.10` dans le navigateur.
2. Le navigateur prévient que le certificat n'est pas reconnu. Affichez le certificat depuis cet avertissement, comparez son empreinte SHA-256 avec celle du résumé de l'installation, et n'acceptez l'avertissement que si elles sont identiques.
3. Connectez-vous avec l'adresse du premier administrateur et son mot de passe temporaire, puis choisissez votre propre mot de passe.
4. Activez la double authentification depuis vos paramètres (voir [premiers pas](../guide/premiers-pas.md#activer-la-double-authentification)).

Un essai sur machine virtuelle n'appelle pas les précautions d'un serveur ; pour une installation réelle, lisez [l'installation](installation.md) et [la sécurité](securite.md).
