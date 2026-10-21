<!-- torsade/README.md -->

# Torsade

Torsade est une application web auto-hébergée de documentation réseau pour les PME : VLAN, sous-réseaux, adresses IP, équipements, ports, liaisons, prises murales, panneaux et brassage. Elle remplace les fichiers Excel et les connaissances détenues par une seule personne.

Elle sert aussi à concevoir une infrastructure qui n'existe pas encore : chaque objet peut être prévu avant d'être actif.

## Ce que Torsade n'est pas

Torsade documente l'état déclaré du réseau. Ce n'est pas un outil de supervision : il ne scanne rien, ne collecte rien et ne se connecte à aucun équipement. Il ne stocke donc aucun identifiant d'équipement, et fonctionne sur un réseau d'administration isolé d'Internet : aucun appel externe, ni police distante, ni télémétrie.

## État

En développement : Torsade n'est pas encore prêt pour la production. Le numéro de version reste en `0.y.z` jusqu'à la première version utilisable en production.

## Fonctions

- Plan d'adressage IPv4 : sites, VLAN, sous-réseaux typés, plages DHCP, fixes ou réservées, adresses IP, adresses libres et prochaine adresse libre, plan d'adressage dessiné case par case.
- Norme de l'organisation : catalogue des VLAN, blocs d'adressage, règle d'adressage facultative qui propose blocs, sous-réseaux, passerelles et plages, et application de la norme à un site en une action.
- Écarts : tout décalage entre la norme et l'existant, ou entre deux objets déclarés, est signalé avec sa raison, sans jamais bloquer la saisie.
- Lieux : bâtiments, étages, locaux et locaux techniques de chaque site.
- Équipements : modèles d'équipement et nommage des ports, stacks, ports, modes d'accès et trunk, VLAN, agrégats, création depuis les noms d'hôte déjà saisis.
- Liaisons entre équipements, une par une ou en série, et liaisons agrégées.
- Câblage : panneaux de brassage, prises murales en série, cordons, brassage en série, chemin complet d'une prise jusqu'au port et à son VLAN.
- Schémas physique et logique de chaque site, générés depuis les données, placés à la main si besoin, exportés en SVG et imprimables.
- Recherche unique depuis chaque page : adresse, préfixe, numéro de VLAN, adresse MAC, port d'un équipement ou nom ; accueil qui résume ce qui est documenté.
- Comptes sans inscription publique, trois rôles, double authentification, journal de toutes les modifications.
- Interface en français, thèmes sombre et clair, utilisable sur un écran étroit.

## Documentation

- [Sommaire de la documentation](documentation/README.md)
- [Installation](documentation/administration/installation.md) sur une machine Debian 13, puis mise à jour, sauvegarde, sécurité et exploitation
- [Guide des utilisateurs](documentation/guide/premiers-pas.md), organisé par tâche
- [Développement](documentation/developpement.md) : installation locale, commandes, tests, contributions
- [Changements de chaque version](CHANGELOG.md)

## Licence

La licence n'est pas encore choisie : elle le sera avant la publication du dépôt.
