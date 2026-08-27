<?php

return [

    'actions' => [
        'magic_link' => 'Envia\'m un enllaç d\'accés',
        'or' => 'o',
    ],

    'messages' => [
        'email_required' => 'Introdueix primer la teva adreça de correu electrònic.',
        'sent_title' => 'Revisa la teva safata d\'entrada',
        'sent_body' => 'Si existeix un compte amb aquesta adreça, t\'hem enviat un enllaç d\'accés. Caduca d\'aquí a :duration.',
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
        'intro' => 'Prem el botó de sota per entrar. L\'enllaç caduca d\'aquí a :duration i només es pot fer servir una vegada.',
        'button' => 'Entrar',
        'ignore' => 'Si no has sol·licitat aquest enllaç, pots ignorar aquest correu.',
        'fallback' => 'Si el botó no funciona, copia aquesta URL al teu navegador:',
    ],

    'admin' => [
        'label' => 'Envia un enllaç d\'accés',
        'modal' => [
            'heading' => 'Envia un enllaç d\'accés',
            'description' => 'S\'enviarà per correu a :user un enllaç d\'accés d\'un sol ús. Aquí no es mostra mai.',
            'panel' => 'Li dóna accés al tauler [:panel].',
            'submit' => 'Envia l\'enllaç',
        ],
        'field' => [
            'expiry' => [
                'label' => 'L\'enllaç caduca d\'aquí a',
            ],
            'custom' => [
                'label' => 'Minuts',
                'helper' => 'Entre 1 i :max minuts.',
            ],
        ],
        'presets' => [
            'minutes' => ':count minut|:count minuts',
            'hours' => ':count hora|:count hores',
            'days' => ':count dia|:count dies',
            'custom' => 'Personalitzat',
        ],
        'sent' => [
            'title' => 'Enllaç d\'accés enviat',
            'body' => 'S\'ha enviat per correu un enllaç d\'accés a :user. Caduca d\'aquí a :duration.',
        ],
        'no_email' => [
            'title' => 'Sense adreça de correu',
            'body' => ':user no té adreça de correu, així que no hi ha on enviar l\'enllaç.',
        ],
        'cannot_access' => [
            'title' => 'Sense accés a aquest tauler',
            'body' => ':user no pot accedir al tauler [:panel], així que un enllaç no el deixaria entrar.',
        ],
        'rate_limited' => [
            'title' => 'S\'han enviat massa enllaços',
            'body' => 'Espera :seconds segons abans d\'enviar-ne un altre.',
        ],
    ],

    'exceptions' => [
        'custom_login_without_trait' => 'El tauler [:panel] fa servir una pàgina d\'accés personalitzada [:class] que no utilitza :trait. Afegeix el trait o crida ->useCustomLoginPage().',
        'panel_without_plugin' => 'El tauler [:panel] no registra el plugin de magic login, així que no pot emetre enllaços d\'accés.',
        'unknown_storage_driver' => 'Driver d\'emmagatzematge desconegut per a filament-magic-login [:driver]. Fes servir "database" o "cache".',
        'unsafe_cache_store' => 'L\'store de memòria cau [:store] no es pot fer servir amb filament-magic-login en producció.',
    ],

    'install' => [
        'description' => 'Instal\'lar filament-magic-login',
        'intro' => 'Instal\'lar filament-magic-login',
        'cache_driver_skip_migrations' => 'El driver d\'emmagatzematge és "cache": no cal cap migració.',
        'config_publish' => 'Vols publicar el fitxer de configuració?',
        'config_skipped' => 'Fitxer de configuració omès. S\'apliquen els valors per defecte del paquet.',
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
        'plugin_registered' => 'El plugin ja està registrat a [:path].',
        'plugin_prompt' => 'Vols registrar el plugin a [:path]?',
        'plugin_added' => 'S\'ha afegit el plugin a [:path].',
        'plugin_failed' => 'No s\'ha pogut editar [:path] amb seguretat, així que s\'ha deixat intacte.',
        'plugin_manual' => 'Registra el plugin al tauler on el vulguis:',
        'resource_prompt' => 'Vols afegir l\'acció "envia un enllaç d\'accés" a [:path]?',
        'resource_added' => 'Afegida l\'acció a [:path].',
        'resource_exists' => 'L\'acció ja és a [:path].',
        'resource_failed' => 'No s\'ha pogut editar [:path] amb seguretat, així que s\'ha deixat intacte.',
        'resource_missing' => 'No s\'ha trobat un únic recurs de Filament per a [:model], així que no s\'ha canviat res.',
        'resource_manual' => 'Afegeix tu mateix l\'acció al teu recurs d\'usuaris:',
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
        'table_kept_hint' => 'Les seves files encara hi són, i en tornar a instal·lar el paquet es trobarà la taula ja creada. Per eliminar-la: php artisan :command',
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
