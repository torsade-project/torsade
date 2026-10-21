<!-- torsade/documentation/administration/securite.md -->

# Sécurité

Le plan d'adressage, les équipements et le brassage que Torsade documente forment une carte du réseau utile à un attaquant. L'installation est donc sûre par défaut ; ce qui touche au système lui-même (SSH, comptes, pare-feu, journalisation) reste à l'administrateur, guidé par la liste de contrôle de la fin de cette page.

Les références entre parenthèses renvoient aux guides de l'ANSSI :

- **PA-009** : « Recommandations pour la mise en œuvre d'un site web : maîtriser les standards de sécurité côté navigateur », version 2.0 ;
- **TLS** : « Recommandations de sécurité relatives à TLS », version 1.2 du 26/03/2020 ;
- **BP-028** : « Recommandations de configuration d'un système GNU/Linux », version 2.0.

## Ce que l'installation applique

### Système

- **Un compte dédié**, `torsade`, sans shell de connexion, fait tourner PHP-FPM et toutes les commandes de l'application ; aucune commande de l'application ne tourne en root (BP-028 R34 et R35).
- **Le code appartient à root** et n'est modifiable par personne d'autre : une faille de l'application ne peut pas réécrire son propre code. Seuls le stockage (`/var/lib/torsade`) et le cache de démarrage de chaque version sont modifiables par `torsade`.
- **Fichiers sensibles fermés** (BP-028 R50 et R52) : `/etc/torsade/torsade.env`, qui contient la clé de l'application et le mot de passe de la base, n'est lisible que par root et `torsade` ; le mot de passe du compte des migrations et la clé privée du certificat, que par root ; les données, les journaux et les sauvegardes sont fermés aux autres utilisateurs ; seul Nginx peut ouvrir le socket de PHP-FPM.
- **Paquets** : seulement ceux dont Torsade a besoin, des seuls dépôts de Debian configurés sur la machine (BP-028 R58 et R59).
- **MariaDB n'écoute que sur la machine** (BP-028 R80). L'application l'atteint par son socket, avec un compte qui lit et écrit les données sans pouvoir changer le schéma ; un second compte, réservé à l'installation, aux mises à jour et à la restauration, change le schéma. Leurs mots de passe sont tirés au hasard et n'apparaissent ni sur une ligne de commande, ni dans une sortie, ni dans un journal.
- **Aucune version exposée** : ni Nginx (`server_tokens off`), ni PHP (`expose_php = Off`), ni l'application ne donnent leur version à un visiteur.

### TLS

- TLS 1.3 de préférence, TLS 1.2 accepté, aucune version plus ancienne (TLS R3 et R4).
- Échange de clés ECDHE sur les courbes recommandées (TLS R7), chiffrement AES ou ChaCha20 en mode intègre seulement (TLS R9 et R10), plusieurs suites dans l'ordre du serveur (TLS R12 et R13) : celles des tableaux 2.1 à 2.3 du guide.
- Ni reprise de session ni données envoyées avant la fin de la négociation (TLS R20, R21 et R23).
- Le certificat autosigné d'un essai suit les recommandations sur les certificats : clé ECDSA P-256 signée en SHA-256, 825 jours au plus, usages limités à l'authentification d'un serveur, nom dans l'extension `subjectAltName` (TLS R24 à R29). Un certificat fourni est vérifié : clé, chaîne complète, nom et dates (TLS R34 pour la chaîne).

### Navigateur

- **Content-Security-Policy** stricte, par en-tête (PA-009 R13 à R16) : seuls les scripts et les styles de l'application, chacun marqué d'un nonce tiré au hasard à chaque requête, ni script en ligne ni `eval` ; rien n'est chargé depuis un autre hôte.
- **Cadres** interdits par `frame-ancestors 'none'` et `X-Frame-Options: DENY` (PA-009 R17 et R18).
- `X-Content-Type-Options: nosniff` (PA-009 R6), `Referrer-Policy: same-origin` (PA-009 R21), `Cross-Origin-Opener-Policy: same-origin` (PA-009 R46) et `Cross-Origin-Resource-Policy: same-origin`, sur toutes les réponses, pages d'erreur comprises. Nginx pose aussi ceux qui valent pour les fichiers statiques et les redirections.
- **Cookie de session** : `Secure`, `HttpOnly`, `SameSite=Lax`, sur le chemin `/`, sans domaine, et préfixé `__Host-` pour que le navigateur n'accepte que ces attributs (PA-009 R28, R30, R31 et R33). `SameSite=Lax` plutôt que `Strict` laisse un lien vers Torsade, reçu par courriel par exemple, ouvrir la page sans demander de se reconnecter ; Torsade ne fait aucune action par une requête GET, condition que pose PA-009 R32 pour `Lax`.
- **Tentatives de connexion** limitées par l'application et, en plus, par Nginx : dix par minute et par adresse, après une rafale de cinq, pour la connexion et la double authentification.
- **Hôtes** : une requête qui ne vise pas exactement le nom de l'application (ou un nom de `TRUSTED_HOSTS`) reçoit une erreur sans détail.

## HSTS

HSTS demande au navigateur de ne plus ouvrir l'application qu'en HTTPS, avec un certificat valide, pendant un an (PA-009 R2). L'installation ne l'active que sur demande explicite, avec un certificat fourni et vérifié : à ses questions, la réponse suivie d'une confirmation ; sans question, l'option `--hsts`, qui vaut confirmation, après le même rappel des risques. Jamais avec un certificat autosigné, car le navigateur interdirait alors de passer outre son avertissement.

Avant de l'activer :

- tous les postes qui ouvrent Torsade doivent connaître l'autorité qui a signé le certificat : un poste qui ne la connaît pas ne pourra plus ouvrir l'application, et l'utilisateur ne pourra pas passer outre ;
- le certificat devra être renouvelé avant son expiration, chaque fois : un certificat expiré rend l'application inaccessible jusqu'au renouvellement ;
- revenir en arrière ne prend effet qu'à l'expiration de la règle dans chaque navigateur, jusqu'à un an.

HSTS s'applique au seul nom de l'application, sans ses sous-domaines (`includeSubDomains`) et sans inscription dans les listes des navigateurs (`preload`).

## Intégrité de l'archive

Le fichier `SHA256SUMS` publié avec chaque version prouve que l'archive téléchargée est intacte : `sha256sum --check SHA256SUMS` et `torsade-update` le vérifient. Il ne prouve pas son origine : quelqu'un qui remplacerait l'archive pourrait remplacer ce fichier aussi. Téléchargez donc toujours les deux depuis la page des versions du dépôt de Torsade, en HTTPS.

## Ce qui reste à l'administrateur

L'installation ne touche ni à SSH, ni aux comptes du système, ni au pare-feu, ni à la journalisation : un script qui les modifierait sur une machine qu'il ne connaît pas risquerait de couper l'accès de l'administrateur. La liste suivante reprend, pour ces sujets, les recommandations des niveaux minimal et intermédiaire de BP-028.

### SSH

- [ ] Administrer la machine par SSH avec des comptes nominatifs, jamais en root directement. BP-028 renvoie, pour la configuration de SSH, aux « Recommandations pour un usage sécurisé d'(Open)SSH » de l'ANSSI.
- [ ] Préférer l'authentification par clé à celle par mot de passe, et limiter l'accès au réseau des administrateurs (voir le pare-feu ci-dessous).

### Comptes

- [ ] Un compte par administrateur, et `sudo` pour toute commande privilégiée, afin que chaque action soit imputable (BP-028 R33).
- [ ] Des règles `sudo` restreintes (BP-028 R39 à R44).
- [ ] Désactiver les comptes inutilisés (BP-028 R30) ; des mots de passe robustes, propres à la machine (BP-028 R31).
- [ ] Verrouiller les sessions locales inactives (BP-028 R32).

### Mises à jour

- [ ] Une procédure de mise à jour de sécurité régulière (BP-028 R61) : l'option `--security-updates` de l'installation installe chaque jour les mises à jour de sécurité de Debian, et elles seules (réglage dans `/etc/apt/apt.conf.d/52torsade-security-updates`) ; sinon, appliquez-les vous-même.
- [ ] Suivre les versions de Torsade et les installer par `torsade-update` (voir [la mise à jour](mise-a-jour.md)).

### Services et pare-feu

- [ ] Aucun service inutile sur la machine (BP-028 R62) ; chaque service à l'écoute seulement sur les interfaces nécessaires (BP-028 R80), et les services exposés surveillés (BP-028 R79).
- [ ] Un pare-feu local qui n'ouvre que SSH, HTTP et HTTPS.

Exemple de pare-feu nftables, qui garde l'accès SSH :

```nftables
#!/usr/sbin/nft -f
# /etc/nftables.conf: local firewall of the machine of Torsade.

flush ruleset

table inet filter {
    chain input {
        type filter hook input priority filter; policy drop;

        ct state established,related accept
        ct state invalid drop
        iifname "lo" accept

        # ICMP and ICMPv6, which IPv6 needs to find its neighbours.
        meta l4proto { icmp, icmpv6 } accept

        # SSH, for the administrators. To limit it to their network:
        # ip saddr 192.0.2.0/24 tcp dport 22 accept
        tcp dport 22 accept

        # Torsade: HTTP, which leads to HTTPS, and HTTPS.
        tcp dport { 80, 443 } accept
    }

    chain forward {
        type filter hook forward priority filter; policy drop;
    }

    chain output {
        type filter hook output priority filter; policy accept;
    }
}
```

Avant de l'activer, vérifiez que SSH écoute bien sur le port 22, et gardez une console ouverte sur la machine (celle de l'hyperviseur, par exemple). Copiez l'exemple dans `/etc/nftables.conf`, puis vérifiez-le et activez-le :

```bash
sudo nft --check --file /etc/nftables.conf
sudo systemctl enable --now nftables
```

### Droits des fichiers

- [ ] Aucun fichier sans propriétaire connu (BP-028 R53), le bit « sticky » sur les répertoires où tous écrivent (BP-028 R54), le moins possible d'exécutables `setuid` ou `setgid` (BP-028 R56).

### Journalisation

- [ ] Garder les journaux du système et des commandes d'administration, pour savoir qui a fait quoi (BP-028 R33). Leur centralisation sur un serveur de journaux relève du niveau renforcé de BP-028 (R71).
- [ ] Surveiller `/up` (voir [l'exploitation](exploitation.md)).

### Copies hors de la machine

- [ ] Copier régulièrement les sauvegardes hors de la machine, protégées comme elle (voir [la sauvegarde et la restauration](sauvegarde-restauration.md)).

## Proxy inverse

Si Torsade est servi derrière un proxy inverse (un répartiteur de charge, ou un frontal HTTPS de l'organisation), l'application et Nginx doivent savoir quelle est l'adresse réelle des clients, pour la limite des tentatives de connexion et pour les journaux. Ils ne croient les en-têtes `X-Forwarded-*` que des proxys déclarés, et les ignorent sinon.

Déclarez l'adresse du proxy, par exemple `192.0.2.1`, aux deux :

1. dans `/etc/torsade/torsade.env`, `TRUSTED_PROXIES=192.0.2.1` (plusieurs adresses ou préfixes séparés par des virgules), puis reconstruisez les caches et rechargez PHP-FPM (voir [l'exploitation](exploitation.md)) ;
2. dans `/etc/torsade/nginx-local.conf`, que les mises à jour ne réécrivent jamais :

    ```nginx
    set_real_ip_from 192.0.2.1;
    real_ip_header X-Forwarded-For;
    ```

    puis vérifiez la configuration et rechargez Nginx :

    ```bash
    sudo nginx -t
    sudo systemctl reload nginx
    ```

L'application compare l'en-tête `Host` que lui transmet le proxy au nom de l'installation : le proxy doit transmettre celui d'origine (avec Nginx, `proxy_set_header Host $host;`). S'il fait connaître l'application sous un autre nom, ajoutez ce nom à `TRUSTED_HOSTS`.

Le proxy doit aussi accepter de grands en-têtes de réponse. Ceux des pages d'un utilisateur connecté dépassent 4 Ko, à cause de l'en-tête `Link` qui précharge les fichiers de l'application ; un proxy qui leur réserve moins de place répond 502 à la place de la page. Le Nginx de Torsade leur réserve 32 Ko. Donnez la même marge à un Nginx placé devant Torsade, dans le bloc `location` qui transmet les requêtes :

```nginx
proxy_buffer_size 32k;
proxy_buffers 16 16k;
```

Pour un autre proxy, vérifiez sa limite de taille des en-têtes de réponse, et portez-la à 32 Ko au moins.
