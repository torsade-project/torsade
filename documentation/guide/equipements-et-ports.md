<!-- torsade/documentation/guide/equipements-et-ports.md -->

# Équipements et ports

Cette page couvre les machines d'un site et ce dont elles sont faites : les équipements, leurs modèles, leurs membres (les boîtiers d'une stack), leurs ports, le mode et les VLAN de chaque port, et les agrégats de ports. Les connexions entre équipements sont décrites dans [Liaisons](liaisons.md), le brassage vers les panneaux et le branchement sur les prises dans [Câblage](cablage.md).

Tout se trouve sous **Réseau physique** dans le menu : **Équipements** et **Modèles d'équipement**.

## Ce qu'est un équipement

Un équipement est une machine d'un site : pare-feu, switch, serveur, imprimante, borne Wi-Fi. Les adresses IP qui lui sont rattachées sont ses interfaces (voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md)).

| Champ                     | Ce qu'il contient                                                                                                                                                                       |
| ------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Nom**                   | Texte libre, 100 caractères au plus : `fw-siege` comme « Imprimante accueil ». Unique dans le site, sans tenir compte de la casse ni des accents : `SW-01` et `sw-01` sont le même nom. |
| **Type**                  | **Serveur**, **Poste fixe**, **Portable**, **Imprimante**, **Téléphone IP**, **Borne Wi-Fi**, **Switch**, **Routeur**, **Pare-feu**, **Caméra**, **NAS** ou **Autre**.                  |
| **Site**                  | Obligatoire.                                                                                                                                                                            |
| **Local**                 | Facultatif : un local du site de l'équipement. Une petite structure n'a pas toujours de plan de ses locaux.                                                                             |
| **Fabricant**, **Modèle** | Facultatifs, 100 caractères au plus.                                                                                                                                                    |
| **Numéro de série**       | Facultatif. Dès que l'équipement a des membres, chaque membre porte le sien (voir [Ajouter un membre à une stack](#ajouter-un-membre-à-une-stack)).                                     |
| **Statut**                | **Prévu**, **Actif** ou **Retiré**. **Actif** est proposé par défaut.                                                                                                                   |
| **Description**           | Facultative, 1 000 caractères au plus.                                                                                                                                                  |

Un équipement appartient à un site : tant qu'aucun site n'existe, la page **Équipements** invite à en créer un d'abord (voir [Sites et lieux](sites-et-lieux.md)).

## Lire la liste des équipements

Rôle : `reader`, `editor` ou `admin`.

1. Dans le menu, ouvrez **Équipements**.
2. La liste montre, par nom, chaque équipement avec son **Type**, son **Site**, son **Lieu**, le nombre de ses **Adresses** et son **Statut**.
3. Filtrez par **Site**, **Type** ou **Statut** : la liste se met à jour dès le choix.
4. Dans **Rechercher**, tapez une partie du nom, du modèle ou du numéro de série, puis cliquez sur **Rechercher**.

Les filtres restent dans l'adresse de la page : une liste filtrée se met en favori ou se partage.

La page d'un site montre aussi ses équipements, dans sa section **Équipements**.

## Lire la page d'un équipement

Rôle : `reader`, `editor` ou `admin`.

Cliquez sur le nom d'un équipement. Sa page montre, de haut en bas :

- son nom, son type et son statut, puis son **Site**, son **Lieu**, son **Fabricant et modèle** et son **Numéro de série** ;
- ses **Adresses**, c'est-à-dire ses interfaces ;
- la liste de ses écarts, s'il en a (voir [Écarts](ecarts.md)) ;
- ses **Membres**, ses **Agrégats** et ses **Liaisons**, dès qu'il a au moins un membre ;
- ses **Ports**, membre par membre.

## Créer un équipement

Rôle : `editor` ou `admin`.

1. Sur la page **Équipements**, cliquez sur **Nouvel équipement**. Depuis la page d'un site, le site est déjà choisi.
2. Choisissez le **Site**, puis, si vous le connaissez, le **Local**. Seuls les locaux du site choisi sont proposés.
3. Remplissez le **Nom** et choisissez le **Type**.
4. Complétez au besoin **Fabricant**, **Modèle**, **Numéro de série**, **Statut** et **Description**.
5. Cliquez sur **Enregistrer**. La page de l'équipement s'ouvre.

Un nom déjà pris dans le site est refusé, quelle que soit sa casse : le message nomme l'équipement tel qu'il est écrit.

Un équipement neuf n'a encore ni membre ni port : voir [Donner ses premiers ports à un équipement](#donner-ses-premiers-ports-à-un-équipement).

## Modifier un équipement

Rôle : `editor` ou `admin`.

1. Sur la page de l'équipement, cliquez sur **Modifier**.
2. Changez ce qu'il faut dans **Modifier l'équipement**, puis cliquez sur **Enregistrer**.

Quand l'équipement a des membres, le champ **Numéro de série** n'est plus proposé : les numéros de série sont ceux des membres, sur la page de l'équipement.

## Changer un équipement de site

Rôle : `editor` ou `admin`.

1. Sur la page de l'équipement, cliquez sur **Modifier**.
2. Choisissez l'autre **Site**. Le **Local** se vide : un local appartient à un seul site. Choisissez au besoin un local du nouveau site.
3. Cliquez sur **Enregistrer**.

Ce qui suit le changement :

- sans local choisi du nouveau site, l'équipement n'a plus de local ;
- ses membres quittent leurs locaux, qui étaient ceux de l'ancien site ;
- ses ports gardent les VLAN qu'ils portent, quel que soit leur site, et ses liaisons restent en place : une liaison peut relier deux sites.

Un équipement dont un port est brassé, ou branché sur une prise, ne change pas de site : un cordon ne relie pas deux sites. Le refus nomme les cordons. Débrassez et débranchez d'abord ses ports, par exemple avec **Débrasser tous ses ports** et **Débrancher tous ses ports** dans la section **Ports** de sa page (voir [Câblage](cablage.md)).

## Supprimer un équipement

Rôle : `editor` ou `admin`.

1. Sur la page de l'équipement, cliquez sur **Supprimer**.
2. Confirmez dans **Supprimer cet équipement ?**.

La suppression emporte ses membres, ses ports, ses agrégats et les VLAN qu'ils portent. Elle est refusée :

- tant qu'une adresse IP est rattachée à l'équipement : une adresse ne perd jamais son équipement sans que quelqu'un le décide. Le refus s'affiche sur la page avec le bouton **Détacher toutes les adresses**. Après confirmation dans **Détacher toutes les adresses ?**, les adresses restent documentées dans leurs sous-réseaux, avec leur nom d'hôte ; relancez ensuite la suppression ;
- tant qu'un de ses ports est relié par une liaison, brassé par un cordon ou branché sur une prise : supprimez d'abord la liaison (voir [Liaisons](liaisons.md)) ou le cordon (voir [Câblage](cablage.md)).

## Créer des équipements depuis les noms d'hôte d'un site

Rôle : `editor` ou `admin`.

Un réseau documenté adresse par adresse passe ainsi aux équipements : chaque nom d'hôte devient un équipement, et ses adresses deviennent les interfaces de cet équipement.

1. Ouvrez la page du site.
2. Dans sa section **Équipements**, cliquez sur **Créer des équipements depuis les noms d'hôte**.
3. La page montre une ligne par nom d'hôte distinct, sans tenir compte de la casse ni des accents, parmi les adresses des sous-réseaux du site qui ne sont rattachées à aucun équipement. Chaque ligne donne le nombre d'adresses qui portent ce nom.
4. Pour chaque ligne, vérifiez le **Type** et le **Statut** proposés, et changez-les au besoin.
5. Décochez les lignes à laisser de côté. **Tout cocher** et **Tout décocher** agissent sur toutes les lignes.
6. Cliquez sur **Traiter les N lignes cochées** (le bouton donne le nombre de lignes).

Ce qui est proposé :

- **Type** : le type d'appareil le plus fréquent parmi les adresses de ce nom ; à égalité, le premier rencontré ; **Autre** si aucune adresse n'a de type.
- **Statut** : **Prévu** si le site est prévu ou si toutes les adresses de ce nom sont prévues ; **Retiré** si elles sont toutes retirées ; **Actif** sinon.
- **Action** : **Créer**, ou **Rattacher à l'équipement existant** quand le site a déjà un équipement de ce nom, sans tenir compte de la casse ni des accents : un équipement nommé « imp-étage-2 » reçoit les adresses nommées « imp-etage-2 ». Les adresses sont alors rattachées à cet équipement, sans en créer un autre.

Règles :

- l'équipement créé prend le nom d'hôte tel que l'écrit sa plus petite adresse, et n'a pas de local ;
- un nom d'hôte de plus de 100 caractères ne peut pas être coché : créez cet équipement à la main ;
- 500 lignes au plus par envoi ;
- tout ou rien : si une ligne est refusée, aucune ne l'est faite, et chaque refus s'affiche sur sa ligne ;
- aucun nom d'hôte n'est modifié.

Exemple de la démonstration : sur le site Siège, l'adresse `10.10.10.23` porte le nom `imp-etage-1` sans être rattachée à un équipement ; la page propose de créer `imp-etage-1`, de type **Imprimante**.

## Ce qu'est un modèle d'équipement

Un modèle décrit une sorte de machine et ses ports, par groupes : « 24 ports RJ45 PoE+, puis 4 ports SFP+ ». Quand un membre est créé à partir d'un modèle, ses ports sont recopiés depuis ce modèle. Ensuite, le modèle et les ports vivent séparément : modifier un modèle ne modifie aucun port existant.

Un modèle contient :

- un **Nom**, unique ; un **Type** ; un **Fabricant** et un **Modèle**, facultatifs ;
- un **Budget PoE (W)** facultatif : ce que la machine peut fournir à tout ce qu'elle alimente ;
- une **Description** facultative ;
- ses **Groupes de ports**, 20 au plus. Un modèle peut être enregistré sans groupe : il n'a alors aucun port.

Chaque groupe de ports se règle ainsi :

| Champ              | Ce qu'il contient                                                              |
| ------------------ | ------------------------------------------------------------------------------ |
| **Quantité**       | De 1 à 128 ports.                                                              |
| **Motif**          | Le nom des ports : `{n}` est le numéro du port, `{m}` le numéro du membre.     |
| **Premier numéro** | Le numéro du premier port du groupe, de 0 à 999.                               |
| **Connecteur**     | **RJ45**, **SFP**, **SFP+**, **SFP28**, **QSFP+**, **QSFP28** ou **Autre**.    |
| **Débit**          | De **100 Mbit/s** à **100 Gbit/s**.                                            |
| **PoE**            | **Aucun**, **802.3af**, **802.3at**, **802.3bt Type 3** ou **802.3bt Type 4**. |
| **Libellé PoE**    | Facultatif : le mot du fabricant, comme `PoE+`.                                |

Les motifs :

- un motif contient `{n}` une fois, et `{m}` une fois au plus ;
- autour, seulement des lettres, des chiffres et les caractères `/`, `-`, `.` et `:` ;
- `{m}/0/{n}` donne `1/0/1` à `1/0/24` sur le membre 1 ; `Gi{m}/0/{n}` donne `Gi2/0/1` sur le membre 2 ; `port{n}` donne `port1` ;
- un nom de port fait 32 caractères au plus, vérifié avec le plus grand numéro de membre ;
- deux groupes d'un même modèle ne peuvent pas donner le même nom de port.

Les fabricants ne s'accordent pas sur les noms de ports : le motif couvre les notations courantes sans en imposer une.

## Lire les modèles d'équipement

Rôle : `reader`, `editor` ou `admin`.

Dans le menu, ouvrez **Modèles d'équipement**. La liste montre, par nom, chaque modèle avec son **Type**, son **Fabricant et modèle**, son nombre de **Ports** et le nombre de **Membres** d'équipements créés à partir de lui.

## Ajouter les modèles génériques

Rôle : `editor` ou `admin`.

Torsade propose cinq modèles sans marque, ajoutés seulement à la demande :

- **Switch 24 ports PoE+ et 4 SFP+** : `{m}/0/1` à `{m}/0/24` en RJ45 1 Gbit/s 802.3at, puis `{m}/1/1` à `{m}/1/4` en SFP+ 10 Gbit/s ;
- **Switch 48 ports PoE+ et 4 SFP+** : de même avec 48 ports ;
- **Switch 8 ports** : `1` à `8` ;
- **Pare-feu 8 ports** : `port1` à `port8` ;
- **Serveur 2 ports** : `eth0` et `eth1`.

Sur la page **Modèles d'équipement**, cliquez sur **Ajouter les modèles génériques**. Seuls les modèles absents, cherchés par leur nom, sont créés ; ceux déjà là restent tels que vous les avez modifiés. Le bouton disparaît quand ils sont tous présents.

## Créer un modèle d'équipement

Rôle : `editor` ou `admin`.

1. Sur la page **Modèles d'équipement**, cliquez sur **Nouveau modèle**.
2. Remplissez le **Nom** et choisissez le **Type**. Complétez au besoin **Fabricant**, **Modèle**, **Budget PoE (W)** et **Description**.
3. Cliquez sur **Ajouter un groupe** pour chaque groupe de ports, et remplissez-le. Les groupes se déplacent vers le haut ou le bas, et se retirent.
4. Vérifiez l'aperçu **Noms sur le membre 1** : il montre les noms que les ports recevront.
5. Cliquez sur **Enregistrer**.

## Modifier ou supprimer un modèle d'équipement

Rôle : `editor` ou `admin`.

- Pour modifier, cliquez sur le bouton **Modifier** de sa ligne, changez ce qu'il faut dans **Modifier le modèle**, puis cliquez sur **Enregistrer**. Les membres déjà créés à partir de ce modèle gardent leurs ports tels quels.
- Pour supprimer, cliquez sur le bouton **Supprimer** de sa ligne, puis confirmez dans **Supprimer ce modèle ?**. La suppression est refusée tant qu'un membre d'équipement utilise ce modèle : retirez d'abord ce modèle de chaque membre (voir [Modifier ou supprimer un membre](#modifier-ou-supprimer-un-membre)).

## Donner ses premiers ports à un équipement

Rôle : `editor` ou `admin`.

Un équipement sans membre n'a pas de port. Dans sa section **Ports**, deux boutons lui en donnent :

- **Choisir un modèle** : choisissez le **Modèle**, vérifiez l'aperçu **Ports créés**, puis cliquez sur **Enregistrer**. L'équipement reçoit son membre 1, de rôle **Principal**, avec les ports du modèle.
- **Ajouter des ports** : décrivez une série de ports (voir [Ajouter des ports en série](#ajouter-des-ports-en-série)). L'équipement reçoit son membre 1, **Principal**, avec ces ports.

Si l'équipement avait un numéro de série, il passe au membre 1 et quitte l'équipement : la fenêtre le signale avant l'enregistrement. Un numéro de série n'existe qu'à un seul endroit.

## Ce que sont les membres et les stacks

Un membre est un des boîtiers physiques d'un équipement. Un switch seul a un membre. Un stack en a plusieurs, gérés comme un seul switch : ses ports appartiennent à ses membres, et `{m}` dans leurs noms est le numéro du membre.

Chaque membre a :

| Champ               | Ce qu'il contient                                                                                                 |
| ------------------- | ----------------------------------------------------------------------------------------------------------------- |
| **Numéro**          | De 1 à 16, unique dans l'équipement.                                                                              |
| **Rôle**            | **Principal**, **Secondaire** ou **Membre**. Un équipement a un membre principal et un membre secondaire au plus. |
| **Modèle**          | Facultatif : le modèle dont ses ports sont recopiés à sa création.                                                |
| **Numéro de série** | Facultatif : celui du boîtier.                                                                                    |
| **Local**           | **Comme l'équipement**, ou un autre local du site de l'équipement.                                                |
| **Description**     | Facultative.                                                                                                      |

Exemple de la démonstration : `sw-serveurs`, sur le site Siège, est une stack de deux membres créés à partir du modèle **Switch 24 ports PoE+ et 4 SFP+** : le membre 1, principal, porte les ports `1/0/1` à `1/0/24` et `1/1/1` à `1/1/4` ; le membre 2, secondaire, les ports `2/0/1` à `2/1/4`.

## Ajouter un membre à une stack

Rôle : `editor` ou `admin`.

1. Sur la page de l'équipement, dans la section **Membres**, cliquez sur **Ajouter un membre**. Le bouton est grisé quand les 16 numéros sont pris.
2. Choisissez le **Numéro** et le **Rôle**.
3. Choisissez au besoin un **Modèle** : l'aperçu **Ports créés** montre les noms des ports, avec le numéro du membre à la place de `{m}`. Sans modèle, le membre n'a aucun port jusqu'à ce que vous en ajoutiez.
4. Complétez **Numéro de série**, **Local** et **Description**, puis cliquez sur **Enregistrer**.

Le membre est refusé si un de ses ports prendrait un nom que l'équipement a déjà, ou si son modèle donnerait deux fois le même nom pour ce numéro de membre.

## Modifier ou supprimer un membre

Rôle : `editor` ou `admin`.

- Pour modifier, cliquez sur le bouton **Modifier** de sa ligne dans **Membres**, changez ce qu'il faut dans **Modifier le membre**, puis cliquez sur **Enregistrer**. Ses ports restent tels quels : un autre numéro ne les renomme pas, un autre modèle ne les remplace pas. Choisir **Aucun** comme modèle retire le modèle du membre sans toucher à ses ports.
- Pour supprimer, cliquez sur le bouton **Supprimer** de sa ligne, puis confirmez dans **Supprimer ce membre ?**. Ses ports sont supprimés avec lui. La suppression est refusée tant qu'un de ses ports est relié, brassé ou branché sur une prise.

## Lire les ports d'un équipement

Rôle : `reader`, `editor` ou `admin`.

La section **Ports** de la page d'un équipement montre ses ports membre par membre, chaque membre avec son rôle et son nombre de ports. Pour chaque port :

| Colonne                 | Ce qu'elle montre                                                                                                                                                                                                           |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Nom**                 | Le nom du port, tel que l'équipement l'écrit.                                                                                                                                                                               |
| **État**                | **Activé**, ou **Désactivé** pour un port arrêté.                                                                                                                                                                           |
| **Mode et VLAN**        | Le résumé de son mode et de ses VLAN (voir [Lire le résumé du mode et des VLAN](#lire-le-résumé-du-mode-et-des-vlan)).                                                                                                      |
| **Liaison et cordon**   | L'équipement et le port d'en face, s'il est relié ; la prise ou le port de panneau vers lequel il est brassé, s'il l'est ; la prise sur laquelle il est branché, s'il l'est (« branché sur B.13 ») ; ses écarts de câblage. |
| **Connecteur et débit** | Par exemple `RJ45 · 1 Gbit/s`.                                                                                                                                                                                              |
| **PoE**                 | La norme, suivie du libellé du fabricant s'il y en a un.                                                                                                                                                                    |
| **Description**         | Ce qui est branché sur le port, ou pourquoi il est désactivé.                                                                                                                                                               |

Pour un `editor` ou un `admin`, la ligne d'un port sans cordon porte l'icône **Brancher sur une prise**, qui le branche sur une prise murale du site, et celle d'un port branché l'icône **Débrancher** (voir [Brancher un équipement sur une prise](cablage.md#brancher-un-équipement-sur-une-prise)). Au-dessus des ports, **Débrasser tous ses ports** et **Débrancher tous ses ports** n'apparaissent que lorsqu'au moins un port est brassé, ou branché.

## Modifier un port

Rôle : `editor` ou `admin`.

1. Dans la section **Ports**, cliquez sur le bouton **Modifier** de la ligne du port.
2. Dans la fenêtre qui porte son nom, par exemple **Port 1/0/1**, changez ce qu'il faut :
    - **Nom** : texte libre de 32 caractères au plus, tel que l'équipement l'écrit, unique dans l'équipement sans tenir compte de la casse ;
    - **Ordre** : sa place dans son membre, de 1 à 9999 ;
    - **Port activé** : décochez-la pour un port arrêté, et dites pourquoi dans sa **Description** ;
    - **Description** : ce qui est branché sur le port ;
    - **Mode** et VLAN (voir [Régler le mode et les VLAN d'un port](#régler-le-mode-et-les-vlan-dun-port)) ;
    - **Connecteur**, **Débit**, **PoE** et **Libellé PoE**.
3. Cliquez sur **Enregistrer**.

## Ajouter des ports en série

Rôle : `editor` ou `admin`.

1. Dans la section **Ports**, cliquez sur **Ajouter des ports**.
2. Si l'équipement a plusieurs membres, choisissez le **Membre**. Les ports s'ajoutent après ceux qu'il a déjà.
3. Tapez le **Motif**, le **Premier numéro** et le **Dernier numéro**. Les règles du motif sont celles des modèles (voir [Ce qu'est un modèle d'équipement](#ce-quest-un-modèle-déquipement)).
4. Choisissez le **Connecteur**, le **Débit**, le **PoE** et, au besoin, le **Libellé PoE** : ils valent pour toute la série.
5. Vérifiez l'aperçu **Ports créés**, qui donne les noms et leur nombre, puis cliquez sur **Enregistrer**.

Règles :

- 128 ports au plus par série ;
- un port seul, avec le même premier et dernier numéro, peut se passer de `{n}` : un motif `MGMT` ou `WAN` donne un port de ce nom ;
- aucun nom produit ne doit déjà exister dans l'équipement, sinon toute la série est refusée ;
- la fenêtre garde la série précédente quand elle se rouvre : une deuxième série ressemble souvent à la première.

## Régler le mode et les VLAN d'un port

Rôle : `editor` ou `admin`.

Dans la fenêtre d'un port, choisissez le **Mode** :

| Mode      | Ce que le port porte                                                                                                                                                                                          |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Accès** | Un **VLAN** non étiqueté, obligatoire, et au plus un **VLAN voix**, étiqueté, pour un téléphone avec un poste derrière lui. Cochez **VLAN voix** pour le choisir ; il doit être un autre VLAN que le premier. |
| **Trunk** | Des **VLAN étiquetés** et, au plus, un **VLAN natif**, non étiqueté, qui ne peut pas être aussi étiqueté. **Tous** coche tous les VLAN du site de l'équipement ; **Aucun** les décoche tous.                  |
| **Aucun** | Un port non configuré, ou routé : aucun VLAN. Le port d'un pare-feu ou d'un serveur n'a souvent pas de mode.                                                                                                  |

Changer de mode vide les VLAN étiquetés déjà cochés.

Un port d'un agrégat n'a ni mode ni VLAN propres : ce sont ceux de l'agrégat, et la fenêtre du port le rappelle (voir [Ce qu'est un agrégat](#ce-quest-un-agrégat)).

## Porter les VLAN d'un autre site

Rôle : `editor` ou `admin`.

Un port peut porter les VLAN de n'importe quel site : un pare-feu central route les VLAN de plusieurs sites, une fibre entre deux bâtiments porte ceux de l'autre.

- Les VLAN du site de l'équipement sont proposés d'abord, puis ceux des autres sites, groupés par site et par ordre de nom.
- Pour les VLAN étiquetés d'un trunk, le groupe d'un autre site est replié ; cliquez sur son nom pour l'ouvrir. Il s'ouvre de lui-même quand le port porte déjà un de ses VLAN, et indique combien de ses VLAN sont cochés. Son bouton **Tous** coche tous les VLAN de ce site.
- Un port ne porte jamais deux VLAN de même numéro, même de deux sites différents : rien ne les distinguerait sur le câble. Le refus nomme les deux sites, par exemple « Le VLAN 10 du site Siège et le VLAN 10 du site Atelier ne peuvent pas passer sur le même port. »

## Lire le résumé du mode et des VLAN

Rôle : `reader`, `editor` ou `admin`.

La colonne **Mode et VLAN** résume la configuration en quelques mots :

| Résumé                                  | Sens                                                                                                                           |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `Accès 10`                              | Accès, VLAN 10.                                                                                                                |
| `Accès 10, voix 30`                     | Accès, VLAN 10, VLAN voix 30.                                                                                                  |
| `Trunk, natif 99 : 10, 20`              | Trunk, VLAN natif 99, VLAN 10 et 20 étiquetés.                                                                                 |
| `Trunk, natif 99 : 10, 20 · ATE 60, 70` | Les VLAN d'un autre site suivent ceux du site de l'équipement, derrière le code de leur site, ou son nom s'il n'a pas de code. |
| `via Po1 : Trunk, natif 99 : 10, 20`    | Le port fait partie de l'agrégat `Po1` : il porte ce que porte l'agrégat.                                                      |
| **Aucun**                               | Le port n'a pas de mode.                                                                                                       |

Exemple de la démonstration : sur `sw-coeur-02`, le port `1/1/3`, vers l'atelier, se résume en `Trunk : 10 · ATE 60` : le VLAN 10 du Siège et le VLAN 60 de l'Atelier.

## Régler plusieurs ports à la fois

Rôle : `editor` ou `admin`.

1. Dans la section **Ports**, cochez les ports voulus :
    - un par un, avec la case de leur ligne ;
    - par plage : cochez un port, puis cliquez sur la case d'un autre en tenant la touche Maj : tous les ports entre les deux suivent ;
    - tout un membre, avec **Tout le membre** sur la ligne du membre.
2. La barre au-dessus du tableau, qui reste visible pendant le défilement, donne le nombre de ports sélectionnés et propose :
    - **Mode et VLAN** : le même mode et les mêmes VLAN pour tous, réglés comme pour un port, puis **Appliquer** ;
    - **Retirer le mode** : plus de mode ni de VLAN, après confirmation ;
    - **Relier en série** : voir [Liaisons](liaisons.md) ;
    - **Activer** et **Désactiver** ;
    - **Supprimer** : voir [Supprimer des ports](#supprimer-des-ports) ;
    - **Tout désélectionner**.

Chaque action vaut pour tous les ports cochés, ou pour aucun. Un port qui a déjà l'état ou le mode demandé est laissé tel quel, et le message final compte seulement les ports changés. **Mode et VLAN** est refusé pour tous si un des ports fait partie d'un agrégat.

## Supprimer des ports

Rôle : `editor` ou `admin`.

- Un port : cliquez sur le bouton **Supprimer** de sa ligne, puis confirmez dans **Supprimer ce port ?**.
- Plusieurs : cochez-les, cliquez sur **Supprimer** dans la barre, puis confirmez dans **Supprimer les ports sélectionnés ?**.

Les VLAN qu'ils portent partent avec eux. Un port relié par une liaison, brassé par un cordon ou branché sur une prise n'est pas supprimé : le refus nomme la liaison ou le cordon à supprimer d'abord. Pour plusieurs ports, un seul port relié, brassé ou branché suffit à tout refuser.

## Ce qu'est un agrégat

Un agrégat regroupe plusieurs ports d'un équipement utilisés comme une seule liaison logique (Port-Channel, LAG, trunk agrégé selon les fabricants). Ses ports peuvent être sur plusieurs membres : une stack garde ainsi sa liaison montante quand un membre tombe.

| Champ            | Ce qu'il contient                                                                                                                                                       |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Nom**          | Tel que l'équipement l'écrit : `Po1`, `lag 1`, `Trk1`. 32 caractères au plus, unique dans l'équipement sans tenir compte de la casse.                                   |
| **Protocole**    | **LACP** ou **Statique**.                                                                                                                                               |
| **Ports**        | Les ports de l'équipement qui en font partie, sur un ou plusieurs membres. Un port fait partie d'un seul agrégat au plus. Un agrégat peut être déclaré avant ses ports. |
| **Mode** et VLAN | Réglés sur l'agrégat, avec les mêmes règles que pour un port.                                                                                                           |
| **Description**  | Facultative.                                                                                                                                                            |

Le mode et les VLAN d'un agrégat s'appliquent à tous ses ports. Un port qui rejoint l'agrégat perd son propre mode et ses VLAN ; un port qui le quitte n'en a plus.

Exemple de la démonstration : `sw-serveurs` a un agrégat `Po1`, en **LACP**, fait des ports `1/1/1` (membre 1) et `2/1/1` (membre 2), en trunk natif 99 avec les VLAN 10, 20, 30, 40 et 50. Dans la colonne **Mode et VLAN**, ces deux ports se lisent `via Po1 : Trunk, natif 99 : 10, 20, 30, 40, 50`.

## Créer un agrégat

Rôle : `editor` ou `admin`.

1. Sur la page de l'équipement, dans la section **Agrégats**, cliquez sur **Nouvel agrégat**.
2. Remplissez le **Nom** et choisissez le **Protocole** (**LACP** par défaut).
3. Cochez ses **Ports**, membre par membre. Un port déjà dans un autre agrégat est grisé, suivi du nom de cet agrégat.
4. Si un port coché a déjà un mode, la fenêtre prévient avant l'enregistrement qu'il perdra son mode et ses VLAN propres.
5. Réglez le **Mode** et les VLAN de l'agrégat, complétez la **Description**, puis cliquez sur **Enregistrer**.

Quand un agrégat d'un autre équipement fait face à des ports de cet équipement qui ne sont dans aucun agrégat, un écart propose de créer directement l'agrégat manquant : voir [Liaisons](liaisons.md).

## Modifier ou supprimer un agrégat

Rôle : `editor` ou `admin`.

- Pour modifier, cliquez sur le bouton **Modifier** de sa ligne dans **Agrégats**, changez ce qu'il faut dans **Modifier l'agrégat**, puis cliquez sur **Enregistrer**. Décocher un port le retire de l'agrégat : il n'a plus ni mode ni VLAN.
- Pour supprimer, cliquez sur le bouton **Supprimer** de sa ligne, puis confirmez dans **Supprimer cet agrégat ?**. Ses ports sont libérés, sans mode ni VLAN : réglez-les ensuite si besoin. Les liaisons de ces ports restent en place.
