# pratcom-connect-bridge — consignes de session

Extension WordPress en PHP 8.1+, PSR-4 (`Pratcom\Connect\Bridge\` → `src/`). Un seul plugin pour tous les modules Connect : validation de domaine, chargement de `loader.js`, activation par *feature packs* côté serveur. **Zéro logique métier** — toute l'intelligence reste sur le serveur Pratcom.

Ce fichier s'adresse à une session qui ne sait encore rien. Chaque règle porte sa raison.

---

## 1. 🔴 CE DÉPÔT EST PUBLIC

`github.com/pratcom/pratcom-connect-bridge` est **ouvert**, et le plugin est publié sur WordPress.org (slug `pratcom-connect`).

**Jamais, dans un commit, un message de commit, un corps de PR ou un commentaire de code :**

- aucune clé, jeton, mot de passe, chaîne de connexion ;
- **aucun nom ni domaine de client** ;
- aucune capture d'écran d'un environnement réel ;
- aucun extrait de journal de production.

Un secret poussé sur un dépôt public est compromis à la seconde, même supprimé ensuite : il reste dans l'historique et dans les miroirs.

## 2. Les portes que tu ne franchis pas

**Tu ouvres une PR et tu t'arrêtes.** Ni fusion, ni déploiement.

**Et surtout : aucun tag, aucune release, aucun bump de version.**

Un tag ici **publie une version chez tous les clients** via le Plugin Update Checker et WordPress.org. C'est un geste de Martin, jamais le tien. Tu prépares, tu dis que c'est prêt.

**Contrôle concret avant de committer** : ni `pratcom-connect-bridge.php` ni `readme.txt` ne doivent apparaître dans ton diff. Ce sont eux qui portent la version (en-tête, constante `PRATCOM_CONNECT_BRIDGE_VERSION`, `Stable tag`). S'ils y sont, tu es en train de préparer une release sans le savoir.

```bash
git diff --cached --name-only | grep -E "pratcom-connect-bridge\.php|readme\.txt"
# doit ne rien renvoyer
```

**Un seul tag en file d'attente à la fois, tous projets du portefeuille confondus.** C'est un plafond de cadence assumé, pas un oubli : deux versions en vol rendent impossible de savoir laquelle un client a reçue. Au 14/08, le créneau `v2.1.14` est réservé au pack Emplois — la file passe par la Super Vigie.

**N'écris jamais** dans `_Portfolio\Ecosysteme_Connect_Etat.md` ni dans un `*_Chantiers_Etat.md`.

## 3. La règle des prémisses

> **Une mission est une intention, pas une vérité. Si une mesure contredit ce qui est écrit, c'est la mesure qui gagne — et il faut le dire AVANT d'exécuter.**

Deux cas réels du 14/08 :

- Une mission demandait de vérifier **avant de corriger** si un défaut était atteignable. Il l'était — mais **pas par où on croyait** : pas par le cycle Stripe, par une route d'administration. Sans cette étape, le correctif aurait été juste pour une mauvaise raison.
- Le relevé d'une mission listait **cinq** points de gating d'abonnement. Un `grep` de contrôle en a trouvé **sept**.

**Corollaire pour ce dépôt** : quand une mission dit « il ne reste plus rien », refais le `grep` et **élargis-le**. Un grep étroit rend une preuve étroite.

## 4. Lire la CI toi-même

`gh` est installé et authentifié (compte `pratcom-media`).

```bash
gh pr checks <numéro> --watch
gh run list --limit 5
gh run view <id> --log-failed
```

⚠️ **N'utilise pas l'API GitHub par jeton** pour lire les vérifications : elle renvoie `Permission Denied` ou `pending` sur des chaînes vertes. **`gh` dit la vérité.**

⚠️ **`gh: command not found`** ? Absent du PATH d'un shell déjà ouvert :

```bash
export PATH="/c/Program Files/GitHub CLI:$PATH"
```

## 5. Vérifier du PHP en local

**PHP 8.4.24 est installé** sur le poste, mais **pas dans le PATH d'un shell déjà ouvert** :

```bash
export PATH="$(ls -d /c/Users/DELL/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.4*):$PATH"
php -v
```

**Reproduis exactement ce que fait la CI** (`.github/workflows/ci.yml`) — `php -l` sur tous les fichiers :

```bash
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

⚠️ **Écart de version à connaître : la CI lint en PHP 8.1, ton poste est en 8.4.** Reste sur une syntaxe compatible 8.1. **C'est la CI qui fait foi.**

**Il n'y a aucun banc de test automatisé.** Pour prouver une logique, écris un script PHP jetable qui l'exécute et colle sa sortie dans le relais — **ne le committe pas**. Une table de vérité exécutée vaut mieux qu'un raisonnement : elle a déjà démontré qu'un correctif « évident » cassait un cas de données hérité.

## 6. Le test d'abonnement passe par UN helper

**`Pratcom\Connect\Bridge\FeaturePacks::is_active('chat'|'forms'|'privacy')`** est la source unique. Huit points de gating y convergent depuis le 14/08.

Auparavant, chacun portait sa copie, et aucune ne lisait `enabled` : en PHP, **`!empty(['enabled' => false])` vaut `true`**, parce qu'un tableau non vide est *truthy*. Un abonnement coupé côté serveur restait donc actif côté client — sur le front public, dans quatre cas.

**N'écris jamais un nouveau test d'abonnement à la main.** Le helper gère deux formes de données : table (`{forms:{enabled:true}}`, celle que l'API sert) et **liste héritée** (`["forms"]`, encore tolérée par les routes de l'API). Un test écrit à la va-vite casse toujours l'une des deux.

**Règle du contrat, à respecter** : `enabled` **absent** de la forme table vaut **actif** — sinon un serveur qui n'émettrait pas encore la clé couperait tous les modules d'un coup.

*Deux lecteurs d'affichage restent hors du helper (`DashboardTab.php:40`, `ModulesTab.php:66`). Ils portent la même cécité à la forme liste, mais en administration seulement, et leur conversion attend un arbitrage.*

## 7. Zones à ne pas toucher sans vérifier l'arbitrage

| Zone | Raison |
|---|---|
| `src/Admin/Tabs/JobsTab.php` | Chantier Connect Jobs en cours dessus (au 14/08). |
| `src/Updater.php` | Retiré de la build WordPress.org (`rm -f` dans `build-org.yml`). Le modifier n'affecte que le canal premium. |
| En-tête de `pratcom-connect-bridge.php` | Porte la version **et** la constante de canal (`premium` / `org`). La CI **refuse** un tag dont l'en-tête et la constante divergent. |

## 8. Le socle n'est pas ici

Ce plugin **consomme** l'API (`/api/bridge/handshake`, `/config`, `/events`, `/forms`, …) ; il ne touche jamais la base directement. Les tables et migrations vivent dans `pratcom-connect-core`, et **leurs numéros sont attribués par la Super Vigie**, jamais devinés.

## 9. Ton relais

```
C:\Users\DELL\PratCom Media Dropbox\PratCom Média Inc\Claude\Desktop\Projets\_Portfolio\Relais_Local_<Sujet>_<AAAAMMJJ>.md
```

C'est le seul canal par lequel la Super Vigie apprend ce que tu as fait. Dis ce qui a frotté, ce que tu n'as **pas** pu vérifier, et ce que tu juges le plus à risque.
