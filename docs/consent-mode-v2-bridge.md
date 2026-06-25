# Google Consent Mode v2 — snippet inline du Bridge

Connect Privacy émet le **Google Consent Mode v2** sur deux niveaux :

| Couche | Où | Rôle |
|---|---|---|
| `consent default` (denied) | **plugin Bridge**, `wp_head` priorité **0** | posé AVANT GTM/gtag, inline synchrone — les balises Google démarrent en « denied » (opt-in Loi 25) |
| `consent update` | **privacy.js** (`chatbot.pratcom.net/privacy.js`) | à chaque choix (accepter / refuser / préférences / retrait) et au retour d'un visiteur déjà consenti |

Le `default` du plugin lit le témoin `pratcom_consent` : pour un **visiteur de retour**, l'état initial reflète déjà son choix (pas de scintillement, pas de bannière re-affichée).

## Mapping catégorie → signaux Google (identique à privacy.js)

| Catégorie Connect | Signaux Google |
|---|---|
| `necessary` (toujours granted) | `security_storage`, `functionality_storage` |
| `preferences` | `personalization_storage` |
| `statistics` | `analytics_storage` |
| `marketing` | `ad_storage`, `ad_user_data`, `ad_personalization` |

Le snippet pose aussi `ads_data_redaction=true`, `url_passthrough=true` et `wait_for_update=500`.

## Activation

**OFF par défaut** (déploiement sûr : tagger le plugin ne change rien tant que ce n'est pas activé). À n'activer **que** là où privacy.js émet aussi les `update`, pour éviter un état « denied » permanent :

1. **Plugin (les deux modes)** — activer le Consent Mode côté Bridge :
   - option `pratcom_connect_consent_mode` = `'1'`, **ou**
   - filtre : `add_filter('pratcom_connect_consent_mode', '__return_true');`
2. **Tier Connect (clé `pck_`)** — activer aussi « Consent Mode » côté serveur
   (`privacy_configs.settings.consentModeV2 = true`) pour que privacy.js émette
   les `update`. (Sur le compte : réglages Privacy du workspace.)
3. **Mode Free (.org, sans compte)** — rien d'autre : si le Consent Mode est
   activé, `FreeBanner` peuple automatiquement `consentModeV2` dans la config
   locale de privacy.js.

> Si on émet le `default` (denied) sans que privacy.js émette les `update`, les
> balises Google resteraient en « denied » jusqu'à la visite suivante (le
> `default` relit alors le témoin) — d'où le défaut OFF et le couplage ci-dessus.

## Vérification (DevTools)

Avant tout consentement (témoin `pratcom_consent` absent) :

```js
window.dataLayer.filter(e => e[0] === 'consent')
// [ ['consent','default', { analytics_storage:'denied', ad_storage:'denied', … , wait_for_update:500 }] ]
```

Le `default` doit apparaître **avant** l'entrée `gtm.js` dans le `dataLayer`. À
l'acceptation : une entrée `['consent','update', { …'granted'… }]` suit. Au refus :
`update` en `denied`.

## Aucune dépendance externe

Le snippet est 100 % côté client (push `dataLayer`), sans appel réseau ni
ressource externe — compatible avec le canal WordPress.org. Aucune dépendance à
GTM : si le site n'utilise pas de balises Google, les signaux restent inertes.
