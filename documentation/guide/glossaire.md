<!-- torsade/documentation/guide/glossaire.md -->

# Glossaire

Les termes du réseau et de Torsade employés dans ce guide, par ordre alphabétique. Chacun renvoie à la page qui l'utilise.

## Accès

Mode d'un port qui ne transporte qu'un VLAN, non étiqueté : celui de la machine branchée. Un port en accès peut porter en plus un VLAN voix. Voir [équipements et ports](equipements-et-ports.md).

## Adresse de diffusion

Dernière adresse d'un sous-réseau, qui s'adresse à toutes ses machines : elle n'est jamais attribuée. Voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md).

## Agrégat

Groupe de ports d'un même équipement qui fonctionnent comme un seul lien, plus rapide et redondant. Ses ports peuvent être sur plusieurs membres d'une stack. Dans Torsade, le mode et les VLAN se règlent sur l'agrégat, pas sur ses ports. Voir [équipements et ports](equipements-et-ports.md).

## Bloc d'adressage

Préfixe IPv4 réservé à l'organisation (par exemple `10.0.0.0/8`) ou à un site (`10.1.0.0/16`), dans lequel se rangent les sous-réseaux. Le bloc d'un site est l'enveloppe de tous ses sous-réseaux, pas le réseau d'un de ses VLAN ; un site sans plan hiérarchique peut n'en avoir aucun. Il fait partie de la norme. Voir [norme](norme.md).

## Brassage

Action de relier un port de panneau à un port d'équipement par un cordon. Voir [câblage](cablage.md).

## Câble capillaire

Câble fixe, posé dans les murs, qui relie une prise murale à un port d'un panneau de brassage. Il se saisit une fois, souvent avec les prises en série. Voir [câblage](cablage.md).

## Catalogue des VLAN

Liste des VLAN normalisés de l'organisation : numéro, nom et rôle. Chaque VLAN d'un site peut y être rattaché. Voir [norme](norme.md).

## Chemin câblé

Chemin physique entre deux ports, déduit des cordons : un port brassé vers une prise sur laquelle un autre port est branché lui est câblé (« par PB-B 13 › B.13 »). Quand une liaison relie ces deux ports, c'est son chemin physique ; sinon, Torsade signale un écart. Voir [câblage](cablage.md).

## Chemin d'une prise

Suite qui va d'une prise murale jusqu'au réseau : l'équipement branché sur la prise s'il y en a un, la prise, le port de panneau, l'équipement et le port au bout du cordon, puis mode et VLAN de ce port (« Accès 10, voix 30 »). Voir [câblage](cablage.md).

## CIDR

Notation d'un réseau par son adresse et la longueur de son préfixe : `10.10.20.0/24` désigne les 256 adresses de `10.10.20.0` à `10.10.20.255`. Voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md).

## Configuration effective

Mode et VLAN d'un port tels qu'ils s'appliquent : ceux du port, ou ceux de son agrégat s'il en fait partie. Les écarts d'une liaison comparent les configurations effectives de ses deux bouts. Voir [liaisons](liaisons.md).

## Cordon de brassage

Câble court et mobile, dans la baie, qui relie un port de panneau à un port d'équipement. Il peut avoir une couleur. Voir [câblage](cablage.md).

## Cordon de prise

Cordon mobile, côté bureau, qui branche le port d'un équipement sur une prise murale de son site : une borne Wi-Fi, un téléphone, un petit switch de salle de réunion. Une prise n'a qu'un cordon de prise, et un port n'a qu'un cordon, de brassage ou de prise. Voir [câblage](cablage.md).

## Découpage type

Partie de la règle d'adressage qui découpe chaque sous-réseau de VLAN en plages (infrastructure, adresses fixes, DHCP…), exprimées en décalages depuis l'adresse réseau. Voir [norme](norme.md).

## Double authentification

Second facteur de connexion : un code à six chiffres donné par une application d'authentification, en plus du mot de passe. Voir [premiers pas](premiers-pas.md).

## Écart

Décalage entre la norme et l'existant, ou incohérence entre deux objets déclarés, signalé avec sa raison. Un écart est calculé à l'affichage et ne bloque jamais une saisie. Voir [écarts](ecarts.md).

## État déclaré

Ce que les utilisateurs ont saisi dans Torsade, et non ce qu'un outil aurait mesuré sur le réseau. Torsade ne scanne rien. Voir [notions](notions.md).

## Étiqueté

Se dit d'un VLAN transporté avec son numéro dans chaque trame, ce qui permet à un même câble de porter plusieurs VLAN. Un port en trunk porte des VLAN étiquetés ; le VLAN voix d'un port en accès est étiqueté. Voir [équipements et ports](equipements-et-ports.md).

## Journal

Historique en lecture seule de toutes les créations, modifications et suppressions, et des événements de connexion : qui, quand, quoi, avant et après. Voir [comptes et journal](comptes-et-journal.md).

## LACP

Protocole par lequel deux équipements négocient un agrégat de liens. Un agrégat est LACP ou statique. Voir [équipements et ports](equipements-et-ports.md).

## Liaison

Connexion entre deux ports de deux équipements, quel que soit le chemin physique qu'elle emprunte. Un port n'a qu'une liaison. Voir [liaisons](liaisons.md).

## Liaison agrégée

Ensemble des liaisons qui relient les ports de deux agrégats, affiché comme une seule liaison. Voir [liaisons](liaisons.md).

## Local technique

Local qui abrite des switchs, des panneaux de brassage ou une baie. Voir [sites et lieux](sites-et-lieux.md).

## Membre

Boîtier physique d'un équipement. Un switch seul a un membre ; une stack en a plusieurs. Les ports appartiennent aux membres. Voir [équipements et ports](equipements-et-ports.md).

## Modèle d'équipement

Description des ports d'un type de machine, par groupes : nombre, motif de nom, connecteur, débit, PoE. Les ports d'un membre sont copiés de son modèle. Voir [équipements et ports](equipements-et-ports.md).

## Natif

VLAN non étiqueté d'un port en trunk : les trames sans numéro de VLAN lui appartiennent. Un trunk a au plus un VLAN natif. Voir [équipements et ports](equipements-et-ports.md).

## Non étiqueté

Se dit d'un VLAN transporté sans numéro dans les trames : le VLAN d'un port en accès, ou le VLAN natif d'un trunk. Voir [équipements et ports](equipements-et-ports.md).

## Norme

Conventions de l'organisation : catalogue des VLAN, blocs d'adressage, règle d'adressage. Elle s'oppose à l'existant, et leurs différences sont les écarts. Voir [norme](norme.md).

## Panneau de brassage

Panneau de la baie dont chaque port reçoit le câble capillaire d'une prise ; un cordon le relie ensuite à un port d'équipement. Voir [câblage](cablage.md).

## Passerelle

Adresse du routeur ou du pare-feu dans un sous-réseau, par laquelle les machines sortent de ce sous-réseau. Elle n'est jamais dans une plage DHCP. Voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md).

## Plage DHCP

Plage d'adresses qu'un serveur DHCP distribue dynamiquement. Une adresse fixe n'y est pas acceptée ; une réservation DHCP, si. Voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md).

## Plage fixe, plage réservée

Plage d'un sous-réseau destinée aux adresses fixes, ou mise de côté (infrastructure, usage futur). La prochaine adresse libre est cherchée d'abord dans les plages fixes, jamais dans une plage réservée. Voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md).

## Plan d'adressage

Dessin d'un sous-réseau, une case par adresse, comme les ports d'un panneau ; chaque état a sa forme. Voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md).

## PoE

Alimentation électrique fournie par le port d'un switch à travers le câble réseau (téléphone, borne Wi-Fi, caméra). Torsade l'enregistre par type de norme IEEE. Voir [équipements et ports](equipements-et-ports.md).

## Préfixe

Longueur, en bits, de la partie réseau d'une adresse : `/24` pour 256 adresses, `/16` pour 65 536. Voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md).

## Prise murale

Prise réseau d'un bureau, libellée sur son étage (« B.15 ») et reliée par son câble capillaire à un port de panneau. Voir [câblage](cablage.md).

## Règle d'adressage

Règle facultative qui dit comment l'organisation numérote ses réseaux : un bloc par site selon son numéro, un sous-réseau par VLAN selon son VID. Sans niveau de site, pour une installation à un seul site, les sous-réseaux de VLAN sont rangés par VID directement dans le bloc de l'organisation. Torsade s'en sert pour proposer des valeurs et signaler des écarts. Voir [norme](norme.md).

## Réservation DHCP

Adresse que le serveur DHCP donne toujours à la même machine, reconnue par sa MAC. Elle peut se trouver dans une plage DHCP. Voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md).

## Rocade

Liaison entre deux locaux techniques, souvent en fibre, qui passe par des ports de panneau sans prise. Voir [câblage](cablage.md).

## Rôle

Droits d'un compte : `reader` consulte, `editor` consulte et modifie les données du réseau, `admin` fait tout, comptes et journal compris. Voir [premiers pas](premiers-pas.md).

## Schéma physique, schéma logique

Dessins d'un site générés depuis les données : le physique montre les équipements, leurs lieux, leurs liaisons et les panneaux de brassage que traverse leur câblage ; le logique, les sous-réseaux et les équipements qui y sont branchés. Voir [schémas](schemas.md).

## Stack

Plusieurs switchs reliés et administrés comme un seul : un nom, une configuration, une adresse d'administration. Dans Torsade, c'est un équipement à plusieurs membres. Voir [équipements et ports](equipements-et-ports.md).

## Statut

État d'un objet du réseau : prévu, actif ou retiré. Un objet prévu documente ce qui n'existe pas encore. Voir [notions](notions.md).

## Sous-réseau

Plage d'adresses IPv4 contiguë, notée en CIDR, porteuse d'un réseau local ou d'un lien. Son type (segment, point à point, loopback, tunnel, externe) fixe ses contraintes. Voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md).

## Trunk

Mode d'un port qui transporte plusieurs VLAN étiquetés, et au plus un VLAN natif : typiquement entre deux switchs, ou vers un pare-feu. Voir [équipements et ports](equipements-et-ports.md).

## VID

Numéro d'un VLAN, de 1 à 4094. Deux sites peuvent réutiliser le même VID pour deux réseaux distincts. Voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md).

## VLAN

Réseau local virtuel : un domaine de diffusion séparé des autres sur les mêmes switchs. Dans Torsade, un VLAN appartient à un site et porte au plus un sous-réseau. Voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md).

## VLAN voix

VLAN étiqueté d'un port en accès, destiné à un téléphone IP qui laisse passer le poste branché derrière lui sur le VLAN non étiqueté. Voir [équipements et ports](equipements-et-ports.md).
