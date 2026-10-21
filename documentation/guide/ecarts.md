<!-- torsade/documentation/guide/ecarts.md -->

# Les écarts

Cette page dit ce qu'est un écart, où les voir, et passe en revue chaque écart que Torsade calcule : ce qu'il signifie, un exemple de raison affichée, où il apparaît, quand il ne s'applique pas et comment le résorber si vous le souhaitez.

## Ce qu'est un écart

Un écart est un décalage que Torsade vous signale. Il en existe deux sortes :

- un décalage entre la norme de l'organisation (le **Catalogue des VLAN**, les **Blocs d'adressage**, la **Règle d'adressage**) et l'existant que vous avez déclaré ;
- une incohérence entre deux objets déclarés : les deux bouts d'une liaison qui ne s'accordent pas, une prise brassée vers un port qui ne porte pas le VLAN de l'adresse branchée dessus, une adresse posée sur l'équipement d'un autre site.

Un écart :

- est calculé à chaque affichage de la page, à partir de ce qui est déclaré ; il n'est jamais enregistré ;
- ne bloque jamais rien : aucune saisie n'est refusée parce qu'elle crée un écart ;
- s'affiche en orange, avec un triangle d'avertissement, le libellé **Écart :** et sa raison en clair, par exemple « Hors des blocs du site (10.10.0.0/16) ». Dans un tableau, le titre de la colonne dit déjà qu'il s'agit d'un écart : seuls le triangle et la raison s'affichent.

Un même objet peut porter plusieurs écarts : chacun a sa ligne et sa raison.

Pourquoi : Torsade documente le réseau tel qu'il est, y compris ses imperfections. Refuser une saisie non conforme empêcherait de documenter justement le réseau que l'on cherche à remettre d'aplomb. L'écart rend l'imperfection visible sans l'interdire ; le résorber reste votre décision.

## Les écarts en un coup d'œil

| Écart                                                                                        | Où il apparaît                                                                                                                         |
| -------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| [VID différent du catalogue](#vid-ou-nom-différent-du-catalogue)                             | liste des VLAN, page du VLAN, page du site, **Catalogue des VLAN** et page de l'entrée, schéma logique                                 |
| [Nom différent du catalogue](#vid-ou-nom-différent-du-catalogue)                             | mêmes endroits                                                                                                                         |
| [Sous-réseau dans le bloc d'un autre site](#sous-réseau-dans-le-bloc-dun-autre-site)         | liste des sous-réseaux, page du sous-réseau, page de chacun des deux sites, page du bloc, schéma logique                               |
| [Sous-réseau hors des blocs de son site](#sous-réseau-hors-des-blocs-de-son-site)            | liste des sous-réseaux, page du sous-réseau, page du site, page du bloc, schéma logique                                                |
| [Sous-réseau sans site dans le bloc d'un site](#sous-réseau-sans-site-dans-le-bloc-dun-site) | liste des sous-réseaux, page du sous-réseau, page du bloc                                                                              |
| [Numéro de site hors de la règle](#numéro-de-site-hors-de-la-règle)                          | page du site, page **Règle d'adressage**                                                                                               |
| [Bloc attendu par la règle non déclaré](#bloc-attendu-par-la-règle-non-déclaré)              | page du site, page **Règle d'adressage**                                                                                               |
| [Sous-réseau différent de celui de la règle](#sous-réseau-différent-de-celui-de-la-règle)    | liste des sous-réseaux, page du sous-réseau, page du VLAN, page du site, page du bloc, schéma logique                                  |
| [Adresse sur un équipement d'un autre site](#adresse-sur-un-équipement-dun-autre-site)       | page du sous-réseau (tableau des adresses), page de l'équipement, schémas du site                                                      |
| [Switch qui fusionne deux VLAN](#switch-qui-fusionne-deux-vlan)                              | page de l'équipement, schémas du site                                                                                                  |
| [Accès face à un trunk](#accès-face-à-un-trunk)                                              | page **Liaisons**, pages des deux équipements, schéma physique                                                                         |
| [Deux accès sur des VLAN différents](#deux-accès-sur-des-vlan-différents)                    | mêmes endroits                                                                                                                         |
| [VLAN natifs différents](#vlan-natifs-différents)                                            | mêmes endroits                                                                                                                         |
| [VLAN étiquetés différents](#vlan-étiquetés-différents)                                      | mêmes endroits                                                                                                                         |
| [Liaison qui fusionne deux réseaux](#liaison-qui-fusionne-deux-réseaux)                      | mêmes endroits                                                                                                                         |
| [Agrégat qui mène à plusieurs équipements](#agrégat-qui-mène-à-plusieurs-équipements)        | page de l'équipement de l'agrégat (agrégats et section **Écarts**)                                                                     |
| [Agrégat d'un seul côté](#agrégat-dun-seul-côté)                                             | page **Liaisons**, pages des deux équipements, schéma physique                                                                         |
| [Liaison active sur un port désactivé](#liaison-active-sur-un-port-désactivé)                | page **Liaisons**, pages des deux équipements, schéma physique                                                                         |
| [Cordon entre deux locaux](#cordon-entre-deux-locaux)                                        | page du panneau, page de l'équipement (ports)                                                                                          |
| [Câblage sans liaison](#câblage-sans-liaison)                                                | pages des deux équipements (ports et section **Écarts**), page **Prises**, schéma physique                                             |
| [Liaison contredite par le câblage](#liaison-contredite-par-le-câblage)                      | page **Liaisons**, pages des deux équipements, schéma physique                                                                         |
| [Câblage d'une liaison retirée](#câblage-dune-liaison-retirée)                               | page **Liaisons**, pages des deux équipements, schéma physique                                                                         |
| [Port relié sans branchement](#port-relié-sans-branchement)                                  | page **Prises**, page de l'équipement (ports et liaisons), page **Liaisons**, schéma physique                                          |
| [Prise non brassée](#prise-non-brassée)                                                      | page **Prises**, page du panneau, adresse (page du sous-réseau, page de l'équipement)                                                  |
| [VLAN non porté](#vlan-non-porté)                                                            | page **Prises**, page du panneau, port au bout de la prise (page de l'équipement), adresse (page du sous-réseau, page de l'équipement) |

## Voir les écarts d'un site

Rôle : `reader`, `editor` ou `admin`.

Les écarts d'un site se lisent à trois endroits.

1. Sur la page du site (menu **Sites**) :
    - sous l'en-tête, les écarts du site lui-même vis-à-vis de la règle d'adressage, avec le bouton **Créer ce bloc** quand le bloc attendu manque ;
    - dans le tableau des VLAN, colonne **Catalogue**, l'écart de chaque VLAN avec son entrée du catalogue ;
    - en bas de page, la section **Écarts** : chaque VLAN et chaque sous-réseau du site qui s'écarte du catalogue, des blocs ou de la règle, ainsi que les sous-réseaux d'autres sites posés dans les blocs de ce site. Chaque ligne mène à la page de l'objet. Sans écart, la section affiche « Aucun écart. », ou « Aucun autre écart. » quand le site lui-même a un écart de règle affiché plus haut.
2. Sur les schémas du site, ouverts par les boutons **Schéma physique** et **Schéma logique** de la page du site : chaque écart dessiné porte un triangle d'avertissement, et la section **Écarts du schéma**, sous le dessin, les reprend tous avec leur raison. Le schéma physique montre les écarts des équipements et des liaisons, le schéma logique ceux des sous-réseaux et des équipements. Voir [les schémas](schemas.md).
3. Sur les pages des objets eux-mêmes : la page d'un équipement a sa propre section **Écarts** (adresses, VLAN fusionnés, ports, agrégats, liaisons) ; les pages **Liaisons** et **Prises** ont une case **Avec écart seulement** qui ne garde que les lignes en écart.

La section **Écarts** de la page du site ne montre que les écarts de norme (catalogue, blocs, règle). Les écarts des équipements, des liaisons et du câblage se voient sur les schémas et sur les pages des équipements, des liaisons, des prises et des panneaux.

## Retrouver les écarts de la démonstration

Rôle : `reader`, `editor` ou `admin`.

Les données de démonstration contiennent des écarts volontaires, pour voir à quoi ils ressemblent. Chacun le dit dans la description de l'objet concerné.

| Objet de la démonstration                                                                               | Écart affiché                                                                                                  |
| ------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| VLAN 150 Vidéosurveillance du site Atelier, rattaché à l'entrée 50 du catalogue                         | « VID 150, catalogue : 50 »                                                                                    |
| Son sous-réseau `10.20.50.0/24`                                                                         | « Attendu par la règle : 10.20.150.0/24 »                                                                      |
| Sous-réseau `192.168.1.0/24` du VLAN 5 Ancien réseau, site Siège                                        | « Hors des blocs du site (10.10.0.0/16) », et aussi « Attendu par la règle : 10.10.5.0/24 »                    |
| Adresse `10.20.60.50` du sous-réseau de production de l'Atelier, rattachée à srv-sauvegarde-01 du Siège | « Sous-réseau du site Atelier, équipement du site Siège »                                                      |
| Liaison entre sw-coeur-01 `1/1/4` et sw-coeur-02 `1/1/4`                                                | « Natif 99 d'un côté, 5 de l'autre »                                                                           |
| Liaison entre sw-etage-2 `1/1/1` et sw-coeur-02 `1/1/1`                                                 | « VLAN 40 seulement sur sw-etage-2 »                                                                           |
| Liaison prévue entre sw-coeur-02 `1/1/3` et sw-atelier-01 `1/1/1`                                       | « VLAN 10 du site Siège d'un côté, VLAN 10 du site Atelier de l'autre : la liaison fusionne deux réseaux »     |
| Cordon du port 24 du panneau PB-A (Siège) vers sw-etage-1 `1/0/20`                                      | « Panneau dans Rez-de-chaussée › Local technique, sw-etage-1 dans 1er étage › Placard technique »              |
| Prise R.05 (Siège), où est branchée l'imprimante de l'accueil                                           | « Prise R.05 non brassée »                                                                                     |
| Prise C.09 (Siège), où est branché le téléphone de la direction                                         | « sw-etage-2 1/0/14 en accès VLAN 10, adresse 10.10.30.53 du VLAN 30 »                                         |
| Prise B.10 (Siège), où la caméra prévue cam-couloir-1 sera branchée                                     | « sw-etage-1 1/0/10 en accès VLAN 10, adresse 10.10.50.16 du VLAN 50 »                                         |
| Liaison entre sw-etage-1 `1/0/22` et ap-salle-reunion `eth0`, brassée vers la prise B.14 (Siège)        | « sw-etage-1 1/0/22 est relié à ap-salle-reunion eth0 et brassé vers B.14, où rien n'est branché »             |
| Borne ap-etage-2 (Siège), branchée sur la prise C.10                                                    | « Le câblage de ap-etage-2 eth0 mène à sw-etage-2 1/0/16 ; la liaison, à sw-etage-2 1/0/21 »                   |
| Borne ap-atelier-1 (Atelier), branchée sur la prise H.03                                                | « sw-atelier-01 1/0/9 et ap-atelier-1 eth0 sont câblés par PB-A 9 › H.03, sans liaison »                       |
| Borne ap-atelier-2 (Atelier), branchée sur la prise H.04                                                | « sw-atelier-01 1/0/10 et ap-atelier-2 eth0 sont encore câblés par PB-A 10 › H.04 ; leur liaison est retirée » |

L'ordre des deux côtés d'une raison de liaison dépend de la page qui l'affiche. Sur les pages et schémas du Siège, ces écarts figurent dans les sections **Écarts** et **Écarts du schéma**. Le sous-réseau externe `203.0.113.8/29` du Siège, lui, est hors du bloc du site sans être signalé : un sous-réseau externe en est exempté (voir plus bas).

## Catalogue des VLAN

Ces écarts comparent un VLAN à l'entrée du **Catalogue des VLAN** à laquelle il est rattaché.

### VID ou nom différent du catalogue

Ce qu'il signifie : le VLAN est rattaché à une entrée du catalogue, mais son numéro, ou son nom, n'est pas celui de l'entrée. Ce sont deux écarts distincts, qui peuvent s'afficher ensemble.

Exemples de raison :

- « VID 150, catalogue : 50 » (le VLAN Vidéosurveillance de l'Atelier, dans la démonstration) ;
- « Nom Caméras, catalogue : Vidéosurveillance », si le VLAN avait été nommé autrement que son entrée.

Où il apparaît :

- liste des VLAN (menu **Réseau logique**), colonne **Catalogue** ;
- page du VLAN, sous l'en-tête ;
- page du site : colonne **Catalogue** du tableau des VLAN, et section **Écarts** ;
- **Catalogue des VLAN** : dans le tableau croisé entrées × sites, sous le VID du VLAN du site ; sur la page d'une entrée, colonne **Écart** du tableau des VLAN rattachés ;
- schéma logique du site, sur le sous-réseau du VLAN.

Quand il ne s'applique pas :

- un VLAN sans entrée du catalogue n'a pas d'écart de catalogue : il est affiché **Hors catalogue** ;
- la comparaison des noms ignore les majuscules : « Serveurs » et « SERVEURS » sont le même nom. Elle tient compte des accents.

### Résorber un écart de catalogue

Rôle : `editor` ou `admin`.

1. Ouvrez la page du VLAN, puis **Modifier**.
2. Selon le cas, dans **Modifier le VLAN** :
    - corrigez le **VID** ou le **Nom** pour qu'il suive l'entrée ;
    - ou choisissez **Aucune entrée** dans **Entrée du catalogue**, si ce VLAN ne suit en fait aucune entrée : il devient **Hors catalogue**, sans écart.
3. Enregistrez.

Le numéro d'un VLAN est unique dans son site : Torsade refuse un VID déjà pris sur le site. Changer le VID d'un VLAN change aussi le sous-réseau que la règle attend pour lui : dans la démonstration, renuméroter le VLAN 150 de l'Atelier en 50 résorbe à la fois l'écart de catalogue et l'écart avec la règle de son sous-réseau `10.20.50.0/24`.

La section **Rattacher des VLAN existants**, sur la page d'une entrée, ne rattache que des VLAN qui portent déjà le numéro de l'entrée : un VLAN d'un autre numéro se rattache depuis son propre formulaire, en connaissance de cause.

## Blocs et sous-réseaux

Ces écarts comparent la place d'un sous-réseau aux **Blocs d'adressage** des sites.

### Sous-réseau dans le bloc d'un autre site

Ce qu'il signifie : le sous-réseau appartient à un site, mais il se trouve dans le bloc d'un autre site.

Exemples de raison :

- sur la page du sous-réseau : « Dans le bloc 10.20.0.0/16 du site Atelier », pour un sous-réseau du Siège posé dans le bloc de l'Atelier ;
- sur la page du site propriétaire du bloc, la même chose vue de l'autre côté : « Sous-réseau du site Siège, dans le bloc 10.20.0.0/16 de ce site ».

Où il apparaît : liste des sous-réseaux (colonne **Site**), page du sous-réseau, section **Écarts** de la page de son site et de la page du site propriétaire du bloc, section **Écarts** de la page du bloc, schéma logique.

Quand il ne s'applique pas : il vaut pour tous les types de sous-réseaux. Un sous-réseau sans site relève de l'écart [sans site](#sous-réseau-sans-site-dans-le-bloc-dun-site).

Un tel sous-réseau, s'il est un **Segment** et que son site a des blocs, est aussi « hors des blocs de son site » : les deux écarts s'affichent.

### Sous-réseau hors des blocs de son site

Ce qu'il signifie : le site du sous-réseau a déclaré au moins un bloc, et le sous-réseau n'est dans aucun d'eux.

Exemple de raison : « Hors des blocs du site (10.10.0.0/16) » (l'ancien sous-réseau `192.168.1.0/24` du Siège, dans la démonstration). La raison nomme les blocs du site, trois au plus, puis « et N de plus » : on voit d'emblée si le sous-réseau est mal placé ou si le bloc a été déclaré trop petit.

Où il apparaît : liste des sous-réseaux (colonne **Site**), page du sous-réseau, section **Écarts** de la page du site et de la page du bloc qui le contient, schéma logique.

Quand il ne s'applique pas :

- le sous-réseau n'est pas de type **Segment** : un sous-réseau **Point à point**, **Loopback**, **Tunnel** ou **Externe** est souvent ailleurs par construction (adresses de l'opérateur, bloc réservé aux interconnexions). C'est pourquoi `203.0.113.8/29`, sous-réseau externe du Siège, n'est pas signalé ;
- le site n'a déclaré aucun bloc ;
- le sous-réseau n'a pas de site.

### Sous-réseau sans site dans le bloc d'un site

Ce qu'il signifie : le sous-réseau n'est rattaché à aucun site, mais il se trouve dans le bloc d'un site.

Exemple de raison : « Sans site, dans le bloc 10.10.0.0/16 du site Siège ».

Où il apparaît : liste des sous-réseaux (colonne **Site**, sous **Aucun site**), page du sous-réseau, section **Écarts** de la page du bloc. Il n'apparaît pas sur la page du site.

Quand il ne s'applique pas : il vaut pour tous les types de sous-réseaux. Un sous-réseau sans site hors de tout bloc de site n'est pas signalé : c'est le cas de la liaison `10.255.0.0/31` entre les deux sites de la démonstration.

### Résorber un écart de bloc

Rôle : `editor` ou `admin`.

Selon ce qui est faux, le sous-réseau ou le bloc :

- le sous-réseau est mal rattaché : ouvrez sa page, **Modifier**, et changez son **Site** dans **Modifier le sous-réseau** ;
- le sous-réseau est mal placé : changez son **Adresse du réseau** ou son **Préfixe** dans le même formulaire, si le réseau réel a été renuméroté ;
- le sous-réseau n'est pas un segment : changez son **Type** (par exemple **Point à point** pour une interconnexion) ; il n'est alors plus attendu dans les blocs de son site ;
- le bloc est trop petit ou manque : ouvrez la page du bloc, **Modifier**, et corrigez-le dans **Modifier le bloc**, ou créez le bloc qui manque depuis **Blocs d'adressage** (menu **Norme**).

Torsade refuse une modification qui laisserait des adresses ou des plages hors du sous-réseau ; le message nomme ce qui gêne. Un écart conservé volontairement, comme un ancien plan d'adressage, peut aussi rester tel quel : une description sur l'objet en dit la raison.

## Règle d'adressage

Ces écarts n'existent que si une **Règle d'adressage** est définie. Sans règle, Torsade ne signale aucun écart d'adressage. Une règle sans niveau de site n'utilise pas le numéro des sites et n'attend aucun bloc de site : les deux premiers écarts n'existent pas avec elle.

### Numéro de site hors de la règle

Ce qu'il signifie : le site a un numéro que la règle ne peut pas atteindre. Avec la règle de la démonstration (`10.0.0.0/8`, un `/16` par site), les numéros vont de 0 à 255.

Exemple de raison :

- sur la page du site : « Numéro 300 : la règle en permet de 0 à 255 » ;
- sur la page **Règle d'adressage**, tableau **Application de la règle**, colonne **État** : « Numéro hors des limites de la règle (0 à 255) ».

Où il apparaît : page du site, sous l'en-tête ; page **Règle d'adressage**.

Quand il ne s'applique pas : site sans numéro (la page de la règle l'indique dans la colonne **État**, sans écart) ; règle sans niveau de site ; aucune règle définie.

### Bloc attendu par la règle non déclaré

Ce qu'il signifie : le site est numéroté, la règle en déduit son bloc, et aucun bloc du site n'est exactement ce bloc. Un bloc plus grand ou plus petit ne compte pas.

Exemple de raison :

- sur la page du site : « Bloc attendu par la règle : 10.30.0.0/16 », si le site Dépôt (numéro 30) n'avait pas déclaré son bloc ;
- sur la page **Règle d'adressage**, colonne **État** : « Bloc attendu non déclaré ». Quand le bloc est là, la colonne affiche **Bloc déclaré**, avec un lien vers le bloc.

Où il apparaît : page du site, sous l'en-tête ; page **Règle d'adressage**, tableau **Application de la règle**.

Quand il ne s'applique pas : site sans numéro ; numéro hors de la règle (c'est alors l'écart précédent) ; règle sans niveau de site ; aucune règle définie.

### Créer le bloc attendu par la règle

Rôle : `editor` ou `admin`.

1. Sur la page du site, ou sur la ligne du site dans **Application de la règle**, cliquez sur **Créer ce bloc**.
2. Torsade calcule lui-même le bloc attendu et le crée pour le site, avec le statut du site : le bloc d'un site **Prévu** est prévu lui aussi.

Si un autre bloc occupe déjà cet espace, la création est refusée et le message nomme le bloc qui gêne. Vous pouvez aussi changer le **Numéro** du site, dans sa fenêtre de modification, s'il est faux.

### Sous-réseau différent de celui de la règle

Ce qu'il signifie : le sous-réseau porté par un VLAN n'est pas celui que la règle calcule à partir du numéro du site et du VID du VLAN, ou du VID seul pour une règle sans niveau de site.

Exemple de raison : « Attendu par la règle : 10.20.150.0/24 » (le sous-réseau `10.20.50.0/24` du VLAN 150 de l'Atelier, dans la démonstration). Sans niveau de site, avec le bloc `192.168.0.0/16` et des `/24`, le VLAN 20 de chaque site attend `192.168.20.0/24` : sur un deuxième site, un autre sous-réseau pour son VLAN 20 porte « Attendu par la règle : 192.168.20.0/24 ».

Où il apparaît : liste des sous-réseaux, page du sous-réseau, page du VLAN (section du sous-réseau), section **Écarts** de la page du site et de la page du bloc, schéma logique.

Quand il ne s'applique pas :

- aucune règle n'est définie ;
- le sous-réseau n'est pas de type **Segment**, ou n'est porté par aucun VLAN ;
- avec un niveau de site, son site n'a pas de numéro, ou un numéro hors de la règle ;
- le VID n'est pas couvert par la règle : avec des `/24` dans un `/16`, la règle couvre les VID 1 à 255. Un VLAN 300 n'est pas comparé : la règle ne dit rien de lui.

### Résorber un écart avec la règle

Rôle : `editor` ou `admin`.

- Si le sous-réseau est faux, changez son **Adresse du réseau** et son **Préfixe** dans **Modifier le sous-réseau**.
- Si c'est le numéro du VLAN qui est faux, changez son **VID** dans **Modifier le VLAN** : le sous-réseau attendu suit le VID.
- Si c'est le numéro du site qui est faux, corrigez le **Numéro** du site.

## Équipements et adresses

### Adresse sur un équipement d'un autre site

Ce qu'il signifie : une adresse est rattachée à un équipement d'un site, alors que son sous-réseau appartient à un autre site, et rien ne dit que l'équipement est branché sur ce réseau.

Exemple de raison : « Sous-réseau du site Atelier, équipement du site Siège » (l'adresse `10.20.60.50` de srv-sauvegarde-01, dans la démonstration). Sur les schémas, l'adresse précède la raison : « 10.20.60.50 : Sous-réseau du site Atelier, équipement du site Siège ».

Où il apparaît : page du sous-réseau, sous l'équipement dans le tableau des adresses ; page de l'équipement, tableau **Adresses** et section **Écarts** ; schémas du site de l'équipement, sur l'équipement.

Quand il ne s'applique pas :

- l'adresse n'est rattachée à aucun équipement, ou son sous-réseau n'a pas de site ;
- l'équipement et le sous-réseau sont du même site ;
- l'équipement porte le VLAN du sous-réseau sur l'un de ses ports ou de ses agrégats : il est alors branché sur ce réseau, où qu'il soit. C'est le cas d'un pare-feu central qui route les VLAN de plusieurs sites.

### Switch qui fusionne deux VLAN

Ce qu'il signifie : un switch porte, sur ses ports et ses agrégats, deux VLAN différents de même numéro, par exemple le VLAN 10 du Siège et le VLAN 10 de l'Atelier, même sur deux ports différents. Un switch n'a qu'une table de VLAN : pour lui, ces deux VLAN ne font qu'un réseau. Un écart par numéro.

Exemple de raison : « VLAN 10 des sites Siège et Atelier : sw-coeur-02 les fusionne », si sw-coeur-02 portait aussi le VLAN 10 de l'Atelier. Dans la démonstration, sw-coeur-02 porte le VLAN 60 de l'Atelier, mais le Siège n'a pas de VLAN 60 : pas d'écart.

Où il apparaît : page de l'équipement, section **Écarts** (ligne « VLAN », qui mène aux ports) ; schémas du site, sur l'équipement.

Quand il ne s'applique pas : l'équipement n'est pas de type **Switch**. Un pare-feu ou un routeur garde les VLAN séparés, chacun étant une sous-interface.

### Résorber un écart d'équipement

Rôle : `editor` ou `admin`.

Adresse sur un équipement d'un autre site :

- si l'adresse est rattachée au mauvais équipement, cliquez sur **Détacher** dans le tableau **Adresses** de l'équipement, puis rattachez-la au bon, ou changez l'**Équipement** dans **Modifier l'adresse IP**, depuis la page du sous-réseau ;
- si l'équipement est réellement branché sur ce réseau, déclarez le VLAN du sous-réseau sur le port concerné (bouton **Mode et VLAN** de la section **Ports**) : l'écart disparaît ;
- si c'est le site de l'équipement qui est faux, corrigez-le dans **Modifier l'équipement**.

Switch qui fusionne deux VLAN : sur la page du switch, cochez les ports qui portent le VLAN de trop, puis **Mode et VLAN** et **Appliquer**, pour ne garder qu'un VLAN par numéro. Les agrégats se règlent de même dans la section **Agrégats**.

## Liaisons

Les écarts de liaison comparent les deux bouts d'une liaison. Pour un port membre d'un agrégat, c'est le mode et les VLAN de l'agrégat qui comptent.

Où ils apparaissent (sauf l'écart « mène à plusieurs équipements », signalé sur l'agrégat seul) :

- page **Liaisons** (menu **Réseau physique**), colonne **Écarts**, avec la case **Avec écart seulement** ;
- page de chacun des deux équipements : tableau **Liaisons**, et section **Écarts** ;
- schéma physique du site, sur le trait de la liaison, et dans **Écarts du schéma**.

Les cinq premiers écarts ci-dessous comparent des configurations : ils ne s'appliquent que si les deux bouts ont un **Mode**. Le port d'un pare-feu ou d'un serveur, sans mode, n'est pas comparé. Les écarts d'agrégat et de port désactivé valent quel que soit le mode.

### Accès face à un trunk

Ce qu'il signifie : un bout est en **Accès**, l'autre en **Trunk**.

Exemple de raison : « Accès d'un côté (sw-etage-1 › 1/0/20), trunk de l'autre ». Le bout nommé est celui en accès, avec son équipement ; pour un port d'agrégat, c'est l'agrégat qui est nommé (« sw-serveurs › Po1 »).

### Deux accès sur des VLAN différents

Ce qu'il signifie : les deux bouts sont en accès, sur deux numéros de VLAN différents.

Exemple de raison : « VLAN 10 d'un côté, 60 de l'autre ».

Quand il ne s'applique pas : un des deux ports en accès n'a pas encore de VLAN. Le **VLAN voix** n'est pas comparé.

### VLAN natifs différents

Ce qu'il signifie : les deux bouts sont en trunk, et leur **VLAN natif** diffère, ou un seul des deux en a un.

Exemples de raison :

- « Natif 99 d'un côté, 5 de l'autre » (la liaison entre sw-coeur-01 et sw-coeur-02, dans la démonstration) ;
- « Natif 99 d'un côté (sw-coeur-01 › 1/1/4), aucun de l'autre ».

### VLAN étiquetés différents

Ce qu'il signifie : les deux bouts sont en trunk, et l'un étiquette des VLAN que l'autre n'étiquette pas.

Exemple de raison : « VLAN 40 seulement sur sw-etage-2 » (dans la démonstration). Quand les deux côtés ont chacun des VLAN en trop, les deux parties sont séparées par un point-virgule. Dix VLAN sont nommés au plus, puis « et N de plus ».

### Liaison qui fusionne deux réseaux

Ce qu'il signifie : sous un même numéro, les deux bouts portent deux VLAN différents, par exemple le VLAN 10 de deux sites. La liaison les relie en un seul réseau. La comparaison vaut quelle que soit la façon dont chaque bout porte le VLAN : non étiqueté, voix, natif ou étiqueté. Un écart par numéro.

Exemple de raison : « VLAN 10 du site Siège d'un côté, VLAN 10 du site Atelier de l'autre : la liaison fusionne deux réseaux » (la fibre prévue entre sw-coeur-02 et sw-atelier-01, dans la démonstration).

### Agrégat qui mène à plusieurs équipements

Ce qu'il signifie : les liaisons des ports d'un agrégat aboutissent à plusieurs équipements.

Exemple de raison : « sw-serveurs › Po1 mène à sw-coeur-01 et sw-coeur-02 ». Sur la page de l'équipement de l'agrégat, l'agrégat est nommé seul : « Po1 mène à … ».

Où il apparaît : seulement sur la page de l'équipement de l'agrégat, tableau **Agrégats** et section **Écarts**. Il ne figure ni sur la page **Liaisons**, ni sur le schéma physique.

### Agrégat d'un seul côté

Ce qu'il signifie : en face d'un agrégat, sur un même équipement, les ports ne forment pas un même agrégat. Deux cas :

- certains ports d'en face ne sont dans aucun agrégat : « sw-coeur-01 › Po1 : en face, ports hors agrégat sur sw-serveurs » ;
- les ports d'en face sont répartis dans plusieurs agrégats : « sw-coeur-01 › Po1 : en face, plusieurs agrégats sur sw-serveurs (Po1, Po2) ».

Où il apparaît en plus : tableau **Agrégats** de la page de l'équipement ; dans la section **Écarts** de cette page, l'agrégat le dit une fois et ses liaisons ne le répètent pas.

### Créer l'agrégat en face

Rôle : `editor` ou `admin`.

Quand toutes les liaisons d'un agrégat mènent à des ports d'un seul équipement, et qu'aucun de ces ports n'est dans un agrégat, l'écart « en face, ports hors agrégat » porte le lien **Créer l'agrégat en face**.

1. Cliquez sur **Créer l'agrégat en face**, là où l'écart s'affiche (page **Liaisons**, page d'un équipement, section **Écarts du schéma**).
2. La page de l'équipement d'en face s'ouvre avec la fenêtre **Nouvel agrégat** déjà remplie : un nom dans la suite des agrégats de l'équipement (« Po2 » après « Po1 », « Po1 » s'il n'en a aucun), les ports d'en face cochés, le protocole, le mode et les VLAN de l'agrégat d'origine.
3. Vérifiez, modifiez si besoin, puis **Enregistrer**. Les ports cochés perdent leur propre mode et leurs VLAN : ceux de l'agrégat s'appliquent à eux.

Rien n'est créé avant l'enregistrement. Le lien n'est pas proposé quand les ports d'en face sont sur plusieurs équipements, ou que l'un d'eux est déjà dans un agrégat : il faut alors corriger les liaisons ou les agrégats à la main.

### Liaison active sur un port désactivé

Ce qu'il signifie : la liaison a le statut **Actif**, mais l'un de ses ports est désactivé. L'un des deux dit quelque chose qui n'est plus vrai.

Exemple de raison : « 1/0/24 est désactivé » sur la page de l'équipement du port, « sw-etage-1 › 1/0/24 est désactivé » ailleurs ; « … sont désactivés » si les deux le sont.

Quand il ne s'applique pas : la liaison est **Prévu** ou **Retiré**. Dans la section **Écarts** d'un équipement, l'écart n'est repris que sur la page de l'équipement dont le port est désactivé.

### Résorber un écart de liaison

Rôle : `editor` ou `admin`.

- Mode ou VLAN qui ne s'accordent pas, liaison qui fusionne deux réseaux : sur la page de l'équipement à corriger, cochez le port dans la section **Ports**, puis **Mode et VLAN**, réglez **Mode**, **VLAN**, **VLAN natif**, **VLAN étiquetés** ou **VLAN voix**, et **Appliquer**. Pour un port d'agrégat, réglez l'agrégat dans la section **Agrégats**.
- Agrégat qui mène à plusieurs équipements, ou agrégat d'un seul côté sans lien de création : corrigez les ports de l'agrégat (fenêtre **Modifier l'agrégat**) ou les liaisons elles-mêmes.
- Liaison active sur un port désactivé : réactivez le port (bouton **Activer** de la sélection, ou case **Port activé** de sa fenêtre), ou changez le **Statut** de la liaison dans **Modifier la liaison**, ou supprimez la liaison si elle n'existe plus.

## Câblage

Ces écarts comparent le câblage (prises, panneaux, cordons de brassage et de prise) à ce qui est déclaré autour. Plusieurs reposent sur le chemin câblé : deux ports sont câblés l'un à l'autre quand le premier est brassé vers une prise sur laquelle le second est branché (voir [Le chemin câblé](cablage.md#le-chemin-câblé)).

### Cordon entre deux locaux

Ce qu'il signifie : un cordon relie un port de panneau et un port d'équipement rangés dans deux locaux différents. Le local qui compte est celui du membre du port, sinon celui de l'équipement.

Exemple de raison : « Panneau dans Rez-de-chaussée › Local technique, sw-etage-1 dans 1er étage › Placard technique » (le cordon de dépannage de la démonstration). Chaque local est nommé sans ce que les deux ont en commun : sur un même étage, « Panneau dans Local technique, sw-atelier-01 dans Bureaux » ; dans deux bâtiments, le chemin entier.

Où il apparaît : page du panneau (colonne **Écarts** du tableau des ports, et triangle sous la case du port dans la vue du panneau) ; page de l'équipement, section **Ports** et section **Écarts**.

Quand il ne s'applique pas : l'équipement (et son membre) n'est rangé dans aucun local.

### Câblage sans liaison

Ce qu'il signifie : deux ports sont câblés l'un à l'autre, et aucun des deux n'a de liaison. Le câblage dit qu'ils se parlent : une liaison est attendue.

Exemple de raison : « sw-atelier-01 1/0/9 et ap-atelier-1 eth0 sont câblés par PB-A 9 › H.03, sans liaison » (la borne de la mezzanine de l'Atelier, dans la démonstration).

Où il apparaît : pages des deux équipements (section **Ports**, et section **Écarts**), page **Prises**, schéma physique, où le câblage est dessiné en deux traits à travers le panneau.

L'écart propose **Créer la liaison** : la page de l'équipement brassé vers le panneau s'ouvre avec la fenêtre **Relier un port**, les deux ports déjà choisis.

### Liaison contredite par le câblage

Ce qu'il signifie : un port est câblé à un autre port que celui de sa liaison. La liaison ou le câblage est faux.

Exemple de raison : « Le câblage de ap-etage-2 eth0 mène à sw-etage-2 1/0/16 ; la liaison, à sw-etage-2 1/0/21 » (la borne du 2e étage du Siège, branchée sur la prise C.10).

Où il apparaît : page **Liaisons** et tableau **Liaisons** des deux équipements, section **Écarts** des deux équipements, schéma physique : la liaison garde son trait direct, et le câblage se dessine à côté. Le même écart n'est compté qu'une fois dans le résumé et la liste du schéma.

### Câblage d'une liaison retirée

Ce qu'il signifie : la liaison entre deux ports est retirée, mais les cordons qui les câblent sont encore déclarés.

Exemple de raison : « sw-atelier-01 1/0/10 et ap-atelier-2 eth0 sont encore câblés par PB-A 10 › H.04 ; leur liaison est retirée » (la borne déplacée de l'Atelier).

Où il apparaît : page **Liaisons**, pages des deux équipements, schéma physique, où seul le câblage est dessiné, avec l'écart.

### Port relié sans branchement

Ce qu'il signifie : un port relié à un autre équipement est brassé vers une prise où rien n'est branché. Le port d'en face devrait y être branché.

Exemple de raison : « sw-etage-1 1/0/22 est relié à ap-salle-reunion eth0 et brassé vers B.14, où rien n'est branché ».

Où il apparaît : page **Prises**, page de l'équipement (section **Ports**, avec la ligne du port dans la section **Écarts**), page **Liaisons** et tableau **Liaisons** de l'équipement, schéma physique.

L'écart propose **Brancher**, qui branche le port d'en face sur la prise, quand il est du même site que la prise, que son équipement n'est pas retiré et qu'il n'a encore aucun cordon.

Quand il ne s'applique pas : le port de panneau n'a pas de prise (un panneau de rocade, comme PB-FO dans la démonstration, relie des liaisons sans prise) ; la liaison est retirée.

### Prise non brassée

Ce qu'il signifie : une adresse active est branchée sur une prise qui ne mène à aucun port d'équipement, soit parce que la prise n'est reliée à aucun port de panneau, soit parce que ce port de panneau n'a pas de cordon.

Exemple de raison : « Prise R.05 non brassée » (l'imprimante de l'accueil du Siège, dans la démonstration).

Où il apparaît : page **Prises** ; page du panneau ; sur l'adresse, dans la page du sous-réseau et la page de l'équipement. Un seul écart par prise, quel que soit le nombre d'adresses actives branchées.

Quand il ne s'applique pas : seules les adresses au statut **Actif** comptent. Une adresse **Prévu** ou **Retiré** sur une prise non brassée n'est pas signalée.

### VLAN non porté

Ce qu'il signifie : une adresse est branchée sur une prise brassée vers un port, et ce port ne porte pas le VLAN du sous-réseau de l'adresse. En accès, le port porte son **VLAN** et son **VLAN voix** ; en trunk, son **VLAN natif** et ses **VLAN étiquetés** ; pour un port d'agrégat, ce sont ceux de l'agrégat. La comparaison porte sur le VLAN lui-même, pas sur son numéro : le VLAN 10 d'un autre site est un autre réseau.

Exemples de raison :

- « sw-etage-2 1/0/14 en accès VLAN 10, adresse 10.10.30.53 du VLAN 30 » (la prise C.09 de la démonstration) ;
- « sw-etage-1 1/0/10 en accès VLAN 10, adresse 10.10.50.16 du VLAN 50 » (la prise B.10) ;
- « … en accès sans VLAN, adresse … du VLAN … » pour un port en accès sans VLAN ;
- « … en trunk sans le VLAN 50, celui de l'adresse 10.10.50.16 » pour un trunk.

Quand le port porte un autre VLAN de même numéro, chaque VLAN est nommé avec son site : « 10 du site Siège ».

Où il apparaît : page **Prises**, page du panneau, port au bout de la prise (page de l'équipement), et sur l'adresse (page du sous-réseau, page de l'équipement). Un écart par adresse concernée.

Quand il ne s'applique pas :

- l'adresse est **Retiré** : elle n'est gardée que pour mémoire. Une adresse **Prévu** est comparée : la prévoir sur le mauvais port est une erreur de conception ;
- le sous-réseau de l'adresse n'a pas de VLAN ;
- le port n'a pas de mode (port de serveur ou de pare-feu).

### Résorber un écart de câblage

Rôle : `editor` ou `admin`.

- Cordon entre deux locaux : si le cordon est réel, laissez l'écart et décrivez-le sur le cordon (**Modifier le cordon**) ; sinon, corrigez le **Local** de l'équipement ou de son membre, ou rebrassez. Les deux bouts d'un cordon ne changent pas : pour le passer sur un autre port, **Débrasser** le port, puis le brasser de nouveau avec **Brasser…** depuis la page du bon panneau.
- Câblage sans liaison : **Créer la liaison**, depuis l'écart, si les deux ports se parlent ; sinon **Débrancher** l'équipement de la prise ou **Débrasser** le port.
- Liaison contredite par le câblage : corrigez la liaison (supprimez-la et reliez le bon port), ou rebrassez la prise vers le port de la liaison, ou branchez l'équipement sur la bonne prise.
- Câblage d'une liaison retirée : **Débrancher** l'équipement de la prise, ou **Débrasser** le port, si le câblage n'existe plus ; sinon remettez la liaison au statut **Active**.
- Port relié sans branchement : **Brancher**, depuis l'écart, si l'équipement d'en face est bien branché sur cette prise ; sinon **Débrasser** le port, ou corrigez la liaison.
- Prise non brassée : sur la page du panneau, reliez la prise à un port de panneau (**Relier une prise**) si ce n'est pas fait, puis brassez ce port vers le port du switch (**Brasser…**). Si l'appareil n'est en fait pas branché là, changez la **Prise** de l'adresse dans **Modifier l'adresse IP**.
- VLAN non porté : réglez le port au bout de la prise avec **Mode et VLAN** pour qu'il porte le VLAN de l'adresse, ou rebrassez la prise vers un port qui le porte, ou changez la **Prise** de l'adresse si elle est fausse.

Voir aussi [le câblage](cablage.md), [les liaisons](liaisons.md) et [les équipements et leurs ports](equipements-et-ports.md).
