# Online self-registration for IANSEO

[Français](#français) · English

IANSEO module that lets archers register themselves online to a competition, from their phone or computer.

- **Per competition**: the organiser opens registration, sets a deadline, and chooses the sessions and bow types offered.
- **Quotas**: on top of each session's capacity (targets × archers per target), a maximum per bow type and session can be set (e.g. 8 compound archers in session 1).
- **Competition page**: the form shows the competition as entered in IANSEO: name, organiser, venue, dates, left and right logos, bottom banner, and the schedule of the sessions open for registration.
- **The archer** enters their license number and date of birth, both checked against IANSEO's license database. They then choose their bow type, possibly their target face, and one or several sessions. Full sessions, and sessions whose quota is reached, cannot be chosen.
- **Validation by the organiser**: requests stay pending, and their places are held. Validated requests are imported through IANSEO's own **List load** page: one entry per session, with category, target face and email. The module never writes to the archer tables.
- **Security**:
  - Cloudflare Turnstile anti-robot;
  - license + date of birth check, with no data shown before it and a generic error message;
  - CSRF protection, rate limiting, strict security headers;
  - HTTPS required.
- **Emails** (with the [Mailer](https://github.com/yet-another-quentin/ianseo-mailer) module): acknowledgement to the archer, validation, refusal, notification of the organiser. Templates are customisable per competition.
- **Language**: French or English, following IANSEO's language (browser language for visitors).

Tested with IANSEO 2025-02-10 (FR rules).

## Requirements

- IANSEO **reachable from the Internet over HTTPS**.
- The **license database** loaded (Participants › Athletes Sync.).
- A free **Cloudflare Turnstile** widget for your domain ([dash.cloudflare.com](https://dash.cloudflare.com) › Turnstile). Its site key and secret key are entered in the module by an IANSEO administrator.
- PHP `curl` extension (or `allow_url_fopen`) for the Turnstile check.

## Installation

1. Download `SelfRegistration.zip` from the [latest release](https://github.com/yet-another-quentin/ianseo-self-registration/releases/latest).
2. Unzip it into IANSEO's `Modules/Custom/` folder, so that you get `Modules/Custom/SelfRegistration/index.php`.
3. Open a competition. The module is in **Participants › Online registration**.

IANSEO updates never touch `Modules/Custom/`, so the module stays installed.

## Usage

1. **Turnstile** (once per installation): paste the site key and secret key at the bottom of the module page.
2. **Settings**:
   - tick the sessions and bow types offered (none ticked = all bow types);
   - fill in the quotas if needed, and the deadline;
   - tick **Open registration**, then save.
3. **Share the public link** shown on the page (`…/Modules/Custom/SelfRegistration/register.php?c=<competition code>`). Without a code, the page lists the competitions open for registration.
4. **Requests**: tick them and click **Validate and import**. IANSEO's List load report is shown. Refused requests free their places.
5. **Targets**: assign them with IANSEO's usual tools.

If IANSEO is behind the Cloudflare proxy, tick the matching option so that rate limiting uses the visitor's real address.

## Putting IANSEO online safely

IANSEO's public release has **no login**. Its IP lock only applies inside a competition: the home page, **creating a competition**, **updating IANSEO**, and through a new competition the installation-wide settings (SMTP, Turnstile keys, module updates) stay open to anyone. Protect the whole site at the reverse proxy and leave only the registration form public.

### Behind Nginx Proxy Manager

1. **Access List** (Access Lists › Add): add users (Authorization) and/or allowed IPs (Access). With both, "Satisfy Any" lets in either a known IP or a password. Select it on the IANSEO proxy host. Force SSL.
2. **Proxy host › Advanced**: the form files are served without the Access List. NPM only applies the list to `location /`; this block is matched first.

```nginx
# Public registration form (the rest of IANSEO keeps the Access List)
location ~ ^/Modules/Custom/SelfRegistration/(register\.php|register-api\.php|register-logo\.php|register\.js|register\.css|i18n\.js)$ {
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $x_forwarded_proto;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Real-IP $remote_addr;
    # $uri, normalised by nginx: IANSEO receives exactly the file matched here
    proxy_pass $forward_scheme://$server:$port$uri$is_args$args;
}
```

3. **In IANSEO's Apache** (container or server), so that IANSEO and the module see the visitor's address and HTTPS instead of the proxy's. Otherwise all visitors share one rate limit, the form says "unavailable" (no HTTPS), and IANSEO's IP lock sees a single address. Replace `172.16.0.0/12` with the network of the proxy (Docker networks are in this range):

```apache
# a2enmod remoteip
RemoteIPHeader X-Real-IP
RemoteIPInternalProxy 172.16.0.0/12
SetEnvIfExpr "%{CONN_REMOTE_ADDR} -ipmatch '172.16.0.0/12' && %{HTTP:X-Forwarded-Proto} == 'https'" HTTPS=on
```

4. **Check**:
   - The IANSEO container port must not be reachable from the Internet: only NPM talks to it.
   - From a private window, `https://your-site/` must ask for the password, and `…/Modules/Custom/SelfRegistration/register.php?c=CODE` must open the form.

If IANSEO runs on the same machine as the proxy without Docker, it sees every visitor as `127.0.0.1`. IANSEO gives `127.0.0.1` all rights, and the module allows plain http for it. The Access List is then the only protection: never expose IANSEO's own port.

### Short address for the form

Instead of `…/Modules/Custom/SelfRegistration/register.php?c=TEST26`, archers can get `https://inscription.example.org/TEST26`. The form only uses relative addresses, so a sub-domain that serves the form files at its root is enough.

1. **DNS**: point `inscription.example.org` to the proxy.
2. **NPM**: add a second proxy host for `inscription.example.org`. Forward it to the same IANSEO container, with a Let's Encrypt certificate and Force SSL. No Access List is needed: the last block below answers 404 to everything except the form. Put this in **Advanced**:

```nginx
# Registration form only: / lists the open competitions, /CODE opens the form of a competition
location = / {
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $x_forwarded_proto;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_pass $forward_scheme://$server:$port/Modules/Custom/SelfRegistration/register.php$is_args$args;
}
location ~ ^/([A-Za-z0-9_-]+)$ {
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $x_forwarded_proto;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_pass $forward_scheme://$server:$port/Modules/Custom/SelfRegistration/register.php?c=$1&$args;
}
# Files the form loads with relative addresses
location ~ ^/(register\.php|register-api\.php|register-logo\.php|register\.js|register\.css|i18n\.js)$ {
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $x_forwarded_proto;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_pass $forward_scheme://$server:$port/Modules/Custom/SelfRegistration/$1$is_args$args;
}
# Nothing else of IANSEO on this sub-domain (regex locations win over NPM's "location /")
location ~ . {
    return 404;
}
```

3. **Turnstile**: add the sub-domain to the hostnames of the widget (Cloudflare dashboard › Turnstile).
4. **Module page** (administrator): enter `https://inscription.example.org/` in **Public address of the form**. The public link shown on the page and the links in emails then use it.
5. **Check** from a private window: `https://inscription.example.org/TEST26` opens the form and a request goes through, and `https://inscription.example.org/index.php` answers 404.

## Development

```sh
node --test tests/*.test.js     # translations, nightly versioning
php tests/core.test.php         # availability, quotas, checks, List load lines

# Test IANSEO on http://localhost:8089/ (IANSEO_DIR = a COPY of the IANSEO sources)
cd dev && IANSEO_DIR=/path/to/ianseo-copy docker compose up -d --build && IANSEO_DIR=/path/to/ianseo-copy ./seed.sh
```

`seed.sh` creates the competition "TEST26" (FR indoor, 4 sessions, the first one with 4 places) and fictitious licenses, such as `123456A` born on 1985-03-12. It also stores Cloudflare's Turnstile test keys.

**Releases**:
- **Stable**: push a `vX.Y.Z` tag.
- **Nightly**: built every night from `main`.
- Administrators update the module in one click from its page.

---

## Français

Module IANSEO qui permet aux archers de s'inscrire eux-mêmes en ligne à une compétition, depuis leur téléphone ou leur ordinateur.

- **Par compétition** : l'organisateur ouvre les inscriptions, fixe une date limite et choisit les départs et les armes proposés.
- **Quotas** : en plus de la capacité de chaque départ (cibles × archers par cible), on peut fixer un maximum par arme et par départ (ex. 8 CO au départ 1).
- **Page de la compétition** : le formulaire reprend la compétition telle que saisie dans IANSEO : nom, organisateur, lieu, dates, logos gauche et droit, bandeau du bas, et le programme des départs ouverts.
- **L'archer** saisit son numéro de licence et sa date de naissance, vérifiés dans la base des licences d'IANSEO. Il choisit ensuite son arme, éventuellement son blason, et un ou plusieurs départs. Un départ complet, ou dont le quota est atteint, n'est pas sélectionnable.
- **Validation par l'organisateur** : les demandes restent en attente, et leurs places sont réservées. Les demandes validées sont importées par l'écran **Import liste** d'IANSEO lui-même : un participant par départ, avec catégorie, blason et email. Le module n'écrit jamais dans les tables des archers.
- **Sécurité** :
  - anti-robot Cloudflare Turnstile ;
  - vérification licence + date de naissance, sans aucune donnée affichée avant et avec un message d'erreur générique ;
  - protection CSRF, limitation du nombre de tentatives, en-têtes de sécurité stricts ;
  - HTTPS obligatoire.
- **Emails** (avec le module [Mailer](https://github.com/yet-another-quentin/ianseo-mailer)) : accusé de réception à l'archer, validation, refus, notification de l'organisateur. Les modèles sont personnalisables par compétition.
- **Langue** : français ou anglais selon la langue d'IANSEO (celle du navigateur pour les visiteurs).

### Prérequis

- IANSEO **accessible depuis Internet en HTTPS**.
- La **base des licences** chargée (Participants › Synchronisation des compétiteurs).
- Un widget **Cloudflare Turnstile** gratuit pour votre domaine. Un administrateur IANSEO saisit ses clés dans le module.

### Installation

1. Télécharger `SelfRegistration.zip` depuis la [dernière release](https://github.com/yet-another-quentin/ianseo-self-registration/releases/latest).
2. Décompresser l'archive dans le dossier `Modules/Custom/` d'IANSEO, pour obtenir `Modules/Custom/SelfRegistration/index.php`.
3. Ouvrir une compétition. Le module se trouve dans **Participants › Inscriptions en ligne**.

### Mise en ligne sécurisée

La version publique d'IANSEO n'a **pas de connexion**. Son verrouillage par IP ne s'applique qu'à l'intérieur d'une compétition. Restent donc ouverts à tous :
- l'accueil ;
- la **création d'une compétition** ;
- la **mise à jour d'IANSEO** ;
- par une nouvelle compétition, les réglages de toute l'installation (SMTP, clés Turnstile, mises à jour des modules).

Il faut protéger tout le site au niveau du reverse proxy et ne laisser public que le formulaire. Avec Nginx Proxy Manager (configurations complètes dans la partie anglaise) :
1. Créer une **Access List** (utilisateurs et/ou IP autorisées, « Satisfy Any » pour accepter l'un ou l'autre), la sélectionner sur le proxy host d'IANSEO et forcer le SSL.
2. Dans l'onglet **Advanced** du proxy host, ajouter le bloc `location` qui laisse publics les 6 fichiers du formulaire.
3. Dans l'**Apache d'IANSEO**, activer `mod_remoteip` et la détection du HTTPS. Sinon tous les visiteurs ont la même adresse, la même limite de tentatives, et le formulaire se croit en HTTP.
4. **Vérifier** :
   - Le port du conteneur IANSEO ne doit pas être accessible depuis Internet.
   - En navigation privée, le site demande le mot de passe et le lien du formulaire s'ouvre sans.

### Adresse courte du formulaire

Au lieu de `…/Modules/Custom/SelfRegistration/register.php?c=TEST26`, les archers peuvent recevoir `https://inscription.example.org/TEST26`. Le formulaire n'utilise que des adresses relatives : il suffit d'un sous-domaine qui sert les fichiers du formulaire à sa racine (configuration complète dans la partie anglaise).
1. **DNS** : faire pointer `inscription.example.org` vers le proxy.
2. **NPM** : créer un second proxy host pour ce sous-domaine, vers le même conteneur IANSEO, avec certificat Let's Encrypt et Force SSL. Pas besoin d'Access List : le bloc **Advanced** ne laisse passer que le formulaire et répond 404 à tout le reste.
3. **Turnstile** : ajouter le sous-domaine aux noms d'hôte du widget.
4. **Page du module** (administrateur) : saisir `https://inscription.example.org/` dans **Adresse publique du formulaire**. Le lien public affiché et les liens des emails l'utilisent alors.
5. **Vérifier** en navigation privée que `https://inscription.example.org/TEST26` ouvre le formulaire et qu'une demande passe, et que `https://inscription.example.org/index.php` répond 404.

### Utilisation

1. **Turnstile** (une fois par installation) : coller la clé du site et la clé secrète en bas de la page du module.
2. **Réglages** :
   - cocher les départs et les armes proposés ;
   - remplir les quotas si besoin et la date limite ;
   - cocher **Ouvrir les inscriptions**, puis enregistrer.
3. **Diffuser le lien public** affiché sur la page.
4. **Demandes** : les cocher puis cliquer sur **Valider et importer**. Le rapport d'Import liste d'IANSEO s'affiche. Refuser une demande libère ses places.
5. **Cibles** : les attribuer avec les outils habituels d'IANSEO.
