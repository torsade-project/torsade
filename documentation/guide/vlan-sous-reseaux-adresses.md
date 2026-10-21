<!-- torsade/documentation/guide/vlan-sous-reseaux-adresses.md -->

# VLAN, sous-réseaux et adresses IP

Cette page couvre le réseau logique d'un site : ses VLAN, les sous-réseaux qu'ils portent ou non, les plages de chaque sous-réseau et les adresses IP qu'on y documente. Elle explique aussi le plan d'adressage et la recherche d'une adresse libre. Les pages **VLAN** et **Sous-réseaux** sont dans le menu **Réseau logique** ; les adresses IP se gèrent sur la page de leur sous-réseau.

Torsade documente le réseau déclaré : il ne scanne rien et ne se connecte à aucun équipement. Les sous-réseaux et les adresses forment un seul espace d'adressage pour toute l'installation : deux sous-réseaux ne se chevauchent jamais, et une adresse n'est documentée qu'une fois, quel que soit son statut.

## Consulter les VLAN

Rôle : `reader`, `editor` ou `admin`.

Menu **Réseau logique**, puis **VLAN**. Le tableau liste les VLAN de tous les sites, avec **VID**, **Nom**, **Site**, **Catalogue**, **Sous-réseau**, **Occupation** et **Statut**. Trois filtres le restreignent :

- **Site** : **Tous les sites** ou un site ;
- **Catalogue** : **Tous les VLAN**, **Hors catalogue**, ou une entrée du catalogue ;
- **Statut** : **Tous les statuts** ou un statut.

## Créer un VLAN

Rôle : `editor` ou `admin`.

Un VLAN appartient toujours à un site : créez d'abord le site (voir [Sites et lieux](sites-et-lieux.md)).

1. Menu **Réseau logique**, **VLAN**, puis **Nouveau VLAN**.
2. **Site** : obligatoire. Avec un seul site, il est déjà choisi.
3. **Entrée du catalogue** : facultative. Choisir une entrée remplit le VID et le nom, que vous pouvez encore changer (voir [la norme](norme.md)).
4. **VID** : de 1 à 4094.
5. **Nom** : 64 caractères au plus.
6. **Statut** et **Description**.
7. **Enregistrer**.

Le VID et le nom sont uniques dans un site : « Ce site a déjà un VLAN avec ce numéro. », « Ce site a déjà un VLAN avec ce nom. ». Deux sites peuvent avoir chacun leur VLAN 10. Une entrée du catalogue est suivie par un VLAN par site au plus.

Exemple de la démonstration : le Siège a le VLAN 10 Bureautique, rattaché à l'entrée 10 du catalogue, et l'Atelier a aussi son VLAN 10 Bureautique.

## Créer le sous-réseau d'un VLAN en même temps

Rôle : `editor` ou `admin`.

Le formulaire **Nouveau VLAN** a une section **Sous-réseau**. Cochez **Créer le sous-réseau de ce VLAN maintenant**, puis renseignez **Adresse du réseau**, **Préfixe** et **Passerelle**.

- Avec une règle d'adressage, l'adresse, le préfixe et la passerelle sont proposés d'après le numéro du site et le VID, avec la mention **Proposé par la règle d'adressage**. Exemple : pour le VLAN 10 de l'Atelier (site numéro 20), `10.20.10.0/24` et la passerelle `10.20.10.1`.
- Si la règle a un découpage type, la case **Appliquer le découpage type**, cochée par défaut, crée aussi ses plages dans le sous-réseau.
- Le VLAN et son sous-réseau sont enregistrés ensemble : si le sous-réseau est refusé, rien n'est créé, et l'erreur s'affiche sous le champ en cause.
- Le sous-réseau prend le statut du VLAN.

Cette section n'existe qu'à la création. Ensuite, le sous-réseau se gère sur sa propre page.

## Lire la page d'un VLAN

Rôle : `reader`, `editor` ou `admin`.

La page d'un VLAN montre :

- **Site**, **VID**, **Catalogue** (l'entrée suivie, ou **Hors catalogue**) et **Statut**, avec les écarts du VLAN vis-à-vis du catalogue ;
- **Sous-réseau** : « Un VLAN porte au plus un sous-réseau. » Son adresse, son **Masque**, sa **Passerelle** et son nombre d'adresses utilisables, avec l'écart éventuel vis-à-vis de la règle d'adressage (« Attendu par la règle : 10.20.150.0/24 » pour le VLAN 150 de l'Atelier, adressé en `10.20.50.0/24`). Sans sous-réseau, un `editor` dispose de **Rattacher un sous-réseau**, qui ouvre le formulaire d'un sous-réseau avec ce VLAN déjà choisi ;
- **Ports** : les ports et les agrégats de ports qui portent ce VLAN, équipement par équipement, avec les colonnes **Port ou agrégat**, **Membre** et **Étiquetage**. Voir [Équipements et ports](equipements-et-ports.md).

## Modifier ou supprimer un VLAN

Rôle : `editor` ou `admin`.

Sur la page du VLAN, **Modifier** ouvre le formulaire, sans la section du sous-réseau ni proposition de la règle.

- Changer le site d'un VLAN déplace aussi son sous-réseau sur le nouveau site.
- Changer le VID est refusé si un port ou un agrégat qui porte ce VLAN porte déjà un autre VLAN de ce numéro.
- Changer l'entrée du catalogue rattache le VLAN à une autre entrée, ou le détache avec **Aucune entrée**. Un VID ou un nom qui diffère de l'entrée devient un écart, jamais un refus.

**Supprimer** est refusé tant que le VLAN a son sous-réseau (« Le sous-réseau 10.20.70.0/24 est rattaché à ce VLAN : supprimez-le ou détachez-le d'abord. ») ou qu'un port ou un agrégat le porte encore.

## Consulter les sous-réseaux

Rôle : `reader`, `editor` ou `admin`.

Menu **Réseau logique**, puis **Sous-réseaux**. La liste est triée par adresse, avec les écarts de chaque sous-réseau. Elle se filtre par site (ou les sous-réseaux sans site) et par type.

## Ce que sont les types de sous-réseau

Un sous-réseau a un **Type**, qui dit à quoi il sert et ce qu'il accepte :

| Type              | Usage                                                              | VLAN       | Préfixe    | Passerelle  | Plages |
| ----------------- | ------------------------------------------------------------------ | ---------- | ---------- | ----------- | ------ |
| **Segment**       | un réseau local, avec ou sans VLAN (type par défaut)               | facultatif | /1 à /32   | facultative | oui    |
| **Point à point** | une liaison entre deux routeurs                                    | facultatif | /30 ou /31 | non         | non    |
| **Loopback**      | l'adresse d'un routeur lui-même                                    | non        | /32        | non         | non    |
| **Tunnel**        | l'intérieur d'un tunnel VPN                                        | non        | /1 à /32   | facultative | oui    |
| **Externe**       | des adresses qui appartiennent à un tiers : opérateur, fournisseur | facultatif | /1 à /32   | facultative | oui    |

Exemples de la démonstration : `203.0.113.8/29` « Accès Internet du siège » (externe), `10.255.0.0/31` « Liaison siège – atelier » (point à point), `172.16.255.0/31` « Tunnel VPN siège – atelier » (tunnel), `10.10.255.1/32` « Loopback du pare-feu du siège » (loopback).

Le formulaire masque ce qu'un type exclut, et passe le préfixe à une taille permise quand on choisit **Point à point** ou **Loopback**. Le serveur refuse de toute façon ce que le type n'accepte pas, par exemple « Un sous-réseau de type « Loopback » n'est pas porté par un VLAN. » ou « Le préfixe d'un sous-réseau de type « Point à point » est /30 ou /31. ».

## Créer un sous-réseau

Rôle : `editor` ou `admin`.

1. Menu **Réseau logique**, **Sous-réseaux**, puis **Nouveau sous-réseau**. Depuis la page d'un VLAN (**Rattacher un sous-réseau**) ou d'un site, le VLAN ou le site est déjà choisi.
2. Saisissez l'adresse en entier, par exemple `10.30.10.0/24`, ou une **Adresse du réseau** et le **Préfixe** à côté.
3. **Type** et **Statut**.
4. **VLAN** : un VLAN qui n'a pas encore de sous-réseau, ou **Aucun VLAN**.
5. **Site** : imposé par le VLAN quand il y en a un (le champ est alors grisé) ; sinon facultatif, pour un lien opérateur ou un tunnel par exemple.
6. **Passerelle**, **Nom** (facultatif, utile pour un sous-réseau sans VLAN) et **Description**.
7. **Enregistrer**.

Quand un VLAN est choisi et que le type est **Segment**, la règle d'adressage propose l'adresse, le préfixe et la passerelle, et offre la case **Appliquer le découpage type** (voir [la norme](norme.md)).

Sont refusés :

- une adresse qui n'est pas l'adresse du réseau ; Torsade propose la bonne : « 10.30.10.7/24 n'est pas une adresse de réseau. Vouliez-vous dire 10.30.10.0/24 ? » ;
- un sous-réseau qui en chevauche un autre, quel que soit son statut : « Ce sous-réseau chevauche 10.20.10.0/24. » ;
- un sous-réseau qui contiendrait strictement un bloc d'adressage : « Un sous-réseau ne peut pas contenir un bloc : il contiendrait le bloc 10.30.0.0/16 du site Dépôt. » Un sous-réseau de la taille exacte d'un bloc reste permis ;
- un VLAN qui a déjà un sous-réseau ;
- une passerelle hors du sous-réseau, égale à l'adresse du réseau ou de diffusion, ou dans une plage DHCP.

Un sous-réseau placé hors des blocs de son site, ou différent de celui qu'attend la règle, est accepté et signalé comme [écart](ecarts.md).

## Modifier ou supprimer un sous-réseau

Rôle : `editor` ou `admin`.

Sur la page du sous-réseau, **Modifier** ouvre le formulaire, sans proposition de la règle.

- Changer l'adresse ou le préfixe est refusé si la passerelle, une plage ou une adresse documentée ne serait plus une adresse utilisable du nouveau sous-réseau. Le message les liste, dix au plus puis le nombre restant. Exemple : réduire `10.20.10.0/24` (Bureautique de l'Atelier) en /25 donne « Cette modification laisserait en dehors du sous-réseau : la passerelle 10.20.10.254, la plage 10.20.10.100 – 10.20.10.199. ».
- Changer le type est refusé si le sous-réseau ne respecte pas les contraintes du nouveau type. Le message dit tout ce qui bloque, en une fois. Exemple, pour le même sous-réseau passé en loopback : « Ce sous-réseau ne peut pas devenir « Loopback » : il est porté par un VLAN, son préfixe est /24 (attendu : /32), il a une passerelle (10.20.10.254), il a une plage. ».

**Supprimer** est refusé tant qu'une adresse IP y est documentée. Les plages du sous-réseau sont supprimées avec lui.

## Lire la page d'un sous-réseau

Rôle : `reader`, `editor` ou `admin`.

En tête : l'adresse du sous-réseau, son VLAN, son site, son statut et ses écarts. Puis :

- **Masque**, **Passerelle** (absente pour un point à point ou une loopback), **Adresse de diffusion** ;
- **Adresses utilisables** : leur nombre, la première et la dernière ;
- **Adresses libres** : leur nombre, avec la part documentée en barre.

Viennent ensuite le **Plan d'adressage**, le tableau des **Adresses IP**, les **Plages** et les **Adresses libres**, décrits ci-dessous.

## Découper un sous-réseau en plages

Rôle : `editor` ou `admin`.

Une plage met de côté une suite d'adresses du sous-réseau. Il y a trois types :

| Type         | Usage                                    | Effet sur la prochaine adresse libre        |
| ------------ | ---------------------------------------- | ------------------------------------------- |
| **DHCP**     | adresses distribuées par un serveur DHCP | jamais proposées : elles ne sont pas libres |
| **Fixe**     | adresses réglées sur les machines        | cherchées en premier                        |
| **Réservée** | adresses gardées de côté                 | jamais proposées                            |

1. Sur la page du sous-réseau, section **Plages**, cliquez sur **Ajouter une plage**.
2. Renseignez **Type**, **Nom** (facultatif, 64 caractères au plus), **Début**, **Fin** et **Description**.
3. **Enregistrer**.

Règles :

- la plage reste dans les adresses utilisables du sous-réseau, et sa fin n'est pas avant son début ;
- deux plages d'un même sous-réseau ne se chevauchent jamais, quels que soient leurs types : « Cette plage chevauche la plage … » ;
- une plage DHCP ne contient ni la passerelle, ni une adresse documentée en affectation **Fixe**, qui finirait en conflit d'adresses ; dans la Bureautique du Siège, une plage DHCP `10.10.10.10` – `10.10.10.30` serait refusée : « Une plage DHCP ne peut pas contenir d'adresses fixes : 10.10.10.20, 10.10.10.21, 10.10.10.22, 10.10.10.23, 10.10.10.24, 10.10.10.30. » ; une **Réservation DHCP** y est permise ;
- une plage fixe ou réservée peut contenir la passerelle et toute adresse documentée ;
- un sous-réseau **Point à point** ou **Loopback** n'a pas de plages.

Exemple de la démonstration : `10.10.99.0/24` (Administration réseau du Siège) a une plage fixe `10.10.99.1` – `10.10.99.99` « Équipements réseau » et une plage réservée `10.10.99.200` – `10.10.99.249` « Extension ».

Chaque plage se modifie ou se supprime depuis sa ligne. Passer une plage au type DHCP est refusé si elle contient la passerelle ou des adresses fixes.

### Appliquer le découpage type

Si la règle d'adressage a un découpage type et que le sous-réseau n'a encore aucune plage, le bouton **Appliquer le découpage type** apparaît sous les plages. La fenêtre montre les plages qui seraient créées, décalées depuis l'adresse du sous-réseau. Le bouton du bas, par exemple « Créer ces 3 plages », les crée toutes ou aucune. Si une plage dépasse les adresses utilisables du sous-réseau, la fenêtre le dit et le bouton reste inactif. Chaque plage passe par les règles ci-dessus : une adresse fixe dans la future plage DHCP fait tout refuser, avec la plage en cause.

## Documenter une adresse IP

Rôle : `editor` ou `admin`.

1. Sur la page du sous-réseau, section **Adresses IP**, cliquez sur **Ajouter une adresse**.
2. **Adresse** : seule obligatoire. Le bouton **Prochaine adresse libre** la remplit (voir plus bas).
3. Facultatifs :
    - **Nom d'hôte** : lettres, chiffres, tirets, tirets bas et points, 253 caractères au plus. Le tiret bas est hors des normes de nommage, mais courant dans les noms de postes Windows : Torsade l'accepte pour documenter l'existant. Le nom est gardé tel que saisi, casse comprise ;
    - **Adresse MAC** : dans l'une des formes `aa:bb:cc:dd:ee:ff`, `aa-bb-cc-dd-ee-ff`, `aabb.ccdd.eeff` ou `aabbccddeeff`, majuscules ou minuscules, sans mélanger les séparateurs. Elle est enregistrée sous la forme `aa:bb:cc:dd:ee:ff` ;
    - **Type** : **Non précisé**, **Serveur**, **Poste fixe**, **Portable**, **Imprimante**, **Téléphone IP**, **Borne Wi-Fi**, **Switch**, **Routeur**, **Pare-feu**, **Caméra**, **NAS** ou **Autre** ;
    - **Affectation** : **Fixe** (par défaut), adresse réglée sur la machine, ou **Réservation DHCP** ;
    - **Statut** : **Prévue**, **Active** (par défaut) ou **Retirée** ;
    - **Équipement**, **Prise** et **Description** (voir les sections suivantes).
4. **Enregistrer**.

Sont refusées :

- une adresse hors des adresses utilisables du sous-réseau : « Cette adresse n'est pas une adresse utilisable de 10.10.20.0/24 (10.10.20.1 – 10.10.20.254). » ;
- une adresse déjà documentée, dans ce sous-réseau ou ailleurs : « Cette adresse est déjà documentée. » ;
- une adresse **Fixe** dans une plage DHCP : « Une adresse fixe ne peut pas se trouver dans la plage DHCP … Choisissez une autre adresse, ou faites-en une réservation DHCP. ». Une **Réservation DHCP** est permise dans une plage DHCP comme en dehors.

L'adresse de la passerelle peut être documentée (l'interface du routeur) : le tableau la marque **Passerelle**. Une adresse ne change jamais de sous-réseau : pour la déplacer, supprimez-la et recréez-la.

Exemple : dans `10.10.10.0/24` (Bureautique du Siège), `10.10.10.20` « imp-accueil » est une adresse fixe d'imprimante, et `10.10.10.110` « pc-accueil-01 » une réservation DHCP dans la plage DHCP `10.10.10.100` – `10.10.10.199`.

## Documenter plusieurs adresses à la suite

Rôle : `editor` ou `admin`.

1. Section **Adresses IP**, cliquez sur **Ajouter plusieurs adresses**. Une grille s'ouvre, avec les colonnes **Adresse**, **Nom d'hôte**, **Adresse MAC**, **Type**, **Affectation**, **Statut** et **Description**.
2. Chaque nouvelle ligne propose la prochaine adresse libre qu'aucune autre ligne n'utilise ; elle est sélectionnée : taper la remplace.
3. Tout se fait au clavier : Entrée ou Tab passe au champ suivant ; Entrée dans la dernière colonne ouvre une nouvelle ligne ; Échap annule la ligne ; Ctrl+Entrée enregistre. **Ajouter une ligne** et **Annuler cette ligne** font de même à la souris.
4. Cliquez sur **Enregistrer les adresses**.

Une saisie compte 100 lignes au plus. Une dernière ligne laissée telle qu'elle était proposée n'est pas envoyée. Tout est enregistré, ou rien : chaque erreur s'affiche sur sa ligne et son champ, toutes en une fois, et la grille annonce « Rien n'a été enregistré : corrigez les lignes en erreur, puis enregistrez à nouveau. ». Les règles sont celles d'une adresse seule, plus une : la même adresse ne peut pas figurer deux fois dans la saisie.

La saisie en série ne rattache ni équipement ni prise : on les ajoute ensuite, adresse par adresse.

## Rattacher une adresse à un équipement ou la détacher

Rôle : `editor` ou `admin`.

Une adresse rattachée à un équipement devient l'une de ses interfaces ; elle garde son nom d'hôte et son type.

- Depuis le sous-réseau : dans la fenêtre de l'adresse, choisissez l'**Équipement**. La liste montre d'abord les équipements du **Site du sous-réseau**, puis les **Autres sites**. **Aucun équipement** la détache.
- Depuis l'équipement : sur sa page, **Rattacher une adresse** cherche, sur tous les sites, les adresses documentées qui n'appartiennent à aucun équipement ; **Rattacher** sur la bonne ligne. **Détacher** retire une adresse, **Détacher toutes les adresses** les retire toutes. Voir [Équipements et ports](equipements-et-ports.md).

Une adresse d'un autre site que l'équipement est acceptée et signalée comme écart. Dans la démonstration, `10.20.60.50` (« srv-sauvegarde-01 », réseau de production de l'Atelier) est une interface d'un serveur du Siège. Une adresse déjà rattachée à un équipement doit être détachée avant d'être rattachée à un autre. Une adresse détachée reste documentée dans son sous-réseau. Chaque rattachement et chaque détachement est inscrit au journal.

## Raccorder une adresse à une prise

Rôle : `editor` ou `admin`.

Dans la fenêtre de l'adresse, le champ **Prise** indique la prise murale où la machine est branchée. Tapez une partie du libellé de la prise pour la trouver parmi celles du site du sous-réseau. Une prise d'un autre site est refusée : « La prise … est sur le site … : choisissez une prise du site du sous-réseau, … ». Le tableau des adresses affiche ensuite, sous le nom d'hôte, le chemin de la prise jusqu'au port d'un switch. Voir [Câblage](cablage.md).

## Trier et chercher dans le tableau des adresses

Rôle : `reader`, `editor` ou `admin`.

Le tableau **Adresses IP** a les colonnes **Adresse**, **Nom d'hôte**, **Adresse MAC**, **Type**, **Affectation**, **Statut** et **Description**, plus **Actions** pour un `editor`. Sous le nom d'hôte figurent l'équipement, la prise et les écarts de l'adresse.

- Il est trié par adresse, dans l'ordre numérique ; cliquez sur l'en-tête d'une colonne pour trier autrement.
- Le champ de recherche (« Adresse, nom d'hôte ou MAC ») filtre les lignes sur l'adresse, le nom d'hôte, le nom de l'équipement et l'adresse MAC. Une MAC est trouvée quelle que soit la façon de la taper : avec deux-points, tirets, points ou rien entre ses chiffres.

## Supprimer une adresse IP

Rôle : `editor` ou `admin`.

Sur la ligne de l'adresse, **Supprimer**, puis confirmez. L'enregistrement est supprimé et l'adresse redevient libre ; le journal garde la trace de ce qu'elle était. Une adresse retirée garde son numéro : pour le réattribuer, supprimez l'enregistrement.

## Lire le plan d'adressage

Rôle : `reader`, `editor` ou `admin`.

Le **Plan d'adressage** dessine le sous-réseau comme un panneau de brassage : une case par adresse, de l'adresse du réseau à l'adresse de diffusion, 32 cases par ligne. Chaque ligne commence par la fin de sa première adresse (`.32`, ou `.1.32` dans un sous-réseau plus grand qu'un /24). Survoler une case affiche l'adresse, son état et son nom d'hôte. La légende montre les mêmes cases.

Chaque état a sa forme, pour ne jamais dépendre de la seule couleur :

| État                 | Forme de la case                | Sens                                                |
| -------------------- | ------------------------------- | --------------------------------------------------- |
| **Documentée**       | pleine                          | adresse documentée et active, réglée sur la machine |
| **Passerelle**       | pleine, avec un point au centre | la passerelle du sous-réseau                        |
| **Prévue**           | vide, contour en pointillés     | adresse documentée au statut prévu                  |
| **Retirée**          | vide, contour épais             | adresse documentée au statut retiré                 |
| **Plage DHCP**       | fines rayures horizontales      | adresse non documentée dans une plage DHCP          |
| **Plage réservée**   | trame de points fins            | adresse non documentée dans une plage réservée      |
| **Réservation DHCP** | moitié basse pleine             | adresse active réservée sur le serveur DHCP         |
| **Libre**            | vide, contour fin               | adresse disponible (une plage fixe ne change rien)  |
| **Non utilisable**   | vide, barrée                    | adresse du réseau et adresse de diffusion           |

Une adresse n'a qu'un état. Quand plusieurs conviendraient, l'ordre suivant tranche : non utilisable, passerelle, prévue, retirée, réservation DHCP, documentée, plage DHCP, plage réservée, libre. Ainsi la passerelle reste la passerelle même documentée, et une réservation prévue apparaît comme prévue.

Le plan n'est dessiné que pour les sous-réseaux de 512 adresses au plus (un /23). Au-delà, la page le dit, et le tableau des adresses et la liste des adresses libres restent disponibles.

## Trouver une adresse libre

Rôle : `reader`, `editor` ou `admin`.

La section **Adresses libres** liste les adresses utilisables qui ne sont ni la passerelle, ni dans une plage DHCP, ni documentées, regroupées en intervalles, chacun avec son nombre d'adresses. Quand le sous-réseau a des plages fixes ou réservées, chaque intervalle dit où il se trouve : **Plage fixe**, **Plage réservée** ou **Hors plage**, suivi du nom de la plage.

En tête, la carte **Prochaine adresse libre** propose l'adresse à documenter ensuite :

1. la plus basse adresse libre d'une plage fixe ;
2. à défaut, la plus basse adresse libre hors de toute plage ;
3. jamais une adresse d'une plage réservée ou DHCP.

Sous l'adresse, la carte dit d'où elle vient : « Plage fixe « Équipements réseau » », **Plage fixe** pour une plage sans nom, ou **Hors plage**. Exemple : dans `10.10.99.0/24`, la prochaine adresse libre est `10.10.99.1`, dans la plage fixe « Équipements réseau ».

Pour un `editor`, le bouton **Utiliser** ouvre la fenêtre d'une nouvelle adresse avec celle-ci déjà saisie. Le bouton **Prochaine adresse libre** de cette fenêtre et chaque nouvelle ligne de la saisie en série suivent la même règle.

Si tout ce qui reste libre est dans des plages réservées, la carte l'indique : « Aucune adresse à proposer : ce qui est libre se trouve uniquement dans des plages réservées. ».
