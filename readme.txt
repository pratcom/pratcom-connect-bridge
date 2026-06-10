=== Pratcom Connect ===
Contributors: pratcommedia
Tags: consent, privacy, cookies, forms, chatbot
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Consentement aux cookies conforme a la Loi 25 (Quebec), gratuit et 100 % local. Modules optionnels : formulaires intelligents et chat IA.

== Description ==

**Pratcom Connect** ajoute a votre site WordPress une banniere de consentement aux cookies conforme a la Loi 25 (Quebec) — gratuitement, sans compte et sans aucun appel a un serveur externe.

= Gratuit (aucun compte requis) =

* **Banniere de consentement Loi 25** : categories de cookies (necessaires, analytiques, marketing), textes FR/EN modifiables, design adaptable a votre marque (couleur principale + palette riche, contraste WCAG calcule automatiquement).
* **Presets des extensions populaires** : cochez les outils que vous utilisez (Google Analytics 4, Meta Pixel, Hotjar, etc.) — la declaration de cookies et les regles de blocage se configurent toutes seules. Suggestion automatique selon les extensions actives sur votre site (detection locale, aucune donnee transmise).
* **Registre local des consentements** : preuve de consentement stockee dans votre base WordPress, export CSV.

= Modules payants (necessitent un compte Pratcom Connect) =

* **Privacy Connect** : scan automatique des cookies et traceurs de votre site.
* **Connect Forms** : formulaires intelligents avec scoring de leads et routage.
* **Connect Chat** : chatbot IA multilingue 24/7 entraine sur votre contenu.

Les modules payants sont fournis par le service Pratcom Connect et s'activent en connectant votre compte (cle API). Sans compte, le plugin reste pleinement fonctionnel pour le consentement Loi 25.

= Service externe (divulgation) =

En version gratuite, **aucune donnee n'est envoyee a un serveur externe** : la banniere, les presets et le registre fonctionnent entierement sur votre site.

Si vous connectez un compte Pratcom Connect (action volontaire), le plugin communique avec le service **api.connect.pratcom.net** (opere par Pratcom Media, Canada) :

* **Quand** : a la connexion du compte (handshake), lors des verifications de statut planifiees, et a l'enregistrement des couleurs de marque.
* **Quoi** : votre cle API, le domaine du site, l'etat des modules et la palette de marque. Les modules actifs (formulaires, chat) transmettent les donnees soumises par vos visiteurs au service pour traitement.
* **Conditions** : [Politique de confidentialite](https://connect.pratcom.net/confidentialite) · [Conditions d'utilisation](https://connect.pratcom.net/conditions)

== Frequently Asked Questions ==

= Le plugin est-il vraiment gratuit ? =

Oui. La banniere de consentement Loi 25, les presets et le registre local sont gratuits, sans compte ni limite de temps. Les modules payants sont optionnels.

= Mes donnees quittent-elles mon site en version gratuite ? =

Non. Aucun appel externe n'est effectue tant que vous ne connectez pas de compte Pratcom Connect.

= La banniere est-elle conforme a la Loi 25 du Quebec ? =

Le plugin fournit les mecanismes requis (consentement prealable par categorie, retrait facile, registre de preuve). La conformite globale depend aussi de vos pratiques (politique de confidentialite, RPRP designe).

= Le plugin est-il bilingue ? =

Oui, francais et anglais (textes de la banniere modifiables dans les deux langues).

= Comment activer les modules Forms et Chat ? =

Creez un compte sur connect.pratcom.net, choisissez vos modules puis collez votre cle API dans l'onglet Compte du plugin.

== Screenshots ==

1. Dashboard — connection overview: workspace, active modules and last handshake.
2. Privacy tab — free Law 25 consent banner with popular plugin presets (GA4, Meta Pixel, WooCommerce and more), local consent registry and CSV export.
3. Forms tab — your Connect Forms with copyable shortcodes.
4. Appearance — brand palette with automatic WCAG-compliant contrast, shared by all modules.
5. Modules — Connect Chat, Forms and Privacy at a glance (activate them with your Pratcom Connect account).
6. Front-end — the consent banner live on a website, in the visitor's language.

== Changelog ==

= 2.0.0 =
* Premiere version publiee sur WordPress.org.
* Banniere de consentement Loi 25 gratuite et 100 % locale.
* Presets des extensions populaires + registre local + export CSV.
* Connexion optionnelle au service Pratcom Connect (modules payants).

== Upgrade Notice ==

= 2.0.0 =
Premiere version WordPress.org.
