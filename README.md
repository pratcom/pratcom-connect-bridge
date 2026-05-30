# Pratcom Connect Bridge

Plugin WordPress universel qui connecte un site WP a l'API Pratcom Connect.

Une seule cle API par site, et tous les modules Pratcom Connect (Chat, Forms, Privacy, CRM, Sentinel, ...) actives cote serveur se chargent automatiquement via un loader unique.

## Installation (Phase 3, en cours)

1. Telecharger le `.zip` depuis [les releases GitHub](https://github.com/pratcom/pratcom-connect-bridge/releases) (pas encore publie en V1)
2. Uploader dans WordPress, Extensions > Ajouter > Televerser une extension
3. Activer
4. Aller dans **Reglages > Pratcom Connect**, coller la cle API fournie par Pratcom Media
5. Cliquer **Connect**

## Architecture

```
Site WP client (ce plugin)
  -> POST https://api.connect.pratcom.net/api/bridge/handshake (au save de la cle)
  -> Injection <script src="https://connect.pratcom.net/loader.js?w=workspace_id"> dans <head>
  -> POST /api/bridge/events au fil de l'usage
```

La cle API est hashee localement avant stockage en option WP (jamais en clair en DB).

## Roadmap

- **V1 (juillet 2026)** : page admin + handshake + injection loader + updater
- **V1.5** : architecture feature packs (toggle modules cote serveur)
- **V2** : scan Privacy enrichi (plugins, cookies), UI multi-keys

## Pre-requis

- WordPress 6.5+
- PHP 8.1+
- Une cle API Pratcom Connect (fournie par Pratcom Media)

## Updater

Mises a jour automatiques via [YahnisElsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) branche sur les releases GitHub privees.

## Reference

- Feuille de route Bridge V1 (Cowork outputs/)
- API : https://github.com/pratcom/pratcom-connect-api
- CIL : https://github.com/pratcom/pratcom-connect-core
