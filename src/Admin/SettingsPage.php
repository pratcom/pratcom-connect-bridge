<?php

namespace Pratcom\Connect\Bridge\Admin;

/**
 * @deprecated 2.0.0-dev Le monolithe SettingsPage (34 Ko, 2 corruptions -
 * lecon #4 du registre) est remplace par AdminShell + src/Admin/Tabs/*
 * (refactor O0, chantier Plugin .org). Cet alias ne contient AUCUNE logique
 * et n'est conserve que si du code externe referencait la classe.
 * A supprimer apres v2.0.0 stable.
 */
class SettingsPage extends AdminShell
{
}
