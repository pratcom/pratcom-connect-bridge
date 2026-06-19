<?php

namespace Pratcom\Connect\Bridge\Privacy;

/**
 * Template LOCAL de la politique de confidentialité (tier Free / fallback).
 *
 * Miroir PHP du template serveur (connect-chat src/lib/privacy/
 * policy-template.ts) — mêmes 11 sections Loi 25, même version. Mis à jour
 * via les mises à jour du plugin (modèle Complianz gratuit, spec §6b).
 * ⚠️ Toute modification de contenu doit être faite EN PAIRE avec le
 * template serveur + bump de TEMPLATE_VERSION des deux côtés.
 *
 * Variables : option OPTION_VARS (legalName, websiteUrl, contactEmail,
 * contactPhone, address, officerName, officerEmail) — défauts raisonnables
 * tirés du site. Témoins : option OPTION_COOKIES (liste manuelle, lignes
 * {name, provider, purpose, expiry, category}) — l'UI d'édition arrive avec
 * l'onglet Confidentialité (O0/O3).
 *
 * ⚠️ Textes V1 provisoires (best-effort sans avocat) — disclaimer inclus.
 */
class LocalPolicy
{
    public const TEMPLATE_VERSION = '2026-06-09.1';
    public const OPTION_VARS = 'pratcom_connect_privacy_policy_vars';
    public const OPTION_COOKIES = 'pratcom_connect_privacy_cookies';

    /** @return array<string, string> */
    private static function variables(string $lang): array
    {
        $missing = $lang === 'en' ? '[to be completed]' : '[à compléter]';
        $saved = get_option(self::OPTION_VARS, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $get = static function (string $k, string $fallback) use ($saved): string {
            return (isset($saved[$k]) && is_string($saved[$k]) && trim($saved[$k]) !== '')
                ? trim($saved[$k])
                : $fallback;
        };
        $email = $get('contactEmail', (string) get_option('admin_email', $missing));
        return [
            'legalName'       => $get('legalName', (string) get_bloginfo('name')),
            'websiteUrl'      => $get('websiteUrl', (string) home_url('/')),
            'contactEmail'    => $email,
            'contactPhone'    => $get('contactPhone', $missing),
            'address'         => $get('address', $missing),
            'officerName'     => $get('officerName', $missing),
            'officerEmail'    => $get('officerEmail', $email),
            'reconsentMonths' => '12',
            'updatedDate'     => date_i18n($lang === 'en' ? 'F j, Y' : 'j F Y'),
        ];
    }

    private static function interpolate(string $text, array $vars): string
    {
        return preg_replace_callback(
            '/\\{\\{(\\w+)\\}\\}/',
            static function (array $m) use ($vars): string {
                return isset($vars[$m[1]]) ? (string) $vars[$m[1]] : $m[0];
            },
            $text
        );
    }

    /**
     * Sections bilingues — miroir du template serveur 2026-06-09.1.
     * @return array<int, array{title: array<string,string>, paragraphs: array<int, array<string,string>>}>
     */
    private static function sections(): array
    {
        return [
            [
                'title' => [
                    'fr' => 'Qui nous sommes et qui est responsable',
                    'en' => 'Who we are and who is in charge',
                ],
                'paragraphs' => [
                    [
                        'fr' => '{{legalName}} (« nous ») exploite le site {{websiteUrl}}. La présente politique décrit quels renseignements personnels nous recueillons, pourquoi nous les recueillons, comment nous les utilisons, les communiquons et les protégeons, ainsi que vos droits. Elle est rédigée pour répondre aux exigences de la Loi 25 (Québec) et, le cas échéant, des autres lois applicables en matière de protection des renseignements personnels.',
                        'en' => '{{legalName}} (“we”) operates the website {{websiteUrl}}. This policy describes what personal information we collect, why we collect it, how we use, disclose and protect it, and what your rights are. It is written to meet the requirements of Quebec\'s Law 25 and, where applicable, other applicable privacy laws.',
                    ],
                    [
                        'fr' => 'La personne responsable de la protection des renseignements personnels est {{officerName}}. Vous pouvez la joindre par courriel à {{officerEmail}}.',
                        'en' => 'The person in charge of the protection of personal information is {{officerName}}. You can reach them by email at {{officerEmail}}.',
                    ],
                ],
            ],
            [
                'title' => [
                    'fr' => 'Renseignements que nous recueillons',
                    'en' => 'Information we collect',
                ],
                'paragraphs' => [
                    [
                        'fr' => 'Nous recueillons uniquement les renseignements nécessaires aux fins décrites dans la présente politique : les renseignements que vous nous fournissez volontairement (par exemple au moyen d\'un formulaire de contact) et certaines données techniques liées à votre visite (type de navigateur, pages consultées, selon vos choix de consentement).',
                        'en' => 'We only collect the information necessary for the purposes described in this policy: information you voluntarily provide to us (for example through a contact form) and certain technical data related to your visit (browser type, pages viewed, depending on your consent choices).',
                    ],
                ],
            ],
            [
                'title' => ['fr' => 'Pourquoi nous les utilisons', 'en' => 'Why we use it'],
                'paragraphs' => [
                    [
                        'fr' => 'Nous utilisons vos renseignements pour : répondre à vos demandes et vous fournir nos services; assurer le fonctionnement, la sécurité et l\'amélioration du site; respecter nos obligations légales; et, uniquement avec votre consentement, mesurer l\'audience du site ou réaliser des activités de marketing. Nous ne vendons jamais vos renseignements personnels.',
                        'en' => 'We use your information to: respond to your requests and provide our services; ensure the operation, security and improvement of the site; comply with our legal obligations; and, only with your consent, measure site traffic or carry out marketing activities. We never sell your personal information.',
                    ],
                ],
            ],
            [
                'title' => ['fr' => 'Témoins (cookies)', 'en' => 'Cookies'],
                'paragraphs' => [
                    [
                        'fr' => 'Le site utilise des témoins (cookies) et technologies similaires. Les témoins non nécessaires sont bloqués tant que vous n\'avez pas donné votre consentement par catégorie au moyen de la fenêtre de gestion des témoins. Le tableau ci-dessous présente les témoins utilisés sur ce site.',
                        'en' => 'The site uses cookies and similar technologies. Non-necessary cookies are blocked until you give your consent per category through the cookie management window. The table below lists the cookies used on this site.',
                    ],
                ],
            ],
            [
                'title' => [
                    'fr' => 'Votre consentement et son retrait',
                    'en' => 'Your consent and how to withdraw it',
                ],
                'paragraphs' => [
                    [
                        'fr' => 'À votre première visite, une fenêtre vous permet d\'accepter, de refuser ou de personnaliser les témoins par catégorie — refuser est aussi simple qu\'accepter, sans pénalité. Votre choix est conservé pendant {{reconsentMonths}} mois, après quoi il vous sera demandé de nouveau. Vous pouvez modifier ou retirer votre consentement à tout moment au moyen de l\'icône flottante de gestion des témoins affichée sur le site.',
                        'en' => 'On your first visit, a window lets you accept, decline or customize cookies by category — declining is as easy as accepting, with no penalty. Your choice is kept for {{reconsentMonths}} months, after which you will be asked again. You can change or withdraw your consent at any time using the floating cookie management icon displayed on the site.',
                    ],
                ],
            ],
            [
                'title' => ['fr' => 'Conservation', 'en' => 'Retention'],
                'paragraphs' => [
                    [
                        'fr' => 'Nous conservons vos renseignements personnels uniquement le temps nécessaire à la réalisation des fins pour lesquelles ils ont été recueillis, ou le temps requis par la loi. Lorsque ces fins sont accomplies, les renseignements sont détruits ou anonymisés de façon sécuritaire.',
                        'en' => 'We keep your personal information only as long as necessary to fulfill the purposes for which it was collected, or as required by law. Once these purposes are fulfilled, the information is securely destroyed or anonymized.',
                    ],
                ],
            ],
            [
                'title' => [
                    'fr' => 'Communication à des tiers et hébergement',
                    'en' => 'Disclosure to third parties and hosting',
                ],
                'paragraphs' => [
                    [
                        'fr' => 'Nous ne communiquons vos renseignements qu\'aux fournisseurs de services nécessaires à l\'exploitation du site (hébergement, envoi de courriels, outils de mesure d\'audience selon vos consentements), qui sont tenus contractuellement de les protéger, ou lorsque la loi l\'exige. Certains fournisseurs peuvent traiter des données à l\'extérieur du Québec ou du Canada; dans ce cas, la communication n\'a lieu qu\'après une évaluation tenant compte de la sensibilité des renseignements et des protections offertes.',
                        'en' => 'We only disclose your information to service providers necessary to operate the site (hosting, email delivery, audience measurement tools according to your consents), who are contractually required to protect it, or where required by law. Some providers may process data outside Quebec or Canada; in such cases, disclosure occurs only after an assessment taking into account the sensitivity of the information and the protections offered.',
                    ],
                ],
            ],
            [
                'title' => ['fr' => 'Vos droits', 'en' => 'Your rights'],
                'paragraphs' => [
                    [
                        'fr' => 'Vous avez le droit de demander l\'accès aux renseignements personnels que nous détenons à votre sujet, d\'en demander la rectification, de retirer votre consentement à leur utilisation ou communication, et de demander la cessation de leur diffusion dans les cas prévus par la loi. Pour exercer ces droits, écrivez à {{officerEmail}}. Nous répondrons dans les 30 jours. Si vous êtes insatisfait de notre réponse, vous pouvez déposer une plainte auprès de la Commission d\'accès à l\'information du Québec (CAI).',
                        'en' => 'You have the right to request access to the personal information we hold about you, to request its rectification, to withdraw your consent to its use or disclosure, and to request that its dissemination cease in the cases provided by law. To exercise these rights, write to {{officerEmail}}. We will respond within 30 days. If you are dissatisfied with our response, you may file a complaint with the Commission d\'accès à l\'information du Québec (CAI).',
                    ],
                ],
            ],
            [
                'title' => ['fr' => 'Sécurité', 'en' => 'Security'],
                'paragraphs' => [
                    [
                        'fr' => 'Nous appliquons des mesures de sécurité raisonnables et proportionnées à la sensibilité des renseignements : chiffrement des communications (TLS), contrôle des accès et minimisation des données. Aucun système n\'est toutefois infaillible; en cas d\'incident de confidentialité présentant un risque de préjudice sérieux, nous vous en informerons ainsi que la CAI, conformément à la loi.',
                        'en' => 'We apply security measures that are reasonable and proportionate to the sensitivity of the information: encrypted communications (TLS), access controls and data minimization. No system is infallible, however; in the event of a confidentiality incident presenting a risk of serious injury, we will notify you and the CAI as required by law.',
                    ],
                ],
            ],
            [
                'title' => [
                    'fr' => 'Modifications de la présente politique',
                    'en' => 'Changes to this policy',
                ],
                'paragraphs' => [
                    [
                        'fr' => 'Nous pouvons mettre à jour la présente politique de temps à autre, notamment lorsque les lois ou nos pratiques changent. La date de la dernière mise à jour figure en haut de la page. En cas de changement important touchant le traitement de vos renseignements, votre consentement sera demandé de nouveau lorsque la loi l\'exige.',
                        'en' => 'We may update this policy from time to time, in particular when laws or our practices change. The date of the last update appears at the top of the page. In the event of a significant change affecting the processing of your information, your consent will be requested again where required by law.',
                    ],
                ],
            ],
            [
                'title' => ['fr' => 'Nous joindre', 'en' => 'Contact us'],
                'paragraphs' => [
                    [
                        'fr' => 'Pour toute question concernant la présente politique ou vos renseignements personnels : {{legalName}}, {{address}}. Courriel : {{contactEmail}}. Téléphone : {{contactPhone}}.',
                        'en' => 'For any question about this policy or your personal information: {{legalName}}, {{address}}. Email: {{contactEmail}}. Phone: {{contactPhone}}.',
                    ],
                ],
            ],
        ];
    }

    public static function render(string $lang): string
    {
        $lang = $lang === 'en' ? 'en' : 'fr';
        $vars = self::variables($lang);

        $title = $lang === 'en' ? 'Privacy Policy' : 'Politique de confidentialité';
        $updated = $lang === 'en'
            ? 'Last updated: ' . $vars['updatedDate']
            : 'Dernière mise à jour : ' . $vars['updatedDate'];
        $disclaimer = $lang === 'en'
            ? 'This document is generated from a template provided for informational purposes by Pratcom Média inc. and does not constitute legal advice. ' . $vars['legalName'] . ' remains responsible for the compliance of its practices and the accuracy of the information presented. For any legal question, consult a qualified advisor.'
            : 'Ce document est généré à partir d\'un modèle fourni à titre informatif par Pratcom Média inc. et ne constitue pas un avis juridique. ' . $vars['legalName'] . ' demeure responsable de la conformité de ses pratiques et de l\'exactitude des renseignements présentés. Pour toute question juridique, consultez un conseiller qualifié.';

        $out  = '<article class="pratcom-policy" lang="' . esc_attr($lang) . '">';
        $out .= '<h1 class="pratcom-policy-title">' . esc_html($title) . '</h1>';
        $out .= '<p class="pratcom-policy-updated"><em>' . esc_html($updated) . '</em></p>';

        $cookie_section_index = 3; // section « Témoins »
        foreach (self::sections() as $i => $section) {
            $out .= '<section><h2>' . esc_html($section['title'][$lang]) . '</h2>';
            foreach ($section['paragraphs'] as $p) {
                $out .= '<p>' . esc_html(self::interpolate($p[$lang], $vars)) . '</p>';
            }
            if ($i === $cookie_section_index) {
                $out .= self::render_cookie_table($lang);
            }
            $out .= '</section>';
        }

        $out .= '<hr class="pratcom-policy-sep" /><p class="pratcom-policy-disclaimer"><small>'
            . esc_html($disclaimer) . '</small></p>';
        $out .= '</article>';
        return $out;
    }

    /**
     * Tableau des témoins intégré à la politique — DYNAMIQUE.
     *
     * Source = CookieScan::merged_rows($lang) : fusion dédupliquée des presets
     * sélectionnés (Presets::cookie_rows) + liste manuelle (OPTION_COOKIES) +
     * noms détectés par le mini-scan local. Les entrées manuelles sont
     * conservées (jamais écrasées). 100 % local, zéro appel serveur.
     *
     * ⚠️ RENDU PUBLIC : les témoins « non classés » (unclassified) sont exclus
     * de ce tableau — ils ne doivent jamais paraître côté visiteur. Ils restent
     * visibles dans l'onglet d'administration pour être classés.
     */
    private static function render_cookie_table(string $lang): string
    {
        $cookies = array_values(array_filter(
            CookieScan::merged_rows($lang),
            static function ($c): bool {
                return is_array($c) && (string) ($c['category'] ?? '') !== 'unclassified';
            }
        ));
        if (!count($cookies)) {
            $msg = $lang === 'en'
                ? 'The cookie list has not been completed yet. It can be filled in from the Pratcom Connect settings (presets, manual list or local scan).'
                : 'La liste des témoins n\'a pas encore été remplie. Elle peut être complétée depuis les réglages Pratcom Connect (presets, liste manuelle ou scan local).';
            return '<p class="pratcom-policy-cookies-empty"><em>' . esc_html($msg) . '</em></p>';
        }

        $head = $lang === 'en'
            ? ['Name', 'Provider', 'Purpose', 'Expiry']
            : ['Nom', 'Fournisseur', 'Finalité', 'Durée'];
        $out  = '<table class="pratcom-policy-table"><thead><tr>';
        foreach ($head as $h) {
            $out .= '<th>' . esc_html($h) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($cookies as $c) {
            if (!is_array($c)) {
                continue;
            }
            $out .= '<tr><td><code>' . esc_html((string) ($c['name'] ?? '')) . '</code></td>'
                . '<td>' . esc_html((string) ($c['provider'] ?? '')) . '</td>'
                . '<td>' . esc_html((string) ($c['purpose'] ?? '')) . '</td>'
                . '<td>' . esc_html((string) ($c['expiry'] ?? '')) . '</td></tr>';
        }
        $out .= '</tbody></table>';
        return $out;
    }
}
