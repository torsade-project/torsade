<!-- torsade/documentation/guide/notions.md -->

# Les notions de Torsade

Cette page présente les idées qui traversent toute l'application : ce que Torsade documente, ce qu'il refuse et ce qu'il se contente de signaler, les statuts, les suppressions, le journal et l'adressage. Les pages de tâches s'y réfèrent.

## Un état déclaré, sans supervision

Torsade documente le réseau tel que vous le déclarez. Il ne fait rien sur le réseau lui-même :

- aucun scan, aucune découverte automatique ;
- aucune collecte d'informations sur les équipements ;
- aucune connexion à un switch, un routeur ou un pare-feu.

Ce que montre Torsade est donc ce qui a été saisi, ni plus ni moins. Si un câble est déplacé sans que la documentation suive, Torsade ne le sait pas.

Pourquoi : se connecter aux équipements obligerait à stocker leurs identifiants. L'outil de documentation deviendrait alors une cible de choix. Torsade ne garde aucun identifiant d'équipement.

## La norme et l'existant

Torsade distingue deux choses.

D'un côté, la norme : ce que l'organisation a décidé. Elle se trouve dans le menu **Norme** :

- le **Catalogue des VLAN** : les VLAN normalisés, avec leur numéro, leur nom et leur description ;
- les **Blocs d'adressage** : les espaces d'adresses réservés à l'organisation et à chaque site, par exemple `10.10.0.0/16` pour le site Siège de la démonstration ;
- la **Règle d'adressage** : la façon de calculer le bloc d'un site à partir de son numéro, puis le sous-réseau de chaque VLAN ; ou, pour une installation à un seul site, le sous-réseau de chaque VLAN directement.

De l'autre, l'existant : les sites, VLAN, sous-réseaux, adresses, équipements et câblages que vous déclarez.

L'existant n'est jamais obligé de suivre la norme. Un site repris, un ancien plan d'adressage, un VLAN numéroté autrement que prévu : tout cela se documente tel quel. La façon de définir la norme est décrite dans [la norme](norme.md).

## Les écarts

Un écart est une différence entre l'existant et la norme, ou une incohérence du câblage. Par exemple, dans la démonstration, le sous-réseau `192.168.1.0/24` du site Siège est signalé « Hors des blocs du site (10.10.0.0/16) » : c'est l'ancien plan d'adressage, conservé tel qu'il était. À l'Atelier, la borne `ap-atelier-1` est câblée au switch `sw-atelier-01` sans qu'aucune liaison ne soit déclarée : le câblage et les liaisons ne disent pas la même chose.

Un écart :

- est calculé à l'affichage, à partir de ce qui est déclaré ;
- n'est jamais stocké : corrigez la cause, il disparaît à l'affichage suivant ;
- ne bloque jamais une saisie ;
- s'affiche avec sa raison, sur la page de l'objet concerné ; la section **Écarts** de la page d'un site rassemble ceux de ses VLAN, de ses sous-réseaux et de ses blocs, et les schémas du site ont leur propre liste.

Pourquoi : refuser un sous-réseau mal placé empêcherait de documenter justement le réseau qu'on cherche à corriger. L'écart rend l'imperfection visible sans l'interdire.

La liste des écarts et leurs raisons sont dans [les écarts](ecarts.md).

## Le câblage, déduit des cordons

Torsade ne stocke que ce qui se pose : les prises, le capillaire de chaque prise vers son port de panneau, les cordons de brassage dans la baie et les cordons de prise dans les bureaux. Le chemin câblé entre deux ports s'en déduit à chaque affichage, comme un écart : un port brassé vers une prise sur laquelle un autre port est branché lui est câblé.

Le chemin câblé confirme une liaison quand elle relie ces deux ports : il devient son chemin physique, affiché avec elle et dessiné sur le schéma physique. Sinon, il la contredit, ou il signale une liaison manquante. Rien n'est jamais déduit dans l'autre sens : Torsade ne crée pas de liaison à partir du câblage, il le propose. Voir [le câblage](cablage.md).

## Ce qui est refusé

Certaines saisies sont incohérentes dans tous les cas, quelle que soit la norme. Celles-là sont refusées, avec un message qui dit pourquoi. Quelques exemples :

| Saisie refusée                                                                      | Message affiché (exemple)                                                                                                         |
| ----------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Une adresse hors de son sous-réseau, ou égale à l'adresse de réseau ou de diffusion | « Cette adresse n'est pas une adresse utilisable de 10.10.20.0/24 (10.10.20.1 – 10.10.20.254). »                                  |
| Une adresse déjà documentée, dans n'importe quel sous-réseau                        | « Cette adresse est déjà documentée. »                                                                                            |
| Deux sous-réseaux qui se chevauchent, même sur deux sites différents                | « Ce sous-réseau chevauche 10.10.20.0/24. »                                                                                       |
| Une adresse fixe dans une plage DHCP                                                | « Une adresse fixe ne peut pas se trouver dans la plage DHCP … Choisissez une autre adresse, ou faites-en une réservation DHCP. » |
| Une passerelle hors de son sous-réseau                                              | « La passerelle doit se trouver dans le sous-réseau. »                                                                            |
| Deux VLAN de même numéro, de deux sites, sur un même port                           | « Le VLAN 10 du site Siège et le VLAN 10 du site Atelier ne peuvent pas passer sur le même port. »                                |
| Deux VLAN de même numéro, ou de même nom, sur un même site                          | « Ce site a déjà un VLAN avec ce numéro. »                                                                                        |
| Deux blocs de site qui se chevauchent                                               | « Ce bloc chevauche … »                                                                                                           |

Une réservation DHCP, elle, peut se trouver dans une plage DHCP : c'est son usage normal. Les règles propres à chaque objet sont décrites dans [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md), [équipements et ports](equipements-et-ports.md) et [la norme](norme.md).

## Les statuts : prévu, actif, retiré

Les objets du réseau portent un statut : **Prévu**, **Actif** ou **Retiré**. Pour les objets féminins (adresse, prise, liaison), l'interface écrit **Prévue**, **Active**, **Retirée**.

Ont un statut : les sites, les blocs d'adressage, les VLAN, les sous-réseaux, les adresses IP, les équipements, les liaisons, les panneaux de brassage et les prises. Les bâtiments, étages et locaux n'en ont pas : ce sont des lieux, pas des objets du réseau.

| Statut     | Usage                                                                                             |
| ---------- | ------------------------------------------------------------------------------------------------- |
| **Prévu**  | Ce qui n'existe pas encore : un site à ouvrir, un VLAN à créer, une caméra à poser.               |
| **Actif**  | Ce qui est en service. C'est le statut par défaut.                                                |
| **Retiré** | Ce qui n'est plus en service mais qu'on garde pour mémoire : un ancien réseau, un serveur arrêté. |

Le statut **Prévu** permet de concevoir une infrastructure qui n'existe pas encore. Dans la démonstration, le site Dépôt est prévu, avec son bloc `10.30.0.0/16` prévu lui aussi. Quelques effets concrets :

- un bloc créé en même temps qu'un site prend le statut du site ;
- **Appliquer la norme** sur un site propose de créer ses VLAN avec le statut **Prévu** ;
- sur le plan d'adresses d'un sous-réseau, une adresse prévue ou retirée se distingue d'une adresse en service, et la légende le dit ;
- les schémas laissent de côté ce qui est retiré et distinguent ce qui est prévu (voir [les schémas](schemas.md)) ;
- la liste des sites se filtre par statut.

Une adresse IP retirée garde son numéro : il reste pris, et personne ne peut le documenter à nouveau par erreur. Pour réattribuer ce numéro, supprimez l'enregistrement de l'adresse ; le journal garde la trace de ce qu'elle était.

## Les suppressions refusées

Un objet ne se supprime pas tant que d'autres objets en dépendent. La suppression est alors refusée avec un message qui dit quoi supprimer, ou déplacer, d'abord. Par exemple, pour le site Siège de la démonstration, qui a sept VLAN : « Des VLAN sont encore rattachés à ce site (7) : supprimez-les d'abord. »

Pourquoi : rien ne disparaît sans que quelqu'un l'ait décidé. Supprimer un site ne doit pas effacer en silence ses VLAN, ses adresses et ses équipements.

Quelques objets partent toutefois avec l'objet qui les porte, parce qu'ils n'ont pas de sens seuls :

- les plages d'un sous-réseau, DHCP, fixes ou réservées, supprimées avec lui (il ne doit plus contenir d'adresse) ;
- les ports d'un panneau de brassage, supprimés avec lui (aucun ne doit avoir de prise ni de cordon) ;
- les membres, les ports et les agrégats d'un équipement, supprimés avec lui (aucune adresse ne doit lui être rattachée, aucun port ne doit être relié, brassé ou branché sur une prise) ;
- les ports d'un membre, supprimés avec lui (aucun ne doit être relié, brassé ou branché) ;
- les VLAN portés par un port, retirés avec lui.

À l'inverse, supprimer une liaison laisse ses deux ports libres, supprimer une prise laisse son port de panneau libre, et supprimer un agrégat rend ses ports libres, sans mode ni VLAN.

## Le journal

Toute création, modification ou suppression est inscrite au journal : qui l'a faite, quand, et les valeurs avant et après. Une modification qui ne change rien n'y laisse pas de trace. Le journal ne se modifie pas depuis l'application ; il se consulte depuis le menu **Journal**, réservé aux administrateurs.

Voir [comptes et journal](comptes-et-journal.md).

## Un seul domaine d'adressage

Torsade considère toute l'installation comme un seul réseau d'adresses :

- une adresse IP n'est documentée qu'une fois, sur toute l'installation, quel que soit son statut ;
- deux sous-réseaux ne se chevauchent jamais, même sur deux sites différents.

Pourquoi : des sites reliés par un VPN ne peuvent pas utiliser les mêmes plages d'adresses, et c'est le cas de la plupart des PME. En contrepartie, deux sites isolés qui réutilisent les mêmes adresses privées, ou une renumérotation où l'ancien et le nouveau plan coexistent, ne se documentent pas.

Les numéros de VLAN, eux, sont propres à chaque site : le VLAN 10 du site Siège et le VLAN 10 du site Atelier sont deux réseaux distincts.

## IPv4 seulement

La saisie accepte les adresses IPv4 seulement : sous-réseaux, blocs, passerelles, plages et adresses. Une adresse IPv6 saisie est refusée avec un message clair : « Les adresses IPv6 ne sont pas encore prises en charge : saisissez une adresse IPv4. »
