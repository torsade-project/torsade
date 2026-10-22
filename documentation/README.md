<!-- torsade/documentation/README.md -->

# Documentation de Torsade

La documentation de Torsade, par public. Elle se lit sur GitHub comme hors ligne, dans le dossier `documentation/` de chaque version.

## Utilisateurs

Le guide des utilisateurs, organisé par tâche. Chaque tâche dit le rôle qu'elle demande : `reader`, `editor` ou `admin`.

| Page                                                                  | Contenu                                                                                                       |
| --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| [Premiers pas](guide/premiers-pas.md)                                 | Connexion, mot de passe, double authentification, rôles, thème, menu, par où commencer                        |
| [Accueil et recherche](guide/accueil-et-recherche.md)                 | Modules de l'accueil, recherche d'une adresse, d'un VLAN, d'une MAC, d'un port ou d'un nom, page de résultats |
| [Notions](guide/notions.md)                                           | État déclaré, norme et existant, écarts, statuts, suppressions refusées, journal                              |
| [Sites et lieux](guide/sites-et-lieux.md)                             | Sites, bâtiments, étages, locaux et locaux techniques                                                         |
| [Norme](guide/norme.md)                                               | Blocs d'adressage, règle d'adressage, catalogue des VLAN, application de la norme à un site                   |
| [VLAN, sous-réseaux et adresses](guide/vlan-sous-reseaux-adresses.md) | VLAN, sous-réseaux, plages, adresses IP, plan d'adressage, adresses libres                                    |
| [Équipements et ports](guide/equipements-et-ports.md)                 | Équipements, modèles, membres et stacks, ports, VLAN des ports, agrégats                                      |
| [Liaisons](guide/liaisons.md)                                         | Liaisons entre équipements, en série, agrégées                                                                |
| [Câblage](guide/cablage.md)                                           | Panneaux de brassage, prises, cordons de brassage et de prise, chemin d'une prise, chemin câblé               |
| [Schémas](guide/schemas.md)                                           | Schémas physique et logique d'un site, disposition, export et impression                                      |
| [Écarts](guide/ecarts.md)                                             | Chaque écart, sa raison, où il apparaît et comment le résorber                                                |
| [Comptes et journal](guide/comptes-et-journal.md)                     | Comptes, rôles, mots de passe, double authentification, journal (`admin`)                                     |
| [Glossaire](guide/glossaire.md)                                       | Les termes du réseau et de Torsade                                                                            |

## Administration

L'installation et l'exploitation d'un serveur Torsade, sur une machine Debian 13 qui lui est dédiée. Ces pages s'adressent à qui administre la machine, en `sudo`.

| Page                                                                          | Contenu                                                                                         |
| ----------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| [Installation](administration/installation.md)                                | Machine, nom de l'application, certificat, déroulé du script, reprise, premier accès            |
| [Essai dans une machine virtuelle](administration/essai-machine-virtuelle.md) | Pas à pas depuis un poste Windows, avec un certificat autosigné                                 |
| [Mise à jour](administration/mise-a-jour.md)                                  | Installer une nouvelle version avec `torsade-update`, et que faire en cas d'échec               |
| [Sauvegarde et restauration](administration/sauvegarde-restauration.md)       | Contenu d'une sauvegarde, sauvegarde quotidienne, copie hors de la machine, restauration        |
| [Sécurité](administration/securite.md)                                        | Ce que l'installation applique, TLS, HSTS, liste de contrôle de l'administrateur, proxy inverse |
| [Exploitation](administration/exploitation.md)                                | Commandes, journaux, minuteries, supervision, maintenance, configuration, certificat, dépannage |

## Contributeurs

| Page                                    | Contenu                                                                                              |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| [Développement](developpement.md)       | Prérequis, installation locale, commandes, tests, démonstration, archive, documentation, conventions |
| [Politique de sécurité](../SECURITY.md) | Signaler une faille en privé, versions qui reçoivent les correctifs                                  |
