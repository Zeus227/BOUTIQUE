# Cosmétiques d'Alger — Gestion de boutique

Application web de gestion d'une boutique de cosmétiques : **caisse**, **inventaire**, **approvisionnement**,
**historique des ventes et des achats**, calculs automatiques, prix en **XOF (FCFA)**.
Deux rôles : *administrateur* (accès complet) et *vendeur* (caisse + historique des ventes, sans voir les prix d'achat).

- Interface : HTML / CSS / JavaScript (un seul fichier, sans framework), utilisable sur mobile et PC
- Serveur : PHP 7.4+ (API JSON) + MySQL, compatible avec l'hébergement gratuit **InfinityFree**

## Structure du projet

```
cosmetiques-alger/
├── public/                      ← tout ce qui est envoyé sur l'hébergement (htdocs)
│   ├── index.html               ← l'application
│   ├── api.php                  ← le serveur (connexion, produits, ventes, achats)
│   └── config.sample.php        ← modèle de configuration (copié en config.php sur le serveur)
├── database/
│   ├── schema.sql               ← tables + nom de la boutique + produits d'exemple
│   └── seed_users.sql           ← les comptes (Ousseini, Bachir, Sadik), mots de passe hachés
├── .github/workflows/
│   └── deploy-infinityfree.yml  ← déploiement automatique GitHub → InfinityFree (optionnel)
├── .gitignore
└── README.md
```

> **Important** : `public/config.php` (identifiants de la base) est dans `.gitignore`.
> Il ne doit **jamais** être envoyé sur GitHub. Gardez aussi le dépôt **privé** : `seed_users.sql`
> contient les empreintes (hachages) des mots de passe des comptes.

---

## 1. Envoyer le projet sur GitHub

1. Créez un compte sur <https://github.com> puis un nouveau dépôt **privé** (bouton *New*), par exemple `cosmetiques-alger`.
   Un dépôt privé est conseillé : c'est le code de votre commerce.
2. Sur votre ordinateur (Windows), installez [Git](https://git-scm.com/download/win), puis dans le dossier du projet :

```bash
git init
git add .
git commit -m "Première version"
git branch -M main
git remote add origin https://github.com/VOTRE-NOM/cosmetiques-alger.git
git push -u origin main
```

Sans ligne de commande : sur la page du dépôt, *Add file → Upload files*, puis glissez le contenu du dossier
(sans `config.php`).

## 2. Préparer la base de données (InfinityFree)

1. Panneau InfinityFree → **MySQL Databases** → créez une base. Notez l'hôte (`sqlXXX.infinityfree.com`),
   le nom de la base, l'utilisateur et le mot de passe.
2. Ouvrez **phpMyAdmin** → sélectionnez la base → onglet **Importer** → `database/schema.sql`.
3. Importez ensuite `database/seed_users.sql` de la même façon : les comptes `ousseini`, `bachir` et `sadik`
   sont créés avec leurs mots de passe actuels.

## 3. Mettre le site en ligne

### Option A — Manuelle (la plus simple)

1. Copiez `public/config.sample.php` en `config.php` et renseignez vos informations de base de données.
2. Panneau InfinityFree → **Online File Manager** → dossier `htdocs` → téléversez
   `index.html`, `api.php` et `config.php`.
3. Ouvrez l'adresse de votre site. Activez aussi le **certificat SSL gratuit** pour avoir `https://`.

### Option B — Automatique depuis GitHub (GitHub Actions)

À chaque envoi sur `main`, le contenu de `public/` est copié dans `htdocs/` par FTP.

1. Dans InfinityFree, repérez vos **informations FTP** (*FTP Details*) : serveur, nom d'utilisateur, mot de passe.
2. Sur GitHub : **Settings → Secrets and variables → Actions → New repository secret**, créez :

| Nom du secret | Valeur |
|---|---|
| `FTP_SERVER` | serveur FTP indiqué par InfinityFree |
| `FTP_USERNAME` | nom d'utilisateur FTP |
| `FTP_PASSWORD` | mot de passe FTP |

3. Créez **une seule fois** `config.php` directement sur le serveur (étape A.1 et A.2). Le déploiement
   automatique ne l'écrase jamais.
4. Faites un `git push` (ou *Actions → Déploiement sur InfinityFree → Run workflow*).

> Si le déploiement FTP échoue (certains hébergeurs gratuits limitent les connexions FTP venant de GitHub),
> utilisez l'option A : elle fonctionne toujours.

> **GitHub Pages ne convient pas** à ce projet : il héberge uniquement des pages statiques, pas du PHP ni MySQL.
> GitHub sert ici à *stocker et versionner* le code ; l'exécution se fait sur InfinityFree.

## 4. Sécurité — à faire dès la mise en ligne

- Les mots de passe actuels sont courts (8 chiffres) : même hachés, ils se devinent facilement. Changez-les dès que possible.
- Pour changer un mot de passe plus tard (phpMyAdmin → onglet SQL) :
  `UPDATE users SET password_hash = SHA2('NouveauMotDePasse', 256) WHERE username = 'bachir';`
- Activez le certificat SSL (`https://`).
- Gardez le dépôt GitHub **privé** et ne publiez jamais `config.php`.
- Faites une sauvegarde régulière de la base (phpMyAdmin → *Exporter*).

## 5. Comptes et droits

| Rôle | Accès |
|---|---|
| `admin` | Tableau de bord, caisse, inventaire, approvisionnement, historique des ventes et des achats |
| `vendeur` | Caisse et historique des ventes (prix d'achat et marges masqués par le serveur) |

Pour ajouter un utilisateur : ajoutez une ligne dans `database/seed_users.sql` (ou une requête `INSERT INTO users …` dans phpMyAdmin).

## 6. Fonctionnement

- Les prix, le stock et les droits sont **vérifiés côté serveur** (`api.php`) : le navigateur ne peut pas les falsifier.
- Les ventes sont enregistrées dans une transaction (stock bloqué pendant l'encaissement pour éviter les doubles ventes).
- Les données se resynchronisent toutes les 10 secondes entre les appareils connectés.
