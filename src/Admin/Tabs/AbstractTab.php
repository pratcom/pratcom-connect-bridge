<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\Admin\AdminShell;

/**
 * Classe de base d'un onglet de l'admin Pratcom Connect.
 *
 * Chaque onglet vit dans son propre fichier (src/Admin/Tabs/) et appartient
 * au chantier qui possede son contenu (Apparence = Privacy, Formulaires =
 * Forms, Chat = Chatbot, le reste = Plugin .org). L'AdminShell ne fait que
 * les assembler. Refactor O0 - remplace le monolithe SettingsPage.php
 * (lecon #4 du registre des erreurs : plus aucun gros fichier fragile).
 */
abstract class AbstractTab
{
    /** Slug de page wp-admin (admin.php?page=...). */
    abstract public function slug(): string;

    /** Libelle affiche dans le menu et la sidebar. */
    abstract public function label(): string;

    /** Nom d'icone dashicons (sans le prefixe "dashicons-"). */
    abstract public function icon(): string;

    /** Rend le contenu de l'onglet (sans le chrome header/sidebar). */
    abstract public function render(): void;

    /**
     * Enregistre les hooks propres a l'onglet (admin_post_*, etc.).
     * Appele une seule fois par AdminShell a la construction.
     */
    public function register(): void
    {
    }

    /** Redirige vers un onglet avec une notice (helper commun). */
    protected function redirect_with_notice(string $page_slug, string $notice, string $msg): void
    {
        AdminShell::redirect_with_notice($page_slug, $notice, $msg);
    }
}
