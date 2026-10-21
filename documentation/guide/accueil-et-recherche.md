<!-- torsade/documentation/guide/accueil-et-recherche.md -->

# Accueil et recherche

Cette page décrit l'accueil, ce que chacun de ses modules montre et à qui, puis la recherche : ce qu'elle comprend, ses raccourcis et sa page de résultats. Les exemples viennent de la démonstration, dont le réseau est fictif.

## L'accueil

Rôle : `reader`, `editor` ou `admin`.

L'**Accueil** est la page qui s'ouvre après la connexion, et la première entrée du menu. Il est fait de modules, dans cet ordre ; un module que votre rôle ne permet pas de voir n'apparaît pas, et Torsade ne lui envoie aucune donnée.

| Module                            | Pour                                                                         | Ce qu'il montre                                                   |
| --------------------------------- | ---------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| **Rechercher**                    | tous                                                                         | le champ de la recherche                                          |
| **Premiers pas**                  | `editor` et `admin` tant que la documentation commence ; `reader`, sans site | les étapes d'une documentation, ou qui doit créer le premier site |
| **Synthèse**                      | tous                                                                         | ce qui est documenté, en nombres                                  |
| **Sites**                         | tous                                                                         | les sites, avec ce qu'ils contiennent et leurs schémas            |
| **Sous-réseaux les plus remplis** | tous                                                                         | les sous-réseaux les plus proches d'être pleins                   |
| **Activité récente**              | `admin`                                                                      | les dernières entrées du journal                                  |

Sur un grand écran, **Sites** et **Sous-réseaux les plus remplis** sont côte à côte ; les autres modules prennent toute la largeur.

### Rechercher

Le champ de la recherche, le même que celui de l'en-tête de chaque page : ce que vous y tapez ouvre la fenêtre de recherche, où la saisie continue (voir [La fenêtre de recherche](#la-fenêtre-de-recherche)).

### Premiers pas

Pour un `editor` ou un `admin`, le module s'affiche tant qu'il manque un site, un VLAN, un sous-réseau ou un équipement. Il donne les étapes d'une documentation dans l'ordre du menu, chacune avec le lien vers sa page ; le guide les reprend dans [commencer à documenter un réseau](premiers-pas.md#commencer-à-documenter-un-réseau) :

1. **Créer un site** ;
2. **Déclarer la norme** (blocs d'adressage, règle d'adressage, catalogue des VLAN), étape facultative, faite dès qu'un bloc, la règle ou une entrée du catalogue existe ;
3. **Créer les VLAN et leurs sous-réseaux** ;
4. **Documenter les adresses IP**, dont le lien mène à la liste des sous-réseaux : une adresse se documente dans son sous-réseau ;
5. **Créer les équipements** ;
6. **Relier les équipements** ;
7. **Documenter les prises**.

Une étape est faite dès que chaque sorte d'objet dont elle parle en a au moins un : **Créer les VLAN et leurs sous-réseaux** demande un VLAN et un sous-réseau. Une étape faite porte une coche et la mention **Fait**.

Pour un `reader`, le module ne s'affiche que sur une installation sans aucun site, et dit seulement qu'un éditeur ou un administrateur doit créer le premier site.

### Synthèse

Le nombre de sites, de VLAN, de sous-réseaux, d'adresses IP, d'équipements, de liaisons et de prises qui ne sont pas retirés. Quand certains sont prévus, le nombre le dit dessous : « dont 3 prévus », « dont 2 prévues » pour les adresses, les liaisons et les prises. Chaque nombre mène à sa liste, sauf celui des adresses IP, qui n'en ont pas : elles se lisent sur la page de leur sous-réseau.

### Sites

Les sites qui ne sont pas retirés, par nom : leur code, leur statut, et le nombre de VLAN, de sous-réseaux et d'équipements qu'ils contiennent sans compter les retirés. Chaque site mène à sa page, à son **Schéma physique** et à son **Schéma logique**. Le module en montre 12 ; au-delà, un lien mène à la liste de tous les sites.

### Sous-réseaux les plus remplis

Les cinq sous-réseaux de type segment, non retirés et ayant au moins une adresse documentée, dont l'occupation est la plus haute ; à égalité, par adresse. L'occupation se lit comme sur la page d'un sous-réseau : les adresses documentées sur les adresses utilisables, en nombres et en pourcentage : « 20 / 254 (7,9 %) » pour le sous-réseau `10.10.10.0/24` de la démonstration. Chaque sous-réseau mène à sa page.

### Activité récente

Pour un `admin` seulement : les dix dernières entrées du journal, présentées comme sur la page **Journal** (voir [lire le journal](comptes-et-journal.md#lire-le-journal)), et le lien **Ouvrir le journal**. Un `editor` ou un `reader` ne voit ni le module ni le lien.

## La recherche

Rôle : `reader`, `editor` ou `admin`.

Une seule recherche, depuis l'en-tête de chaque page et depuis l'accueil, porte sur tout ce qui documente le réseau : sites et lieux, VLAN, catalogue des VLAN, blocs d'adressage, sous-réseaux, adresses IP, équipements, ports, prises, panneaux de brassage et modèles d'équipement. Elle ne cherche jamais dans les comptes ni dans le journal. Elle ne fait que lire : rien n'est écrit au journal.

### Ce que la recherche comprend

Ce que vous tapez est d'abord lu comme une forme reconnue, qui donne ses propres résultats en tête ; sinon, comme un texte.

| Ce que vous tapez        | Exemple de la démonstration | Ce que la recherche trouve                                                                                                                                          |
| ------------------------ | --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| une adresse IPv4         | `10.10.10.20`               | l'adresse documentée (l'imprimante `imp-accueil`), le sous-réseau `10.10.10.0/24` qui la contient et les blocs qui la contiennent                                   |
| un préfixe CIDR          | `10.10.20.0/24`             | le sous-réseau ou le bloc égal ; s'il n'y en a pas, ceux qui le contiennent et ceux qu'il contient, 50 au plus en tout                                              |
| un début d'adresse       | `10.20.`                    | les adresses et les sous-réseaux dont l'adresse commence ainsi, ici ceux de l'atelier ; de un à trois nombres complets, chacun suivi d'un point                     |
| un numéro de VLAN        | `10`                        | les VLAN de ce numéro sur tous les sites (Bureautique du siège et de l'atelier), l'entrée 10 du catalogue, puis ce dont le nom contient « 10 »                      |
| une adresse MAC          | `02:00:0a:0a:0a:14`         | les adresses de cette MAC, ici `10.10.10.20`, quelle que soit sa notation : `02-00-0A-0A-0A-14`, `0200.0a0a.0a14` ou `02000a0a0a14`                                 |
| un début d'adresse MAC   | `02:00:0a:14`               | les adresses dont la MAC commence ainsi, au moins trois octets séparés par des deux-points ou des tirets, puis ce dont le nom le contient                           |
| un équipement et un port | `sw-etage-1 1/0/24`         | ce port de cet équipement ; le nom de l'équipement peut contenir des espaces, le port est le dernier mot                                                            |
| un texte                 | `quai`                      | ce dont le nom, la description ou un autre champ contient le texte : ici, entre autres, le switch `sw-quai`, la caméra `cam-quai` et le local « Quai d'expédition » |

Un nombre de 1 à 4094 est lu comme un numéro de VLAN ; au-delà, comme un texte. Un préfixe écrit avec des bits d'hôte est cherché à son adresse réseau, et la fenêtre le dit : `10.10.20.37/24` donne « Recherché à son adresse réseau : 10.10.20.0/24 ».

Un texte est cherché sans tenir compte des majuscules ni des accents : `etage` trouve « 1er étage ». Les caractères `%`, `_` et `\` sont cherchés tels quels. Le texte est cherché dans ces champs :

| Résultats            | Champs cherchés                                                                          |
| -------------------- | ---------------------------------------------------------------------------------------- |
| Sites                | nom, code                                                                                |
| Lieux                | nom et code d'un bâtiment, nom d'un étage, nom d'un local                                |
| VLAN                 | nom, description                                                                         |
| Catalogue des VLAN   | nom, description                                                                         |
| Blocs d'adressage    | nom, description                                                                         |
| Sous-réseaux         | nom, description                                                                         |
| Adresses IP          | nom d'hôte, description                                                                  |
| Équipements          | nom, fabricant, modèle, numéro de série (de l'équipement et de ses membres), description |
| Ports                | description                                                                              |
| Prises               | libellé, emplacement, description                                                        |
| Panneaux de brassage | nom, description                                                                         |
| Modèles d'équipement | nom, fabricant, modèle                                                                   |

Quand le texte se trouve dans un champ que le résultat ne montre pas, une description par exemple, le résultat cite ce champ autour du texte trouvé.

### L'ordre des résultats

Les résultats sont groupés par sorte d'objet, chaque groupe avec son nombre. Dans un groupe viennent d'abord les correspondances exactes, puis celles qui commencent par le texte, puis celles qui le contiennent ; à égalité, les objets prévus ou actifs avant les retirés, puis par nom ou par adresse. Un groupe qui contient une correspondance exacte passe avant les autres, puis viennent les groupes des formes reconnues, puis les autres dans un ordre fixe.

La partie de chaque résultat qui correspond à la saisie est en gras et soulignée. Un résultat prévu ou retiré dit son statut ; les objets retirés sont inclus.

### Où mène un résultat

Chaque résultat mène à la page de l'objet. Un objet sans page propre ouvre la page qui le montre, à sa ligne :

| Résultat                        | Page ouverte                                        |
| ------------------------------- | --------------------------------------------------- |
| une adresse IP                  | la page de son sous-réseau, à la ligne de l'adresse |
| un port                         | la page de son équipement, à la ligne du port       |
| un bâtiment, un étage, un local | la page de son site, à la ligne du lieu             |
| une prise                       | la liste des prises de son étage, à sa ligne        |
| un modèle d'équipement          | la liste des modèles, à sa ligne                    |

### Une adresse qui n'est pas documentée

Une adresse IPv4 qu'aucune adresse documentée ne porte est signalée : `10.10.10.50` donne « 10.10.10.50 n'est pas documentée. », suivi du sous-réseau qui la contient, « Elle se trouve dans le sous-réseau 10.10.10.0/24 ». Hors de tout sous-réseau documenté, la fenêtre dit « Aucun sous-réseau documenté ne la contient. ».

Rôle : `editor` ou `admin`.

Quand l'adresse est une adresse utilisable du sous-réseau qui la contient, le bouton **Documenter cette adresse** ouvre la page du sous-réseau et la fenêtre **Nouvelle adresse IP**, remplie avec l'adresse. Le formulaire garde ses propres refus : une adresse fixe dans une plage DHCP, par exemple (voir [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md)). L'adresse réseau et l'adresse de diffusion d'un sous-réseau ne donnent pas ce bouton.

## La fenêtre de recherche

Rôle : `reader`, `editor` ou `admin`.

Pour l'ouvrir :

- cliquez sur le champ **Rechercher** de l'en-tête, en haut de chaque page (sur un téléphone, la loupe seule) ;
- ou tapez `/`, ou Ctrl+K, quand le curseur n'est pas dans un champ de saisie : dans un champ, `/` reste un caractère ;
- ou tapez dans le champ du module **Rechercher** de l'accueil.

Les résultats arrivent au fil de la frappe, dès deux caractères ou dès un chiffre, une fois la frappe arrêtée un instant. La fenêtre montre cinq résultats par groupe et le nombre total de chaque groupe.

| Touche     | Effet                                                                  |
| ---------- | ---------------------------------------------------------------------- |
| ↑ et ↓     | passer d'un résultat à l'autre                                         |
| **Entrée** | ouvrir le résultat choisi ; sans résultat choisi, la page de résultats |
| **Échap**  | fermer la fenêtre, en gardant le texte tapé                            |

Le premier résultat est choisi dès son arrivée. Taper de nouveau retire ce choix jusqu'aux résultats du nouveau texte : **Entrée** ouvre alors la page de résultats de ce que vous avez tapé.

Le lien **Voir tous les résultats**, en bas de la fenêtre, ouvre la page de résultats.

**Échap**, comme le bouton **Fermer** (la croix en haut de la fenêtre), ferme la fenêtre sans effacer ce que vous avez tapé : rouverte, elle reprend la même recherche.

Une recherche de plus de 100 caractères est refusée : « Une recherche contient 100 caractères au plus. ». Chaque compte peut lancer 120 recherches par minute ; une frappe ordinaire en lance quelques-unes. Au-delà, la fenêtre dit « Trop de recherches en peu de temps : attendez un instant, puis tapez de nouveau. ». Si le serveur ne répond pas, elle le dit aussi, et reste utilisable.

## La page de résultats

Rôle : `reader`, `editor` ou `admin`.

La page de résultats, intitulée « Résultats pour « … » », montre tous les groupes, 25 résultats chacun ; **Afficher plus**, sous un groupe incomplet, ajoute les 25 suivants. Son adresse contient la recherche, par exemple `/search?q=sw-etage-1` : vous pouvez la garder dans vos favoris ou la transmettre à un collègue, qui verra les résultats que son propre rôle lui permet de voir. Les adresses des versions précédentes, comme `/recherche?q=sw-etage-1`, mènent à la même page : un lien déjà partagé marche toujours.
