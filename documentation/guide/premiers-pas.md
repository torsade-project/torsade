<!-- torsade/documentation/guide/premiers-pas.md -->

# Premiers pas

Cette page couvre ce qu'il faut savoir le premier jour : se connecter, choisir son mot de passe, protéger son compte par la double authentification, comprendre ce que permet son rôle, régler le thème, se repérer dans le menu, trouver la recherche et savoir par où commencer.

Torsade n'a pas d'inscription publique ni de réinitialisation du mot de passe par e-mail. Un compte est toujours créé par un administrateur, qui vous remet un mot de passe temporaire (voir [comptes et journal](comptes-et-journal.md)). Un serveur interne n'a souvent pas de relais de messagerie : c'est l'administrateur qui réinitialise un mot de passe perdu.

## Se connecter

Rôle : `reader`, `editor` ou `admin`.

1. Ouvrez l'adresse de Torsade : sans session ouverte, vous arrivez sur la page **Connexion**.
2. Saisissez votre **Adresse e-mail** et votre **Mot de passe**.
3. Cochez **Rester connecté sur cet appareil** si vous voulez retrouver votre session après la fermeture du navigateur. Ne la cochez pas sur un poste partagé.
4. Cliquez sur **Se connecter**.

Si la double authentification est active sur votre compte, une seconde page demande un code (voir [Se connecter avec la double authentification](#se-connecter-avec-la-double-authentification)).

Une adresse inconnue, un mot de passe faux et un compte désactivé donnent le même message, « Ces identifiants ne correspondent à aucun compte actif. » : rien ne laisse deviner si un compte existe. En cas d'oubli du mot de passe, demandez à un administrateur de le réinitialiser depuis la page **Utilisateurs**.

Pour quitter, ouvrez le menu de votre compte, en bas du menu latéral, puis cliquez sur **Se déconnecter**.

### Trop de tentatives

Les essais sont limités pour freiner qui chercherait un mot de passe :

| Essai                           | Limite                                                                      | Ensuite                                                                                        |
| ------------------------------- | --------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| Mot de passe à la connexion     | 5 échecs par minute pour une même adresse e-mail depuis une même adresse IP | Le formulaire affiche « Trop de tentatives de connexion. Veuillez réessayer dans … secondes. » |
| Code de double authentification | 5 essais par minute                                                         | Le même message, sous le champ du code ; le code n'est plus vérifié avant la fin de l'attente  |
| Changement de son mot de passe  | 6 essais par minute                                                         | Il faut patienter avant de réessayer                                                           |

Seuls les échecs comptent à la connexion : une connexion réussie ne consomme rien. Chaque échec est inscrit au journal, avec sa raison et l'adresse IP.

## Choisir son mot de passe à la première connexion

Rôle : `reader`, `editor` ou `admin`.

Le mot de passe remis par l'administrateur est temporaire : quelqu'un d'autre l'a vu. Tant qu'il n'est pas remplacé, Torsade n'ouvre aucune autre page que celle-ci.

1. Connectez-vous avec le mot de passe temporaire. La page **Choisissez votre mot de passe** s'affiche.
2. Saisissez le **Mot de passe temporaire**.
3. Saisissez un **Nouveau mot de passe** d'au moins 12 caractères, différent du mot de passe temporaire, puis répétez-le dans **Confirmer le mot de passe**.
4. Cliquez sur **Enregistrer et continuer**. Vous arrivez sur l'accueil.

Le lien **Se déconnecter** reste disponible si vous voulez finir plus tard. La même page revient après chaque réinitialisation de votre mot de passe par un administrateur.

## Changer son mot de passe

Rôle : `reader`, `editor` ou `admin`.

1. Ouvrez le menu de votre compte, en bas du menu latéral, puis **Paramètres**, puis **Sécurité**. Torsade peut d'abord vous demander votre mot de passe (**Confirmer le mot de passe**) : cette page est protégée.
2. Dans **Modifier le mot de passe**, saisissez le **Mot de passe actuel**, le **Nouveau mot de passe** (12 caractères au moins, différent de l'actuel) et sa confirmation.
3. Cliquez sur **Enregistrer**.

Vos autres sessions sont fermées : celles ouvertes sur d'autres postes ou navigateurs, y compris celles où **Rester connecté sur cet appareil** était coché. La session en cours reste ouverte. Si l'ancien mot de passe était connu de quelqu'un d'autre, cette personne perd donc l'accès. Le changement est inscrit au journal.

## Modifier son nom

Rôle : `reader`, `editor` ou `admin`.

Dans **Paramètres**, puis **Profil**, changez votre **Nom** et cliquez sur **Enregistrer**. L'adresse e-mail sert d'identifiant de connexion : seul un administrateur peut la modifier.

## Activer la double authentification

Rôle : `reader`, `editor` ou `admin`.

La double authentification demande, en plus du mot de passe, un code à six chiffres produit par une application d'authentification (application dite TOTP) sur votre téléphone. Elle est facultative et désactivée au départ, pour tous les comptes. Vous seul pouvez l'activer, depuis votre appareil : un administrateur qui l'activerait à votre place détiendrait le secret, ce qui annulerait le second facteur.

1. Ouvrez **Paramètres**, puis **Sécurité**, et descendez jusqu'à la section **Double authentification**.
2. Cliquez sur **Activer la double authentification**. Une fenêtre affiche un QR code.
3. Scannez le QR code avec votre application d'authentification. Si vous ne pouvez pas scanner, recopiez la clé affichée sous **ou saisissez la clé manuellement**.
4. Cliquez sur **Continuer**.
5. À l'étape **Vérifier le code d'authentification**, saisissez le code à six chiffres affiché par l'application, puis cliquez sur **Confirmer**.

La double authentification n'est active qu'après cette confirmation. Si vous fermez la fenêtre avant, le bouton **Poursuivre la configuration** permet de la rouvrir.

### Conserver les codes de récupération

Une fois la double authentification active, la section **Codes de récupération** apparaît sur la même page. Ces codes remplacent l'application si vous perdez votre téléphone.

- **Afficher les codes de récupération** les montre ; **Masquer les codes de récupération** les cache.
- Rangez-les dans un endroit sûr, un gestionnaire de mots de passe par exemple, hors du téléphone qui porte l'application.
- Chaque code ne sert qu'une fois : il est retiré après usage.
- **Régénérer les codes** remplace la liste par une nouvelle ; les anciens codes ne servent plus.

## Se connecter avec la double authentification

Rôle : `reader`, `editor` ou `admin`.

1. Connectez-vous avec votre adresse e-mail et votre mot de passe. La page **Code d'authentification** s'affiche.
2. Saisissez le code à six chiffres affiché par votre application, puis cliquez sur **Continuer**.

Sans votre téléphone, cliquez sur **Se connecter avec un code de récupération**, saisissez l'un de vos codes de récupération, puis cliquez sur **Continuer**. Ce code est alors consommé. Le lien **Se connecter avec un code d'authentification** ramène au code de l'application.

Si vous avez perdu à la fois votre téléphone et vos codes de récupération, un administrateur peut réinitialiser votre double authentification (voir [comptes et journal](comptes-et-journal.md)).

## Désactiver la double authentification

Rôle : `reader`, `editor` ou `admin`.

Dans **Paramètres**, puis **Sécurité**, cliquez sur **Désactiver la double authentification**. La connexion ne demande plus que le mot de passe. La désactivation est inscrite au journal.

Ce bouton n'existe pas quand un administrateur exige la double authentification sur votre compte : la page affiche alors « Un administrateur exige la double authentification sur votre compte : elle ne peut pas être désactivée. », et Torsade refuse aussi la demande elle-même.

## Configurer la double authentification exigée par un administrateur

Rôle : `reader`, `editor` ou `admin`.

Un administrateur peut exiger la double authentification sur un compte. Dès votre page suivante, même dans une session déjà ouverte, Torsade vous envoie sur la page **Configurez la double authentification** et n'ouvre rien d'autre tant qu'elle n'est pas active.

1. Si Torsade le demande, confirmez votre mot de passe.
2. Suivez les mêmes étapes que dans [Activer la double authentification](#activer-la-double-authentification) : QR code, **Continuer**, code à six chiffres, **Confirmer**.
3. Notez vos codes de récupération, affichés sur la page, puis cliquez sur **J'ai conservé mes codes de récupération**. Vous arrivez sur l'accueil.

Le lien **Se déconnecter** reste disponible. Si votre compte porte aussi un mot de passe temporaire, Torsade vous fait d'abord choisir votre mot de passe, puis configurer la double authentification ; la confirmation du mot de passe n'est alors pas redemandée.

Tant que l'exigence est en place, vous ne pouvez plus désactiver la double authentification. Si un administrateur la réinitialise (téléphone perdu), vous la configurez de nouveau à votre page suivante.

## Ce que permet chaque rôle

Torsade a trois rôles fixes, choisis par un administrateur pour chaque compte. Il n'y a pas de système de permissions plus fin.

| Rôle     | Libellé à l'écran  | Ce qu'il permet                                                                                           |
| -------- | ------------------ | --------------------------------------------------------------------------------------------------------- |
| `reader` | **Lecteur**        | Consulter toute la documentation réseau : sites, norme, réseau logique, réseau physique, schémas, écarts. |
| `editor` | **Éditeur**        | Tout ce que fait un lecteur, plus créer, modifier et supprimer les données réseau.                        |
| `admin`  | **Administrateur** | Tout ce que fait un éditeur, plus gérer les comptes (page **Utilisateurs**) et lire le **Journal**.       |

Un compte désactivé n'a plus aucun droit, quel que soit son rôle. Tout le monde peut gérer son propre compte : nom, mot de passe, double authentification, thème.

Votre rôle s'affiche sous votre nom, en bas du menu latéral, avec la version de Torsade. Masquer un bouton ou une entrée de menu n'est qu'un confort : chaque page et chaque action est vérifiée par le serveur, qui refuse ce que votre rôle ne permet pas.

## Choisir le thème

Rôle : `reader`, `editor` ou `admin`.

1. Ouvrez **Paramètres**, puis **Apparence**.
2. Choisissez **Clair**, **Sombre** ou **Système**. **Système** suit le réglage de votre ordinateur.

Le thème sombre est celui par défaut : c'est la référence de l'identité visuelle de Torsade. Le choix est gardé par votre navigateur, pas par votre compte : sur un autre poste ou un autre navigateur, il faut le refaire.

## Se repérer dans le menu

Rôle : `reader`, `editor` ou `admin`.

Le menu latéral suit l'ordre dans lequel on documente un réseau : où (les sites), selon quelles conventions (la norme), puis ce qui existe (le réseau logique et le réseau physique).

| Place                      | Entrées                                                                                  |
| -------------------------- | ---------------------------------------------------------------------------------------- |
| En tête, toujours visibles | **Accueil**, **Sites**                                                                   |
| Groupe **Norme**           | **Blocs d'adressage**, **Règle d'adressage**, **Catalogue des VLAN**                     |
| Groupe **Réseau logique**  | **VLAN**, **Sous-réseaux**, **Schéma logique**                                           |
| Groupe **Réseau physique** | **Équipements**, **Liaisons**, **Schéma physique**, **Prises**, **Modèles d'équipement** |
| Groupe **Administration**  | **Utilisateurs**, **Journal**                                                            |

- Chaque groupe s'ouvre et se ferme d'un clic sur son titre ; le chevron indique son état.
- Le groupe de la page affichée s'ouvre de lui-même.
- Votre navigateur retient les groupes que vous avez ouverts ou fermés, d'une page et d'une visite à l'autre. Sans cette mémoire (navigation privée, par exemple), seul le groupe de la page affichée est ouvert.
- Le groupe **Administration** n'apparaît que pour un administrateur.
- L'entrée de la page affichée est mise en évidence, y compris sur les pages qui en dépendent : la fiche d'un sous-réseau relève de **Sous-réseaux**.

### Réduire le menu à ses icônes

Le bouton **Afficher ou masquer le menu latéral**, en haut de la page, réduit le menu à ses icônes ou le rétablit ; le raccourci `Ctrl+B` (`Cmd+B` sur Mac) fait de même. Réduit, le menu montre toutes ses entrées à plat, sans titre de groupe, un trait séparant deux groupes ; le nom de chaque entrée s'affiche au survol. Votre navigateur retient ce choix.

Sur un écran étroit, le menu s'ouvre par-dessus la page et se referme dès qu'une page est choisie.

### Rechercher depuis l'en-tête

En haut de chaque page, le champ **Rechercher** cherche dans toute la documentation : une adresse, un sous-réseau, un numéro de VLAN, une MAC, un port ou un nom. Il s'ouvre aussi par `/` ou Ctrl+K, hors d'un champ de saisie. Voir [accueil et recherche](accueil-et-recherche.md#la-recherche).

## Commencer à documenter un réseau

Rôle : `reader`, `editor` ou `admin`.

L'accueil vous y guide. Pour un éditeur ou un administrateur, tant qu'il manque un site, un VLAN, un sous-réseau ou un équipement, son module **Premiers pas** donne les étapes d'une documentation, chacune avec le lien vers sa page. Sur une installation sans aucun site, un lecteur y lit qu'un éditeur ou un administrateur doit créer le premier site. Voir [accueil et recherche](accueil-et-recherche.md#premiers-pas).

Suivez ensuite le menu de haut en bas :

1. Lisez les [notions](notions.md) de Torsade, et gardez le [glossaire](glossaire.md) sous la main.
2. Créez les sites, puis leurs bâtiments, étages et locaux : [sites et lieux](sites-et-lieux.md).
3. Posez la norme de l'organisation, blocs d'adressage, règle d'adressage et catalogue des VLAN : [norme](norme.md).
4. Déclarez les VLAN, les sous-réseaux et les adresses IP : [VLAN, sous-réseaux et adresses](vlan-sous-reseaux-adresses.md).
5. Décrivez les équipements et leurs ports : [équipements et ports](equipements-et-ports.md).
6. Reliez les équipements entre eux : [liaisons](liaisons.md).
7. Documentez les panneaux de brassage, les prises et les cordons : [câblage](cablage.md).
8. Consultez les schémas du site : [schémas](schemas.md).
9. Retrouvez un objet par la recherche, et suivez ce qui est documenté depuis l'accueil : [accueil et recherche](accueil-et-recherche.md).
10. Lisez ce qui s'écarte de la norme, et résorbez-le si vous le souhaitez : [écarts](ecarts.md).

Les administrateurs trouveront la gestion des comptes et la lecture du journal dans [comptes et journal](comptes-et-journal.md).

Torsade documente le réseau tel qu'il est déclaré : il ne scanne rien, ne surveille rien et ne se connecte à aucun équipement. Ce que vous saisissez est ce que les autres liront.

## La démonstration

Pour découvrir Torsade avant de documenter un vrai réseau, la commande `php artisan torsade:demo`, lancée sur le serveur, remplit une installation vide avec le réseau d'une société fictive : trois sites, Siège (code `SIE`), Atelier (code `ATE`) et Dépôt (code `DEP`, en projet, sans VLAN), avec la norme de l'organisation, des VLAN, sous-réseaux, adresses, lieux, équipements, liaisons, panneaux, prises et cordons. Les exemples de ce guide en sont tirés.

- Elle se lance sur une installation de développement, jamais en production : dans ce cas, la commande refuse de s'exécuter.
- Elle refuse aussi de s'exécuter si des données réseau existent déjà : elle ne mélange jamais ses données avec une documentation réelle.
- Elle ne crée aucun compte.
- Chaque objet créé est inscrit au journal, avec la **Console** pour auteur.

La mise en place d'une installation de développement est décrite dans le [guide de développement](../developpement.md).
