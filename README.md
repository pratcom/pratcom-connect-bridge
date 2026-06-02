# Pratcom Connect Bridge

Plugin WordPress qui connecte un site WP a l'API Pratcom Connect (`api.connect.pratcom.net`). Une seule cle API par site, tous les modules Pratcom Connect actives cote serveur (Chat, Forms, Privacy, ...) se chargent automatiquement via un loader unique.

**Statut :** V1 livre 2026-06-01, version courante `v1.1.1` (refonte UI dashboard SaaS). Voir [Releases](https://github.com/pratcom/pratcom-connect-bridge/releases).

---

## Installation

### Pre-requis

- WordPress **6.5 ou plus recent**
- PHP **8.1 ou plus recent**
- Une cle API Pratcom Connect au format `pck_<workspace_slug>_<32 caracteres>` (fournie par Pratcom Media)
- Domaine du site WordPress autorise pour la cle (verifie cote serveur)

### Procedure (3 minutes)

1. **Telecharger** le `.zip` de la derniere release : [github.com/pratcom/pratcom-connect-bridge/releases/latest](https://github.com/pratcom/pratcom-connect-bridge/releases/latest)
   Fichier : `pratcom-connect-bridge.zip` (environ 200 Ko).

2. **Uploader dans WordPress** : `Extensions > Ajouter une extension > Televerser une extension`, choisir le `.zip`, cliquer `Installer maintenant`.

3. **Activer** l'extension dans la liste des extensions.

4. **Acceder a la page admin** : dans le menu de gauche, cliquer sur **Pratcom Connect** (icone randomize). Ou, dans la liste des extensions, cliquer sur le lien `Reglages` a cote de `Desactiver`.

5. Dans l'onglet **Connexion**, coller la cle API et cliquer **Connecter**.

Le plugin envoie un handshake a `https://api.connect.pratcom.net/api/bridge/handshake`, recupere le `workspace_id` et la liste des modules actifs (`feature_packs`), puis injecte le snippet `loader.js` dans le `<head>` de toutes les pages publiques.

### Mises a jour automatiques

Le plugin utilise [YahnisElsts/plugin-update-checker (puc)](https://github.com/YahnisElsts/plugin-update-checker) branche sur les releases GitHub publiques.

- Verification automatique toutes les 12 heures
- Notification visible dans `Extensions > Mises a jour`
- Aucune configuration cote client necessaire

Pour forcer une verification immediate : `Pratcom Connect > Aide > Verifier les mises a jour`.

---

## Page admin

L'interface est structuree en 4 onglets dans le menu lateral gauche :

| Onglet | Contenu |
|--------|---------|
| **Tableau de bord** | Vue d'ensemble : workspace, modules actifs, dernier handshake |
| **Modules** | Liste des modules disponibles : Connect Chat (actif si workspace l'a active), Connect Forms et Connect Privacy (bientot disponibles) |
| **Connexion** | Saisie/edition de la cle API, statut de la connexion, boutons Verifier maintenant / Se deconnecter |
| **Aide** | Documentation, support, informations systeme (version plugin, endpoints API, bouton vers les mises a jour) |

Le header affiche le logo Pratcom Connect, la version du plugin, et un badge de statut :

- 🟢 **Connecte** : cle valide, handshake reussi recemment
- ⚪ **Non connecte** : aucune cle saisie
- 🔴 **Cle revoquee** : cle invalidee cote serveur (mode degrade actif)
- 🔴 **Erreur** : derniere verification a echoue (probleme reseau, format de cle, etc.)

Captures :

- `docs/screenshots/dashboard.png` — Onglet Tableau de bord
- `docs/screenshots/modules.png` — Onglet Modules (Chat actif, Forms et Privacy bientot)
- `docs/screenshots/connection-form.png` — Onglet Connexion (formulaire de saisie de cle)
- `docs/screenshots/connection-active.png` — Onglet Connexion (etat connecte)
- `docs/screenshots/notice-revoked.png` — Notice admin globale quand la cle est revoquee

---

## Architecture

```
Site WordPress client (ce plugin)
   |
   |  POST /api/bridge/handshake   <- au save de la cle (Connexion)
   |  GET  /api/bridge/config       <- via WP cron horaire (HealthCheck.php)
   |  POST /api/bridge/events       <- au fil de l'usage
   |
   v
API Pratcom Connect (api.connect.pratcom.net)
   |
   |  -> CIL (PostgreSQL + pgvector sur Sevalla)
   |     - bridge_credentials (cle hashee argon2id)
   |     - workspaces, contacts, sessions, events, ...
   |
   |  -> loader public (connect.pratcom.net/loader.js)
   |     - injecte les bundles des feature packs actifs
   |       (chat.snippet_url, privacy.bundle_url, ...)
```

La cle API en clair est stockee dans une option WP (`pratcom_connect_bridge_api_key`) avec usage exclusif sortant (jamais affichee dans l'admin). Le serveur ne stocke que le hash argon2id avec un PEPPER en variable d'environnement.

---

## Troubleshooting

### "Erreur : format de cle invalide" au moment de connecter

Verifier que la cle commence par `pck_`, contient le slug du workspace, un underscore, puis exactement 32 caracteres alphanumeriques. Total : 48 caracteres avec le prefixe.

### "Cle revoquee" affiche dans le bandeau admin

La cle a ete invalidee cote Pratcom (rotation par l'equipe ops, ou cle suspectee compromise). Contacter Pratcom Media pour une nouvelle cle, puis dans l'onglet `Connexion` cliquer `Effacer la cle` et coller la nouvelle.

### Le chat (ou un autre module actif) n'apparait pas sur le front

1. Verifier que **les feature_packs sont synchronises** : `Pratcom Connect > Modules`, le module doit avoir le badge `Actif`. Si non, cliquer `Verifier maintenant` dans `Connexion`.
2. **Vider les caches** :
   - Plugins de cache (WP Rocket, LiteSpeed, W3TC) : purger leur cache
   - Cloudflare ou CDN devant le site : purger les fichiers `/loader.js` cibles si servis depuis le site
   - Cache navigateur : recharger en mode incognito ou avec Ctrl+Shift+R
3. **Verifier le SSL** : `https://connect.pratcom.net/loader.js` doit retourner du JS valide. Si `403 blocked-by-allowlist` ou erreur reseau, ouvrir un ticket support.
4. Inspecter la console du navigateur : un message `[Pratcom Connect] loaded for workspace <slug>` doit s'afficher. Sinon, le snippet `window.__pratcomConnect` n'est pas injecte (verifier que le plugin est connecte).

### Conflits connus

- **Plugins de cache full-page** : peuvent cacher la version HTML *sans* le snippet inline `window.__pratcomConnect`. Solution : purger le cache apres connexion/changement de modules, ou exclure les pages du cache si le snippet contient du contenu dynamique (rare).
- **Plugins de securite** (Wordfence, Sucuri) : peuvent bloquer le user-agent du health check. Si le plugin passe en `Erreur` recurrent, verifier les logs du firewall et whitelister `api.connect.pratcom.net`.
- **CSP strict** : si un header `Content-Security-Policy` restreint `script-src`, ajouter `https://connect.pratcom.net` et l'URL du snippet de chaque module actif.

### Reinitialisation complete

`Pratcom Connect > Connexion > Se deconnecter` efface toutes les options du plugin (cle, workspace_id, feature_packs, derniers handshakes). Le plugin reste actif mais n'injecte plus rien jusqu'a la prochaine connexion.

### Desinstallation

`Extensions > Pratcom Connect Bridge > Desactiver` puis `Supprimer`. Toutes les options sont purgees automatiquement (`register_uninstall_hook`).

---

## Liens utiles

- **Releases** : [github.com/pratcom/pratcom-connect-bridge/releases](https://github.com/pratcom/pratcom-connect-bridge/releases)
- **Source API** : [github.com/pratcom/pratcom-connect-api](https://github.com/pratcom/pratcom-connect-api) (prive)
- **Schema CIL** : [github.com/pratcom/pratcom-connect-core](https://github.com/pratcom/pratcom-connect-core) (prive)
- **Support** : `support@pratcom.net`
- **Documentation produit** : `https://docs.pratcom.net/connect/bridge` (a venir)

---

## Licence

Proprietaire. Pratcom Media Inc. 2026.
