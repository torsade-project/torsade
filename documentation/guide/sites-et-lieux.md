<!-- torsade/documentation/guide/sites-et-lieux.md -->

# Sites et lieux

Cette page explique comment déclarer les sites de l'organisation, puis leurs lieux : bâtiments, étages et locaux. Elle décrit aussi ce que montre la page d'un site, et pourquoi certaines suppressions sont refusées.

## Ce qu'est un site, ce qu'est un lieu

Un site est un lieu qui a son propre réseau : le siège, un atelier, un dépôt. Chaque VLAN, chaque sous-réseau d'un site et chaque équipement appartient à un site. Les numéros de VLAN sont uniques au sein d'un site, mais deux sites peuvent avoir chacun leur VLAN 10.

Les lieux disent où se trouvent les choses, à l'intérieur d'un site :

- un bâtiment appartient à un site ;
- un étage appartient à un bâtiment ;
- un local appartient à un étage. Un local peut être marqué « local technique ».

Un équipement est rangé dans un local, ou rattaché au site sans local : un petit site n'a souvent ni plan ni local technique. Une prise murale est placée sur un étage. Les lieux n'ont pas de statut : ce ne sont pas des objets du réseau.

La démonstration contient trois sites : Siège (code `SIE`, numéro 10), Atelier (code `ATE`, numéro 20) et Dépôt (code `DEP`, numéro 30, prévu).

## Consulter la liste des sites

Rôle : `reader`, `editor` ou `admin`.

1. Dans le menu, choisissez **Sites**.
2. Le tableau donne pour chaque site : **Nom**, **Code**, **Numéro**, **Description**, le nombre de **VLAN** et le **Statut**.
3. Pour ne voir qu'un statut, choisissez-le dans la liste **Statut** au-dessus du tableau ; **Tous les statuts** les montre tous.

Le nom d'un site ouvre sa page. Le nombre de VLAN ouvre la liste des VLAN de ce site.

## Créer un site

Rôle : `editor` ou `admin`.

1. Dans **Sites**, choisissez **Nouveau site**.
2. Remplissez la fenêtre **Nouveau site**, puis choisissez **Enregistrer**.

| Champ           | Contenu                                                                                                                                                                               |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Nom**         | Obligatoire, 100 caractères au plus. Deux sites ne portent pas le même nom.                                                                                                           |
| **Code**        | Facultatif. Un code court, 10 caractères au plus : lettres sans accent, chiffres et tirets. Il est enregistré en majuscules (`sie` devient `SIE`). Deux sites n'ont pas le même code. |
| **Numéro**      | Facultatif. Un nombre de 0 à 65535, unique : c'est le rang du site dans la règle d'adressage.                                                                                         |
| **Statut**      | **Prévu**, **Actif** ou **Retiré**. **Actif** par défaut.                                                                                                                             |
| **Description** | Facultative, 1 000 caractères au plus.                                                                                                                                                |

Le code désigne le site là où son nom serait trop long, son nom servant à défaut : devant les VLAN d'un autre site dans le résumé d'un port (`ATE 60`), ou dans le nom du fichier d'un schéma exporté. L'aide du champ le rappelle, avec deux exemples de la démonstration : « Trunk : 10 · ATE 60 » et « schema-physique-SIE-2026-10-18.svg ». Il peut aussi servir de repère dans vos noms d'équipements ; Torsade ne les compose pas à votre place.

Le statut **Prévu** sert à concevoir un site qui n'existe pas encore ; **Retiré**, à documenter un site fermé. Voir [les notions](notions.md).

### Le numéro et la règle d'adressage

Quand l'organisation a une règle d'adressage, le numéro du site donne le bloc d'adresses attendu pour lui. Dans la démonstration, le site Atelier, numéro 20, a pour bloc attendu `10.20.0.0/16`.

Pendant la saisie du numéro, la fenêtre affiche **Bloc attendu par la règle :** suivi du bloc, et précise s'il est **déjà déclaré pour ce site.** S'il ne l'est pas, la case **Créer ce bloc** est proposée, cochée d'avance : le bloc est créé avec le site, et prend son statut. Si le bloc ne peut pas être créé (il chevauche un bloc existant, par exemple), rien n'est enregistré, ni le site ni le bloc.

Un numéro que la règle n'atteint pas est accepté. La fenêtre prévient alors que le site sera montré comme un écart. Sans règle d'adressage, ou avec une règle sans niveau de site, le numéro est enregistré mais ne sert à rien pour l'instant : aucun bloc n'est attendu (voir [Une règle sans niveau de site](norme.md#une-règle-sans-niveau-de-site)).

Un numéro déjà pris est refusé, avec le nom du site qui l'a : « Le site Siège porte déjà le numéro 10. »

La règle d'adressage et les blocs sont décrits dans [la norme](norme.md).

## Modifier un site

Rôle : `editor` ou `admin`.

1. Dans **Sites**, choisissez **Modifier** sur la ligne du site. Depuis la page du site, choisissez le bouton **Modifier**.
2. La fenêtre **Modifier le site** reprend les champs de la création. Choisissez **Enregistrer**.

Changer le numéro propose à nouveau **Créer ce bloc** si le bloc attendu pour le nouveau numéro n'est pas encore déclaré. Le retour se fait là où la modification a commencé : la liste ou la page du site.

## Lire la page d'un site

Rôle : `reader`, `editor` ou `admin`.

Dans **Sites**, choisissez le nom du site. La page **Site Siège**, par exemple, montre de haut en bas :

| Partie                     | Contenu                                                                                                                                                  |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| En-tête                    | Le nom, le code, le **N°**, le statut et la description du site.                                                                                         |
| Boutons                    | **Schéma physique** et **Schéma logique**, pour tous. Pour `editor` et `admin` : **Appliquer la norme**, **Modifier**, **Supprimer**.                    |
| Écart du site              | Si le numéro dépasse la règle, ou si le bloc attendu n'est pas déclaré. Dans ce cas, le bouton **Créer ce bloc** le déclare en un clic.                  |
| **Blocs d'adressage**      | Ce qu'est le bloc d'un site, puis les blocs du site, avec **Bloc**, **Espace occupé** et **Statut**. Bouton **Nouveau bloc**.                            |
| **VLAN**                   | Les VLAN du site, avec **VID**, **Nom**, **Catalogue** (l'entrée suivie, ou **Hors catalogue**), **Sous-réseau** et **Statut**. Bouton **Nouveau VLAN**. |
| **Sous-réseaux sans VLAN** | Liens d'opérateur, tunnels, loopbacks : **Sous-réseau**, **Type**, **Passerelle**, **Statut**. Bouton **Nouveau sous-réseau**.                           |
| **Lieux**                  | Les bâtiments, leurs étages et leurs locaux, avec les panneaux de brassage de chaque local. Bouton **Nouveau bâtiment**.                                 |
| **Équipements**            | Les équipements du site. Boutons **Créer des équipements depuis les noms d'hôte** et **Nouvel équipement**.                                              |
| **Écarts**                 | Les VLAN et sous-réseaux du site qui s'écartent de la norme, chacun avec sa raison.                                                                      |

Les boutons de création n'apparaissent qu'aux rôles `editor` et `admin`.

Le détail de chaque partie est ailleurs :

- blocs, règle d'adressage et **Appliquer la norme** : [la norme](norme.md) ;
- VLAN et sous-réseaux : [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md) ;
- équipements : [équipements et ports](equipements-et-ports.md) ;
- prises et panneaux de brassage : [le câblage](cablage.md) ;
- schémas : [les schémas](schemas.md) ;
- écarts : [les écarts](ecarts.md).

## Supprimer un site

Rôle : `editor` ou `admin`.

1. Dans **Sites**, choisissez **Supprimer** sur la ligne du site, ou le bouton **Supprimer** de sa page.
2. Confirmez dans la fenêtre **Supprimer ce site ?** avec **Supprimer**.

Seul un site vide se supprime. La suppression est refusée tant que le site a encore, dans cet ordre de vérification :

1. des VLAN ;
2. des sous-réseaux ;
3. des blocs d'adressage ;
4. des bâtiments ;
5. des équipements.

Le message dit quoi faire d'abord, par exemple : « Des bâtiments sont encore rattachés à ce site (1) : supprimez-les d'abord. » Pour un sous-réseau ou un équipement, il propose aussi de changer son site.

Pourquoi : rien ne disparaît avec un site sans que quelqu'un l'ait décidé. Voir [les notions](notions.md).

## Ajouter un bâtiment

Rôle : `editor` ou `admin`.

1. Sur la page du site, dans **Lieux**, choisissez **Nouveau bâtiment**.
2. Remplissez la fenêtre, puis choisissez **Enregistrer**.

| Champ           | Contenu                                                                                                                                                        |
| --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Nom**         | Obligatoire, 100 caractères au plus, unique dans le site.                                                                                                      |
| **Code**        | Facultatif. Un code court pour les étiquettes, 10 caractères au plus : lettres sans accent, chiffres et tirets, enregistré en majuscules. Unique dans le site. |
| **Description** | Facultative.                                                                                                                                                   |

Exemple de la démonstration : le bâtiment « Bâtiment principal », code `A`, du site Siège.

Les bâtiments sont classés par nom.

## Ajouter un étage

Rôle : `editor` ou `admin`.

1. Dans **Lieux**, sur la ligne du bâtiment, choisissez **Nouvel étage**.
2. Remplissez la fenêtre, puis choisissez **Enregistrer**.

| Champ           | Contenu                                                                                                                            |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| **Niveau**      | Obligatoire, de -9 à 99 : 0 pour le rez-de-chaussée, -1 pour un sous-sol. Deux étages d'un même bâtiment n'ont pas le même niveau. |
| **Nom**         | Obligatoire, 100 caractères au plus, unique dans le bâtiment.                                                                      |
| **Description** | Facultative.                                                                                                                       |

Le niveau range les étages dans l'ordre. Un niveau déjà pris est refusé avec le nom de l'étage qui l'a, par exemple : « L'étage Rez-de-chaussée de ce bâtiment est déjà au niveau 0. »

Chaque étage affiche son niveau et son nombre de prises. Les prises se créent depuis l'étage, avec **Ajouter une prise** ou **Ajouter des prises en série** : voir [le câblage](cablage.md).

## Ajouter un local

Rôle : `editor` ou `admin`.

1. Dans **Lieux**, sur la ligne de l'étage, choisissez **Nouveau local**.
2. Remplissez la fenêtre, puis choisissez **Enregistrer**.

| Champ               | Contenu                                                                              |
| ------------------- | ------------------------------------------------------------------------------------ |
| **Nom**             | Obligatoire, 100 caractères au plus, unique dans l'étage.                            |
| **Local technique** | À cocher pour un local qui abrite une baie, des switchs ou des panneaux de brassage. |
| **Description**     | Facultative.                                                                         |

Un local technique porte l'étiquette **Technique** partout où il apparaît. Ce n'est qu'une indication : un équipement peut être rangé dans n'importe quel local, technique ou non.

Chaque local affiche son nombre d'équipements et la liste de ses panneaux de brassage. Depuis sa ligne, **Ajouter un panneau** et **Ajouter un équipement** créent un panneau ou un équipement directement dans ce local : voir [le câblage](cablage.md) et [équipements et ports](equipements-et-ports.md).

Exemple de la démonstration, au site Siège : au rez-de-chaussée du Bâtiment principal, l'« Accueil » et le « Local technique », marqué technique, qui abrite la baie du cœur de réseau.

## Lire le chemin d'un lieu

Rôle : `reader`, `editor` ou `admin`.

Un local s'écrit toujours avec son chemin, du bâtiment au local, séparés par `›` :

`Bâtiment › Étage › Local`

Par exemple : `Bâtiment principal › Rez-de-chaussée › Local technique`, suivi de l'étiquette **Technique**. C'est ainsi que les pages des équipements et des panneaux de brassage disent où ils se trouvent. Un étage s'écrit de même, sans le local : `Bâtiment principal › 1er étage`.

## Modifier un bâtiment, un étage ou un local

Rôle : `editor` ou `admin`.

1. Dans **Lieux**, choisissez **Modifier** sur la ligne du bâtiment, de l'étage ou du local.
2. La fenêtre **Modifier le bâtiment**, **Modifier l'étage** ou **Modifier le local** reprend les champs de la création. Choisissez **Enregistrer**.

## Déplacer un étage ou un local

Rôle : `editor` ou `admin`.

Un lieu ne change jamais de site. À l'intérieur de son site, en revanche :

- un étage peut changer de bâtiment. Dans **Modifier l'étage**, choisissez un autre **Bâtiment** : l'étage part avec ses locaux et ses prises. Le champ n'apparaît que si le site a plusieurs bâtiments.
- un local peut changer d'étage. Dans **Modifier le local**, choisissez un autre **Étage** : le local part avec les équipements qui y sont rangés. Le champ n'apparaît que si le site a plusieurs étages.

Ces déplacements servent à corriger une erreur de saisie. Ils ne changent pas le site des équipements.

Pourquoi un lieu ne change pas de site : les équipements rangés dans ses locaux changeraient de site sans que personne ne l'ait décidé. Une tentative est refusée, par exemple : « Le bâtiment Halle de production appartient au site Atelier : un lieu ne change jamais de site. »

Les règles d'unicité s'appliquent à la destination : un étage déplacé ne peut pas prendre un niveau ou un nom déjà pris dans son nouveau bâtiment, ni un local un nom déjà pris sur son nouvel étage.

## Supprimer un bâtiment, un étage ou un local

Rôle : `editor` ou `admin`.

1. Dans **Lieux**, choisissez **Supprimer** sur la ligne du lieu.
2. Confirmez avec **Supprimer**.

Seul un lieu vide se supprime :

| Lieu     | Suppression refusée tant qu'il a encore                                                   |
| -------- | ----------------------------------------------------------------------------------------- |
| Bâtiment | des étages                                                                                |
| Étage    | des locaux, puis des prises                                                               |
| Local    | des équipements rangés, des membres d'équipements rangés à part, des panneaux de brassage |

La fenêtre de confirmation d'un local le rappelle : « … sera supprimé. Un local où des équipements, des membres d'équipement ou des panneaux de brassage sont encore rangés ne peut pas être supprimé. » Le refus nomme d'un coup chaque sorte d'objet encore présente, avec son nombre, par exemple : « Ce local contient encore 2 équipements et 1 panneau de brassage : videz-le d'abord. » Pour un bâtiment ou un étage, le message dit quoi supprimer d'abord. Un équipement se range dans un autre local depuis sa propre page : voir [équipements et ports](equipements-et-ports.md).

Pourquoi : un équipement ne perd jamais sa place sans que quelqu'un l'ait décidé.
