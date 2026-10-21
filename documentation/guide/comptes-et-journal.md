<!-- torsade/documentation/guide/comptes-et-journal.md -->

# Comptes et journal

Cette page s'adresse aux administrateurs. Elle couvre la gestion des comptes (création, rôle, désactivation, mot de passe, double authentification) et la lecture du journal, qui garde la trace de qui a changé quoi et quand. Les deux pages se trouvent dans le groupe **Administration** du menu, visible des seuls administrateurs.

Pour ce que chaque utilisateur fait sur son propre compte (premier mot de passe, double authentification, thème), voir [premiers pas](premiers-pas.md).

## Ouvrir la liste des comptes

Rôle : `admin`.

Dans le menu, ouvrez **Administration**, puis **Utilisateurs**. Le tableau liste tous les comptes, actifs ou non, par nom :

| Colonne                     | Contenu                                                                                                   |
| --------------------------- | --------------------------------------------------------------------------------------------------------- |
| **Nom**                     | Le nom du compte ; le vôtre porte la mention **(vous)**.                                                  |
| **Adresse e-mail**          | L'identifiant de connexion.                                                                               |
| **Rôle**                    | **Lecteur**, **Éditeur** ou **Administrateur**.                                                           |
| **Statut**                  | **Actif** ou **Désactivé**, et **Mot de passe temporaire** tant que l'utilisateur n'a pas choisi le sien. |
| **Double authentification** | **Activée**, **Exigée, à configurer** (exigée mais pas encore configurée) ou **Désactivée**.              |
| **Dernière connexion**      | La date de la dernière connexion réussie, ou **Jamais**.                                                  |
| **Actions**                 | **Modifier**, **Réinitialiser le mot de passe**, **Désactiver** ou **Réactiver**.                         |

## Créer un compte

Rôle : `admin`.

Il n'y a pas d'inscription publique : seul un administrateur crée un compte.

1. Sur la page **Utilisateurs**, cliquez sur **Nouvel utilisateur**.
2. Saisissez le **Nom** et l'**Adresse e-mail**. L'adresse doit être unique ; elle est enregistrée en minuscules.
3. Choisissez le **Rôle**. **Lecteur** est proposé par défaut : donnez le moins de droits possible, vous pourrez les étendre.
4. Cliquez sur **Créer l'utilisateur**.

Une fenêtre **Mot de passe temporaire** affiche le mot de passe généré, 24 lettres et chiffres.

- Il n'est affiché qu'une seule fois : **Ce mot de passe ne sera plus affiché.** Il n'est conservé en clair nulle part.
- Copiez-le avec **Copier**, puis transmettez-le à l'utilisateur par un moyen sûr.
- Fermez la fenêtre avec **J'ai copié le mot de passe**.

Si le mot de passe est perdu avant d'avoir été transmis, réinitialisez-le (voir [Réinitialiser le mot de passe d'un compte](#réinitialiser-le-mot-de-passe-dun-compte)). À sa première connexion, l'utilisateur doit choisir son propre mot de passe avant toute autre chose.

## Les trois rôles

Torsade a trois rôles fixes, sans système de permissions plus fin :

| Rôle     | Libellé            | Droits                                             |
| -------- | ------------------ | -------------------------------------------------- |
| `reader` | **Lecteur**        | Lit toute la documentation réseau.                 |
| `editor` | **Éditeur**        | Lit, crée, modifie et supprime les données réseau. |
| `admin`  | **Administrateur** | Tout, plus les comptes et le journal.              |

Deux règles protègent l'accès à l'administration :

- vous ne pouvez pas changer votre propre rôle ;
- il reste toujours au moins un administrateur actif : le dernier ne peut ni changer de rôle ni être désactivé.

## Modifier un compte

Rôle : `admin`.

1. Sur la page **Utilisateurs**, cliquez sur **Modifier** sur la ligne du compte. La page **Modifier l'utilisateur** s'ouvre.
2. Changez le **Nom** ou l'**Adresse e-mail**. L'utilisateur se connecte ensuite avec la nouvelle adresse.
3. Cliquez sur **Enregistrer**.

Modifier le nom ou l'adresse ne ferme aucune session. Chaque champ changé est inscrit au journal avec sa valeur avant et après.

## Changer le rôle d'un compte

Rôle : `admin`.

1. Ouvrez **Modifier** sur la ligne du compte.
2. Choisissez le nouveau **Rôle**, puis cliquez sur **Enregistrer**.

Un changement de rôle ferme toutes les sessions de l'utilisateur, y compris celles où il avait coché **Rester connecté sur cet appareil** : il se reconnecte avec son nouveau rôle.

Sur votre propre compte, la liste des rôles est grisée (« Vous ne pouvez pas modifier votre propre rôle. »). Rétrograder le dernier administrateur actif est refusé avec le message « Le dernier administrateur actif ne peut pas changer de rôle. » : nommez d'abord un autre administrateur.

## Désactiver un compte

Rôle : `admin`.

Un compte n'est jamais supprimé, seulement désactivé : le journal garde ainsi ses auteurs.

1. Sur la page **Utilisateurs**, cliquez sur **Désactiver** sur la ligne du compte.
2. Confirmez dans la fenêtre **Désactiver ce compte ?** avec **Désactiver**.

L'utilisateur est déconnecté aussitôt : toutes ses sessions sont fermées, et toute requête d'un compte désactivé le renvoie à la page de connexion. Il ne peut plus se connecter : il obtient le même message qu'avec un mauvais mot de passe. Un compte désactivé n'a plus aucun droit, quel que soit son rôle.

- Le bouton n'apparaît pas sur votre propre ligne : vous ne pouvez pas désactiver votre compte.
- Le dernier administrateur actif ne peut pas être désactivé.

## Réactiver un compte

Rôle : `admin`.

Sur la ligne d'un compte désactivé, cliquez sur **Réactiver**. L'utilisateur se connecte de nouveau avec son mot de passe et son rôle d'avant. Si ce mot de passe n'est plus sûr, réinitialisez-le aussi.

## Réinitialiser le mot de passe d'un compte

Rôle : `admin`.

Il n'y a pas de réinitialisation par e-mail : un utilisateur qui a perdu son mot de passe s'adresse à un administrateur. La page de connexion le lui rappelle.

1. Sur la page **Utilisateurs**, cliquez sur **Réinitialiser le mot de passe** sur la ligne du compte.
2. Confirmez dans la fenêtre **Réinitialiser le mot de passe ?** avec **Réinitialiser le mot de passe**.
3. Copiez le nouveau mot de passe temporaire, affiché une seule fois comme à la création, puis transmettez-le.

Toutes les sessions de l'utilisateur sont fermées. À sa prochaine connexion, il doit choisir un nouveau mot de passe. Sa double authentification reste telle quelle : un nouveau mot de passe ne doit pas affaiblir le second facteur.

Ce bouton n'apparaît pas sur votre propre ligne, et le serveur refuse cette demande pour votre compte : la réinitialisation fermerait votre session en cours et vous remettrait un mot de passe temporaire. Votre mot de passe se change dans vos paramètres (voir [changer son mot de passe](premiers-pas.md#changer-son-mot-de-passe)).

## Exiger la double authentification

Rôle : `admin`.

Un administrateur ne peut pas activer la double authentification à la place d'un utilisateur : il en détiendrait le secret. Il peut en revanche l'exiger.

1. Ouvrez **Modifier** sur la ligne du compte. La section **Double authentification** donne son **Statut**.
2. Cliquez sur **Exiger la double authentification**, puis confirmez.

Dès sa requête suivante, l'utilisateur est envoyé sur la page de configuration et ne peut rien faire d'autre tant qu'elle n'est pas active. Il ne peut plus la désactiver ensuite. Ses sessions ne sont pas fermées : l'exigence s'applique dans la session ouverte. Si la double authentification est déjà active sur le compte, rien ne change pour lui, sinon qu'il ne peut plus la désactiver.

Pour lever l'exigence, cliquez sur **Ne plus l'exiger**, puis confirmez. L'utilisateur est alors libre de la garder ou de la désactiver ; une double authentification déjà configurée reste active.

## Réinitialiser la double authentification

Rôle : `admin`.

À utiliser quand un utilisateur a perdu son téléphone et ses codes de récupération.

1. Ouvrez **Modifier** sur la ligne du compte.
2. Cliquez sur **Réinitialiser la double authentification**, puis confirmez.

La configuration de l'application d'authentification et les codes de récupération du compte sont effacés, et toutes ses sessions sont fermées. L'utilisateur se reconnecte avec son seul mot de passe. Si la double authentification est exigée sur son compte, il la configure de nouveau dès sa connexion.

### Votre propre compte

Les boutons de double authentification n'apparaissent pas sur votre propre compte (« C'est votre propre compte : gérez sa double authentification depuis vos paramètres. »), et le serveur refuse ces actions sur lui. Un administrateur qui a perdu son appareil ne doit pas pouvoir lever son propre second facteur depuis une session restée ouverte. Un autre administrateur s'en charge.

### Quand l'unique administrateur a perdu son appareil

S'il n'y a pas d'autre administrateur pour réinitialiser sa double authentification, la commande `torsade:reset-two-factor`, lancée sur le serveur, efface la configuration de double authentification d'un compte et ferme ses sessions. Son exécution est inscrite au journal. La façon de la lancer est décrite dans [les commandes de l'application](../administration/exploitation.md#commandes-de-lapplication).

## Sessions fermées par chaque action

Le tableau dit ce que chaque action fait des sessions ouvertes du compte concerné. Fermer une session inclut le jeton de **Rester connecté sur cet appareil** : l'utilisateur doit se reconnecter sur chaque poste.

| Action                                                      | Sessions du compte                                                   |
| ----------------------------------------------------------- | -------------------------------------------------------------------- |
| Créer le compte                                             | Aucune n'existe encore.                                              |
| Modifier le nom ou l'adresse e-mail                         | Aucune n'est fermée.                                                 |
| Changer le rôle                                             | Toutes sont fermées.                                                 |
| Désactiver le compte                                        | Toutes sont fermées, et toute nouvelle requête est refusée.          |
| Réactiver le compte                                         | Aucune n'est fermée.                                                 |
| Réinitialiser le mot de passe                               | Toutes sont fermées.                                                 |
| Exiger la double authentification, ou ne plus l'exiger      | Aucune n'est fermée ; l'exigence s'applique dès la requête suivante. |
| Réinitialiser la double authentification (page ou commande) | Toutes sont fermées.                                                 |
| L'utilisateur change lui-même son mot de passe              | Toutes les autres sont fermées ; celle en cours reste ouverte.       |

## Lire le journal

Rôle : `admin`.

Dans le menu, ouvrez **Administration**, puis **Journal**. Les entrées s'affichent de la plus récente à la plus ancienne, 25 par page.

Le journal est en lecture seule : aucune page de Torsade ne permet de modifier ou de supprimer une entrée. C'est ce qui en fait une trace fiable de qui a changé quoi.

| Colonne            | Contenu                                                                                                                                             |
| ------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Date**           | Le moment de l'action.                                                                                                                              |
| **Événement**      | Le type d'action (par exemple **Liaison créée**, **Rôle modifié**, **Échec de connexion**), avec parfois un détail en dessous.                      |
| **Auteur**         | Le nom et l'adresse e-mail du compte qui a agi ; **Console** pour une commande lancée sur le serveur ; **Non connecté** pour un essai de connexion. |
| **Objet concerné** | Le compte ou l'objet réseau touché. Le nom d'un objet réseau est gardé avec l'entrée : il reste lisible après la suppression de l'objet.            |
| **Modifications**  | Les champs changés, avec leur valeur avant et après. Une création liste les valeurs de l'objet créé, une suppression celles de l'objet supprimé.    |
| **Adresse IP**     | L'adresse du poste d'où l'action a été faite. Vide pour une commande lancée sur le serveur.                                                         |

### Les détails d'un échec de connexion ou d'une commande

Sous un **Échec de connexion**, le journal donne la raison : **Mot de passe incorrect**, **Compte inconnu**, **Compte désactivé**, **Trop de tentatives** ou **Code de double authentification invalide**. La page de connexion, elle, ne dit jamais laquelle. Pour un compte inconnu, l'adresse saisie n'est pas gardée : ce pourrait être un mot de passe tapé dans le mauvais champ.

Sous une **Commande console**, le journal donne le nom de la commande et son issue : **Réussie**, **Refusée** ou **Échec**.

## Filtrer le journal

Rôle : `admin`.

Au-dessus du tableau, quatre filtres se combinent :

| Filtre          | Effet                                                                                                |
| --------------- | ---------------------------------------------------------------------------------------------------- |
| **Utilisateur** | Les entrées où ce compte est l'auteur ou l'objet concerné. **Tous les utilisateurs** lève le filtre. |
| **Événement**   | Un seul type d'événement. **Tous les événements** lève le filtre.                                    |
| **Du**          | Les entrées de cette date et après.                                                                  |
| **Au**          | Les entrées jusqu'à cette date, journée comprise. Elle ne peut pas précéder la date **Du**.          |

Cliquez sur **Filtrer** pour appliquer, sur **Réinitialiser** pour tout effacer. Les filtres restent dans l'adresse de la page : vous pouvez la garder en favori. Si rien ne correspond, la page affiche **Aucune entrée ne correspond à ces filtres.**

Exemple : pour savoir qui s'est connecté au compte d'une personne partie, choisissez son compte dans **Utilisateur** et **Connexion** dans **Événement**.

## Ce que le journal enregistre

Chaque entrée dit qui a fait quoi, sur quel objet, quand, avec les valeurs avant et après.

- Comptes : création, modification, changement de rôle, désactivation, réactivation ; exigence, levée de l'exigence et réinitialisation de la double authentification par un administrateur.
- Authentification : connexion, échec de connexion avec sa raison, déconnexion, mot de passe modifié par l'utilisateur, mot de passe réinitialisé, double authentification activée ou désactivée par l'utilisateur. Chaque entrée porte l'adresse IP du poste.
- Documentation réseau : chaque création, modification et suppression d'objet (sites, lieux, norme, VLAN, sous-réseaux, plages, adresses, équipements, ports, liaisons, panneaux, prises, cordons), ainsi que l'enregistrement de la disposition d'un schéma ou son retour à la disposition automatique.
- Commandes : les commandes d'administration lancées sur le serveur, avec leur issue.

### Les créations en nombre

Pour qu'une création en série ne noie pas le journal, ce qui est porté par un autre objet donne une seule entrée sur l'objet qui le porte, avec le nombre et la liste des noms :

- des ports ajoutés en série donnent une entrée **Ports ajoutés** sur le membre de l'équipement ; les ports créés avec un membre sont listés dans l'entrée de création du membre ;
- des prises créées en série donnent une entrée **Prises ajoutées** sur l'étage ;
- les ports d'un panneau de brassage font partie de l'entrée du panneau.

Une connexion, elle, n'est portée par aucun objet : chaque liaison et chaque cordon de brassage a sa propre entrée (**Liaison créée**, **Port brassé**), même créés en série.

### Ce que le journal ne contient jamais

Aucun mot de passe, jeton ou secret n'est écrit au journal : ni le mot de passe temporaire, ni le nouveau mot de passe, ni la clé ou les codes de récupération de la double authentification. L'entrée **Mot de passe réinitialisé** dit seulement qui a réinitialisé le mot de passe de qui.
