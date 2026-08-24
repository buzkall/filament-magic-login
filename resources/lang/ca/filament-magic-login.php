<?php

return [

    'actions' => [
        'magic_link' => 'Envia\'m un enllaç d\'accés',
        'or' => 'o',
    ],

    'messages' => [
        'email_required' => 'Introdueix primer la teva adreça de correu electrònic.',
        'sent_title' => 'Revisa la teva safata d\'entrada',
        'sent_body' => 'Si existeix un compte amb aquesta adreça, t\'hem enviat un enllaç d\'accés. Caduca d\'aquí a :minutes minuts.',
        'too_many_requests_title' => 'Massa intents',
        'too_many_requests_body' => 'Espera :seconds segons abans de tornar-ho a provar.',
        'invalid_title' => 'Aquest enllaç d\'accés no es pot fer servir',
        'invalid_reason' => [
            'invalid' => 'L\'enllaç no és vàlid.',
            'expired' => 'L\'enllaç ha caducat. Sol·licita\'n un de nou.',
            'used' => 'L\'enllaç ja s\'ha fet servir. Sol·licita\'n un de nou.',
            'cannot_access_panel' => 'No tens accés a aquest tauler.',
        ],
    ],

    'mail' => [
        'subject' => 'El teu enllaç d\'accés a :app',
        'greeting' => 'Hola!',
        'intro' => 'Prem el botó de sota per entrar. L\'enllaç caduca d\'aquí a :minutes minuts i només es pot fer servir una vegada.',
        'button' => 'Entrar',
        'ignore' => 'Si no has sol·licitat aquest enllaç, pots ignorar aquest correu.',
        'fallback' => 'Si el botó no funciona, copia aquesta URL al teu navegador:',
    ],

    'exceptions' => [
        'custom_login_without_trait' => 'El tauler [:panel] fa servir una pàgina d\'accés personalitzada [:class] que no utilitza :trait. Afegeix el trait o crida ->useCustomLoginPage().',
        'unknown_storage_driver' => 'Driver d\'emmagatzematge desconegut per a filament-magic-login [:driver]. Fes servir "database" o "cache".',
        'unsafe_cache_store' => 'L\'store de memòria cau [:store] no es pot fer servir amb filament-magic-login en producció.',
    ],

    'install' => [
        'description' => 'Instal\'lar filament-magic-login',
        'intro' => 'Instal\'lar filament-magic-login',
        'cache_driver_skip_migrations' => 'El driver d\'emmagatzematge és "cache": no cal cap migració.',
        'config_overwrite' => 'El fitxer de configuració ja està publicat. El vols sobreescriure?',
        'config_kept' => 'S\'ha mantingut el fitxer de configuració que ja tenies.',
        'config_published' => 'Fitxer de configuració publicat.',
        'migrations_kept' => 'La migració ja està publicada.',
        'migrations_published' => 'Migració publicada.',
        'run_migrations' => 'Vols executar les migracions ara?',
        'migrations_ran' => 'Migracions executades.',
        'migrations_skipped' => 'Migracions omeses. Executa-les abans d\'enviar el primer enllaç d\'accés.',
        'schedule_prompt' => 'Vols programar la neteja diària dels tokens caducats?',
        'schedule_added' => 'S\'ha afegit la neteja a [:path].',
        'schedule_exists' => 'La neteja ja està programada.',
        'schedule_failed' => 'No s\'ha pogut editar [:path] amb seguretat, així que s\'ha deixat intacte.',
        'schedule_manual' => 'Programa la neteja tu mateix amb:',
        'done' => 'filament-magic-login s\'ha instal\'lat.',
    ],

    'uninstall' => [
        'description' => 'Elimina els fitxers que ha publicat filament-magic-login i els seus tokens desats',
        'confirm' => 'Això esborra la configuració i la migració publicades, i elimina tots els tokens d\'accés desats. Vols continuar?',
        'aborted' => 'No s\'ha eliminat res.',
        'drop_table' => 'Vols eliminar la taula [:table] i tots els seus tokens?',
        'table_dropped' => 'S\'ha eliminat la taula [:table].',
        'table_missing' => 'No hi ha cap taula [:table] per eliminar.',
        'table_kept' => 'S\'ha conservat la taula [:table].',
        'cache_note' => 'El driver d\'emmagatzematge és "cache": els enllaços desats caduquen sols, no hi ha res per eliminar.',
        'deleted' => 'S\'ha esborrat [:path].',
        'missing' => 'No hi ha res publicat a [:path].',
        'panels_warning' => 'Encara està registrat en aquests taulers, treu-ne abans el plugin: :panels',
        'code_updated' => 'S\'ha tret el paquet de [:path].',
        'code_manual' => '[:path] encara menciona el paquet a la/les línia/es :lines, treu-les a mà.',
        'next_steps' => 'Ara executa: composer remove arzcode/filament-magic-login',
        'done' => 'filament-magic-login s\'ha desinstal·lat.',
    ],

];
