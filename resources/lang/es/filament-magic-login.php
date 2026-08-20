<?php

return [

    'actions' => [
        'magic_link' => 'Envíame un enlace de acceso',
        'magic_link_tooltip' => 'Entrar sin contraseña',
    ],

    'messages' => [
        'email_required' => 'Introduce primero tu dirección de correo electrónico.',
        'sent_title' => 'Revisa tu bandeja de entrada',
        'sent_body' => 'Si existe una cuenta con esa dirección, te hemos enviado un enlace de acceso. Caduca en :minutes minutos.',
        'too_many_requests_title' => 'Demasiados intentos',
        'too_many_requests_body' => 'Espera :seconds segundos antes de volver a intentarlo.',
        'invalid_title' => 'Este enlace de acceso no se puede usar',
        'invalid_reason' => [
            'invalid' => 'El enlace no es válido.',
            'expired' => 'El enlace ha caducado. Solicita uno nuevo.',
            'used' => 'El enlace ya se ha utilizado. Solicita uno nuevo.',
            'cannot_access_panel' => 'No tienes acceso a este panel.',
        ],
    ],

    'mail' => [
        'subject' => 'Tu enlace de acceso a :app',
        'greeting' => '¡Hola!',
        'intro' => 'Pulsa el botón de abajo para entrar. El enlace caduca en :minutes minutos y solo se puede usar una vez.',
        'button' => 'Entrar',
        'ignore' => 'Si no has solicitado este enlace, puedes ignorar este correo.',
        'fallback' => 'Si el botón no funciona, copia esta URL en tu navegador:',
    ],

    'exceptions' => [
        'custom_login_without_trait' => 'El panel [:panel] usa una página de acceso personalizada [:class] que no utiliza :trait. Añade el trait o llama a ->useCustomLoginPage().',
        'unknown_storage_driver' => 'Driver de almacenamiento desconocido para filament-magic-login [:driver]. Usa "database" o "cache".',
        'unsafe_cache_store' => 'El store de caché [:store] no puede usarse con filament-magic-login en producción.',
    ],

    'install' => [
        'cache_driver_skip_migrations' => 'El driver de almacenamiento es "cache": no hace falta migración.',
    ],

];
