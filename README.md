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
- **Langue** : français ou anglais selon la langue d'IANSEO (celle du navigateur pour les visiteurs).

### Prérequis

- IANSEO **accessible depuis Internet en HTTPS**.
- La **base des licences** chargée (Participants › Synchronisation des compétiteurs).
- Un widget **Cloudflare Turnstile** gratuit pour votre domaine. Un administrateur IANSEO saisit ses clés dans le module.

### Installation

1. Télécharger `SelfRegistration.zip` depuis la [dernière release](https://github.com/yet-another-quentin/ianseo-self-registration/releases/latest).
2. Décompresser l'archive dans le dossier `Modules/Custom/` d'IANSEO, pour obtenir `Modules/Custom/SelfRegistration/index.php`.
3. Ouvrir une compétition. Le module se trouve dans **Participants › Inscriptions en ligne**.

### Utilisation

1. **Turnstile** (une fois par installation) : coller la clé du site et la clé secrète en bas de la page du module.
2. **Réglages** :
   - cocher les départs et les armes proposés ;
   - remplir les quotas si besoin et la date limite ;
   - cocher **Ouvrir les inscriptions**, puis enregistrer.
3. **Diffuser le lien public** affiché sur la page.
4. **Demandes** : les cocher puis cliquer sur **Valider et importer**. Le rapport d'Import liste d'IANSEO s'affiche. Refuser une demande libère ses places.
5. **Cibles** : les attribuer avec les outils habituels d'IANSEO.
