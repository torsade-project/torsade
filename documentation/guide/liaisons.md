<!-- torsade/documentation/guide/liaisons.md -->

# Liaisons

Cette page couvre les liaisons : ce qui est branché sur quoi entre les ports de deux équipements. Elle dit comment créer une liaison, en relier plusieurs en série, les modifier, les supprimer, les lire sur la page d'un équipement et dans la liste **Liaisons**, et comment créer l'agrégat qui manque en face d'un autre. Les équipements, leurs ports et leurs agrégats sont décrits dans [Équipements et ports](equipements-et-ports.md).

## Ce qu'est une liaison

Une liaison est la connexion logique entre un port d'un équipement et un port d'un autre équipement : « `fw-siege` › `port2` est branché sur `sw-coeur-01` › `1/0/1` ». Elle dit ce qui se parle, quel que soit le chemin physique entre les deux : cordon direct, ou passage par un panneau de brassage, un câble posé et une prise murale. Quand ce chemin est saisi, Torsade le retrouve, et l'affiche avec la liaison (voir [Le chemin câblé d'une liaison](#le-chemin-câblé-dune-liaison)).

Règles :

- une liaison relie deux ports de deux équipements différents ;
- un port est le bout d'une seule liaison au plus ;
- une liaison peut relier deux sites : une fibre entre deux bâtiments, une liaison entre le siège et un autre site.

Une liaison a :

| Champ           | Ce qu'il contient                                                                                                                |
| --------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| **Support**     | **Cuivre**, **Fibre multimode**, **Fibre monomode**, **Câble direct** ou **Autre** ; ou **Non précisé** quand on ne le sait pas. |
| **Statut**      | **Prévue**, **Active** ou **Retirée**. **Active** est proposé par défaut.                                                        |
| **Description** | Facultative, 1 000 caractères au plus.                                                                                           |

Ses deux ports ne changent pas : pour brancher ailleurs, on supprime la liaison et on relie l'autre port.

## Relier un port à un autre équipement

Rôle : `editor` ou `admin`.

1. Ouvrez la page de l'équipement. Dans la section **Ports**, sur la ligne du port, cliquez sur le bouton **Relier…**. Un port déjà relié ne le propose pas.
2. La fenêtre **Relier un port** s'ouvre. Dans **Équipement d'en face**, tapez une partie du nom de l'autre équipement. Les équipements de tous les sites sont cherchés, sauf celui-ci ; chacun est montré avec son site, son type et son nombre de ports libres. Vingt équipements au plus sont listés : tapez davantage pour trouver les autres.
3. Cliquez sur **Choisir** devant l'équipement voulu. Un équipement sans port libre ne peut pas être choisi. **Choisir un autre équipement** revient à la recherche.
4. Sous **Port d'en face**, choisissez le port. Seuls les ports libres sont proposés, membre par membre, chacun avec le résumé de son mode et de ses VLAN, ou de ceux de son agrégat ; un port arrêté porte la mention **Désactivé**.
5. Choisissez au besoin le **Support** et le **Statut**, ajoutez une **Description**.
6. Cliquez sur **Relier**.

La liaison apparaît dans la section **Liaisons** des deux équipements, et dans la colonne **Liaison et cordon** de leurs ports.

Quand deux ports sont déjà câblés l'un à l'autre sans liaison, l'écart qui le signale propose **Créer la liaison** : la page de l'équipement brassé vers le panneau s'ouvre avec la fenêtre **Relier un port**, le port et le port d'en face déjà choisis. Il reste à préciser le **Support**, le **Statut** et la **Description**, puis à cliquer sur **Relier**.

## Relier des ports en série

Rôle : `editor` ou `admin`.

Pour relier d'un coup plusieurs ports d'un équipement à autant de ports d'un autre, par exemple les liens d'une stack vers un switch de cœur :

1. Dans la section **Ports**, cochez les ports à relier (un par un, par plage avec la touche Maj, ou **Tout le membre**).
2. Dans la barre au-dessus du tableau, cliquez sur **Relier en série**. La fenêtre **Relier des ports en série** s'ouvre.
3. Cherchez et choisissez l'équipement d'en face, comme pour un seul port.
4. Sous **Ports d'en face**, cochez autant de ports libres que de ports à relier, ni plus ni moins.
5. La fenêtre montre les paires avant l'enregistrement : le premier port coché ici avec le premier port d'en face, et ainsi de suite, dans l'ordre de chaque équipement (membre, puis ordre du port), quel que soit l'ordre dans lequel vous avez coché.
6. Choisissez le **Support** et le **Statut**, qui valent pour toutes les liaisons de la série, puis cliquez sur **Relier**.

Règles :

- il faut autant de ports de chaque côté ; sinon, le refus donne les deux nombres ;
- un port déjà relié parmi ceux cochés bloque la série : la fenêtre le nomme et demande de le désélectionner ;
- tout ou rien : si un port n'est plus libre au moment de l'enregistrement, aucune liaison n'est créée, et le refus nomme ce à quoi chaque port est déjà relié ;
- une fois la série reliée, les ports ne sont plus cochés.

Exemple de la démonstration : `sw-serveurs` › `1/1/1` et `2/1/1` sont reliés en série à `sw-coeur-01` › `1/1/1` et `1/1/2`, en **Câble direct**.

## Modifier une liaison

Rôle : `editor` ou `admin`.

1. Sur la page d'un des deux équipements, dans la section **Liaisons**, ou dans la liste **Liaisons**, cliquez sur le bouton **Modifier** de la ligne de la liaison.
2. Dans **Modifier la liaison**, changez le **Support**, le **Statut** ou la **Description**.
3. Cliquez sur **Enregistrer**. Vous restez sur la page d'où vous êtes parti.

Les deux ports d'une liaison ne se modifient pas : pour la brancher ailleurs, supprimez-la et reliez l'autre port.

## Supprimer une liaison

Rôle : `editor` ou `admin`.

1. Cliquez sur le bouton **Supprimer** de la ligne de la liaison, sur la page d'un équipement ou dans la liste **Liaisons**.
2. Confirmez dans **Supprimer cette liaison ?**.

Les deux ports restent, libres d'être reliés à nouveau.

Un port relié ne peut pas être supprimé, ni son membre, ni son équipement : le refus nomme chaque liaison en cause, par exemple « 1/0/1 est relié à fw-siege › port2 : supprimez d'abord la liaison. » Supprimez d'abord la liaison : une liaison ne perd jamais un de ses bouts sans que quelqu'un le décide.

## Lire les liaisons d'un équipement

Rôle : `reader`, `editor` ou `admin`.

Sur la page d'un équipement qui a au moins un membre, la section **Liaisons** montre ce sur quoi ses ports sont branchés, dans l'ordre de ses ports :

| Colonne     | Ce qu'elle montre                                                                                                               |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------- |
| **Port**    | Le port de cet équipement, suivi entre parenthèses de son agrégat s'il en a un.                                                 |
| **En face** | L'équipement et le port d'en face, le site d'en face quand ce n'est pas celui de l'équipement, et la description de la liaison. |
| **Support** | Le support, ou **Non précisé**.                                                                                                 |
| **Statut**  | **Prévue**, **Active** ou **Retirée**.                                                                                          |
| **Écarts**  | Là où les deux bouts ne s'accordent pas (voir [Repérer les écarts d'une liaison](#repérer-les-écarts-dune-liaison)).            |

La colonne **Liaison et cordon** de la section **Ports** donne aussi, pour chaque port relié, l'équipement et le port d'en face.

## Lire la liste des liaisons

Rôle : `reader`, `editor` ou `admin`.

1. Dans le menu, sous **Réseau physique**, ouvrez **Liaisons**.
2. La liste montre toutes les liaisons, classées par l'équipement de leur premier bout, puis par port. Chaque liaison a ses deux bouts, **Bout 1** et **Bout 2**, chacun avec son équipement, son port et son site, puis son **Support**, avec son chemin câblé s'il est connu, son **Statut** et ses **Écarts**.
3. Filtrez au besoin :
    - **Site** : les liaisons dont un des deux bouts est sur ce site, les liaisons vers un autre site comprises ;
    - **Statut** : **Prévue**, **Active** ou **Retirée** ;
    - **Avec écart seulement** : les liaisons dont les deux bouts ne s'accordent pas.

Le haut de la liste donne le nombre de liaisons, ou le nombre affiché sur le total quand un filtre est actif.

Tant qu'aucune liaison n'existe, la page rappelle qu'une liaison se déclare depuis la page d'un équipement, sur la ligne d'un de ses ports.

## Lire une liaison agrégée

Rôle : `reader`, `editor` ou `admin`.

Quand les deux bouts de plusieurs liaisons sont des ports d'agrégats, et que ces liaisons vont d'un même agrégat à un même agrégat d'en face, elles forment une liaison agrégée. Elle s'affiche sur une seule ligne : l'agrégat de ce côté, la mention « Liaison agrégée, 2 liens » (avec le nombre de liaisons), et en face l'équipement et son agrégat. Par exemple : `Po1` ↔ `sw-coeur-01` › `Po1`, 2 liens.

- Cliquez sur le nom de l'agrégat pour déplier la ligne : les liaisons qui la composent apparaissent dessous, chacune avec ses ports, et se modifient ou se suppriment une par une.
- La ligne regroupe les supports et les statuts de ses liaisons, chacun nommé une fois.
- Ses écarts sont dits une fois, sur la ligne de la liaison agrégée.

Exemple de la démonstration : sur la page de `sw-serveurs`, son agrégat `Po1` (ports `1/1/1` et `2/1/1`) fait face à l'agrégat `Po1` de `sw-coeur-01` (ports `1/1/1` et `1/1/2`) : une liaison agrégée de 2 liens.

## Le chemin câblé d'une liaison

Rôle : `reader`, `editor` ou `admin`.

Quand un bout d'une liaison est brassé vers une prise sur laquelle l'autre bout est branché, ce câblage est le chemin physique de la liaison (voir [Le chemin câblé](cablage.md#le-chemin-câblé)). Dans la liste **Liaisons**, il s'affiche sous le support : « par PB-B 13 › B.13 » pour la liaison de `sw-etage-1` › `1/0/21` à `ap-etage-1` › `eth0`. Le lien mène à la prise, sur la page **Prises** ; le lieu du panneau et celui de la prise sont dans son infobulle.

Le chemin câblé ne remplace pas la liaison : il la confirme. Quand le câblage la contredit, ou qu'il reste en place pour une liaison retirée, Torsade le signale comme écart. Sur le schéma physique, une liaison au chemin câblé connu se dessine en deux traits, à travers son panneau (voir [Schémas](schemas.md)). Le chemin câblé d'une liaison agrégée est donné lien par lien, dans l'infobulle de son trait.

## Repérer les écarts d'une liaison

Rôle : `reader`, `editor` ou `admin`.

Torsade compare les deux bouts de chaque liaison et signale où ils ne s'accordent pas. Un écart ne refuse rien : il montre le réseau tel qu'il est documenté. Ce qui est comparé est la configuration effective de chaque bout : le mode et les VLAN du port, ou ceux de son agrégat. Les modes et les VLAN ne sont comparés que si les deux bouts ont un mode : le port d'un pare-feu ou d'un serveur n'en a souvent pas.

Les écarts d'une liaison, en bref :

- un bout en accès, l'autre en trunk ;
- deux ports d'accès sur des VLAN différents ;
- deux trunks dont le VLAN natif diffère, ou dont un VLAN étiqueté n'est porté que d'un côté ;
- deux VLAN différents sous un même numéro aux deux bouts, par exemple le VLAN 10 de deux sites : la liaison fusionne deux réseaux ;
- un agrégat dont les liaisons mènent à plusieurs équipements, ou dont les ports d'en face ne forment pas un même agrégat ;
- une liaison active sur un port désactivé ;
- une liaison que le câblage contredit : le câblage d'un de ses ports mène à un autre port que la liaison ;
- un câblage encore en place pour une liaison retirée ;
- un port relié et brassé vers une prise où rien n'est branché : l'écart propose **Brancher**, qui branche le port d'en face sur cette prise, quand il est du même site et libre de tout cordon.

Ils apparaissent dans la colonne **Écarts** des liaisons, dans la liste des écarts de la page de l'équipement, et peuvent être isolés avec le filtre **Avec écart seulement** de la liste **Liaisons**. Leur détail est dans [Écarts](ecarts.md).

Exemple de la démonstration : la liaison entre `sw-coeur-01` › `1/1/4` et `sw-coeur-02` › `1/1/4` montre un VLAN natif 99 d'un côté et 5 de l'autre.

## Créer l'agrégat en face

Rôle : `editor` ou `admin`.

Quand un agrégat n'existe que d'un côté, l'écart le dit, par exemple « Po1 : en face, ports hors agrégat sur sw-coeur-01 ». Si toutes les liaisons de cet agrégat mènent à des ports d'un même équipement, et qu'aucun de ces ports n'est dans un agrégat, l'écart propose le lien **Créer l'agrégat en face**.

1. Cliquez sur **Créer l'agrégat en face**, là où l'écart s'affiche (page d'un des deux équipements, ou liste **Liaisons**).
2. La page de l'équipement d'en face s'ouvre avec la fenêtre **Nouvel agrégat** déjà remplie :
    - les ports d'en face, cochés ;
    - le **Protocole** de l'agrégat d'origine ;
    - son **Mode** et ses VLAN : VLAN natif et VLAN étiquetés, ou VLAN d'accès et VLAN voix ;
    - un **Nom** proposé : le nom suivant dans la série des agrégats de cet équipement (« LAG3 » après « LAG2 »), ou `Po1` si aucun de ses agrégats ne se termine par un numéro. Un nom déjà pris est sauté.
3. La fenêtre rappelle d'où vient l'agrégat, et que les ports cochés perdent leur propre mode et leurs VLAN : ceux de l'agrégat s'appliquent à eux.
4. Tout reste modifiable. Cliquez sur **Enregistrer** pour créer l'agrégat, comme un agrégat créé à la main.

Le lien n'est pas proposé quand les liaisons de l'agrégat mènent à plusieurs équipements, ni quand un des ports d'en face est déjà dans un agrégat : il faut alors corriger à la main (voir [Équipements et ports](equipements-et-ports.md)).
