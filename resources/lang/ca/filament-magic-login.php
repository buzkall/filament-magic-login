<?php

return [

    'actions' => [
        'magic_link' => 'Envia\'m un enllaç d\'accés',
        'magic_link_tooltip' => 'Entrar sense contrasenya',
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
        'cache_driver_skip_migrations' => 'El driver d\'emmagatzematge és "cache": no cal cap migració.',
    ],

];
