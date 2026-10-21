<!-- torsade/documentation/developpement.md -->

# Développement

Cette page s'adresse à qui veut faire tourner Torsade sur son poste pour le modifier : prérequis, installation locale sous WampServer, commandes, tests, données de démonstration, archive d'une version, et conventions de contribution.

La cible de production est Debian 13 avec PHP 8.4, MariaDB 11.8, Nginx et PHP-FPM, décrite dans [l'installation](administration/installation.md). Le poste de développement décrit ici est sous Windows 11 avec WampServer.

## Prérequis

| Outil      | Version                                                       |
| ---------- | ------------------------------------------------------------- |
| Windows 11 | avec WampServer (Apache, PHP, MariaDB)                        |
| PHP        | 8.4.1 ou plus récent, en ligne de commande et dans WampServer |
| MariaDB    | 11.8                                                          |
| Composer   | 2                                                             |
| Node.js    | 22.18 ou plus récent, dans la branche 22                      |
| npm        | fourni avec Node.js                                           |
| Git        | 2.45.1 ou plus récent                                         |

Extensions PHP requises : `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `intl`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, `xml`, `zip`.

Pour construire l'archive d'une version ou modifier les scripts shell : Git Bash, fourni avec Git pour Windows, et `shellcheck` (voir [l'archive d'une version](#archive-dune-version)).

## Installation locale sous WampServer

### 1. Code et dépendances

```bash
git clone https://github.com/Xe-La/Torsade.git torsade
cd torsade
composer install
npm ci
```

### 2. Bases de données

Créer deux bases en `utf8mb4` / `utf8mb4_unicode_ci`, `torsade` et `torsade_test`, ainsi qu'un utilisateur MariaDB dédié dont les droits sont limités à ces deux bases. Choisir un mot de passe aléatoire : il ne figure nulle part dans le dépôt.

La base `torsade_test` sert uniquement aux tests, qui tournent sur MariaDB et jamais sur SQLite.

Torsade impose le moteur InnoDB dans sa configuration : certaines installations de MariaDB, dont celle de WampServer, ont MyISAM par défaut, sans transactions ni clés étrangères.

### 3. Configuration

```bash
cp .env.example .env
php artisan key:generate
```

Renseigner ensuite dans `.env` les variables `DB_HOST`, `DB_PORT`, `DB_USERNAME` et `DB_PASSWORD`. Sous WampServer, MariaDB écoute souvent sur le port 3307 quand MySQL occupe le 3306 : le port exact est indiqué dans le menu de WampServer.

Le fichier `.env` n'est jamais commité.

Les autres variables gardent en développement la valeur de `.env.example`, dont `APP_ENV=local`. Celles de la sécurité ne servent qu'en production, où l'installation les règle (voir [la sécurité](administration/securite.md)) :

| Variable          | Rôle                                                                                                      |
| ----------------- | --------------------------------------------------------------------------------------------------------- |
| `HSTS_ENABLED`    | Envoie l'en-tête HSTS sur les requêtes HTTPS ; jamais avec un certificat autosigné                        |
| `TRUSTED_HOSTS`   | Noms acceptés en plus de celui de `APP_URL` ; une requête vers un autre nom reçoit une erreur sans détail |
| `TRUSTED_PROXIES` | Adresses ou préfixes des proxys dont les en-têtes `X-Forwarded-*` sont crus ; vide, ils sont ignorés      |

En production, le cookie de session est toujours `Secure` et s'appelle `__Host-torsade-session` ; ailleurs, il n'est `Secure` que sur une requête HTTPS.

### 4. Schéma et interface

```bash
php artisan migrate
npm run build
```

Aucun utilisateur n'est créé par les migrations ni par les seeders : il n'existe aucun identifiant par défaut.

Dans le dépôt de développement, ne mettez jamais la configuration ni les routes en cache (`config:cache`, `route:cache`, `optimize`) : une configuration en cache figerait la base `torsade`, et les tests écriraient dedans. Un garde-fou des tests PHP s'arrête avant toute écriture si la base ouverte n'est pas `torsade_test`.

### 5. VirtualHost

Le VirtualHost `torsade.test` doit pointer vers le dossier `public/`, jamais vers la racine du projet : celle-ci contient `.env`, le code et les dépendances, qui ne doivent pas être servis.

```apache
<VirtualHost *:80>
    ServerName torsade.test
    DocumentRoot "C:/chemin/vers/torsade/public"

    <Directory "C:/chemin/vers/torsade/public/">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require local
    </Directory>
</VirtualHost>
```

Ajouter la ligne `127.0.0.1 torsade.test` au fichier `hosts` de Windows, puis redémarrer les services de WampServer. Le menu « Vos VirtualHosts » de WampServer fait les deux opérations : indiquer `torsade.test` comme nom et le chemin du dossier `public/`.

### 6. Premier administrateur

```bash
php artisan torsade:create-admin
```

La commande demande un nom et une adresse e-mail, génère un mot de passe aléatoire et l'affiche une seule fois. Ce mot de passe devra être changé à la première connexion sur `http://torsade.test`. La suite se lit dans le [guide des utilisateurs](guide/premiers-pas.md).

## Commandes utiles

| Commande                                       | Rôle                                                                      |
| ---------------------------------------------- | ------------------------------------------------------------------------- |
| `npm run dev`                                  | Serveur de développement du front, avec rechargement à chaud              |
| `npm run build`                                | Compilation du front dans `public/build`                                  |
| `php artisan test`                             | Tests, sur la base `torsade_test` (lancer `npm run build` au préalable)   |
| `npm run test:e2e`                             | Tests de navigateur, sur la base `torsade_test` (voir plus bas)           |
| `vendor/bin/pint`                              | Mise en forme du code PHP (`--test` pour seulement vérifier)              |
| `vendor/bin/phpstan analyse`                   | Analyse statique (Larastan)                                               |
| `npm run check`                                | Lint et mise en forme du front (`npm run check:fix` pour corriger)        |
| `npm run types:check`                          | Vérification des types TypeScript (`vue-tsc`)                             |
| `npm run docs:check`                           | Contrôles de la documentation (voir plus bas)                             |
| `php artisan torsade:create-admin`             | Création du premier administrateur ; refusée s'il en existe déjà un actif |
| `php artisan torsade:reset-password {email}`   | Mot de passe temporaire pour un compte, affiché une seule fois            |
| `php artisan torsade:reset-two-factor {email}` | Réinitialisation de la double authentification d'un compte                |
| `php artisan torsade:demo`                     | Réseau fictif pour essayer l'application ; refusée en production          |
| `php artisan about`                            | État de l'installation, dont la version de Torsade                        |
| `scripts/build-archive.sh {dossier}`           | Archive d'une version, depuis le commit en cours (voir plus bas)          |

Les commandes `torsade:*` acceptent `--no-interaction` ; `torsade:create-admin` prend alors `--name` et `--email`.

Deux commandes servent surtout en production, décrites dans [l'exploitation](administration/exploitation.md) : `torsade:check`, qui contrôle la configuration d'une installation de production, et `torsade:purge-health-cache`, qui vide le cache de `/up` de ses entrées expirées.

`torsade:reset-two-factor` est le recours quand l'unique administrateur a perdu son appareil et ses codes de récupération : personne ne peut alors réinitialiser sa double authentification depuis l'interface.

## Point de contrôle

`/up` indique à une sonde si l'application joint sa base de données. Il répond sans authentification, en texte brut :

| Réponse       | Code | Signification                                                        |
| ------------- | ---- | -------------------------------------------------------------------- |
| `OK`          | 200  | La base de données est joignable                                     |
| `Unavailable` | 503  | Elle ne l'est pas, ou l'application est en maintenance ; sans détail |

Il ne révèle rien d'autre : ni page, ni version, ni logiciel utilisé. Il accepte 60 appels par minute et par adresse IP, puis répond `Too Many Requests` (429). Pendant une maintenance (`php artisan down`), il répond `Unavailable` : une maintenance prévue se déclare dans l'outil de supervision.

## Politique de sécurité du contenu

Chaque page, pages d'erreur comprises, porte un en-tête `Content-Security-Policy`. Il n'autorise que les scripts et les styles servis par l'application ou marqués d'un nonce tiré à chaque requête, et les images, polices et workers servis par l'application.

- Un élément `script` ou `style` écrit dans une vue Blade porte le nonce : `nonce="{{ Vite::cspNonce() }}"`. Les balises produites par `@vite` le portent d'elles-mêmes.
- Le code du front qui insère un élément `script` ou `style` lui donne le nonce de `cspNonce()`, dans `resources/js/lib/cspNonce.ts`.
- Aucun fichier du build n'est inscrit dans un autre en adresse `data:`, que la politique refuserait : `vite.config.ts` fixe `assetsInlineLimit` à `0`.
- Avec `npm run dev`, quand `APP_ENV` vaut `local` et que le serveur de Vite tourne (fichier `public/hot`), la politique accepte aussi l'origine de ce serveur, sa WebSocket et les styles en ligne, que Vite insère depuis JavaScript. Jamais en production, ce qu'un test vérifie.
- En développement, avec `APP_DEBUG=true`, la page d'erreur de Laravel d'une réponse 500 ou plus part sans politique, puisqu'elle ne porte pas le nonce.

Les tests de navigateur échouent sur tout refus de la politique, signalé par le navigateur.

## Tests de navigateur

Les parcours principaux sont rejoués dans un vrai navigateur, Chromium, piloté par Playwright : connexion et droits de chaque rôle, saisie au clavier, filtres et tris, plan d'adressage, norme et écarts, lieux, équipements, ports, agrégats et liaisons, câblage et chemin des prises, schémas physique et logique (navigation, export, placement à la main), accueil et ses modules, recherche et page de résultats, menu, thèmes clair et sombre, largeurs d'écran de 360 à 1920 pixels, focus au clavier. Les tests vérifient des comportements, avec des sélecteurs fondés sur les rôles et les libellés accessibles.

Le navigateur de test se télécharge une fois, hors du dépôt (environ 700 Mo) :

```
npx playwright install chromium
```

Puis, après chaque modification du front :

```
npm run build
npm run test:e2e
```

La commande lance elle-même l'application sur `http://127.0.0.1:8125`, avec la base `torsade_test` qu'elle vide et reconstruit à chaque exécution : la base `torsade` n'est jamais touchée, et la commande refuse de démarrer si l'application en utiliserait une autre que `torsade_test`. Elle y crée un compte par rôle et le réseau de démonstration ; le mot de passe de ces comptes est tiré au hasard à chaque exécution et n'est écrit nulle part. Les tests de l'accueil d'une installation vide la reconstruisent sans le réseau de démonstration, puis avec, de la même façon.

Aucun test ne compare d'images. Une capture de chaque écran principal, en thème sombre et en thème clair, est déposée dans `test-results/screenshots` pour être regardée ; la CI les conserve en artefact.

## Données de démonstration

```bash
php artisan torsade:demo
```

La commande crée le réseau d'une société inventée, pour essayer l'application et pour illustrer le guide : deux sites avec leur code et leur numéro, un troisième en projet, au statut « Prévu » et encore sans VLAN, un catalogue de six VLAN, un bloc d'adressage pour l'organisation et un par site, une règle d'adressage et son découpage type, une douzaine de VLAN avec leurs sous-réseaux en adresses privées, un sous-réseau de chaque type, des plages des trois types et une centaine d'adresses IP.

Les deux sites ont leurs bâtiments, étages et locaux, et une trentaine d'équipements rangés dans ces locaux et rattachés aux adresses qui portent leur nom. Une stack de deux membres, deux switchs de cœur, deux switchs d'accès, un pare-feu et deux switchs de l'atelier ont leurs ports, créés depuis les modèles génériques, en accès, avec un VLAN voix, en trunk ou en agrégat ; ils sont reliés entre eux, et l'une des liaisons est prévue. Chaque local technique a son panneau de brassage, les prises des étages y sont reliées par séries et brassées vers les switchs, et des postes et des téléphones y sont raccordés. Le schéma physique de l'atelier est placé à la main, celui du siège reste en disposition automatique.

Des noms d'hôte restent sans équipement, pour essayer la création d'équipements depuis les noms d'hôte, et des équipements restent sans membre, pour essayer « Choisir un modèle » et « Ajouter des ports ». Quelques écarts y sont volontaires, chacun expliqué dans sa description : ils sont décrits dans le guide, page [écarts](guide/ecarts.md).

Tout y est fictif, fabricant des équipements compris ; les quelques adresses publiques sont prises dans les plages réservées à la documentation.

La commande ne s'exécute que dans une installation sans aucune donnée réseau, et jamais en production : elle le refuse. Les migrations et les seeders ne l'appellent pas. Chaque objet créé apparaît dans le journal, avec la console pour auteur.

## Polices

L'interface est composée en Barlow et, pour les valeurs techniques, en IBM Plex Mono. Les deux sont publiées sous licence SIL Open Font License 1.1. Leurs fichiers viennent des paquets `@fontsource/barlow` et `@fontsource/ibm-plex-mono`, sont copiés dans `public/build` par `npm run build` et servis par l'application : aucune police n'est chargée depuis un autre hôte.

Le texte complet de la licence de chaque police, avec ses mentions de copyright, est copié à chaque build à côté de ses fichiers, tel que le fournit le paquet installé : `public/build/assets/barlow-LICENSE.txt` et `public/build/assets/ibm-plex-mono-LICENSE.txt`. Ces deux fichiers font partie de toute archive qui contient le build.

## Mise en page des schémas

Les schémas sont mis en page par ELK, du paquet `elkjs`, publié sous licence « EPL-2.0 OR GPL-3.0-or-later ». Son moteur fait partie du build et tourne dans un Web Worker servi par l'application, chargé seulement sur la page d'un schéma : rien n'est chargé depuis un autre hôte. Le texte de sa licence est copié à chaque build, tel que le fournit le paquet installé, dans `public/build/assets/elkjs-LICENSE.txt`.

## Archive d'une version

L'archive `torsade-X.Y.Z.tar.gz` est ce qu'un serveur reçoit : le code, les dépendances PHP de production, l'interface compilée, les scripts d'installation de `deploy/`, la documentation, `README.md` et `CHANGELOG.md`. Elle ne contient ni les tests, ni les sources du front, ni les outils de développement.

```bash
scripts/build-archive.sh ../release
scripts/check-archive.sh ../release/torsade-X.Y.Z.tar.gz
```

- `build-archive.sh` part du commit en cours, jamais de l'arbre de travail, et refuse de tourner si celui-ci contient des modifications non commitées. Il demande Git, PHP, Composer, Node, npm, GNU tar, gzip et `sha256sum`, et écrit l'archive et son fichier `SHA256SUMS` dans le dossier donné.
- `check-archive.sh` compare l'archive à la liste de ce qu'elle peut contenir : rien d'autre, chaque fichier à root et écrit par lui seul, les scripts d'installation exécutables et sans retour chariot, les fichiers nécessaires à l'installation présents.

Sous Windows, les deux scripts se lancent dans Git Bash, où `composer` doit être une commande que Bash trouve : un `composer.bat` ne suffit pas.

Les scripts shell, ceux de `scripts/` comme ceux de `deploy/`, passent `shellcheck --external-sources` dans la CI. Sur le poste, `bash -n` en vérifie la syntaxe et `shellcheck` le reste.

La CI construit et vérifie l'archive à chaque push, puis l'installe sur Debian 13, dans des conteneurs qui démarrent systemd comme une machine (`scripts/ci/test-installation.sh`) : installation et reprise, HTTPS, en-têtes, droits des fichiers, première connexion, `torsade:check`, sauvegarde et restauration, mises à jour et leurs échecs, certificat d'une autorité avec HSTS, reconstruction sur une machine neuve. Ces essais demandent Docker et ne tournent que dans la CI.

## Documentation

La documentation est écrite en Markdown, en français, dans `documentation/` ; son sommaire est [documentation/README.md](README.md). Elle se lit sur GitHub comme hors ligne.

- Le guide des utilisateurs, dans `documentation/guide/`, est organisé par tâche ; chaque tâche dit le rôle qu'elle demande (`reader`, `editor` ou `admin`).
- Dans le guide, le gras est réservé aux libellés de l'interface, cités exactement comme dans `lang/fr.json`. Un comportement décrit est celui du code ; les exemples viennent des données de démonstration.
- Une modification qui change un comportement visible met à jour la page qui le décrit.

Deux contrôles, sans dépendance, tournent dans la CI et par une commande :

```bash
npm run docs:check
```

- liens : dans `README.md`, `CHANGELOG.md` et `documentation/`, chaque lien relatif mène à un fichier suivi par Git, et chaque ancre à un titre de ce fichier ; un lien externe n'est jamais appelé ; chaque page de `documentation/` est liée depuis le sommaire ;
- libellés : dans `documentation/guide/`, chaque texte en gras est une valeur de `lang/fr.json`, éventuellement avec ses paramètres (`:count`, `:name`…) remplacés par un texte quelconque, ou l'une de ses formes plurielles ; le gras par tirets bas est refusé.

Chaque erreur donne le fichier et la ligne. Les contrôles s'essaient eux-mêmes sur les dossiers d'exemples de `scripts/docs/fixtures/` :

```bash
node --test scripts/docs/checks.test.mjs
```

Un lien ne compte qu'une fois le fichier visé suivi par Git : après avoir créé une page, ajoutez-la à l'index (`git add`) avant de lancer les contrôles.

## Contribuer

- Une contribution est reçue sous la licence de Torsade, la licence publique générale GNU, version 3 ou toute version ultérieure, sans cession de droits : aucun accord de contributeur n'est demandé. Voir la [licence](../README.md#licence).
- Code, noms de tables et de colonnes, commentaires, messages de commit et `CHANGELOG.md` sont en anglais ; l'interface et la documentation, en français. Chaque texte de l'interface passe par les traductions (`lang/fr.json`).
- Une branche par sujet, nommée `feat/`, `fix/` ou `chore/` suivi d'un nom court en anglais.
- Commits au format Conventional Commits : `feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`, `ci:`. Un sujet par commit.
- Fins de ligne LF dans tout le dépôt, imposées par `.gitattributes`, sauf pour les fichiers `.bat` et `.cmd` ; la CI refuse un fichier texte enregistré autrement. Les scripts shell s'exécutent sur Debian, où un retour chariot les casserait.
- Avant chaque commit : `npm run build`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run check`, `npm run types:check`, les tests de la partie modifiée, `npm run docs:check` quand la documentation change, `shellcheck` quand un script shell change. Avant de pousser : la suite PHP complète et `npm run test:e2e`.
- Les tests tournent sur MariaDB, base `torsade_test`, jamais sur SQLite. Chaque comportement a son test de fonctionnalité, y compris les refus : un utilisateur sans droit reçoit une erreur 403.
- Une modification de schéma passe par une nouvelle migration ; une migration publiée n'est plus modifiée.
- Aucun appel réseau externe, ni à l'exécution ni à la compilation : pas de CDN, pas de police distante, pas de télémétrie.
- Données de test et d'exemple fictives : jamais de plan IP, de société ou de personne réels.
- Une nouvelle dépendance est justifiée : besoin couvert, alternative sans dépendance, état de maintenance du paquet.

## Versions

Le numéro de version suit le versionnage sémantique : `MAJEUR.MINEUR.CORRECTIF`, en `0.y.z` pendant le développement initial. Sa source unique est le fichier `VERSION`. Les changements de chaque version sont décrits dans [CHANGELOG.md](../CHANGELOG.md), au format Keep a Changelog, en anglais : une modification ajoute son entrée à la section `Unreleased`, qui devient la section de la version à sa publication. Chaque version publiée porte un tag Git `vX.Y.Z`. Sur ce tag, une fois tous les autres travaux de la CI réussis, la CI vérifie que le tag est la version de l'archive et publie la version sur GitHub, avec l'archive et son fichier `SHA256SUMS`.
