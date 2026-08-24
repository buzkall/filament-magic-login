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

    'exceptions' => [
        'custom_login_without_trait' => 'El panel [:panel] usa una página de acceso personalizada [:class] que no utiliza :trait. Añade el trait o llama a ->useCustomLoginPage().',
        'unknown_storage_driver' => 'Driver de almacenamiento desconocido para filament-magic-login [:driver]. Usa "database" o "cache".',
        'unsafe_cache_store' => 'El store de caché [:store] no puede usarse con filament-magic-login en producción.',
    ],

    'install' => [
        'description' => 'Instalar filament-magic-login',
        'intro' => 'Instalar filament-magic-login',
        'cache_driver_skip_migrations' => 'El driver de almacenamiento es "cache": no hace falta migración.',
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
