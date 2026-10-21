<!-- torsade/documentation/guide/norme.md -->

# La norme : blocs, règle d'adressage et catalogue des VLAN

Cette page couvre ce que l'organisation décide une fois pour tous ses sites : les blocs d'adressage, la règle d'adressage et le catalogue des VLAN, puis l'action qui applique cette norme à un site. Ces trois pages sont dans le menu **Norme**. Tout y est facultatif : sans norme, Torsade documente le réseau tel quel, sans rien proposer.

## Ce qu'est la norme

- Un bloc d'adressage est un espace d'adresses mis de côté : pour l'organisation entière (par exemple `10.0.0.0/8`), ou pour un site (par exemple `10.20.0.0/16`, qui contient tous les sous-réseaux de ce site). Un site peut avoir plusieurs blocs.
- La règle d'adressage dit comment l'organisation numérote ses réseaux : son bloc est découpé en un bloc par site, dans l'ordre des numéros de site, et chaque bloc de site en un sous-réseau par VLAN, dans l'ordre des VID. Sans niveau de site, le bloc de l'organisation est découpé directement en un sous-réseau par VLAN, le même pour tous les sites. Il y a une seule règle par installation.
- Le catalogue des VLAN liste les VLAN normalisés de l'organisation (VID, nom, description). Une entrée vaut pour tous les sites ; chaque site y rattache son propre VLAN.

La norme propose et signale ; elle ne bloque presque rien. Un VLAN ou un sous-réseau qui s'en écarte est enregistré, puis montré comme un [écart](ecarts.md). Torsade refuse seulement ce qui n'a de sens dans aucun réseau. La raison : on documente souvent un réseau justement pour le corriger, et il faut pouvoir le saisir tel qu'il est.

Dans la démonstration, l'organisation a le bloc `10.0.0.0/8` (« Adressage interne »), une règle « un /16 par site, un /24 par VLAN », et un catalogue de six entrées : 10 Bureautique, 20 Serveurs, 40 Wi-Fi invités, 50 Vidéosurveillance, 60 Production, 99 Administration réseau.

## Consulter les blocs d'adressage

Rôle : `reader`, `editor` ou `admin`.

Menu **Norme**, puis **Blocs d'adressage**. La page montre :

- **Blocs de l'organisation** : chaque bloc de l'organisation, avec en dessous, en retrait, les blocs qu'il contient (autres blocs de l'organisation et blocs de site) ;
- **Blocs de site hors des blocs de l'organisation** : les blocs de site qu'aucun bloc de l'organisation ne contient.

Le tableau a les colonnes **Bloc**, **Site**, **Espace occupé** et **Statut**. L'imbrication se déduit des adresses : Torsade ne la stocke pas.

## Lire la page d'un bloc

Rôle : `reader`, `editor` ou `admin`.

Cliquez sur un bloc. La page affiche :

- **Masque** et **Adresses** : le masque, le nombre d'adresses et la première et la dernière ;
- **Espace occupé** : la part du bloc prise par des sous-réseaux, en barre ;
- **Espace libre** : le nombre d'adresses libres, et en combien de préfixes ;
- **Sous-réseaux** : les sous-réseaux dont les adresses sont dans ce bloc, quel que soit leur site, avec **Type**, **Site**, **VLAN** et **Statut** ;
- **Espace libre** (liste) : les adresses du bloc qui n'appartiennent à aucun sous-réseau, sous forme des plus grands préfixes possibles, chacun avec son nombre d'adresses. Les 50 premiers sont listés ; au-delà, la page le dit (« Les 50 premiers préfixes sur 73 sont listés. ») ;
- les écarts des sous-réseaux du bloc.

Exemple : la page de `10.20.0.0/16` (« Réseaux de l'atelier ») liste les six sous-réseaux de l'Atelier, de `10.20.10.0/24` à `10.20.99.0/24`, puis les préfixes encore libres.

## Créer un bloc d'adressage

Rôle : `editor` ou `admin`.

1. Menu **Norme**, **Blocs d'adressage**, puis **Nouveau bloc**.
2. Saisissez le bloc en entier, par exemple `10.30.0.0/16`, ou une adresse et choisissez le préfixe à côté. Une longueur tapée après l'adresse l'emporte sur la liste.
3. **Site** : un site, ou **Organisation (aucun site)** pour un bloc de l'organisation.
4. **Statut**, **Nom** (facultatif, 100 caractères au plus) et **Description** (facultative).
5. **Enregistrer**.

L'adresse doit être une adresse de réseau. Sinon Torsade propose la bonne : « 10.20.10.5/24 n'est pas une adresse de réseau. Vouliez-vous dire 10.20.10.0/24 ? ».

Depuis la page d'un site, le site est déjà choisi. Avec une règle d'adressage, la fenêtre d'un site propose aussi de créer le bloc que la règle attend (voir [Créer le bloc attendu d'un site](#créer-le-bloc-attendu-dun-site)).

### Ce que le formulaire annonce

Pendant la saisie d'un bloc de site, le formulaire compte les sous-réseaux de type segment du site qui tomberaient dedans. Exemple, pour l'Atelier, en tapant `10.20.60.0/24` : « Ce bloc contiendrait 1 des 6 sous-réseaux du site Atelier. ». Si aucun n'y tombe et qu'aucun autre bloc du site ne les contient, un avertissement s'ajoute : « Aucun ne s'y trouverait : ils resteraient signalés hors des blocs du site. ».

C'est une aide, jamais un refus. Elle évite une erreur fréquente : déclarer un réseau du site comme bloc. Sous le choix du site, le formulaire le rappelle : « Le bloc d'un site est l'enveloppe de tous ses sous-réseaux, par exemple 192.168.0.0/16 pour des VLAN en 192.168.10.0/24 et 192.168.20.0/24. Le réseau d'un LAN se déclare comme sous-réseau de son VLAN, pas comme bloc. Un site sans plan hiérarchique peut n'avoir aucun bloc. » La section **Blocs d'adressage** de la page d'un site le dit avec les mêmes mots, qu'il ait des blocs ou non.

### Ce qui est refusé

| Situation                                                                                          | Message                                                                                                |
| -------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| Le même bloc existe déjà                                                                           | « Ce bloc existe déjà. »                                                                               |
| Deux blocs de site se chevauchent, du même site ou non (par exemple `10.10.0.0/17` pour l'Atelier) | « Ce bloc chevauche le bloc 10.10.0.0/16 du site Siège. »                                              |
| Un bloc de site contiendrait un bloc de l'organisation                                             | « Un bloc de site ne peut pas contenir un bloc de l'organisation : il contiendrait … »                 |
| Un bloc de l'organisation serait dans un bloc de site                                              | « Un bloc de l'organisation ne peut pas se trouver dans le bloc d'un site : il serait dans … »         |
| Le bloc serait strictement dans un sous-réseau (par exemple `192.168.1.0/25`)                      | « Un bloc ne peut pas se trouver dans un sous-réseau : il serait dans le sous-réseau 192.168.1.0/24. » |

Dans l'autre sens, un sous-réseau ne peut pas être créé ni agrandi autour d'un bloc (voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md)). Un sous-réseau de la même taille qu'un bloc reste permis : un petit site peut n'avoir qu'un réseau.

Ces cas sont refusés parce qu'ils sont incohérents dans tous les réseaux : un bloc se découpe en sous-réseaux, jamais l'inverse. Un sous-réseau mal placé vis-à-vis des blocs, lui, est accepté et signalé comme écart.

## Modifier ou supprimer un bloc

Rôle : `editor` ou `admin`.

Sur la page du bloc, **Modifier** ouvre le même formulaire, avec les mêmes contrôles. **Supprimer** demande une confirmation : « 10.30.0.0/16 sera supprimé. Les sous-réseaux qu'il contient sont conservés tels quels. ». Aucun objet n'est attaché à un bloc : sa suppression ne touche à rien d'autre.

Le bloc dont part la règle d'adressage est protégé :

- il ne se supprime pas : « La règle d'adressage part de ce bloc : modifiez la règle ou supprimez-la d'abord. » ;
- il reste un bloc de l'organisation : « La règle d'adressage part de ce bloc : il reste un bloc de l'organisation. » ;
- son préfixe reste plus court que celui des blocs de site, pour que la règle puisse encore le découper ; sans niveau de site, plus court que celui des sous-réseaux de VLAN.

Son adresse, elle, peut changer : la règle propose alors d'autres adresses.

## Définir la règle d'adressage

Rôle : `editor` ou `admin`.

Il faut d'abord un bloc de l'organisation : sans lui, la page le dit et propose **Nouveau bloc**.

1. Menu **Norme**, puis **Règle d'adressage**.
2. S'il n'y a pas de règle, cliquez sur **Créer une règle**.
3. Renseignez :
    - **Bloc de l'organisation** : le bloc de départ ;
    - **Position de la passerelle** : **Première adresse utilisable**, **Dernière adresse utilisable** ou **Aucune passerelle** ;
    - **Niveau de site** : la longueur des blocs de site, par exemple **Blocs de site en /16**, ou **Aucun (un seul site)** ;
    - **Longueur des sous-réseaux de VLAN** : par exemple 24.
4. Facultatif : le **Découpage type** (voir plus bas).
5. **Enregistrer**.

Pour une première règle, le formulaire part du premier bloc de l'organisation, de blocs de site 8 bits plus longs, de sous-réseaux 16 bits plus longs, et de la passerelle en première adresse.

La liste **Niveau de site** propose les longueurs que laissent le bloc et les sous-réseaux de VLAN. Les longueurs doivent croître du bloc de l'organisation aux blocs de site, puis aux sous-réseaux de VLAN, un /30 au plus (un /30 a encore la place d'une passerelle et d'un poste). Sinon le champ en cause est refusé, par exemple « Les sous-réseaux de VLAN sont découpés dans les blocs de site : leur longueur doit être supérieure à 16. ».

Pendant la saisie, la page montre ce que donne la règle, en trois phrases. Avec la règle de la démonstration :

- « Blocs de site en /16 dans 10.0.0.0/8 : numéros 0 à 255. »
- « Sous-réseaux de VLAN en /24 : VID 1 à 255. »
- « Exemple : site 20, VLAN 10 → 10.20.10.0/24, passerelle 10.20.10.1. »

### Une règle sans niveau de site

**Aucun (un seul site)** convient à une installation qui n'a qu'un site, avec le plan courant `192.168.<VID>.0/24` : les sous-réseaux de VLAN sont rangés par VID directement dans le bloc de l'organisation. Le formulaire le rappelle : « Sans niveau de site, tous les sites attendent les mêmes sous-réseaux : ce choix convient à une installation à un seul site. »

Avec le bloc `192.168.0.0/16` et des sous-réseaux en /24, le résumé dit :

- « Aucun niveau de site. »
- « Sous-réseaux de VLAN en /24 dans 192.168.0.0/16 : VID 1 à 255. »
- « Exemple : VLAN 20 → 192.168.20.0/24, passerelle 192.168.20.1. »

Sans niveau de site, la règle n'utilise pas le numéro des sites et n'attend aucun bloc de site : ni écart de numéro, ni bloc attendu non déclaré, ni bloc proposé dans la fenêtre d'un site. Les sous-réseaux de VLAN doivent seulement rester plus longs que le bloc de l'organisation : « Sans niveau de site, les sous-réseaux de VLAN sont découpés dans le bloc de l'organisation : leur longueur doit être supérieure à 16. »

Avec plusieurs sites, tous attendent les mêmes sous-réseaux : le VLAN 20 d'un deuxième site se voit proposer `192.168.20.0/24`, que le premier a déjà, et sa création est refusée comme tout chevauchement (« Ce sous-réseau chevauche 192.168.20.0/24. »). Un autre sous-réseau pour ce VLAN porte l'écart « Attendu par la règle : 192.168.20.0/24 ». Passer d'une règle avec niveau à une règle sans, ou l'inverse, ne change aucun objet : seules changent les propositions et les écarts.

### Les calculs de la règle

Le numéro du site donne le rang de son bloc dans le bloc de l'organisation ; le VID donne le rang du sous-réseau dans le bloc du site. Avec le bloc `10.0.0.0/8`, des blocs de site en /16, des sous-réseaux de VLAN en /24 et la passerelle en première adresse :

| Élément                           | Calcul                                                                                       | Résultat        |
| --------------------------------- | -------------------------------------------------------------------------------------------- | --------------- |
| Numéros de site permis            | un /8 contient 2^8 blocs en /16, le premier étant le site 0                                  | 0 à 255         |
| Bloc attendu du site numéro 20    | `10.0.0.0` + 20 × 65 536 adresses                                                            | `10.20.0.0/16`  |
| VID couverts                      | un /16 contient 2^8 sous-réseaux en /24 ; le rang 0 ne sert pas, aucun VLAN n'ayant le VID 0 | 1 à 255         |
| Sous-réseau du VLAN 10 du site 20 | `10.20.0.0` + 10 × 256 adresses                                                              | `10.20.10.0/24` |
| Passerelle proposée               | première adresse utilisable                                                                  | `10.20.10.1`    |

Avec **Dernière adresse utilisable**, la passerelle serait `10.20.10.254`. Avec **Aucune passerelle**, aucune n'est proposée.

Un site sans numéro, ou dont le numéro dépasse les limites, n'a pas de bloc attendu. Un VID au-delà des limites (par exemple 300 avec des /24 dans des /16) n'a pas de sous-réseau proposé : la règle ne le couvre pas, ce n'est pas un écart.

Sans niveau de site, le VID donne le rang du sous-réseau directement dans le bloc de l'organisation. Avec le bloc `192.168.0.0/16` et des sous-réseaux en /24 :

| Élément                 | Calcul                                                                                       | Résultat          |
| ----------------------- | -------------------------------------------------------------------------------------------- | ----------------- |
| VID couverts            | un /16 contient 2^8 sous-réseaux en /24 ; le rang 0 ne sert pas, aucun VLAN n'ayant le VID 0 | 1 à 255           |
| Sous-réseau du VLAN 20  | `192.168.0.0` + 20 × 256 adresses, quel que soit le site                                     | `192.168.20.0/24` |
| Sous-réseau du VLAN 256 | au-delà des limites : la règle ne le couvre pas                                              | aucun             |

### Le découpage type

Le **Découpage type** liste les plages en lesquelles un sous-réseau de VLAN est d'habitude découpé. Chaque plage a un **Type** (**DHCP**, **Fixe** ou **Réservée**), un **Début** et une **Fin** exprimés en décalages depuis l'adresse du réseau, et un **Nom** facultatif. Dans un /24, les décalages 2 à 20 donnent `.2` à `.20` ; la page affiche ce que donne chaque ligne dans le sous-réseau de l'exemple.

Le découpage de la démonstration :

| Type     | Début | Fin | Nom            | Dans `10.30.10.0/24` |
| -------- | ----- | --- | -------------- | -------------------- |
| Réservée | 2     | 20  | Infrastructure | `.2` à `.20`         |
| Fixe     | 21    | 99  | Adresses fixes | `.21` à `.99`        |
| DHCP     | 100   | 200 | —              | `.100` à `.200`      |

**Ajouter une plage** ajoute une ligne qui commence juste après la précédente. Une règle a au plus 20 plages. Sont refusés, sur leur ligne : un début à 0 (l'adresse du réseau), une fin avant le début, une fin au-delà de la dernière adresse utilisable, deux plages qui se chevauchent, et une plage DHCP qui contiendrait la passerelle de la règle (« Une plage DHCP ne peut pas contenir la passerelle de la règle, au décalage 1. »). Raccourcir les sous-réseaux sous des plages qui n'y tiendraient plus est refusé sur la longueur.

Un `reader` voit la règle en vigueur en lecture seule : les mêmes phrases, puis le **Découpage type**.

## Suivre l'application de la règle site par site

Rôle : `reader`, `editor` ou `admin`.

Sous la règle, le tableau **Application de la règle** donne pour chaque site son **Numéro**, son **Bloc attendu** et son **État**. Sans niveau de site, il n'y a rien à appliquer site par site, et la section le dit : « La règle n'attend aucun bloc de site. »

- **Bloc déclaré**, avec un lien vers le bloc ;
- **Sans numéro** : la règle n'attend rien pour ce site ;
- **Bloc attendu non déclaré** : un écart ;
- un numéro hors des limites de la règle, par exemple « Numéro hors des limites de la règle (0 à 255) » : un écart.

Dans la démonstration, Siège (10), Atelier (20) et Dépôt (30) ont chacun leur bloc déclaré : `10.10.0.0/16`, `10.20.0.0/16` et `10.30.0.0/16`.

## Créer le bloc attendu d'un site

Rôle : `editor` ou `admin`.

Deux chemins :

- dans le tableau **Application de la règle**, sur la ligne d'un site au bloc attendu non déclaré, cliquez sur **Créer ce bloc** ;
- dans la fenêtre d'un site (voir [Sites et lieux](sites-et-lieux.md)), le champ **Numéro** affiche « Bloc attendu par la règle : » suivi du bloc, et la case **Créer ce bloc** le crée avec le site.

Le bloc prend le statut du site : un site prévu reçoit un bloc prévu. Il passe par les mêmes contrôles qu'un bloc saisi à la main ; un bloc gênant le fait refuser, avec un message qui nomme le bloc attendu puis donne le motif.

## Modifier ou supprimer la règle

Rôle : `editor` ou `admin`.

Sur la page **Règle d'adressage**, changez les champs puis **Enregistrer**, ou cliquez sur **Supprimer la règle** et confirmez.

Modifier ou supprimer la règle ne modifie rien d'existant : blocs, sous-réseaux et plages restent tels quels. Seules changent les propositions et les écarts. Sans règle, Torsade ne propose plus d'adresses et ne signale plus d'écart d'adressage. Corriger un adressage existant reste un geste volontaire, fait objet par objet.

## Utiliser les propositions de la règle

Rôle : `editor` ou `admin`.

Avec une règle, les formulaires de création proposent des valeurs :

| Formulaire                                                                       | Ce qui est proposé                                    | À partir de                             |
| -------------------------------------------------------------------------------- | ----------------------------------------------------- | --------------------------------------- |
| Fenêtre d'un site                                                                | le bloc attendu, et la case **Créer ce bloc**         | le numéro du site                       |
| **Nouveau VLAN**, section **Sous-réseau**                                        | l'adresse, le préfixe et la passerelle du sous-réseau | le numéro du site et le VID             |
| **Nouveau sous-réseau**, quand un VLAN est choisi et que le type est **Segment** | l'adresse, le préfixe et la passerelle                | le numéro du site du VLAN et son VID    |
| **Appliquer la norme** sur un site                                               | le sous-réseau et la passerelle de chaque ligne       | le numéro du site et le VID de la ligne |

Sous un champ rempli par la règle, la mention **Proposé par la règle d'adressage** l'indique. Les valeurs suivent le site et le VID tant que vous ne les touchez pas ; une valeur modifiée à la main n'est plus jamais écrasée. La règle propose, elle n'impose pas : le serveur vérifie ce qui est envoyé, quelle que soit sa provenance.

Quand la règle ne propose rien, le formulaire dit pourquoi : le site n'a pas de numéro (« Le site … n'a pas de numéro : la règle ne propose rien »), son numéro dépasse les limites (« La règle ne couvre pas le numéro de site 300 »), ou le VID les dépasse (« La règle ne couvre pas le VID 300 »). Sans niveau de site, seul le VID compte : un site sans numéro reçoit les mêmes propositions que les autres.

Les formulaires de modification ne proposent rien : pour l'existant, seuls les écarts signalent les différences.

Si la règle a un découpage type, une case **Appliquer le découpage type**, cochée par défaut, accompagne la création d'un sous-réseau (depuis le formulaire d'un VLAN ou celui d'un sous-réseau porté par un VLAN). Elle crée les plages de la règle, décalées depuis l'adresse du sous-réseau. Sur la page d'un sous-réseau qui n'a encore aucune plage, le bouton **Appliquer le découpage type** fait de même après coup (voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md)).

## Consulter le catalogue des VLAN

Rôle : `reader`, `editor` ou `admin`.

Menu **Norme**, puis **Catalogue des VLAN**. Le tableau a une ligne par entrée, triée par **VID**, avec son **Nom**, puis une colonne par site : dans chaque case, le VID du VLAN de ce site qui suit l'entrée, avec ses écarts éventuels.

La page d'une entrée montre le tableau **Sur chaque site** : pour chaque site, le VLAN rattaché, son **Statut** et son **Écart**. Une entrée est suivie par un VLAN par site au plus. Sur un site qui ne la suit pas, le lien **Créer ce VLAN** ouvre le formulaire d'un VLAN avec le site et l'entrée déjà choisis.

## Créer, modifier ou supprimer une entrée du catalogue

Rôle : `editor` ou `admin`.

1. Sur la page **Catalogue des VLAN**, cliquez sur **Nouvelle entrée**.
2. Renseignez **VID** (1 à 4094), **Nom** (64 caractères au plus) et **Description** (facultative).
3. **Enregistrer**.

Le VID et le nom sont chacun uniques dans le catalogue. Une entrée vaut pour tous les sites : on ne crée pas un deuxième « 10 Bureautique » pour un autre site. Le refus nomme l'entrée en place, par exemple « Le VLAN 10 « Bureautique » est déjà au catalogue. Une entrée vaut pour tous les sites : rattachez-y le VLAN de ce site depuis la page de l'entrée. », et donne un lien vers elle : **Ouvrir l'entrée 10 — Bureautique**.

**Modifier** change l'entrée sans toucher aux VLAN rattachés : ce qui diffère devient un écart.

**Supprimer** est refusé tant qu'un VLAN est rattaché à l'entrée : « Un VLAN est encore rattaché à cette entrée : détachez-le d'abord. ». Pour détacher un VLAN, choisissez **Aucune entrée** dans le champ **Entrée du catalogue** de son formulaire.

## Rattacher des VLAN existants à une entrée

Rôle : `editor` ou `admin`.

Trois façons, selon le cas.

### En série, pour les VLAN qui ont déjà le VID de l'entrée

Sur la page de l'entrée, la section **Rattacher des VLAN existants** liste, sur tous les sites, les VLAN de ce VID qui ne suivent encore aucune entrée. Cochez ceux qui sont bien ce VLAN (**Tout cocher** existe), puis **Rattacher la sélection**. Tout est rattaché, ou rien : chaque refus s'affiche sur la ligne de son VLAN (VLAN déjà rattaché, site qui suit déjà l'entrée…).

Exemple dans la démonstration : la Téléphonie n'est pas au catalogue. Créez l'entrée 30 « Téléphonie » : la section propose alors les VLAN 30 du Siège et de l'Atelier.

### Un par un, depuis la page d'un VLAN

Quand le catalogue a une entrée de même VID et que le site ne la suit pas encore, la page du VLAN propose un bouton qui nomme l'entrée, par exemple **Rattacher à 10 — Bureautique**.

### Un par un, pour un autre VID

Un VLAN dont le VID diffère de l'entrée ne se rattache pas en série : la section ne le propose pas, et le serveur le refuserait (« Ce VLAN porte le numéro 150, pas le 50 de l'entrée : rattachez-le depuis son formulaire. »). Ouvrez son formulaire et choisissez l'entrée dans **Entrée du catalogue**. C'est une décision à prendre VLAN par VLAN, en connaissance de cause.

### Les écarts avec le catalogue

Un VLAN rattaché peut différer de son entrée ; ce n'est jamais refusé, c'est signalé :

- VID différent : « VID 150, catalogue : 50 » ;
- nom différent, sans tenir compte de la casse (« Serveurs » et « SERVEURS » sont le même nom) : par exemple « Nom Vidéo, catalogue : Vidéosurveillance ».

Dans la démonstration, le VLAN 150 Vidéosurveillance de l'Atelier, numéroté 150 par l'installateur, suit l'entrée 50 : il montre l'écart de VID. Voir [Écarts](ecarts.md).

## Appliquer la norme à un site

Rôle : `editor` ou `admin`.

Cette action crée en une fois, sur un site, les VLAN du catalogue qu'il ne suit pas encore, avec leurs sous-réseaux.

1. Sur la page d'un site, cliquez sur **Appliquer la norme**. La page s'intitule par exemple « Appliquer la norme au site Dépôt ».
2. Choisissez le **Statut de ce qui est créé** : il vaut pour tous les VLAN et sous-réseaux créés. Il est **Prévu** par défaut, car appliquer une norme est d'abord un geste de conception.
3. Chaque entrée du catalogue que le site ne suit pas encore a sa ligne, cochée par défaut (**Tout décocher** et **Tout cocher** existent). Sur chaque ligne cochée :
    - **VID** et **Nom** sont préremplis par l'entrée et restent modifiables ;
    - quand la règle d'adressage propose un sous-réseau pour ce VID, **Créer le sous-réseau** est coché, et **Sous-réseau** et **Passerelle** sont remplis, avec la mention **Proposé par la règle d'adressage** ; sinon la ligne dit pourquoi, et vous pouvez cocher la case et saisir le sous-réseau vous-même ;
    - si la règle a un découpage type, la ligne montre les plages qu'il créerait dans ce sous-réseau, chacune avec son type, sa première et sa dernière adresse ; elles sont recalculées quand vous corrigez le sous-réseau. La case **Découpage type**, cochée par défaut, décide si elles sont créées ; elles ne se modifient pas ici, mais ensuite sur la page du sous-réseau ;
    - quand le découpage ne tient pas dans le sous-réseau saisi, la plage qui déborde est marquée « hors du sous-réseau », la ligne dit « Le découpage type ne tient pas dans ce sous-réseau : aucune plage n'est créée. » et la case est inactive. Sans découpage type dans la règle, la ligne dit « La règle d'adressage n'a pas de découpage type : aucune plage n'est créée. » ;
    - tant que **Sous-réseau** ne contient pas un sous-réseau complet, avec son préfixe, la ligne invite à le saisir pour voir les plages.
4. Cliquez sur le bouton du bas, par exemple « Créer les 6 VLAN cochés ».

Tout est créé, ou rien. En cas de refus, chaque erreur s'affiche sur sa ligne et rien n'est enregistré ; corrigez et recommencez. Chaque ligne passe par les mêmes contrôles qu'un VLAN créé à la main. Un cas propre à cette page : si le site a déjà un VLAN de ce VID qui ne suit aucune entrée, la ligne est refusée avec l'invitation à le rattacher (« Le VLAN 30 « Téléphonie » existe déjà sur ce site, hors catalogue : rattachez-le depuis sa page. »).

Sans règle d'adressage, la page fonctionne aussi : elle le dit, aucun sous-réseau n'est proposé, et vous les saisissez ou créez les VLAN seuls. Si le site suit déjà toutes les entrées, ou si le catalogue est vide, la page n'a rien à proposer et offre **Retour au site**.

Exemple : le site Dépôt (numéro 30, bloc `10.30.0.0/16`) n'a encore aucun VLAN. Appliquer la norme y propose les six entrées du catalogue ; la ligne 10 Bureautique reçoit `10.30.10.0/24`, passerelle `10.30.10.1`, et montre les plages du découpage type : réservée `10.30.10.2` à `10.30.10.20`, fixe `10.30.10.21` à `10.30.10.99`, DHCP `10.30.10.100` à `10.30.10.200`.
