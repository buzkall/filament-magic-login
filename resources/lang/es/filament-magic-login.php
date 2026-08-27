<?php

return [

    'actions' => [
        'magic_link' => 'Envíame un enlace de acceso',
        'or' => 'o',
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

    'admin' => [
        'label' => 'Enviar un enlace de acceso',
        'modal' => [
            'heading' => 'Enviar un enlace de acceso',
            'description' => 'Se enviará por correo a :user un enlace de acceso de un solo uso. Aquí nunca se muestra.',
            'submit' => 'Enviar el enlace',
        ],
        'field' => [
            'expiry' => [
                'label' => 'El enlace caduca en',
            ],
            'custom' => [
                'label' => 'Minutos',
                'helper' => 'Entre 1 y :max minutos.',
            ],
        ],
        'presets' => [
            'minutes' => ':count minuto|:count minutos',
            'hours' => ':count hora|:count horas',
            'days' => ':count día|:count días',
            'custom' => 'Personalizado',
        ],
        'sent' => [
            'title' => 'Enlace de acceso enviado',
            'body' => 'Se ha enviado por correo un enlace de acceso a :user. Caduca en :minutes minutos.',
        ],
        'no_email' => [
            'title' => 'Sin dirección de correo',
            'body' => ':user no tiene dirección de correo, así que no hay dónde enviar el enlace.',
        ],
        'cannot_access' => [
            'title' => 'Sin acceso a este panel',
            'body' => ':user no puede acceder al panel [:panel], así que un enlace no le dejaría entrar.',
        ],
        'rate_limited' => [
            'title' => 'Demasiados enlaces enviados',
            'body' => 'Espera :seconds segundos antes de enviar otro.',
        ],
    ],

    'exceptions' => [
        'custom_login_without_trait' => 'El panel [:panel] usa una página de acceso personalizada [:class] que no utiliza :trait. Añade el trait o llama a ->useCustomLoginPage().',
        'panel_without_plugin' => 'El panel [:panel] no registra el plugin de magic login, así que no puede emitir enlaces de acceso.',
        'unknown_storage_driver' => 'Driver de almacenamiento desconocido para filament-magic-login [:driver]. Usa "database" o "cache".',
        'unsafe_cache_store' => 'El store de caché [:store] no puede usarse con filament-magic-login en producción.',
    ],

    'install' => [
        'description' => 'Instalar filament-magic-login',
        'intro' => 'Instalar filament-magic-login',
        'cache_driver_skip_migrations' => 'El driver de almacenamiento es "cache": no hace falta migración.',
        'config_publish' => '¿Quieres publicar el archivo de configuración?',
        'config_skipped' => 'Archivo de configuración omitido. Se aplican los valores por defecto del paquete.',
        'config_overwrite' => 'El archivo de configuración ya está publicado. ¿Quieres sobrescribirlo?',
        'config_kept' => 'Se ha mantenido el archivo de configuración que ya tenías.',
        'config_published' => 'Archivo de configuración publicado.',
        'migrations_kept' => 'La migración ya está publicada.',
        'migrations_published' => 'Migración publicada.',
        'run_migrations' => '¿Ejecutar las migraciones ahora?',
        'migrations_ran' => 'Migraciones ejecutadas.',
        'migrations_skipped' => 'Migraciones omitidas. Ejecútalas antes de enviar el primer enlace de acceso.',
        'schedule_prompt' => '¿Programar la limpieza diaria de los tokens caducados?',
        'schedule_added' => 'Se ha añadido la limpieza a [:path].',
        'schedule_exists' => 'La limpieza ya está programada.',
        'schedule_failed' => 'No se ha podido editar [:path] con seguridad, así que se ha dejado intacto.',
        'schedule_manual' => 'Programa la limpieza tú mismo con:',
        'plugin_registered' => 'El plugin ya está registrado en [:path].',
        'plugin_prompt' => '¿Registrar el plugin en [:path]?',
        'plugin_added' => 'Se ha añadido el plugin a [:path].',
        'plugin_failed' => 'No se ha podido editar [:path] con seguridad, así que se ha dejado intacto.',
        'plugin_manual' => 'Registra el plugin en el panel donde lo quieras:',
        'resource_prompt' => '¿Añadir la acción "enviar un enlace de acceso" a [:path]?',
        'resource_added' => 'Añadida la acción a [:path].',
        'resource_exists' => 'La acción ya está en [:path].',
        'resource_failed' => 'No se pudo editar [:path] con seguridad, así que se dejó intacto.',
        'resource_missing' => 'No se encontró un único recurso de Filament para [:model], así que no se cambió nada.',
        'resource_manual' => 'Añade tú mismo la acción a tu recurso de usuarios:',
        'done' => 'filament-magic-login se ha instalado.',
    ],

    'uninstall' => [
        'description' => 'Elimina los archivos que publicó filament-magic-login y sus tokens guardados',
        'confirm' => 'Esto borra la configuración y la migración publicadas, y elimina todos los tokens de acceso guardados. ¿Continuar?',
        'aborted' => 'No se ha eliminado nada.',
        'drop_table' => '¿Eliminar la tabla [:table] y todos sus tokens?',
        'table_dropped' => 'Se ha eliminado la tabla [:table].',
        'table_missing' => 'No hay ninguna tabla [:table] que eliminar.',
        'table_kept' => 'Se ha conservado la tabla [:table].',
        'cache_note' => 'El driver de almacenamiento es "cache": los enlaces guardados caducan solos, no hay nada que eliminar.',
        'deleted' => 'Se ha borrado [:path].',
        'missing' => 'No hay nada publicado en [:path].',
        'panels_warning' => 'Sigue registrado en estos paneles, quita antes el plugin de ellos: :panels',
        'code_updated' => 'Se ha quitado el paquete de [:path].',
        'code_manual' => '[:path] todavía menciona el paquete en la(s) línea(s) :lines, quítalas a mano.',
        'next_steps' => 'Ahora ejecuta: composer remove arzcode/filament-magic-login',
        'done' => 'filament-magic-login se ha desinstalado.',
    ],

];
