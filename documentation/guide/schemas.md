<!-- torsade/documentation/guide/schemas.md -->

# Schémas physique et logique

Cette page couvre les deux schémas d'un site : le schéma physique, qui montre les équipements, les liaisons entre eux et le câblage qu'elles traversent, et le schéma logique, qui montre les sous-réseaux, Internet et les équipements qui y sont branchés. Elle dit ce que chacun dessine, comment le lire, comment s'y déplacer, comment placer ses éléments à la main, et comment l'exporter ou l'imprimer.

Un schéma est calculé à chaque affichage à partir de ce qui est déclaré : rien n'y est dessiné à la main. Ajoutez une liaison, une adresse ou un VLAN, et le schéma suit. Seules les positions placées à la main sont gardées. Pourquoi : un schéma dessiné à part finit toujours par diverger de la documentation ; calculé, il ne peut pas mentir sur ce qui est saisi.

## Ouvrir le schéma d'un site

Rôle : `reader`, `editor` ou `admin`.

Deux chemins mènent à un schéma :

- dans le menu, **Réseau physique**, puis **Schéma physique** ; ou **Réseau logique**, puis **Schéma logique** ;
- sur la page d'un site, les boutons **Schéma physique** et **Schéma logique**.

Depuis le menu, le schéma s'ouvre sur le premier site par ordre alphabétique. La liste **Site**, au-dessus du schéma, en choisit un autre. Le site est dans l'adresse de la page : un schéma se partage par son lien.

Le titre de la page nomme le site : **Schéma physique du site Siège**, **Schéma logique du site Siège**. Pendant le calcul de la disposition, le cadre affiche **Calcul du schéma…**. Si le calcul échoue, un message le dit : les données du site ne sont pas touchées, et recharger la page relance le calcul.

Chaque équipement dessiné mène à sa page ; sur le schéma physique, chaque panneau de brassage aussi ; sur le schéma logique, chaque sous-réseau. Un clic avec Ctrl ou Maj ouvre la page comme le ferait le navigateur, par exemple dans un nouvel onglet.

## Lire le schéma physique

Rôle : `reader`, `editor` ou `admin`.

### Ce qui est dessiné

- Les pare-feu, routeurs, switchs et bornes Wi-Fi du site, qu'ils aient une liaison ou non : ils font le réseau.
- Tout autre équipement du site qui a au moins une liaison, par exemple un serveur relié à un switch.
- En bordure, les équipements d'autres sites reliés à ceux du site, avec le nom de leur site.
- Tout équipement qu'un chemin câblé relie à un équipement dessiné, par un de ses ports ou par son adresse branchée sur une prise (voir [Le chemin câblé](cablage.md#le-chemin-câblé)), de proche en proche, jusqu'à ce que plus rien ne change. Un équipement d'un autre site branché sur une prise du site est dessiné en bordure.

Les équipements et les liaisons retirés ne sont pas dessinés. Le texte sous le schéma dit combien d'équipements du site ne le sont pas : ce ne sont ni des pare-feu, ni des routeurs, ni des switchs, ni des bornes Wi-Fi, et ils n'ont ni liaison ni câblage vers un équipement dessiné.

Chaque équipement est une boîte : son nom, puis une ligne qui donne son type, « stack de 2 membres » pour une pile, **Prévu** s'il est prévu, et le nom de son site s'il est d'un autre site. Un équipement prévu ou d'un autre site a un contour en pointillés. Les postes (postes fixes et portables, imprimantes, téléphones IP, caméras) sont des boîtes plus petites.

### Les cadres des lieux

Avec **Regrouper par lieu** coché, chaque équipement du site est dans le cadre de son local, lui-même dans le cadre de son étage et de son bâtiment. Un local technique porte la mention **Technique**. Un équipement rangé dans aucun local est dans un cadre en pointillés **Sans local**. Les équipements d'autres sites ne sont dans aucun cadre.

Seuls les lieux qui contiennent un équipement ou un panneau dessiné ont un cadre.

### Les ports et les liaisons

Seuls les ports reliés sont dessinés, sur le bord de leur équipement, avec leur nom. Chaque liaison part d'un port et arrive à un autre :

- trait fin : cuivre ou autre support ; trait épais : fibre ;
- trait en tirets : liaison prévue ;
- trait orange, avec un triangle au milieu : liaison qui a un écart.

Les liaisons entre deux agrégats forment un seul trait. Il part, de chaque côté, d'un port qui porte le nom de l'agrégat, et son étiquette compte les liaisons. Au Siège, les deux liaisons entre l'agrégat Po1 de `sw-coeur-01` et celui de `sw-serveurs` donnent un seul trait, étiqueté « Po1 ↔ Po1 · 2 liens ». Au survol, une liaison rappelle ses deux bouts, son support, son statut prévu et ses écarts ; une liaison agrégée, le chemin câblé de chacun de ses liens, s'il est connu.

### Le câblage et les panneaux

Une connexion dont le chemin câblé est connu se dessine en deux traits : du port de l'équipement au port du panneau, puis du port du panneau à l'équipement branché, la prise en étiquette sur ce second trait. C'est le cas :

- d'une liaison dont le câblage est le chemin physique : au Siège, la liaison de `sw-etage-1` `1/0/21` à `ap-etage-1` passe par le port 13 du panneau PB-B, puis par la prise B.13 ;
- d'un câblage sans liaison, dessiné avec son écart, comme `ap-atelier-1` derrière la prise H.03 à l'Atelier ;
- d'un équipement branché par son adresse, sans port à lui : le second trait arrive au bord de sa boîte, comme pour l'imprimante `imp-compta`, derrière la prise B.16.

Les panneaux traversés sont dessinés dans le cadre de leur local, avec leurs seuls ports utilisés, numérotés : une boîte plus basse qu'un équipement, son nom en police technique et la mention **Panneau**. Chaque port de panneau est un seul carré, où arrivent le trait du côté de la baie et celui du côté du bureau. Un panneau prévu a un contour en pointillés ; un panneau retiré n'apparaît pas, et coupe le chemin. Chaque panneau mène à sa page.

Une liaison sans chemin câblé connu reste un trait direct, d'un port à l'autre. Une liaison que le câblage contredit garde aussi son trait direct, avec son écart, et le câblage de son bout se dessine à côté : au Siège, `ap-etage-2` est reliée à `sw-etage-2` `1/0/21`, mais branchée sur la prise C.10, brassée vers `1/0/16`. Le câblage encore en place d'une liaison retirée se dessine avec son écart, comme celui de `ap-atelier-2` sur H.04. Une liaison agrégée reste un seul trait direct, et un panneau qu'elle seule traverse n'est pas dessiné.

### Le sens de lecture

Le schéma se lit de haut en bas, par rangs :

1. les pare-feu et les routeurs ;
2. les switchs ;
3. tous les autres équipements.

Le rang dépend du type de l'équipement, quel que soit son site : un pare-feu d'un autre site reste au-dessus des switchs du site affiché. Entre deux équipements du même rang, celui qui a le plus de liaisons, en comptant celles qui sortent du schéma, est au-dessus ; à égalité, l'ordre alphabétique des noms tranche. Un panneau se place entre les deux équipements d'une connexion qui le traverse. Les mêmes données donnent toujours le même dessin.

### Légende et résumé

La légende, au-dessus du schéma, montre chaque forme telle qu'elle est dessinée : **Équipement**, **Prévu, ou d'un autre site**, **Poste**, **Panneau**, **Port relié**, **Cuivre ou autre**, **Fibre**, **Liaison prévue**, **Prise**, **Écart**, **Bâtiment, étage ou local**.

Le résumé, sous le schéma, compte les équipements, les postes à part, les liaisons et les écarts dessinés, par exemple au Siège « 12 équipements, 1 poste, 12 liaisons, 5 écarts ». Un écart que le câblage d'une liaison répète, à côté de son trait direct, n'est compté qu'une fois.

Un site sans rien à dessiner affiche un message à la place du schéma ; un `editor` ou un `admin` y trouve le bouton **Nouvel équipement**. Un site dont les équipements n'ont aucune liaison rappelle qu'une liaison se déclare depuis la page d'un équipement, sur la ligne d'un de ses ports, avec **Relier…** (voir [liaisons](liaisons.md)).

## Lire le schéma logique

Rôle : `reader`, `editor` ou `admin`.

### Les sous-réseaux et Internet

Chaque sous-réseau est une barre. Sa première ligne donne son adresse, son nom et son VLAN ; sa seconde, son type, sa **Passerelle**, le nombre d'adresses documentées qui n'appartiennent à aucun équipement dessiné (« N autres adresses ») et **Prévu** s'il est prévu. Au Siège, la barre de `10.10.10.0/24` porte ainsi « VLAN 10 Bureautique » et la passerelle `10.10.10.254`. Une barre prévue a un contour en pointillés.

Sont dessinés :

- tous les sous-réseaux du site, quel que soit leur type ;
- les sous-réseaux sans site où un équipement dessiné du site a une adresse : lien opérateur, tunnel, liaison point à point.

Dès qu'un sous-réseau de type **Externe** est dessiné, **Internet** apparaît, en ellipse, relié à chacun d'eux. Dans la démonstration, le sous-réseau `203.0.113.8/29` « Accès Internet du siège » est externe : Internet est relié à sa barre.

### Les équipements

- Tous les pare-feu, routeurs et switchs du site, même sans adresse ni VLAN : un switch qui ne porte rien se voit ainsi.
- Tout autre équipement du site qui a des adresses dans au moins deux sous-réseaux dessinés, ou qui porte le VLAN de l'un d'eux sur un port ou un agrégat.
- En bordure, avec le nom de leur site, les équipements d'autres sites qui ont une adresse dans un sous-réseau dessiné ou en portent le VLAN : un pare-feu central apparaît ainsi sur le schéma de chaque site qu'il route.

Les postes, imprimantes et caméras qui n'ont qu'une adresse ne sont pas dessinés un par un : ils sont comptés dans les « autres adresses » de la barre de leur sous-réseau. Les sous-réseaux, équipements et adresses retirés ne comptent pas.

### Les branchements

Un trait relie un équipement à une barre quand il a une adresse dans ce sous-réseau, ou qu'il en porte le VLAN ; un seul trait par équipement et par sous-réseau. Près de l'équipement, une étiquette donne ses adresses dans ce sous-réseau ; celle qui est la passerelle du sous-réseau porte la marque **passerelle**. Un branchement sans adresse, par exemple un switch qui porte seulement le VLAN, n'a pas d'étiquette : au survol, il dit comment le VLAN est porté, port par port : en accès, en voix, en natif ou étiqueté, avec le nombre de ports de chaque sorte. Un agrégat compte pour chacun de ses ports.

### Le sens de lecture

Le schéma se lit de haut en bas, par rangs :

1. Internet ;
2. les sous-réseaux externes, tunnels, points à point et loopbacks ;
3. les pare-feu et les routeurs ;
4. les sous-réseaux de type **Segment** ;
5. les switchs et les autres équipements.

Chaque branchement va du rang le plus haut au plus bas. Les sous-réseaux sont rangés par adresse, les équipements par nom : les mêmes données donnent toujours le même dessin. Le schéma logique n'a pas de cadres de lieux.

### Légende et résumé

La légende montre : **Sous-réseau**, **Sous-réseau prévu**, **Internet**, **Équipement**, **Prévu, ou d'un autre site**, **Branchement à un sous-réseau**, **Passerelle du sous-réseau** et **Écart**.

Le résumé compte les sous-réseaux, Internet s'il est dessiné, les équipements, les branchements et les écarts. Un site sans sous-réseau ni pare-feu, routeur ou switch affiche un message à la place du schéma.

## Se déplacer dans un schéma

Rôle : `reader`, `editor` ou `admin`.

| Pour…                 | Faites…                                                                                                                                                                                                |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Déplacer la vue       | Glissez le fond du schéma, à la souris ou au doigt.                                                                                                                                                    |
| Zoomer ou dézoomer    | Les boutons **Zoomer** et **Dézoomer**, en haut à droite du cadre ; ou Ctrl et la molette, à l'endroit du pointeur ; ou un pincement à deux doigts ; ou les touches + et − quand le schéma a le focus. |
| Revoir tout le schéma | Le bouton **Ajuster**.                                                                                                                                                                                 |

La molette seule fait défiler la page, pas le schéma.

Au clavier, la touche Tab parcourt les équipements, les sous-réseaux et les icônes d'écart dans l'ordre de lecture : de haut en bas, puis de gauche à droite.

## Regrouper par lieu et afficher les postes

Rôle : `reader`, `editor` ou `admin`.

Sur le schéma physique, deux cases, au-dessus du schéma :

- **Regrouper par lieu**, cochée par défaut : chaque équipement et chaque panneau sont dessinés dans le cadre de leur local, de leur étage et de leur bâtiment. Décochée, les cadres disparaissent et tout est posé côte à côte, sans tenir compte des lieux.
- **Afficher les postes**, cochée par défaut. Décochée, les postes fixes et portables, les imprimantes, les téléphones IP et les caméras disparaissent avec leurs connexions, comme les ports de panneau et les panneaux qui ne servaient qu'à eux. Chaque équipement dessiné auquel ils étaient reliés en porte le nombre dans une pastille, à côté de son nom : au Siège, `sw-etage-1` porte « 1 poste ». Le résumé et la liste **Écarts du schéma** les comptent toujours.

Le navigateur retient ces deux choix, pour tous les sites, comme l'état du menu : ils sont les mêmes à la prochaine ouverture du schéma, quel que soit le site. Si le navigateur ne garde rien (navigation privée, données des sites bloquées), les deux cases sont cochées à chaque ouverture.

Pendant le placement à la main, les postes sont toujours affichés, pour que l'enregistrement garde leurs positions : la case est cochée et grisée, avec la mention « affichés pendant le placement ». Elle reprend son état en sortant du mode.

## Lire les écarts d'un schéma

Rôle : `reader`, `editor` ou `admin`.

Un schéma dessine les écarts de ce qu'il montre :

- sur le schéma physique : ceux des liaisons, au milieu de leur trait ; ceux du câblage (câblage sans liaison, liaison contredite, liaison retirée encore câblée), au milieu du trait qui va du port de l'équipement au panneau ; ceux des équipements du site (une adresse dans le réseau d'un autre site, un switch qui fusionne des VLAN de même numéro), dans le coin de leur boîte. Les écarts d'une adresse branchée sur une prise ne sont pas dessinés : ils restent sur les pages de l'adresse, de la prise et de l'équipement ;
- sur le schéma logique : ceux des sous-réseaux et de leur VLAN, et ceux des équipements du site.

Chaque écart est signalé par une icône en triangle, qui donne ses raisons dans une bulle :

- au clavier, la bulle s'ouvre dès que l'icône a le focus ; Entrée ou Espace l'ouvre ou la ferme ;
- au toucher ou au clic, une pression sur l'icône l'ouvre, une seconde la ferme ;
- Échap, la sortie du focus ou une pression ailleurs la ferment.

À la souris, le survol de l'icône montre aussi les raisons, comme le survol d'un équipement ou d'une liaison.

Sous le schéma, la liste **Écarts du schéma** reprend chaque écart dessiné, avec sa raison et un lien vers l'objet concerné : la page de l'équipement ou du sous-réseau ; pour une liaison, la page **Liaisons** filtrée sur le site et sur les écarts ; pour un câblage sans liaison, la page de l'équipement brassé vers le panneau. Un câblage sans liaison y propose aussi **Créer la liaison**. Un écart ne refuse rien : il montre le réseau tel qu'il est déclaré. Le détail des écarts est dans la page [écarts](ecarts.md).

## Placer les éléments à la main

Rôle : `editor` ou `admin`.

La disposition automatique convient souvent. Quand elle ne suffit pas, les éléments se placent à la main : les équipements et les panneaux sur le schéma physique ; les sous-réseaux, les équipements et Internet sur le schéma logique. Les positions sont gardées par site et par schéma : placer le schéma physique du Siège ne change ni son schéma logique, ni les schémas de l'Atelier.

1. Cliquez **Modifier la disposition**, au-dessus du schéma. Le bouton n'apparaît pas tant que le schéma est vide, et reste grisé pendant son calcul.
2. Une grille de points apparaît, tous les 8 pixels, et une barre s'ouvre au-dessus du schéma avec **Enregistrer**, **Annuler** et **Réorganiser automatiquement**.
3. Déplacez les éléments :
    - à la souris ou au doigt : glissez l'élément ;
    - au clavier : donnez le focus à l'élément avec Tab, puis utilisez les flèches. Chaque flèche le déplace de 8 pixels ; avec Maj, de 40 pixels.

    Chaque position se cale sur la grille. Pendant ce mode, cliquer un élément le déplace au lieu d'ouvrir sa page. La barre dit si des déplacements ne sont pas encore enregistrés.

4. Cliquez **Enregistrer**.

L'enregistrement garde la position de tous les éléments dessinés, d'un coup, ou d'aucun. Au premier placement, les éléments partent de la position que leur donnait la disposition automatique : ceux que vous ne touchez pas sont enregistrés là où ils étaient.

**Annuler** quitte le mode et revient à la disposition enregistrée, sans rien garder des déplacements.

L'enregistrement est refusé si quelqu'un a modifié ou réorganisé la disposition de ce schéma depuis l'ouverture de la page : « La disposition a été modifiée entre-temps : rechargez la page. » Il l'est aussi si un élément n'est plus dessiné. Le message s'affiche dans la barre ; rechargez la page, puis recommencez.

Quitter la page, ou choisir un autre site dans la liste **Site**, avec des déplacements non enregistrés demande confirmation : « Des déplacements ne sont pas enregistrés. Quitter la page et les perdre ? » Fermer l'onglet déclenche aussi l'avertissement du navigateur.

Chaque enregistrement est inscrit au journal du site, avec le nombre d'éléments placés ; pour le schéma physique, aussi le nombre d'équipements et celui de panneaux (voir [comptes et journal](comptes-et-journal.md)).

### Ce que devient un schéma placé à la main

Dès qu'une position est enregistrée, le schéma n'est plus disposé automatiquement :

- chaque élément placé garde sa position ;
- un élément qui n'a pas de position, par exemple un équipement ajouté depuis, se range sur une ligne sous l'élément le plus bas, marqué **Non placé**. Placez-le, puis enregistrez. Dans la démonstration, le schéma physique de l'Atelier est placé à la main, panneau PB-A compris, et la borne `ap-quai`, laissée de côté, porte la mention **Non placé** ;
- les liaisons et les branchements sont tracés en lignes à angle droit, d'un port ou d'un bord à l'autre ; un port de panneau se place sur le côté qui fait face au milieu de ses deux bouts, et ne se trace pas autour des boîtes : placez le panneau pour que ses deux traits partent du même côté ;
- sur le schéma physique, les cadres des lieux entourent les équipements et les panneaux qu'ils contiennent.

La position d'un élément qui n'est plus dessiné, un équipement retiré par exemple, est ignorée, et disparaît au prochain enregistrement.

## Revenir à la disposition automatique

Rôle : `editor` ou `admin`.

1. Cliquez **Modifier la disposition**.
2. Dans la barre, cliquez **Réorganiser automatiquement**. Le bouton est grisé tant qu'aucune position n'est enregistrée pour ce schéma.
3. Confirmez (**Réorganiser automatiquement le schéma ?**).

Toutes les positions de ce schéma, pour ce site, sont supprimées, et le schéma est de nouveau disposé automatiquement. L'autre schéma du site n'est pas touché. La réorganisation est inscrite au journal du site.

## Télécharger un schéma en SVG

Rôle : `reader`, `editor` ou `admin`.

Cliquez **Télécharger en SVG**, au-dessus du schéma. Le navigateur enregistre le fichier sous un nom fait du schéma, du code du site et de la date du jour :

- `schema-physique-SIE-2026-10-17.svg` pour le schéma physique du site Siège, de code SIE ;
- `schema-logique-SIE-2026-10-17.svg` pour son schéma logique.

Un site sans code prend son nom, sans accents ni espaces.

Le fichier contient tout le schéma, quels que soient le zoom et la partie visible à l'écran, dans la disposition affichée, automatique ou placée à la main, avec les postes s'ils sont affichés. Comme l'impression, il prend toujours le thème clair, quel que soit le thème à l'écran : c'est un document. Il garde le fond du cadre, et porte en titre « Schéma physique du site Siège ». La grille et les bulles d'écart n'y figurent pas.

## Imprimer un schéma

Rôle : `reader`, `editor` ou `admin`.

Cliquez **Imprimer**, au-dessus du schéma : la fenêtre d'impression du navigateur s'ouvre. L'impression garde le titre de la page, la légende et le schéma entier, ajusté à la page, toujours en thème clair. Les boutons, la barre de disposition, le résumé et la liste des écarts ne sont pas imprimés.
