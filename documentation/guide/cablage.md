<!-- torsade/documentation/guide/cablage.md -->

# Câblage : panneaux, prises et brassage

Cette page couvre le câblage d'un site : les panneaux de brassage et leurs ports, les prises murales, le câble capillaire qui relie une prise à un port de panneau, les cordons de brassage qui relient, dans la baie, un port de panneau à un port d'équipement, les cordons de prise qui branchent un équipement sur une prise, côté bureau, le chemin d'une prise jusqu'au VLAN qu'elle reçoit et le chemin câblé entre deux ports. Elle dit aussi où brancher une adresse sur une prise et ce que Torsade signale quand le câblage ne colle pas avec le reste.

Les exemples viennent des données de démonstration, fictives : le site Siège, son panneau PB-B au 1er étage, le switch `sw-etage-1`, les prises B.01 à B.16, et la borne `ap-etage-1`, branchée sur la prise B.13.

## Ce qu'est la chaîne de câblage

Torsade décrit le chemin d'un équipement du bureau jusqu'au switch de la baie en sept maillons :

| Maillon                    | Ce que c'est                                                                   | Où il se saisit                                         |
| -------------------------- | ------------------------------------------------------------------------------ | ------------------------------------------------------- |
| Port d'équipement (bureau) | Le port de l'équipement branché sur la prise, par exemple `ap-etage-1` `eth0`. | Sur la page de l'équipement.                            |
| Cordon de prise            | Le cordon mobile, côté bureau, de la prise au port de l'équipement.            | Sur la page **Prises**, ou sur la page de l'équipement. |
| Prise murale               | La prise dans le bureau, par exemple B.13.                                     | Sur la page du site, à son étage.                       |
| Câble capillaire           | Le câble fixe, dans le mur, de la prise jusqu'au panneau.                      | Avec la prise, ou depuis la page du panneau.            |
| Port de panneau            | Le port du panneau de brassage où arrive le capillaire, par exemple PB-B 13.   | Créé avec le panneau.                                   |
| Cordon de brassage         | Le cordon mobile, dans la baie, du port de panneau au port d'équipement.       | Sur la page du panneau.                                 |
| Port d'équipement (baie)   | Le port du switch au bout du cordon, par exemple `sw-etage-1` `1/0/21`.        | Sur la page de l'équipement.                            |

Le capillaire se saisit une fois, à la pose. Le brassage, lui, change souvent : c'est pourquoi la page d'un panneau ne manipule que les cordons, et que les prises s'y relient à part. Le cordon de prise suit l'équipement du bureau : il se branche quand une borne ou un téléphone arrive, il se débranche quand ils partent.

Règles communes :

- une prise n'a qu'un port de panneau, et un port de panneau qu'une prise ;
- un port de panneau n'a qu'un cordon de brassage, et une prise qu'un cordon de prise ;
- un port d'équipement n'a qu'un connecteur : un cordon de brassage ou un cordon de prise, jamais les deux ;
- une prise, son panneau et les équipements brassés ou branchés sont du même site : un cordon ne relie pas deux sites.

## Créer un panneau de brassage

Rôle : `editor` ou `admin`.

Un panneau est toujours rangé dans un local. Il se crée depuis la page du site, dans la section **Lieux** :

1. Sur la ligne du local, cliquez **Ajouter un panneau**. La fenêtre **Nouveau panneau** s'ouvre.
2. Remplissez les champs :
    - **Nom** : tel qu'écrit sur le panneau, par exemple PB-A ; 64 caractères au plus ; unique dans le local, sans tenir compte des majuscules ;
    - **Nombre de ports** : de 1 à 96 ;
    - **Connecteur** : **RJ45**, **Fibre LC**, **Fibre SC** ou **Autre** ;
    - **Catégorie** : facultative, par exemple 6A ; 16 caractères au plus ;
    - **Statut** : **Prévu**, **Actif** ou **Retiré** ;
    - **Description** : facultative.
3. Cliquez **Enregistrer**.

Les ports sont créés avec le panneau et numérotés de 1 au nombre de ports. Un deuxième panneau du même nom dans le même local est refusé : « Le local a déjà un panneau nommé PB-A. »

Sur la page du site, chaque panneau apparaît sous son local, avec son connecteur, sa catégorie et son taux d'occupation (« 12 ports occupés sur 24 »). Un port est occupé dès qu'il a une prise ou un cordon. Le nom du panneau mène à sa page.

## Modifier ou supprimer un panneau

Rôle : `editor` ou `admin`.

Un panneau se modifie depuis la page du site (icône **Modifier** sur sa ligne) ou depuis sa propre page (bouton **Modifier**). La fenêtre **Modifier le panneau** reprend les champs de la création ; le local, lui, ne change pas.

Changer le nombre de ports :

- augmenter ajoute des ports après le dernier ;
- réduire retire les derniers ports, sauf si l'un d'eux a une prise ou un cordon. Le refus nomme ces ports, dix au plus : « Les ports 23, 24 ont une prise ou un cordon : ils ne sont pas retirés. Détachez les prises et retirez les cordons d'abord, ou gardez plus de ports. »

Supprimer un panneau supprime aussi ses ports. La suppression se fait par **Supprimer**, sur la page du site ou celle du panneau, après confirmation (**Supprimer ce panneau ?**). Elle est refusée tant qu'un de ses ports a une prise ou un cordon, et le message nomme les ports en cause. Détachez d'abord les prises et débrassez les ports.

## Lire la page d'un panneau

Rôle : `reader`, `editor` ou `admin`.

La page d'un panneau s'ouvre depuis son nom, sur la page du site, ou depuis le chemin d'une prise. Elle montre en tête le nom du panneau, son statut, puis quatre repères : **Site**, **Lieu**, **Connecteur** (avec la catégorie) et **Ports** (le nombre de ports occupés).

### Le panneau dessiné

La partie **Ports du panneau** dessine le panneau comme on le voit dans la baie : une case par port, numérotée, 24 par ligne. La forme de chaque case dit l'état de son port :

| Case                  | Le port a…                                                          |
| --------------------- | ------------------------------------------------------------------- |
| **Brassé**            | une prise et un cordon.                                             |
| **Prise sans cordon** | une prise, mais aucun cordon.                                       |
| **Cordon sans prise** | un cordon, mais aucune prise : c'est le cas d'un panneau de rocade. |
| **Libre**             | ni prise ni cordon.                                                 |

Un triangle sous une case signale un **Écart** sur ce port. La légende, au-dessus du dessin, montre chaque forme telle qu'elle est dessinée. Au survol, une case rappelle le numéro du port, son état, la prise et le port d'équipement au bout du cordon. Sur un écran étroit, le panneau défile dans son cadre, à la souris comme au clavier.

Dans la démonstration, le panneau PB-A du Siège a ses six premiers ports en **Prise sans cordon** (prises R.01 à R.06, pas encore brassées), et le panneau PB-FO, en fibre, n'a que des **Cordon sans prise** : ce sont des rocades vers les étages.

### Le tableau des ports

Sous le dessin, le tableau **Ports** donne les mêmes informations, une ligne par port :

| Colonne          | Contenu                                                                                                 |
| ---------------- | ------------------------------------------------------------------------------------------------------- |
| **Port**         | Le numéro du port.                                                                                      |
| **Prise**        | Le libellé de la prise et son étage, ou **Aucune prise**.                                               |
| **Cordon**       | La couleur du cordon, **Brassé** sans couleur, ou **Non brassé**.                                       |
| **En face**      | L'équipement et le port au bout du cordon ; le nom de l'équipement mène à sa page.                      |
| **Mode et VLAN** | Ce que porte le port d'équipement : « Accès 10, voix 30 », ou **Aucun mode**.                           |
| **Écarts**       | Les écarts de câblage de ce port, avec leur raison.                                                     |
| **Actions**      | Pour un `editor` ou un `admin` : modifier le cordon, brasser ou débrasser, relier ou détacher la prise. |

Un `reader` voit la page sans les cases à cocher ni les actions.

## Ajouter une prise

Rôle : `editor` ou `admin`.

Une prise est rattachée à un étage. Elle se crée depuis la page du site, dans la section **Lieux** :

1. Sur la ligne de l'étage, cliquez **Ajouter une prise**. La fenêtre **Nouvelle prise** s'ouvre.
2. Remplissez les champs :
    - **Libellé** : tel qu'écrit sur la prise, par exemple B.15 ; 32 caractères au plus ; unique dans l'étage, sans tenir compte des majuscules ;
    - **Emplacement** : facultatif, par exemple « bureau 12, côté fenêtre » ; 100 caractères au plus ;
    - **Panneau de brassage** et **Port** : le port où arrive son câble capillaire, ou **Non reliée à un panneau**. Seuls les panneaux du site sont proposés, et seuls leurs ports sans prise ; au choix d'un panneau, son premier port libre est proposé d'office ;
    - **Statut** : **Prévue**, **Active** ou **Retirée** ;
    - **Description** : facultative.
3. Cliquez **Enregistrer**.

Un libellé déjà pris sur l'étage est refusé : « L'étage a déjà une prise libellée B.15. » Un port de panneau qui a déjà une prise aussi : « Le port PB-B 15 a déjà la prise B.15. »

Le nombre de prises de chaque étage s'affiche sur sa ligne, dans la section **Lieux**.

## Ajouter des prises en série

Rôle : `editor` ou `admin`.

Une série crée d'un coup les prises d'un étage, et peut les relier dans l'ordre aux ports d'un panneau. C'est la saisie habituelle à la pose d'un étage : prises B.01 à B.16 sur les ports 1 à 16 de PB-B.

1. Sur la ligne de l'étage, dans la section **Lieux** de la page du site, cliquez **Ajouter des prises en série**.
2. Composez les libellés :
    - **Début du libellé** : le préfixe, par exemple `B.` ;
    - **Premier numéro** et **Dernier numéro** : de 0 à 99999 ; le dernier ne peut pas être plus petit que le premier ;
    - **Chiffres** : le nombre minimal de chiffres du numéro, de 1 à 6, complété par des zéros devant (`B.` et 2 chiffres donnent B.01, B.02…) ;
    - **Fin du libellé** : un suffixe facultatif.
3. Pour relier les prises, choisissez un **Panneau de brassage** du site et son **Premier port** : la première prise va sur ce port, la suivante sur le port d'après, et ainsi de suite. Laissez **Non reliée à un panneau** pour poser les prises sans les relier.
4. Choisissez le **Statut** de toutes les prises de la série.
5. Vérifiez l'aperçu **Prises créées**, qui montre chaque libellé et son port pendant la saisie, puis cliquez **Enregistrer**.

La série est créée en entier, ou pas du tout. Elle est refusée, avec un message qui nomme les éléments en cause (dix au plus) :

- au-delà de 200 prises : « Une série compte 200 prises au plus. » ;
- si un libellé dépasse 32 caractères ;
- si un libellé existe déjà sur l'étage : « L'étage a déjà des prises libellées B.03, B.04. » ;
- si la série dépasse le dernier port du panneau : « La série finirait sur le port 30 de PB-B, au-delà de son dernier port, le 24. » ;
- si un port de la plage a déjà une prise : « Les ports 5, 6 de PB-B ont déjà une prise. » Aucun port n'est sauté.

À la réouverture de la fenêtre, la série précédente reste remplie : l'étage suivant se libelle souvent comme le dernier. Le panneau, lui, est à choisir de nouveau.

## Consulter la liste des prises

Rôle : `reader`, `editor` ou `admin`.

Dans le menu, ouvrez **Réseau physique**, puis **Prises**. La page liste toutes les prises, tous sites confondus, triées par site, bâtiment, étage, puis libellé. Le tri des libellés est naturel : B.2 vient avant B.10.

| Colonne         | Contenu                                                                                                                                 |
| --------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| **Prise**       | Le libellé, avec le site et l'étage en dessous.                                                                                         |
| **Emplacement** | L'emplacement saisi.                                                                                                                    |
| **Chemin**      | Le chemin de la prise jusqu'au port d'équipement et à sa configuration (voir [Lire le chemin d'une prise](#lire-le-chemin-dune-prise)). |
| **Adresses**    | Les adresses branchées sur la prise, chacune menant à la page de son sous-réseau, avec le nom d'hôte.                                   |
| **Statut**      | **Prévue**, **Active** ou **Retirée**.                                                                                                  |
| **Écarts**      | Les écarts de câblage de la prise.                                                                                                      |
| **Actions**     | Pour un `editor` ou un `admin` : **Modifier** et **Supprimer**.                                                                         |

Les filtres, au-dessus du tableau :

- **Site**, puis **Bâtiment**, puis **Étage** : choisir un site restreint la liste des bâtiments, et un bâtiment celle des étages ;
- **Statut** ;
- **Rechercher** : cherche le texte saisi dans le libellé et dans l'emplacement ;
- **Avec écart seulement** : ne garde que les prises qui ont un écart de câblage.

Les filtres restent dans l'adresse de la page : une liste filtrée se garde en favori ou se partage. Le compte affiché dit combien de prises les filtres gardent sur le total.

Sans aucune prise, la page invite à aller sur les sites : les prises s'ajoutent à un étage depuis la page de son site.

## Modifier ou supprimer une prise

Rôle : `editor` ou `admin`.

Depuis la page **Prises**, l'icône **Modifier** d'une ligne ouvre la fenêtre **Modifier la prise**, avec les mêmes champs qu'à la création. La prise reste sur son étage. Changer son **Panneau de brassage** ou son **Port** déplace son capillaire ; choisir **Non reliée à un panneau** le retire. Seuls les panneaux du site de la prise sont proposés.

L'icône **Supprimer** demande confirmation (**Supprimer cette prise ?**). La suppression est refusée tant qu'une adresse est branchée sur la prise : « Une adresse est encore raccordée à cette prise : débranchez-la d'abord. » Le port de panneau de la prise, lui, reste, libre.

## Relier une prise existante à un port de panneau

Rôle : `editor` ou `admin`.

Une prise posée sans panneau, comme R.07 et R.08 au rez-de-chaussée du Siège dans la démonstration, se relie ensuite de deux façons.

Depuis la page du panneau :

1. Sur la ligne d'un port sans prise, cliquez l'icône **Relier une prise**.
2. Dans la fenêtre, choisissez la **Prise**. Sont proposées les prises de tous les étages du site qui ne sont reliées à aucun port, groupées par bâtiment et étage. Quand il n'en reste aucune, la fenêtre le dit : « Toutes les prises de ce site sont déjà reliées. Les prises s'ajoutent à un étage depuis la page de son site. »
3. Cliquez **Enregistrer**.

Depuis la page **Prises**, en modifiant la prise : tous les panneaux du site sont proposés, quel que soit leur étage.

Pour détacher une prise de son port, cliquez l'icône **Détacher la prise** sur la ligne du port, puis confirmez (**Détacher cette prise ?**). La prise reste à son étage, le port redevient libre. Le cordon du port, s'il y en a un, n'est pas touché.

Une prise déjà reliée à un autre port est refusée : « La prise B.15 est déjà reliée au port PB-B 15 : détachez-la d'abord. » Une prise et un panneau de deux sites aussi.

## Brasser un port

Rôle : `editor` ou `admin`.

Brasser, c'est poser un cordon entre un port du panneau et un port d'un équipement du même site. Depuis la page du panneau :

1. Sur la ligne d'un port non brassé, cliquez l'icône **Brasser…**. La fenêtre **Brasser le port PB-B 13** s'ouvre.
2. Choisissez l'**Équipement**. Seuls les équipements du site du panneau sont proposés, par nom, avec leur local.
3. Choisissez le **Port**. Ne sont proposés que les ports sans cordon, de panneau ou de prise : un port branché sur une prise ne se brasse pas. Un port relié à un autre équipement est suivi de l'équipement et du port en face (voir [Brasser le port d'une liaison](#brasser-le-port-dune-liaison)).
4. Facultatif : une **Couleur** (32 caractères au plus) et une **Description** (255 caractères au plus). La couleur saisie au cordon précédent est gardée : les cordons d'un panneau sont souvent d'une seule couleur.
5. Cliquez **Enregistrer**.

Les ports de l'équipement sont rangés dans son ordre : membre, puis place sur le membre.

Un cordon ne relie pas deux sites. La fenêtre ne propose que les équipements du site, et Torsade refuse aussi un équipement d'un autre site : « L'équipement sw-atelier-01 est sur le site Atelier, le panneau sur le site Siège : un cordon ne relie pas deux sites. » Entre deux locaux d'un même site, en revanche, le cordon est accepté et signalé comme écart.

Un port branché sur une prise est refusé, même s'il était proposé à l'ouverture de la fenêtre : « sw-salle-reunion 8 est branché sur la prise B.15 : un port n'a qu'un connecteur, débranchez-le d'abord. »

## Brasser plusieurs ports en série

Rôle : `editor` ou `admin`.

Plusieurs ports consécutifs du panneau se brassent d'un coup sur autant de ports d'un équipement. Par exemple, les ports 1 à 12 de PB-B sur `1/0/1` à `1/0/12` de `sw-etage-1`.

1. Dans le tableau **Ports** de la page du panneau, cochez les ports à brasser. La barre au-dessus du tableau compte les ports sélectionnés ; elle reste visible quand le tableau défile.
2. Cliquez **Brasser en série**. Le bouton reste grisé, avec une explication, si les ports cochés ne se suivent pas ou si l'un d'eux est déjà brassé.
3. Dans la fenêtre **Brasser 12 ports en série**, choisissez l'**Équipement** et son **Premier port**.
4. L'aperçu **Cordons posés** montre chaque paire : « PB-B 1 → 1/0/1 », « PB-B 2 → 1/0/2 »…
5. Ajoutez au besoin une **Couleur** et une **Description**, communes à tous les cordons, puis cliquez **Enregistrer**.

Les ports de l'équipement sont pris dans son ordre, membre puis place, à partir du premier port choisi. La série est posée en entier, ou pas du tout :

- si un port de l'équipement, dans la plage, est déjà brassé, la série est refusée, et ce port n'est pas sauté : « sw-etage-1 1/0/5 est brassé vers PB-B 5 (B.05) : débrassez-le d'abord. » ;
- si l'équipement n'a pas assez de ports après le premier : « À partir de 1/0/20, sw-etage-1 n'a pas assez de ports pour 12 cordons. »

La fenêtre signale ces problèmes avant l'envoi, avec les mêmes mots, et grise **Enregistrer**. **Vider la sélection** décoche tous les ports.

Chaque cordon posé a sa propre entrée dans le journal.

## Brasser le port d'une liaison

Rôle : `editor` ou `admin`.

Un port d'équipement relié par une liaison à un autre équipement se brasse comme les autres. Dans la liste de la fenêtre, il est suivi de l'équipement et du port en face, comme « 1/1/3 ↔ sw-etage-1 › 1/1/1 » pour le port de remontée de `sw-coeur-01`.

- Vers un port de panneau sans prise, c'est une rocade, comme les fibres du panneau PB-FO du Siège, brassées vers les ports de remontée des switchs de cœur : `sw-coeur-01` `1/1/3` est brassé sur PB-FO 1.
- Vers une prise, la liaison passe par cette prise. Quand l'équipement en face y est branché, le câblage est le chemin physique de la liaison (voir [Le chemin câblé](#le-chemin-câblé)) : dans la démonstration, `sw-etage-1` `1/0/21` est brassé vers B.13, où est branchée la borne `ap-etage-1`, à laquelle il est relié. Quand rien n'est branché sur la prise, Torsade le signale, et propose de brancher le port en face (voir [Repérer les écarts de câblage](#repérer-les-écarts-de-câblage)).

## Modifier un cordon

Rôle : `editor` ou `admin`.

Sur la ligne d'un port brassé, cliquez l'icône **Modifier le cordon**. La fenêtre rappelle le **Port du panneau** et le **Port de l'équipement**, et permet de changer la **Couleur** et la **Description**.

Les deux bouts d'un cordon ne changent pas. Pour passer un cordon sur un autre port, débrassez le port, puis brassez-le de nouveau. Un cordon débrassé entre-temps par quelqu'un d'autre est signalé : « Ce cordon a été débrassé entre-temps : rechargez la page. »

## Débrasser un ou plusieurs ports

Rôle : `editor` ou `admin`.

Débrasser retire le cordon. Les deux ports restent ; la prise reste reliée au port de panneau.

- Un port : cliquez l'icône **Débrasser** sur sa ligne, puis confirmez (**Débrasser ce port ?**).
- Plusieurs ports, consécutifs ou non : cochez-les, cliquez **Débrasser** dans la barre au-dessus du tableau, puis confirmez (**Débrasser les ports cochés ?**). La confirmation nomme les cordons retirés par leurs deux bouts, dix au plus (« PB-B 1 ↔ sw-etage-1 › 1/0/1 »). Les ports cochés sans cordon sont laissés tels quels.

Chaque cordon retiré a sa propre entrée dans le journal.

## Débrasser tous les ports d'un équipement

Rôle : `editor` ou `admin`.

Sur la page d'un équipement, au-dessus de ses ports, le bouton **Débrasser tous ses ports** retire d'un coup tous ses cordons de brassage, après confirmation (**Débrasser tous les ports de sw-etage-1 ?**). La confirmation nomme les cordons, dix au plus. Le bouton n'apparaît que lorsqu'au moins un port est brassé.

C'est le passage obligé pour changer un équipement de site. Un cordon ne relie pas deux sites : tant qu'un de ses ports est brassé ou branché sur une prise, un équipement ne change pas de site. Le refus, sur le champ **Site** de la modification de l'équipement, nomme les cordons, dix au plus, puis « et N de plus » : « 1/0/1 est brassé vers PB-B 1 (B.01), 1/0/2 est brassé vers PB-B 2 (B.02) : débrassez-les avant de déplacer l'équipement vers un autre site. » Pour un équipement brassé et branché, le message les nomme tous et finit par « débrassez-les et débranchez-les avant de déplacer l'équipement vers un autre site. »

Une liaison, elle, peut relier deux sites : elle ne bloque pas le changement de site.

De même, un port, un membre ou un équipement brassé ou branché ne se supprime pas tant que le cordon existe.

## Brancher un équipement sur une prise

Rôle : `editor` ou `admin`.

Un cordon de prise branche le port d'un équipement du bureau sur une prise murale de son site : une borne Wi-Fi, un petit switch de salle de réunion, un téléphone. Il se pose de deux façons.

Depuis la page **Prises**, sur la ligne d'une prise où rien n'est branché :

1. Cliquez l'icône **Brancher un équipement**. La fenêtre **Brancher un équipement** s'ouvre.
2. Choisissez l'**Équipement**, parmi ceux du site de la prise qui ne sont pas retirés.
3. Choisissez le **Port**. Ne sont proposés que les ports sans cordon, de panneau ou de prise. Quand aucun équipement du site n'en a, la fenêtre le dit : « Aucun équipement de ce site ne peut être branché. »
4. Facultatif : une **Couleur** (32 caractères au plus) et une **Description** (255 caractères au plus).
5. Cliquez **Brancher**.

Depuis la page de l'équipement, sur la ligne d'un port sans cordon :

1. Cliquez l'icône **Brancher sur une prise**. La fenêtre **Brancher sur une prise** s'ouvre.
2. Choisissez la **Prise**, parmi les prises libres du site, groupées par étage. Quand il n'en reste aucune : « Aucune prise de ce site n'est libre : un équipement est déjà branché sur chacune, ou elles sont retirées. »
3. Ajoutez au besoin une **Couleur** et une **Description**, puis cliquez **Brancher**.

Le branchement est refusé, avec un message qui nomme ce qui gêne :

- une prise où un équipement est déjà branché, ou un port déjà branché sur une autre prise : « sw-salle-reunion 8 est déjà branché sur la prise B.15 : débranchez-le d'abord. » ;
- un port brassé vers un panneau : « sw-etage-1 1/0/21 est brassé vers PB-B 13 : un port n'a qu'un connecteur, débrassez-le d'abord. » ;
- une prise ou un équipement retirés : « La prise B.13 est retirée : rien n'y est branché. » ;
- un équipement d'un autre site : un cordon ne relie pas deux sites.

Une fois branché, l'équipement apparaît en tête du chemin de la prise (« Branché : ap-etage-1 eth0 › B.13 › … »). Sur la page de l'équipement, la colonne **Liaison et cordon** de son port dit « branché sur B.13 », qui mène à la prise dans la page **Prises**, filtrée sur son étage.

Dans la démonstration, cinq équipements sont branchés : `ap-etage-1` sur B.13, `sw-salle-reunion` sur B.15, `ap-etage-2` sur C.10, et, à l'Atelier, `ap-atelier-1` sur H.03 et `ap-atelier-2` sur H.04.

## Modifier ou débrancher un cordon de prise

Rôle : `editor` ou `admin`.

Sur la page **Prises**, la ligne d'une prise où un équipement est branché porte l'icône **Modifier le cordon de prise**. La fenêtre **Modifier le cordon de prise** rappelle la **Prise** et le **Port de l'équipement**, et permet de changer la **Couleur** et la **Description**. Son bouton **Débrancher** retire le cordon, après confirmation (**Débrancher cet équipement ?**).

Sur la page d'un équipement :

- un port : l'icône **Débrancher** sur sa ligne, puis confirmez (**Débrancher ce port ?**) ;
- tous ses ports : le bouton **Débrancher tous ses ports**, au-dessus des ports, puis confirmez (**Débrancher tous les ports de sw-salle-reunion ?**). Le bouton n'apparaît que lorsqu'au moins un port est branché.

Les deux bouts d'un cordon de prise ne changent pas : pour brancher l'équipement ailleurs, débranchez-le, puis branchez-le de nouveau. Débrancher ne touche ni la prise ni le port, et chaque cordon retiré a sa propre entrée dans le journal.

Une prise où un équipement est branché ne se supprime pas tant que le cordon existe (« ap-etage-1 eth0 est branché sur cette prise : débranchez-le d'abord. »), pas plus que le port, le membre ou l'équipement branchés.

## Raccorder une adresse à une prise

Rôle : `editor` ou `admin`.

Une adresse IP peut indiquer la prise où sa machine est branchée. Torsade relie alors l'adresse au chemin de la prise, et peut comparer son VLAN à ce que porte le port au bout.

1. Ouvrez la fenêtre de l'adresse, depuis la page de son sous-réseau ou depuis la page de l'équipement auquel elle est rattachée (voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md)).
2. Dans le champ **Prise**, tapez une partie du libellé (**Libellé de la prise**). La liste propose au plus 20 prises ; au-delà, elle demande de préciser le libellé.
3. Choisissez la prise, puis enregistrez l'adresse.

Seules les prises du site du sous-réseau sont proposées, et Torsade refuse une prise d'un autre site : « La prise M.01 est sur le site Atelier : choisissez une prise du site du sous-réseau, … » Pour un sous-réseau sans site, toutes les prises sont proposées.

Pour débrancher l'adresse, cliquez **Débrancher** dans le champ **Prise**, puis enregistrez.

Une prise accepte plusieurs adresses : dans la démonstration, la prise B.01 porte un téléphone (`10.10.30.51`) et le poste branché derrière lui (`10.10.10.111`).

## Lire le chemin d'une prise

Rôle : `reader`, `editor` ou `admin`.

Le chemin d'une prise répond à la question de tous les jours : « cette prise, elle est sur quel port, dans quel VLAN ? ». Il tient sur une ligne, ses étapes séparées par « › » :

1. l'équipement branché sur la prise, s'il y en a un, précédé de « Branché : » ;
2. la prise ;
3. le port de panneau où arrive son capillaire ;
4. l'équipement et le port au bout du cordon ;
5. ce que porte ce port : son mode et ses VLAN, ou ceux de son agrégat.

Exemples de la démonstration :

`B.05 › PB-B 5 › sw-etage-1 1/0/5 › Accès 10, voix 30`

`Branché : ap-etage-1 eth0 › B.13 › PB-B 13 › sw-etage-1 1/0/21 › …`

Le port de panneau mène à la page du panneau, chaque équipement à sa page.

Le chemin s'arrête là où rien n'est saisi, et le dit :

| Chemin                           | Ce qui manque                                                                      |
| -------------------------------- | ---------------------------------------------------------------------------------- |
| `B.15 › PB-B 15 › non brassée`   | Aucun cordon sur le port de panneau.                                               |
| `R.07 › non reliée à un panneau` | Aucun capillaire saisi pour la prise.                                              |
| `… › Aucun mode`                 | Le port d'équipement au bout du cordon n'a pas de mode (cas d'un port de serveur). |

Le chemin s'affiche dans la colonne **Chemin** de la page **Prises**, et sous le nom d'hôte de chaque adresse branchée sur une prise, sur la page de son sous-réseau et sur celle de son équipement. Les adresses branchées ne font pas partie de la ligne du chemin : sur la page **Prises**, elles sont dans la colonne **Adresses**.

Sur la page d'un équipement, la colonne **Liaison et cordon** de ses ports montre l'autre sens : « brassé vers B.05 », qui mène à la page du panneau, ou « branché sur B.13 », qui mène à la prise.

## Le chemin câblé

Rôle : `reader`, `editor` ou `admin`.

Deux ports sont câblés l'un à l'autre quand le premier est brassé vers une prise sur laquelle le second est branché : `sw-etage-1` `1/0/21` est brassé vers PB-B 13, dont la prise B.13 porte la borne `ap-etage-1`, branchée par son port `eth0`. Torsade ne stocke pas ce chemin : il le déduit des cordons et du capillaire, à chaque affichage.

Quand une liaison relie ces deux ports, le câblage est son chemin physique. La page **Liaisons** l'affiche sous le support de la liaison : « par PB-B 13 › B.13 », qui mène à la prise ; le lieu du panneau et celui de la prise sont dans l'infobulle. Le schéma physique dessine la liaison en deux traits, du switch au port du panneau, puis du panneau à la borne, la prise en étiquette (voir [Schémas](schemas.md)).

Un équipement branché par son adresse, sans cordon de prise, est câblé lui aussi au port brassé vers sa prise, sans port à lui : c'est le cas de l'imprimante `imp-compta`, dont l'adresse `10.10.10.21` est branchée sur B.16. Derrière une prise où un équipement est branché, seul cet équipement est câblé : un poste branché derrière un téléphone ne l'est pas.

Ce qui est retiré, l'équipement, le panneau ou la prise, coupe le chemin.

## Repérer les écarts de câblage

Rôle : `reader`, `editor` ou `admin`.

Un écart ne refuse rien : il montre un câblage qui ne colle pas avec ce qui est déclaré autour. Torsade en signale sept sortes :

- un cordon entre un panneau et un équipement rangés dans deux locaux différents ;
- deux ports câblés l'un à l'autre sans liaison, comme `sw-atelier-01` `1/0/9` et la borne `ap-atelier-1`, par PB-A 9 › H.03, à l'Atelier ; l'écart propose **Créer la liaison** ;
- une liaison que le câblage contredit : le câblage d'un de ses ports mène ailleurs, comme pour `ap-etage-2`, branchée sur C.10, brassée vers `sw-etage-2` `1/0/16`, mais reliée à `1/0/21` ;
- un câblage encore en place pour une liaison retirée, comme `ap-atelier-2` sur H.04 ;
- un port relié à un autre équipement et brassé vers une prise où rien n'est branché, comme `sw-etage-1` `1/0/22`, relié à `ap-salle-reunion` et brassé vers B.14 ; l'écart propose **Brancher** le port en face sur la prise, quand c'est possible ;
- une adresse active branchée sur une prise non brassée ;
- une adresse, active ou prévue, dont le VLAN n'est pas porté par le port au bout de la prise.

Ils apparaissent dans la colonne **Écarts** de la page d'un panneau (et en triangle sous la case du port), de la page **Prises** (avec le filtre **Avec écart seulement**), de la page d'un équipement et de la page **Liaisons**. La démonstration en garde plusieurs exprès, par exemple la prise C.09, brassée vers un port sans le VLAN de la téléphonie.

Le détail de chaque écart et de sa raison est dans la page [écarts](ecarts.md).
